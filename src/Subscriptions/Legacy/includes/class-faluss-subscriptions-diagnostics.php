<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Read-only operational diagnostics, deliberately free of payment details or member PII. */
final class Faluss_Subscriptions_Diagnostics {
    /** @return array<string,mixed> */
    public static function status() {
        global $wpdb;
        $counts = array();
        foreach ( array( 'subscriptions' => Faluss_Subscriptions_Schema::subscriptions_table(), 'trials' => Faluss_Subscriptions_Schema::trials_table(), 'entitlements' => Faluss_Subscriptions_Schema::entitlements_table(), 'events' => Faluss_Subscriptions_Schema::events_table(), 'audit' => Faluss_Subscriptions_Schema::audit_table(), 'customers' => Faluss_Subscriptions_Schema::customers_table(), 'checkout_sessions' => Faluss_Subscriptions_Schema::checkout_sessions_table(), 'notifications' => Faluss_Subscriptions_Schema::notifications_table() ) as $key => $table ) {
            $counts[ $key ] = Faluss_Subscriptions_Schema::is_ready() ? max( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Faluss_Subscriptions_Schema::quote_identifier( $table ) ) ) : null;
        }
        return array(
            'schema' => Faluss_Subscriptions_Schema::get_status(),
            'catalogue_version' => Faluss_Subscriptions_Catalog::VERSION,
            'catalogue' => Faluss_Subscriptions_Catalog::plans(),
            'counts' => $counts,
            'transactions_available' => self::transactions_available(),
            'stripe' => Faluss_Subscriptions_Stripe_Config::diagnostics(),
        );
    }

    /** A rollback-only probe confirms that the current connection can protect an admin mutation. */
    private static function transactions_available() {
        global $wpdb;
        if ( ! Faluss_Subscriptions_Schema::is_ready() ) { return false; }
        if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return false; }
        return false !== $wpdb->query( 'ROLLBACK' );
    }
}
