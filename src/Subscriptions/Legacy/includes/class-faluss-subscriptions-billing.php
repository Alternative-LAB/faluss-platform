<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Private central billing façade. It is intentionally not attached to an AJAX,
 * shortcode or anonymous endpoint: SUB-01C will authenticate product callers.
 */
final class Faluss_Subscriptions_Billing {
    const PROVIDER = 'stripe';

    /** @return array<string,mixed>|WP_Error */
    public static function create_checkout( $faluss_id, $period ) {
        $faluss_id = self::faluss_id( $faluss_id );
        $period = is_string( $period ) ? $period : '';
        if ( ! $faluss_id || ! in_array( $period, array( 'monthly', 'annual' ), true ) || ! Faluss_Subscriptions_Schema::is_ready() ) {
            return self::error( 'checkout_invalid' );
        }
        $adapter = Faluss_Subscriptions_Stripe_Sdk::adapter();
        if ( is_wp_error( $adapter ) || ! Faluss_Subscriptions_Stripe_Config::tax_enabled() ) {
            return is_wp_error( $adapter ) ? $adapter : self::error( 'stripe_tax_not_enabled' );
        }
        $price = self::validated_price( $adapter, $period );
        if ( is_wp_error( $price ) ) { return $price; }
        if ( ! self::acquire_lock( $faluss_id ) ) { return self::error( 'checkout_busy' ); }
        try {
            if ( self::has_blocking_subscription( $faluss_id ) ) { return self::error( 'checkout_subscription_exists' ); }
            $trial = Faluss_Subscriptions_Repository::trial_for_faluss_id( $faluss_id );
            if ( is_array( $trial ) && 'eligible' !== (string) ( $trial['trial_state'] ?? '' ) ) { return self::error( 'checkout_trial_already_used' ); }
            $customer = self::ensure_customer( $adapter, $faluss_id );
            if ( is_wp_error( $customer ) ) { return $customer; }
            $opaque_state = self::random_state();
            $idempotency_key = self::random_state();
            if ( '' === $opaque_state || '' === $idempotency_key ) { return self::error( 'checkout_entropy_failed' ); }
            $checkout = Faluss_Subscriptions_Repository::create_checkout( array(
                'faluss_id' => $faluss_id, 'billing_interval' => $period,
                'provider_customer_reference' => $customer['provider_customer_reference'],
                'opaque_state' => $opaque_state, 'idempotency_key' => $idempotency_key,
            ) );
            if ( is_wp_error( $checkout ) ) { return $checkout; }
            $parameters = self::checkout_parameters( $checkout, $faluss_id, $period, $price['id'], $opaque_state );
            $session = $adapter->create_checkout_session( $parameters, $idempotency_key );
            if ( is_wp_error( $session ) || empty( $session['id'] ) || empty( $session['url'] ) || ! self::stripe_url( $session['url'] ) ) {
                return is_wp_error( $session ) ? $session : self::error( 'checkout_session_invalid' );
            }
            $saved = Faluss_Subscriptions_Repository::mark_checkout_provider_session( (int) $checkout['id'], (string) $session['id'], self::timestamp_to_utc( $session['expires_at'] ?? null ) );
            if ( is_wp_error( $saved ) ) { return $saved; }
            Faluss_Subscriptions_Audit::record( 0, 'stripe_checkout_created', $faluss_id, 'billing', array(), array( 'checkout_uuid' => $checkout['checkout_uuid'], 'period' => $period ), null );
            return array( 'url' => (string) $session['url'], 'checkout_uuid' => $checkout['checkout_uuid'], 'status' => 'pending' );
        } finally {
            self::release_lock( $faluss_id );
        }
    }

    /** Retrieve and validate the configured Stripe Price immediately before Checkout. */
    public static function validated_price( $adapter, $period ) {
        $price_id = Faluss_Subscriptions_Stripe_Config::price_id( $period );
        $product_id = Faluss_Subscriptions_Stripe_Config::product_id();
        if ( is_wp_error( $price_id ) || is_wp_error( $product_id ) ) { return is_wp_error( $price_id ) ? $price_id : $product_id; }
        $price = $adapter->retrieve_price( $price_id );
        if ( is_wp_error( $price ) || ! is_array( $price ) ) { return is_wp_error( $price ) ? $price : self::error( 'stripe_price_unavailable' ); }
        $amount = 'monthly' === $period ? 999 : 9900;
        $product = self::id( $price['product'] ?? '' );
        $recurring = is_array( $price['recurring'] ?? null ) ? $price['recurring'] : array();
        $interval = 'monthly' === $period ? 'month' : 'year';
        if ( empty( $price['active'] ) || 'eur' !== strtolower( (string) ( $price['currency'] ?? '' ) ) || $amount !== (int) ( $price['unit_amount'] ?? -1 ) || $interval !== (string) ( $recurring['interval'] ?? '' ) || 1 !== (int) ( $recurring['interval_count'] ?? 1 ) || 'inclusive' !== (string) ( $price['tax_behavior'] ?? '' ) || $product !== $product_id || self::id( $price['id'] ?? '' ) !== $price_id ) {
            return self::error( 'stripe_price_catalogue_mismatch' );
        }
        return $price;
    }

    /** @return array<string,mixed>|WP_Error */
    public static function create_portal( $faluss_id ) {
        $faluss_id = self::faluss_id( $faluss_id );
        $adapter = Faluss_Subscriptions_Stripe_Sdk::adapter();
        $configuration = Faluss_Subscriptions_Stripe_Config::portal_configuration_id();
        if ( ! $faluss_id || is_wp_error( $adapter ) || is_wp_error( $configuration ) ) { return is_wp_error( $adapter ) ? $adapter : self::error( 'portal_unavailable' ); }
        $customer = Faluss_Subscriptions_Repository::customer_for_faluss_id( $faluss_id, self::PROVIDER );
        if ( ! is_array( $customer ) ) { return self::error( 'portal_customer_missing' ); }
        $session = $adapter->create_portal_session( array( 'customer' => $customer['provider_customer_reference'], 'configuration' => $configuration, 'return_url' => self::return_url( 'portal' ) ) );
        if ( is_wp_error( $session ) || empty( $session['url'] ) || ! self::stripe_url( $session['url'] ) ) { return is_wp_error( $session ) ? $session : self::error( 'portal_session_invalid' ); }
        return array( 'url' => (string) $session['url'] );
    }

    /** @return array<string,mixed>|WP_Error */
    public static function request_cancellation( $faluss_id, $subscription_reference, $cancel = true ) {
        $faluss_id = self::faluss_id( $faluss_id );
        $subscription_reference = self::id( $subscription_reference );
        $adapter = Faluss_Subscriptions_Stripe_Sdk::adapter();
        if ( ! $faluss_id || '' === $subscription_reference || is_wp_error( $adapter ) ) { return is_wp_error( $adapter ) ? $adapter : self::error( 'subscription_update_invalid' ); }
        $record = self::subscription_for_reference( $faluss_id, $subscription_reference );
        if ( ! is_array( $record ) ) { return self::error( 'subscription_not_owned' ); }
        $subscription = $adapter->cancel_at_period_end( $subscription_reference, $cancel );
        if ( is_wp_error( $subscription ) ) { return $subscription; }
        return self::apply_stripe_subscription( $subscription, 'customer.subscription.updated' );
    }

    /** @return array<string,mixed>|WP_Error */
    public static function resync( $subscription_reference ) {
        $subscription_reference = self::id( $subscription_reference );
        $adapter = Faluss_Subscriptions_Stripe_Sdk::adapter();
        if ( '' === $subscription_reference || is_wp_error( $adapter ) ) { return is_wp_error( $adapter ) ? $adapter : self::error( 'subscription_resync_invalid' ); }
        $subscription = $adapter->retrieve_subscription( $subscription_reference );
        return is_wp_error( $subscription ) ? $subscription : self::apply_stripe_subscription( $subscription, 'resync' );
    }

    /**
     * Test-only recovery for a locally created Checkout that has no usable local
     * subscription yet. It reads Stripe's current Checkout and Subscription;
     * it never accepts browser data or creates an administrative entitlement.
     *
     * @return array<string,mixed>|WP_Error
     */
    public static function reconcile_checkout( $session_reference ) {
        $session_reference = self::id( $session_reference );
        if ( 'test' !== Faluss_Subscriptions_Stripe_Config::mode() ) { return self::error( 'sandbox_test_only' ); }
        $checkout = Faluss_Subscriptions_Repository::checkout_for_provider_session( $session_reference );
        if ( ! self::valid_local_checkout( $checkout ) ) { return self::error( 'checkout_reconciliation_invalid' ); }
        $adapter = Faluss_Subscriptions_Stripe_Sdk::adapter();
        if ( is_wp_error( $adapter ) ) { return $adapter; }
        $session = $adapter->retrieve_checkout_session( $session_reference );
        if ( ! self::valid_completed_checkout_session( $session, $checkout, $session_reference ) ) { return self::error( 'checkout_reconciliation_incomplete' ); }
        $subscription_reference = self::id( $session['subscription'] ?? '' );
        if ( '' === $subscription_reference ) { return self::error( 'checkout_reconciliation_invalid' ); }
        $subscription = $adapter->retrieve_subscription( $subscription_reference );
        if ( is_wp_error( $subscription ) ) { return $subscription; }
        $record = self::apply_stripe_subscription( $subscription, 'checkout_reconciliation' );
        if ( is_wp_error( $record ) ) { return $record; }
        $linked = Faluss_Subscriptions_Repository::link_checkout_subscription( (string) $checkout['checkout_uuid'], (string) $checkout['faluss_id'], (string) $checkout['provider_customer_reference'], $subscription_reference );
        if ( is_wp_error( $linked ) ) { return $linked; }
        Faluss_Subscriptions_Audit::record( 0, 'stripe_checkout_reconciled', (string) $checkout['faluss_id'], 'billing', array(), array( 'checkout_uuid' => $checkout['checkout_uuid'] ), null );
        return $record;
    }

    /** @return array<string,mixed>|WP_Error */
    public static function apply_stripe_subscription( $subscription, $event_type ) {
        if ( ! is_array( $subscription ) ) { return self::error( 'stripe_subscription_invalid' ); }
        $customer_reference = self::id( $subscription['customer'] ?? '' );
        $customer = Faluss_Subscriptions_Repository::customer_for_reference( $customer_reference, self::PROVIDER );
        $faluss_id = is_array( $customer ) ? self::faluss_id( $customer['faluss_id'] ?? '' ) : '';
        $metadata = is_array( $subscription['metadata'] ?? null ) ? $subscription['metadata'] : array();
        if ( ! $faluss_id || $faluss_id !== self::faluss_id( $metadata['faluss_id'] ?? '' ) ) { return self::error( 'stripe_subscription_identity_mismatch' ); }
        $period = self::subscription_period( $subscription );
        $adapter = Faluss_Subscriptions_Stripe_Sdk::adapter();
        if ( is_wp_error( $adapter ) ) { return $adapter; }
        $price = self::validated_subscription_price( $adapter, $subscription, $period );
        if ( is_wp_error( $price ) ) { return $price; }
        $normalized = self::normalise_state( $subscription );
        $subscription_reference = self::id( $subscription['id'] ?? '' );
        // The Customer, subscription metadata and Price are now server-read
        // and validated. Persist the durable Checkout relation before a trial
        // can become effective, so a mismatched local relation fails closed.
        $linked = self::link_checkout_from_subscription( $subscription, $faluss_id, $customer_reference, $subscription_reference );
        if ( is_wp_error( $linked ) ) { return $linked; }
        if ( is_array( $linked ) && in_array( $linked['session_status'] ?? '', array( 'trial_ineligible_pending', 'trial_ineligible' ), true ) ) {
            return $linked;
        }
        $trial_starts = self::timestamp_to_utc( $subscription['trial_start'] ?? null );
        $trial_ends = self::timestamp_to_utc( $subscription['trial_end'] ?? null );
        if ( 'trialing' === $normalized ) {
            if ( ! self::valid_trial_window( $trial_starts, $trial_ends ) ) {
                return self::refuse_trial( $adapter, $subscription, $faluss_id, 'trial_window_invalid' );
            }
            $proof = self::trial_payment_proof( $adapter, $subscription );
            if ( is_wp_error( $proof ) ) {
                // A Checkout can expose its Customer default payment method just
                // after the first delivery. Fail closed and let a signed retry or
                // reconciliation re-read it; do not cancel a valid Stripe trial.
                return self::refuse_trial( $adapter, $subscription, $faluss_id, $proof->get_error_code(), false );
            }
            $trial = Faluss_Subscriptions_Trials::activate_verified_trial( $faluss_id, self::id( $subscription['id'] ?? '' ), $proof['fingerprint'], $trial_starts );
            if ( is_wp_error( $trial ) ) {
                $reason = 'trial_activation_failed';
                $cancel = false;
                if ( 'trial_payment_method_already_used' === $trial->get_error_code() ) {
                    $reason = 'payment_fingerprint_already_consumed';
                    // A valid local Checkout association makes this a
                    // demonstrated cross-identity collision rather than a
                    // transient Stripe delivery or missing local relation.
                    $cancel = is_array( $linked ) && 'completed' === ( $linked['session_status'] ?? '' );
                } elseif ( 'trial_already_used' === $trial->get_error_code() ) {
                    $reason = 'trial_already_consumed';
                }
                return self::refuse_trial( $adapter, $subscription, $faluss_id, $reason, $cancel, $linked );
            }
        }
        $existing = self::subscription_for_reference( $faluss_id, $subscription_reference );
        // Different signed Stripe event IDs can resolve to the exact same
        // current trial. Keep event-level idempotence in Webhooks, and avoid a
        // second canonical subscription write and notification here as well.
        if ( 'trialing' === $normalized && self::same_trialing_subscription( $existing, $subscription, $customer_reference, $period, $trial_starts, $trial_ends ) ) {
            return $existing;
        }
        $failed_at = in_array( $event_type, array( 'invoice.payment_failed', 'invoice.payment_action_required' ), true ) ? gmdate( 'Y-m-d H:i:s' ) : null;
        $record = Faluss_Subscriptions_Repository::upsert_provider_subscription( array(
            'faluss_id' => $faluss_id, 'provider' => self::PROVIDER, 'provider_customer_reference' => $customer_reference,
            'provider_subscription_reference' => $subscription_reference, 'plan_key' => Faluss_Subscriptions_Catalog::PRO,
            'billing_interval' => $period, 'provider_status' => self::id( $subscription['status'] ?? '' ), 'normalized_state' => $normalized,
            'trial_starts_at' => $trial_starts, 'trial_ends_at' => $trial_ends,
            'period_starts_at' => self::timestamp_to_utc( $subscription['current_period_start'] ?? null ), 'period_ends_at' => self::timestamp_to_utc( $subscription['current_period_end'] ?? null ),
            'grace_started_at' => $failed_at, 'grace_ends_at' => $failed_at ? gmdate( 'Y-m-d H:i:s', strtotime( '+' . Faluss_Subscriptions_Catalog::GRACE_DAYS . ' days', strtotime( $failed_at ) ) ) : null,
            'cancel_at_period_end' => ! empty( $subscription['cancel_at_period_end'] ), 'cancelled_at' => self::timestamp_to_utc( $subscription['canceled_at'] ?? null ), 'ended_at' => self::timestamp_to_utc( $subscription['ended_at'] ?? null ),
        ) );
        if ( is_wp_error( $record ) ) { return $record; }
        Faluss_Subscriptions_Audit::record( 0, 'stripe_subscription_synced', $faluss_id, 'billing', array(), array( 'subscription_uuid' => $record['subscription_uuid'], 'state' => $normalized, 'event_type' => $event_type ), null );
        if ( 'trialing' === $normalized ) { Faluss_Subscriptions_Notifications::queue( $faluss_id, 'trial_started', self::id( $subscription['id'] ?? '' ), gmdate( 'Y-m-d H:i:s' ) ); }
        if ( 'canceling' === $normalized ) { Faluss_Subscriptions_Notifications::queue( $faluss_id, 'cancellation_recorded', self::id( $subscription['id'] ?? '' ), gmdate( 'Y-m-d H:i:s' ) ); }
        if ( 'expired' === $normalized ) { Faluss_Subscriptions_Notifications::queue( $faluss_id, 'rights_ended', self::id( $subscription['id'] ?? '' ), gmdate( 'Y-m-d H:i:s' ) ); }
        if ( in_array( $event_type, array( 'invoice.payment_failed', 'invoice.payment_action_required' ), true ) ) { Faluss_Subscriptions_Notifications::queue( $faluss_id, 'payment_problem', self::id( $subscription['id'] ?? '' ), gmdate( 'Y-m-d H:i:s' ) ); }
        if ( 'invoice.paid' === $event_type ) { Faluss_Subscriptions_Notifications::queue( $faluss_id, 'payment_confirmed', self::id( $subscription['id'] ?? '' ), gmdate( 'Y-m-d H:i:s' ) ); }
        return $record;
    }

    public static function normalise_state( $subscription ) {
        $status = self::id( is_array( $subscription ) ? ( $subscription['status'] ?? '' ) : '' );
        if ( 'trialing' === $status ) { return 'trialing'; }
        if ( 'active' === $status ) { return ! empty( $subscription['cancel_at_period_end'] ) ? 'canceling' : 'active'; }
        if ( 'past_due' === $status ) { return 'past_due'; }
        if ( in_array( $status, array( 'unpaid', 'paused' ), true ) ) { return 'suspended'; }
        return 'expired'; // canceled, incomplete and incomplete_expired never grant Pro.
    }

    private static function ensure_customer( $adapter, $faluss_id ) {
        $existing = Faluss_Subscriptions_Repository::customer_for_faluss_id( $faluss_id, self::PROVIDER );
        if ( is_array( $existing ) ) { return $existing; }
        $created = $adapter->create_customer( $faluss_id );
        if ( is_wp_error( $created ) || empty( $created['id'] ) ) { return is_wp_error( $created ) ? $created : self::error( 'stripe_customer_invalid' ); }
        return Faluss_Subscriptions_Repository::record_customer( $faluss_id, self::PROVIDER, self::id( $created['id'] ), Faluss_Subscriptions_Stripe_Config::mode() );
    }

    private static function checkout_parameters( $checkout, $faluss_id, $period, $price_id, $state ) {
        $base = self::return_url( 'checkout' );
        $token = $state;
        return array(
            'mode' => 'subscription', 'customer' => $checkout['provider_customer_reference'], 'line_items' => array( array( 'price' => $price_id, 'quantity' => 1 ) ),
            'payment_method_types' => array( 'card' ), 'payment_method_collection' => 'always', 'billing_address_collection' => 'required', 'customer_update' => array( 'address' => 'auto' ), 'automatic_tax' => array( 'enabled' => true ),
            'success_url' => add_query_arg( array( 'kind' => 'success', 'state' => $token, 'session_id' => '{CHECKOUT_SESSION_ID}' ), $base ),
            'cancel_url' => add_query_arg( array( 'kind' => 'cancel', 'state' => $token ), $base ),
            'client_reference_id' => $checkout['checkout_uuid'],
            'metadata' => array( 'faluss_billing_session' => $checkout['checkout_uuid'] ),
            'subscription_data' => array( 'trial_period_days' => Faluss_Subscriptions_Catalog::TRIAL_DAYS, 'trial_settings' => array( 'end_behavior' => array( 'missing_payment_method' => 'cancel' ) ), 'metadata' => array( 'faluss_id' => $faluss_id, 'faluss_billing_session' => $checkout['checkout_uuid'], 'billing_interval' => $period ) ),
        );
    }

    private static function has_blocking_subscription( $faluss_id ) {
        foreach ( Faluss_Subscriptions_Repository::subscriptions_for_faluss_id( $faluss_id ) as $subscription ) {
            if ( in_array( $subscription['normalized_state'] ?? '', array( 'trialing', 'active', 'canceling', 'past_due' ), true ) ) { return true; }
        }
        return false;
    }

    private static function subscription_for_reference( $faluss_id, $reference ) { foreach ( Faluss_Subscriptions_Repository::subscriptions_for_faluss_id( $faluss_id ) as $subscription ) { if ( self::PROVIDER === ( $subscription['provider'] ?? '' ) && $reference === ( $subscription['provider_subscription_reference'] ?? '' ) ) { return $subscription; } } return null; }
    /** The subscription was already synchronised from the same current Stripe trial. */
    private static function same_trialing_subscription( $record, $subscription, $customer_reference, $period, $trial_starts, $trial_ends ) {
        return is_array( $record )
            && self::PROVIDER === ( $record['provider'] ?? '' )
            && 'trialing' === ( $record['normalized_state'] ?? '' )
            && $customer_reference === ( $record['provider_customer_reference'] ?? '' )
            && $period === ( $record['billing_interval'] ?? '' )
            && self::id( $subscription['status'] ?? '' ) === ( $record['provider_status'] ?? '' )
            && $trial_starts === ( $record['trial_starts_at'] ?? null )
            && $trial_ends === ( $record['trial_ends_at'] ?? null )
            && self::timestamp_to_utc( $subscription['current_period_start'] ?? null ) === ( $record['period_starts_at'] ?? null )
            && self::timestamp_to_utc( $subscription['current_period_end'] ?? null ) === ( $record['period_ends_at'] ?? null )
            && (int) ! empty( $subscription['cancel_at_period_end'] ) === (int) ( $record['cancel_at_period_end'] ?? 0 );
    }
    private static function subscription_period( $subscription ) { $items = $subscription['items']['data'] ?? array(); $price = is_array( $items ) && ! empty( $items[0]['price'] ) ? $items[0]['price'] : array(); return 'year' === ( $price['recurring']['interval'] ?? '' ) ? 'annual' : 'monthly'; }
    /** Revalidate the configured Price before an event can affect a central right. */
    private static function validated_subscription_price( $adapter, $subscription, $period ) {
        $items = $subscription['items']['data'] ?? array();
        $embedded = is_array( $items ) && ! empty( $items[0]['price'] ) ? Faluss_Subscriptions_Stripe_Adapter::normalise( $items[0]['price'] ) : array();
        $configured = Faluss_Subscriptions_Stripe_Config::price_id( $period );
        if ( is_wp_error( $configured ) || self::id( $embedded['id'] ?? '' ) !== $configured ) { return self::error( 'stripe_subscription_price_mismatch' ); }
        $current = self::validated_price( $adapter, $period );
        return is_wp_error( $current ) ? $current : $embedded;
    }
    /** @return array{fingerprint:string}|WP_Error */
    private static function trial_payment_proof( $adapter, $subscription ) {
        $customer_reference = self::id( $subscription['customer'] ?? '' );
        $payment_method = $subscription['default_payment_method'] ?? '';
        if ( '' === self::payment_method_id( $payment_method ) && ! is_array( $payment_method ) ) {
            $customer = '' !== $customer_reference ? $adapter->retrieve_customer( $customer_reference ) : null;
            if ( is_array( $customer ) ) { $payment_method = $customer['invoice_settings']['default_payment_method'] ?? ''; }
        }
        if ( is_array( $payment_method ) ) {
            $method = $payment_method;
        } else {
            $payment_method_id = self::payment_method_id( $payment_method );
            if ( '' === $payment_method_id ) { return self::error( 'payment_proof_missing' ); }
            $method = $adapter->retrieve_payment_method( $payment_method_id );
        }
        if ( ! is_array( $method ) ) { return self::error( 'payment_proof_missing' ); }
        $method_customer = self::id( $method['customer'] ?? '' );
        if ( '' === $customer_reference || ( '' !== $method_customer && $customer_reference !== $method_customer ) ) { return self::error( 'payment_proof_missing' ); }
        $fingerprint = self::fingerprint( $method['card']['fingerprint'] ?? '' );
        return '' === $fingerprint ? self::error( 'payment_fingerprint_missing' ) : array( 'fingerprint' => $fingerprint );
    }
    private static function link_checkout_from_subscription( $subscription, $faluss_id, $customer_reference, $subscription_reference ) {
        $metadata = is_array( $subscription['metadata'] ?? null ) ? $subscription['metadata'] : array();
        $checkout_uuid = self::uuid( $metadata['faluss_billing_session'] ?? '' );
        if ( '' === $checkout_uuid ) { return null; }
        return Faluss_Subscriptions_Repository::link_checkout_subscription( $checkout_uuid, $faluss_id, $customer_reference, $subscription_reference );
    }
    private static function refuse_trial( $adapter, $subscription, $faluss_id, $reason, $cancel = false, $checkout = null ) {
        $safe_reason = in_array( $reason, array( 'trial_already_consumed', 'payment_proof_missing', 'payment_fingerprint_missing', 'payment_fingerprint_already_consumed', 'trial_window_invalid', 'trial_activation_failed' ), true ) ? $reason : 'trial_activation_failed';
        $record_refusal = true;
        if ( $cancel && 'payment_fingerprint_already_consumed' === $safe_reason && is_array( $checkout ) ) {
            $cancellation = self::cancel_for_trial_ineligibility( $adapter, $subscription, $faluss_id, $safe_reason, $checkout );
            if ( is_wp_error( $cancellation ) ) { return $cancellation; }
            // A worker that lost the durable claim has observed the exact same
            // Checkout/subscription decision. It must neither call Stripe nor
            // duplicate its refusal audit.
            $record_refusal = ! empty( $cancellation['claimed'] );
        }
        if ( $record_refusal ) {
            Faluss_Subscriptions_Audit::record( 0, 'stripe_trial_refused', $faluss_id, 'billing', array(), array( 'reason' => $safe_reason ), $safe_reason );
        }
        return self::error( $safe_reason );
    }
    private static function cancel_for_trial_ineligibility( $adapter, $subscription, $faluss_id, $reason, $checkout ) {
        $subscription_reference = self::id( $subscription['id'] ?? '' );
        $checkout_uuid = (string) ( $checkout['checkout_uuid'] ?? '' );
        $customer_reference = (string) ( $checkout['provider_customer_reference'] ?? '' );
        if ( '' === $subscription_reference || '' === $checkout_uuid || '' === $customer_reference ) { return self::error( 'checkout_trial_ineligible_invalid' ); }

        // Claim before the provider call. The conditional repository update is
        // the only cross-request gate around Stripe's destructive endpoint.
        $claim = Faluss_Subscriptions_Repository::claim_checkout_trial_ineligibility( $checkout_uuid, $faluss_id, $customer_reference, $subscription_reference );
        if ( is_wp_error( $claim ) ) { return $claim; }
        if ( empty( $claim['claimed'] ) ) { return $claim; }

        $result = $adapter->cancel_now( $subscription_reference );
        // A resource_missing/404 from Stripe can only be a recovery outcome for
        // this already-claimed attempt; it is never retried from another local
        // webhook worker.
        $outcome = ! is_wp_error( $result ) ? 'requested' : ( 'stripe_subscription_not_found' === $result->get_error_code() ? 'already_canceled' : 'request_failed' );
        $finalized = Faluss_Subscriptions_Repository::finalize_checkout_trial_ineligibility( $checkout_uuid, $faluss_id, $customer_reference, $subscription_reference );
        if ( is_wp_error( $finalized ) ) { return $finalized; }
        Faluss_Subscriptions_Audit::record( 0, 'stripe_subscription_canceled_for_trial_ineligibility', $faluss_id, 'billing', array(), array( 'reason' => $reason, 'outcome' => $outcome ), $reason );
        return array( 'claimed' => true, 'checkout' => $finalized );
    }
    private static function valid_local_checkout( $checkout ) { return is_array( $checkout ) && 'stripe' === ( $checkout['provider'] ?? '' ) && Faluss_Subscriptions_Catalog::PRO === ( $checkout['plan_key'] ?? '' ) && in_array( $checkout['session_status'] ?? '', array( 'open', 'completed' ), true ) && self::faluss_id( $checkout['faluss_id'] ?? '' ); }
    private static function valid_completed_checkout_session( $session, $checkout, $session_reference ) { return is_array( $session ) && self::id( $session['id'] ?? '' ) === $session_reference && 'complete' === ( $session['status'] ?? '' ) && self::id( $session['customer'] ?? '' ) === ( $checkout['provider_customer_reference'] ?? '' ); }
    private static function payment_method_id( $value ) { return is_array( $value ) ? self::id( $value['id'] ?? '' ) : self::id( $value ); }
    private static function fingerprint( $value ) { return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_-]{8,191}$/', $value ) ? $value : ''; }
    private static function uuid( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $value ) ? strtolower( $value ) : ''; }
    private static function valid_trial_window( $start, $end ) { return $start && $end && 1296000 === strtotime( $end ) - strtotime( $start ); }
    private static function return_url( $kind ) { return add_query_arg( array( 'faluss_subscriptions_return' => sanitize_key( $kind ) ), home_url( '/' ) ); }
    private static function stripe_url( $url ) { $parts = wp_parse_url( (string) $url ); return is_array( $parts ) && 'https' === ( $parts['scheme'] ?? '' ) && isset( $parts['host'] ) && 1 === preg_match( '/(^|\\.)stripe\.com$/', strtolower( $parts['host'] ) ); }
    private static function id( $value ) { if ( is_array( $value ) ) { $value = $value['id'] ?? ''; } return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_]+$/', $value ) ? $value : ''; }
    private static function faluss_id( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $value ) ? strtolower( $value ) : ''; }
    private static function timestamp_to_utc( $value ) { if ( is_numeric( $value ) && (int) $value > 0 ) { return gmdate( 'Y-m-d H:i:s', (int) $value ); } return is_string( $value ) && '' !== $value ? $value : null; }
    private static function random_state() { try { return bin2hex( random_bytes( 32 ) ); } catch ( Exception $exception ) { return ''; } }
    private static function acquire_lock( $faluss_id ) { global $wpdb; return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', 'faluss_sub_billing_' . substr( hash( 'sha256', $faluss_id ), 0, 32 ), 10 ) ); }
    private static function release_lock( $faluss_id ) { global $wpdb; $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'faluss_sub_billing_' . substr( hash( 'sha256', $faluss_id ), 0, 32 ) ) ); }
    private static function error( $code ) { return new WP_Error( $code, __( 'La facturation Faluss Max ne peut pas être traitée.', 'faluss-subscriptions' ) ); }
}
