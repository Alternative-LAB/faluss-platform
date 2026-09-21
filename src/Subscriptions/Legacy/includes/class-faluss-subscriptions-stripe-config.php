<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Reads only server configuration. Stripe credentials are deliberately never
 * copied to options, HTML, URLs, JavaScript, logs or audit records.
 */
final class Faluss_Subscriptions_Stripe_Config {
    const API_VERSION = '2025-03-31.basil';
    const MODE_TEST = 'test';
    const MODE_LIVE = 'live';

    public static function mode() {
        $mode = defined( 'FALUSS_STRIPE_MODE' ) ? (string) FALUSS_STRIPE_MODE : self::MODE_TEST;
        return in_array( $mode, array( self::MODE_TEST, self::MODE_LIVE ), true ) ? $mode : self::MODE_TEST;
    }

    public static function is_live_allowed() {
        return self::MODE_LIVE === self::mode() && defined( 'FALUSS_STRIPE_LIVE_ENABLED' ) && true === FALUSS_STRIPE_LIVE_ENABLED;
    }

    /** @return string|WP_Error */
    public static function secret_key() {
        $mode = self::mode();
        if ( self::MODE_LIVE === $mode && ! self::is_live_allowed() ) {
            return self::error( 'stripe_live_disabled' );
        }
        $constant = self::MODE_LIVE === $mode ? 'FALUSS_STRIPE_LIVE_SECRET_KEY' : 'FALUSS_STRIPE_TEST_SECRET_KEY';
        $key = self::constant_string( $constant );
        if ( '' === $key || 1 !== preg_match( '/^sk_' . $mode . '_[A-Za-z0-9]+$/', $key ) ) {
            return self::error( 'stripe_secret_key_invalid' );
        }
        return $key;
    }

    /** @return string|WP_Error */
    public static function webhook_secret() {
        $mode = self::mode();
        if ( self::MODE_LIVE === $mode && ! self::is_live_allowed() ) {
            return self::error( 'stripe_live_disabled' );
        }
        $constant = self::MODE_LIVE === $mode ? 'FALUSS_STRIPE_LIVE_WEBHOOK_SECRET' : 'FALUSS_STRIPE_TEST_WEBHOOK_SECRET';
        $secret = self::constant_string( $constant );
        return 1 === preg_match( '/^whsec_[A-Za-z0-9]+$/', $secret ) ? $secret : self::error( 'stripe_webhook_secret_invalid' );
    }

    /** @return string|WP_Error */
    public static function price_id( $period ) {
        $period = is_string( $period ) ? $period : '';
        if ( ! in_array( $period, array( 'monthly', 'annual' ), true ) ) {
            return self::error( 'stripe_period_invalid' );
        }
        $mode = self::mode();
        if ( self::MODE_LIVE === $mode && ! self::is_live_allowed() ) {
            return self::error( 'stripe_live_disabled' );
        }
        $constant = 'FALUSS_STRIPE_' . strtoupper( $mode ) . '_PRICE_PRO_' . strtoupper( $period );
        $price = self::constant_string( $constant );
        return 1 === preg_match( '/^price_[A-Za-z0-9]+$/', $price ) ? $price : self::error( 'stripe_price_not_configured' );
    }

    /** @return string|WP_Error */
    public static function product_id() {
        $mode = self::mode();
        if ( self::MODE_LIVE === $mode && ! self::is_live_allowed() ) {
            return self::error( 'stripe_live_disabled' );
        }
        $product = self::constant_string( 'FALUSS_STRIPE_' . strtoupper( $mode ) . '_PRO_PRODUCT_ID' );
        return 1 === preg_match( '/^prod_[A-Za-z0-9]+$/', $product ) ? $product : self::error( 'stripe_product_not_configured' );
    }

    /** @return string|WP_Error */
    public static function portal_configuration_id() {
        $mode = self::mode();
        if ( self::MODE_LIVE === $mode && ! self::is_live_allowed() ) {
            return self::error( 'stripe_live_disabled' );
        }
        $configuration = self::constant_string( 'FALUSS_STRIPE_' . strtoupper( $mode ) . '_PORTAL_CONFIGURATION_ID' );
        return 1 === preg_match( '/^bpc_[A-Za-z0-9]+$/', $configuration ) ? $configuration : self::error( 'stripe_portal_not_configured' );
    }

    /** Stripe Tax must be explicitly enabled; absence fails closed before Checkout. */
    public static function tax_enabled() {
        return defined( 'FALUSS_STRIPE_TAX_ENABLED' ) && true === FALUSS_STRIPE_TAX_ENABLED;
    }

    /** A non-secret Stripe Dashboard URL limited to known local references. */
    public static function dashboard_url( $resource, $reference ) {
        $resources = array( 'customers', 'subscriptions' );
        $resource = is_string( $resource ) ? $resource : '';
        $reference = is_string( $reference ) ? $reference : '';
        if ( ! in_array( $resource, $resources, true ) || 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $reference ) ) {
            return '';
        }
        return 'https://dashboard.stripe.com' . ( self::MODE_TEST === self::mode() ? '/test' : '' ) . '/' . $resource . '/' . rawurlencode( $reference );
    }

    /**
     * Safe, non-secret readiness projection for diagnostics and administration.
     * @return array<string,mixed>
     */
    public static function diagnostics() {
        $key = self::secret_key();
        $webhook = self::webhook_secret();
        $monthly = self::price_id( 'monthly' );
        $annual = self::price_id( 'annual' );
        $product = self::product_id();
        $portal = self::portal_configuration_id();
        return array(
            'mode' => self::mode(),
            'live_allowed' => self::is_live_allowed(),
            'api_version' => self::API_VERSION,
            'secret_configured' => ! is_wp_error( $key ),
            'webhook_configured' => ! is_wp_error( $webhook ),
            'monthly_price_configured' => ! is_wp_error( $monthly ),
            'annual_price_configured' => ! is_wp_error( $annual ),
            'product_configured' => ! is_wp_error( $product ),
            'portal_configured' => ! is_wp_error( $portal ),
            'tax_enabled' => self::tax_enabled(),
        );
    }

    /** @return WP_Error */
    private static function error( $code ) {
        return new WP_Error( $code, __( 'La configuration Stripe n’est pas disponible.', 'faluss-subscriptions' ) );
    }

    private static function constant_string( $name ) {
        return defined( $name ) && is_string( constant( $name ) ) ? trim( constant( $name ) ) : '';
    }
}
