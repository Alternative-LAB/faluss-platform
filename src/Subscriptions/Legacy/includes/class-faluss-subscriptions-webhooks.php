<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Signed public Stripe endpoint. It is deliberately independent of WordPress sessions and nonces. */
final class Faluss_Subscriptions_Webhooks {
    const ROUTE = 'faluss-subscriptions/v1';
    const PATH = '/stripe/webhook';

    public static function boot() { add_action( 'rest_api_init', array( __CLASS__, 'register' ) ); }

    public static function register() {
        register_rest_route( self::ROUTE, self::PATH, array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'receive' ), 'permission_callback' => '__return_true' ) );
    }

    public static function receive( $request ) {
        nocache_headers();
        $payload = file_get_contents( 'php://input' );
        if ( ! is_string( $payload ) || '' === $payload ) { $payload = is_object( $request ) && method_exists( $request, 'get_body' ) ? $request->get_body() : ''; }
        $signature = is_object( $request ) && method_exists( $request, 'get_header' ) ? $request->get_header( 'stripe-signature' ) : '';
        $secret = Faluss_Subscriptions_Stripe_Config::webhook_secret();
        $adapter = Faluss_Subscriptions_Stripe_Sdk::adapter();
        if ( ! is_string( $payload ) || '' === $payload || ! is_string( $signature ) || '' === $signature ) { return self::response( array( 'received' => false ), 400 ); }
        if ( is_wp_error( $secret ) || is_wp_error( $adapter ) ) { return self::response( array( 'received' => false ), 503 ); }
        $event = $adapter->verify_webhook( $payload, $signature, $secret );
        if ( is_wp_error( $event ) || ! is_array( $event ) || '' === self::id( $event['id'] ?? '' ) || '' === self::event_type( $event['type'] ?? '' ) ) { return self::response( array( 'received' => false ), 400 ); }
        $recorded = Faluss_Subscriptions_Repository::record_event( 'stripe', self::id( $event['id'] ), self::event_type( $event['type'] ), hash( 'sha256', $payload ) );
        if ( is_wp_error( $recorded ) ) { return self::response( array( 'received' => false ), 503 ); }
        $record = $recorded['event'];
        if ( ! empty( $recorded['idempotent'] ) && in_array( $record['processing_status'] ?? '', array( 'processed', 'ignored' ), true ) ) { return self::response( array( 'received' => true ), 200 ); }
        $result = self::process_event( $record, $event, $adapter );
        if ( is_wp_error( $result ) ) { Faluss_Subscriptions_Repository::update_event_status( (int) $record['id'], 'failed', $result->get_error_code() ); return self::response( array( 'received' => false ), 503 ); }
        return self::response( array( 'received' => true ), 200 );
    }

    /** @return true|WP_Error */
    public static function retry_event( $event_id ) {
        $record = Faluss_Subscriptions_Repository::event_by_id( $event_id );
        if ( ! is_array( $record ) || 'stripe' !== ( $record['provider'] ?? '' ) ) { return new WP_Error( 'stripe_event_missing' ); }
        $adapter = Faluss_Subscriptions_Stripe_Sdk::adapter();
        if ( is_wp_error( $adapter ) ) { return $adapter; }
        // Raw signed delivery bodies are intentionally not retained. A privileged
        // retry instead fetches the provider's canonical event by its durable ID,
        // then follows the same current-object re-fetch path as a delivery.
        $event = $adapter->retrieve_event( self::id( $record['provider_event_id'] ?? '' ) );
        if ( is_wp_error( $event ) || ! is_array( $event ) ) { return is_wp_error( $event ) ? $event : new WP_Error( 'stripe_event_unavailable' ); }
        $type = self::event_type( $event['type'] ?? '' );
        if ( '' === $type || self::id( $event['id'] ?? '' ) !== self::id( $record['provider_event_id'] ?? '' ) ) { return new WP_Error( 'stripe_event_unavailable' ); }
        $result = self::process_event( $record, $event, $adapter );
        if ( is_wp_error( $result ) ) { Faluss_Subscriptions_Repository::update_event_status( (int) $record['id'], 'failed', $result->get_error_code() ); }
        return $result;
    }

    /** @return true|WP_Error */
    private static function process_event( $record, $event, $adapter ) {
        $type = self::event_type( $event['type'] ?? '' );
        if ( ! self::handled_type( $type ) ) { Faluss_Subscriptions_Repository::update_event_status( (int) $record['id'], 'ignored' ); return true; }
        Faluss_Subscriptions_Repository::update_event_status( (int) $record['id'], 'processing' );
        $subscription = self::current_subscription( $adapter, $event, $type );
        if ( is_wp_error( $subscription ) ) { return $subscription; }
        if ( ! is_array( $subscription ) ) { Faluss_Subscriptions_Repository::update_event_status( (int) $record['id'], 'ignored' ); return true; }
        $result = Faluss_Subscriptions_Billing::apply_stripe_subscription( $subscription, $type );
        if ( is_wp_error( $result ) ) { return $result; }
        Faluss_Subscriptions_Repository::update_event_status( (int) $record['id'], 'processed' );
        return true;
    }

    /** Re-fetch the current Stripe object; the event snapshot is never authoritative. */
    private static function current_subscription( $adapter, $event, $type ) {
        $object = is_array( $event['data']['object'] ?? null ) ? $event['data']['object'] : array();
        if ( 0 === strpos( $type, 'customer.subscription.' ) ) { return $adapter->retrieve_subscription( self::id( $object['id'] ?? '' ) ); }
        if ( 'checkout.session.completed' === $type ) {
            $session = $adapter->retrieve_checkout_session( self::id( $object['id'] ?? '' ) );
            return is_wp_error( $session ) ? $session : $adapter->retrieve_subscription( self::id( $session['subscription'] ?? '' ) );
        }
        if ( 0 === strpos( $type, 'invoice.' ) ) {
            $invoice = $adapter->retrieve_invoice( self::id( $object['id'] ?? '' ) );
            return is_wp_error( $invoice ) ? $invoice : $adapter->retrieve_subscription( self::id( $invoice['subscription'] ?? '' ) );
        }
        if ( 'charge.refunded' === $type || 0 === strpos( $type, 'charge.dispute.' ) ) {
            $charge = $adapter->retrieve_charge( self::id( $object['id'] ?? '' ) );
            if ( is_wp_error( $charge ) ) { return $charge; }
            $invoice = $adapter->retrieve_invoice( self::id( $charge['invoice'] ?? '' ) );
            return is_wp_error( $invoice ) ? $invoice : $adapter->retrieve_subscription( self::id( $invoice['subscription'] ?? '' ) );
        }
        if ( 'customer.updated' === $type || 0 === strpos( $type, 'payment_method.' ) ) {
            $customer_reference = 'customer.updated' === $type ? self::id( $object['id'] ?? '' ) : self::id( $object['customer'] ?? '' );
            $customer = Faluss_Subscriptions_Repository::customer_for_reference( $customer_reference, 'stripe' );
            if ( is_array( $customer ) ) {
                foreach ( Faluss_Subscriptions_Repository::subscriptions_for_faluss_id( (string) $customer['faluss_id'] ) as $record ) {
                    if ( 'stripe' === ( $record['provider'] ?? '' ) ) { return $adapter->retrieve_subscription( self::id( $record['provider_subscription_reference'] ?? '' ) ); }
                }
                // The first signed delivery may legitimately precede Stripe's
                // Customer default-PaymentMethod projection. When its later
                // customer/payment event arrives, recover only through the
                // matching local Checkout and re-read both current objects.
                $checkout = Faluss_Subscriptions_Repository::open_checkout_for_customer_reference( $customer_reference );
                $session_reference = is_array( $checkout ) ? self::id( $checkout['provider_session_reference'] ?? '' ) : '';
                if ( '' !== $session_reference ) {
                    $session = $adapter->retrieve_checkout_session( $session_reference );
                    if ( is_wp_error( $session ) ) { return $session; }
                    if ( is_array( $session ) && 'complete' === ( $session['status'] ?? '' ) && $customer_reference === self::id( $session['customer'] ?? '' ) ) {
                        return $adapter->retrieve_subscription( self::id( $session['subscription'] ?? '' ) );
                    }
                }
            }
        }
        // A member with no local Stripe subscription has no entitlement to alter.
        // The snapshot is never trusted to infer one.
        return null;
    }

    private static function handled_type( $type ) { return in_array( $type, array( 'checkout.session.completed', 'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted', 'customer.subscription.trial_will_end', 'invoice.paid', 'invoice.payment_failed', 'invoice.payment_action_required', 'invoice.finalization_failed', 'charge.refunded', 'charge.dispute.created', 'charge.dispute.closed', 'customer.updated', 'payment_method.attached', 'payment_method.detached' ), true ); }
    private static function event_type( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-z0-9_.]{3,80}$/', $value ) ? $value : ''; }
    private static function id( $value ) { return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_]+$/', $value ) ? $value : ''; }
    private static function response( $body, $status ) { $response = new WP_REST_Response( $body, $status ); $response->header( 'Cache-Control', 'no-store, private' ); return $response; }
}
