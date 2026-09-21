<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Internal Checkout-return controller. Browser parameters never grant an entitlement. */
final class Faluss_Subscriptions_Returns {
    public static function boot() { add_action( 'template_redirect', array( __CLASS__, 'render' ), 0 ); }

    public static function render() {
        if ( empty( $_GET['faluss_subscriptions_return'] ) ) { return; }
        nocache_headers();
        $notice = self::checkout_notice();
        if ( current_user_can( Faluss_Subscriptions_Admin::CAPABILITY ) ) {
            Faluss_Subscriptions_Admin_Notices::add( get_current_user_id(), $notice['type'], $notice['code'] );
        }
        // The destination is a fixed internal administration URL. Return
        // parameters, Stripe URLs and member data can never select a redirect.
        wp_safe_redirect( Faluss_Subscriptions_Admin::sandbox_url() );
        exit;
    }

    /** @return array{type:string,code:string} */
    private static function checkout_notice() {
        if ( 'test' !== Faluss_Subscriptions_Stripe_Config::mode() || 'checkout' !== self::request_key( 'faluss_subscriptions_return' ) ) {
            return array( 'type' => 'error', 'code' => 'checkout_return_invalid' );
        }
        $state = self::request_state();
        if ( '' === $state ) {
            return array( 'type' => 'error', 'code' => 'checkout_return_invalid' );
        }
        $checkout = Faluss_Subscriptions_Repository::checkout_for_state( $state );
        if ( ! self::valid_checkout( $checkout ) ) {
            return array( 'type' => 'error', 'code' => 'checkout_return_invalid' );
        }
        if ( self::expired( $checkout ) ) {
            return array( 'type' => 'error', 'code' => 'checkout_return_expired' );
        }
        $kind = self::request_key( 'kind' );
        if ( 'cancel' === $kind ) {
            return array( 'type' => 'warning', 'code' => 'checkout_return_cancelled' );
        }
        if ( 'success' !== $kind || ! self::matches_provider_session( $checkout, self::request_session_id() ) ) {
            return array( 'type' => 'error', 'code' => 'checkout_return_session_invalid' );
        }
        // This is a presentation-only acknowledgement. Webhooks alone resolve
        // trial, subscription and entitlement state.
        if ( self::has_canonical_trialing_subscription( $checkout ) ) {
            return array( 'type' => 'success', 'code' => 'checkout_return_trialing' );
        }
        return array( 'type' => 'success', 'code' => 'checkout_return_completed' );
    }

    private static function request_key( $key ) {
        return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_key( wp_unslash( $_GET[ $key ] ) ) : '';
    }

    private static function request_state() {
        $state = isset( $_GET['state'] ) && is_string( $_GET['state'] ) ? wp_unslash( $_GET['state'] ) : '';
        return is_string( $state ) && 64 === strlen( $state ) && ctype_xdigit( $state ) ? $state : '';
    }

    private static function request_session_id() {
        $session_id = isset( $_GET['session_id'] ) && is_string( $_GET['session_id'] ) ? wp_unslash( $_GET['session_id'] ) : '';
        return is_string( $session_id ) && 1 === preg_match( '/^cs_[A-Za-z0-9_]+$/', $session_id ) ? $session_id : '';
    }

    private static function valid_checkout( $checkout ) {
        // Stripe's signed webhook can complete and link this exact local
        // Checkout before the browser returns. completed is therefore a valid
        // state to acknowledge, not evidence supplied by the browser.
        return is_array( $checkout ) && 'stripe' === ( $checkout['provider'] ?? '' ) && Faluss_Subscriptions_Catalog::PRO === ( $checkout['plan_key'] ?? '' ) && in_array( $checkout['session_status'] ?? '', array( 'open', 'completed', 'trial_ineligible_pending', 'trial_ineligible' ), true );
    }

    private static function expired( $checkout ) {
        $expires_at = is_array( $checkout ) && is_string( $checkout['expires_at'] ?? null ) ? $checkout['expires_at'] : '';
        $timestamp = '' === $expires_at ? false : strtotime( $expires_at . ' UTC' );
        return false === $timestamp || $timestamp < time();
    }

    private static function matches_provider_session( $checkout, $session_id ) {
        $provider_session = is_array( $checkout ) && is_string( $checkout['provider_session_reference'] ?? null ) ? $checkout['provider_session_reference'] : '';
        return '' !== $provider_session && '' !== $session_id && hash_equals( $provider_session, $session_id );
    }

    /**
     * Reads an already-synchronised local decision only. It never calls
     * Stripe, writes a subscription/trial/audit, or treats the browser return
     * as proof. The Checkout link prevents an unrelated member subscription
     * from changing this acknowledgement.
     */
    private static function has_canonical_trialing_subscription( $checkout ) {
        if ( ! is_array( $checkout ) ) { return false; }
        $faluss_id = $checkout['faluss_id'] ?? '';
        $reference = $checkout['provider_subscription_reference'] ?? '';
        if ( ! is_string( $faluss_id ) || ! is_string( $reference ) || '' === $reference ) { return false; }
        $linked_trialing = false;
        foreach ( Faluss_Subscriptions_Repository::subscriptions_for_faluss_id( $faluss_id ) as $subscription ) {
            if ( is_array( $subscription ) && 'stripe' === ( $subscription['provider'] ?? '' ) && $reference === ( $subscription['provider_subscription_reference'] ?? '' ) && 'trialing' === ( $subscription['normalized_state'] ?? '' ) ) {
                $linked_trialing = true;
                break;
            }
        }
        if ( ! $linked_trialing ) { return false; }
        $decision = Faluss_Subscriptions_Resolver::resolve_for_faluss_id( $faluss_id );
        return 'pro' === ( $decision['level'] ?? '' ) && 'trialing' === ( $decision['state'] ?? '' ) && 'subscription_trialing' === ( $decision['reason'] ?? '' );
    }
}
