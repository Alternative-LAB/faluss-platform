<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Transactional notification queue; it never stores or derives an email address. */
final class Faluss_Subscriptions_Notifications {
    const CRON = 'faluss_subscriptions_daily';

    public static function boot() {
        add_action( self::CRON, array( __CLASS__, 'run_daily' ) );
        add_action( 'init', array( __CLASS__, 'schedule' ) );
    }

    public static function schedule() {
        if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
        }
    }

    public static function deactivate() {
        if ( function_exists( 'wp_clear_scheduled_hook' ) ) { wp_clear_scheduled_hook( self::CRON ); }
    }

    /** @return bool */
    public static function queue( $faluss_id, $type, $reference, $scheduled_at ) {
        global $wpdb;
        if ( ! self::faluss_id( $faluss_id ) || ! is_string( $type ) || 1 !== preg_match( '/^[a-z0-9_]{3,64}$/', $type ) || ! is_string( $reference ) || '' === $reference || ! Faluss_Subscriptions_Schema::is_ready() ) { return false; }
        $scheduled_at = self::utc( $scheduled_at ) ?: gmdate( 'Y-m-d H:i:s' );
        $now = gmdate( 'Y-m-d H:i:s' );
        $written = $wpdb->insert( Faluss_Subscriptions_Schema::notifications_table(), array( 'faluss_id' => strtolower( $faluss_id ), 'notification_type' => $type, 'reference_hash' => hash( 'sha256', 'faluss-notification|' . $reference ), 'status' => 'pending', 'scheduled_at' => $scheduled_at, 'sent_at' => null, 'created_at' => $now, 'updated_at' => $now ) );
        return false !== $written || null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Faluss_Subscriptions_Schema::quote_identifier( Faluss_Subscriptions_Schema::notifications_table() ) . ' WHERE faluss_id=%s AND notification_type=%s AND reference_hash=%s', strtolower( $faluss_id ), $type, hash( 'sha256', 'faluss-notification|' . $reference ) ) );
    }

    /** Daily reconciliation and reminders use a server-side lock and remain idempotent. */
    public static function run_daily() {
        if ( ! self::lock() ) { return; }
        try {
            self::queue_due_trial_reminders();
            self::send_due();
            Faluss_Subscriptions_Reconciliation::run();
        } finally {
            self::unlock();
        }
    }

    private static function queue_due_trial_reminders() {
        $now = time();
        foreach ( Faluss_Subscriptions_Repository::all_subscriptions( 250 ) as $subscription ) {
            if ( 'trialing' !== ( $subscription['normalized_state'] ?? '' ) || empty( $subscription['trial_ends_at'] ) ) { continue; }
            $seconds = strtotime( $subscription['trial_ends_at'] . ' UTC' ) - $now;
            foreach ( array( 7, 3, 1 ) as $days ) {
                if ( $seconds <= ( $days * DAY_IN_SECONDS ) && $seconds > ( ( $days - 1 ) * DAY_IN_SECONDS ) ) {
                    self::queue( $subscription['faluss_id'], 'trial_reminder_j' . $days, $subscription['provider_subscription_reference'], gmdate( 'Y-m-d H:i:s' ) );
                }
            }
        }
    }

    private static function send_due() {
        global $wpdb;
        $table = Faluss_Subscriptions_Schema::quote_identifier( Faluss_Subscriptions_Schema::notifications_table() );
        $rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status='pending' AND scheduled_at<=%s ORDER BY id ASC LIMIT 100", gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A );
        foreach ( $rows as $row ) {
            // Identity does not expose email data to this authority. A trusted
            // server integration may provide it transiently; it is never stored.
            $recipient = function_exists( 'apply_filters' ) ? apply_filters( 'faluss_subscriptions_transactional_recipient', '', $row['faluss_id'], $row['notification_type'] ) : '';
            if ( ! is_string( $recipient ) || ! is_email( $recipient ) ) { continue; }
            $subject = __( 'Information Faluss Max', 'faluss-subscriptions' );
            $body = self::message( $row['notification_type'] );
            if ( wp_mail( $recipient, $subject, $body ) ) {
                $wpdb->update( Faluss_Subscriptions_Schema::notifications_table(), array( 'status' => 'sent', 'sent_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $row['id'], 'status' => 'pending' ) );
            }
        }
    }

    private static function message( $type ) {
        $messages = array(
            'trial_started' => __( 'Votre essai Faluss Max a commencé.', 'faluss-subscriptions' ),
            'payment_problem' => __( 'Votre paiement Faluss Max demande une mise à jour.', 'faluss-subscriptions' ),
            'payment_confirmed' => __( 'Votre paiement Faluss Max a été confirmé.', 'faluss-subscriptions' ),
            'cancellation_recorded' => __( 'Votre résiliation Faluss Max prendra effet à la fin de la période en cours.', 'faluss-subscriptions' ),
            'rights_ended' => __( 'Vos droits Faluss Max sont arrivés à échéance.', 'faluss-subscriptions' ),
            'trial_reminder_j7' => __( 'Votre essai Faluss Max se termine dans 7 jours.', 'faluss-subscriptions' ),
            'trial_reminder_j3' => __( 'Votre essai Faluss Max se termine dans 3 jours.', 'faluss-subscriptions' ),
            'trial_reminder_j1' => __( 'Votre essai Faluss Max se termine demain.', 'faluss-subscriptions' ),
        );
        return $messages[ $type ] ?? __( 'Votre situation Faluss Max a changé.', 'faluss-subscriptions' );
    }

    /** A database advisory lock prevents two WP-Cron workers reconciling together. */
    private static function lock() { global $wpdb; return is_object( $wpdb ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', 'faluss_subscriptions_daily_lock', 1 ) ); }
    private static function unlock() { global $wpdb; if ( is_object( $wpdb ) ) { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'faluss_subscriptions_daily_lock' ) ); } }
    private static function faluss_id( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $value ); }
    private static function utc( $value ) { if ( ! is_string( $value ) || '' === trim( $value ) ) { return null; } try { return ( new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ); } catch ( Exception $exception ) { return null; } }
}

/** The daily task reads Stripe only through the same private billing façade. */
final class Faluss_Subscriptions_Reconciliation {
    public static function run() {
        foreach ( Faluss_Subscriptions_Repository::all_subscriptions( 100 ) as $subscription ) {
            if ( self::needs_sync( $subscription ) ) { Faluss_Subscriptions_Billing::resync( $subscription['provider_subscription_reference'] ?? '' ); }
        }
    }
    private static function needs_sync( $subscription ) { return 'stripe' === ( $subscription['provider'] ?? '' ) && ! empty( $subscription['provider_subscription_reference'] ) && ( empty( $subscription['last_synced_at'] ) || strtotime( $subscription['last_synced_at'] . ' UTC' ) < time() - DAY_IN_SECONDS ); }
}
