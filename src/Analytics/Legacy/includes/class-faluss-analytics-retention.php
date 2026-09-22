<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Bounded UTC retention for Analytics receipts and daily aggregates. */
final class Faluss_Analytics_Retention {
    const HOOK = 'faluss_analytics_run_retention';
    const BATCH_SIZE = 50;

    public static function boot() {
        if ( ! function_exists( 'add_action' ) ) { return; }
        add_action( 'plugins_loaded', array( __CLASS__, 'schedule' ), 70 );
        add_action( self::HOOK, array( __CLASS__, 'run' ) );
    }

    public static function activate() { self::schedule(); }

    public static function deactivate() {
        if ( function_exists( 'wp_clear_scheduled_hook' ) ) { wp_clear_scheduled_hook( self::HOOK ); }
    }

    public static function schedule() {
        if ( ! class_exists( 'Faluss_Analytics' ) || ! Faluss_Analytics::is_local_hub() || ! Faluss_Analytics_Schema::is_ready() || ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) { return; }
        if ( false === wp_next_scheduled( self::HOOK ) ) { wp_schedule_event( time() + 300, 'hourly', self::HOOK ); }
    }

    public static function run() {
        global $wpdb;
        if ( ! class_exists( 'Faluss_Analytics' ) || ! Faluss_Analytics::is_local_hub() || ! Faluss_Analytics_Schema::is_ready() ) { return false; }
        $lock = 'faluss_analytics_retention_' . substr( hash( 'sha256', (string) $wpdb->prefix ), 0, 18 );
        $acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $lock, 0 ) );
        if ( ( 1 !== $acquired && '1' !== $acquired ) || self::database_has_error() ) { return false; }
        $result = false;
        try {
            $result = self::purge_transaction();
        } catch ( Throwable $throwable ) {
            self::rollback();
            $result = false;
        }
        $released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        return $result && ( 1 === $released || '1' === $released ) && ! self::database_has_error();
    }

    private static function purge_transaction() {
        global $wpdb;
        if ( ! self::start_transaction() ) { return false; }
        $now = gmdate( 'Y-m-d H:i:s' );
        $cutoff = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->sub( new DateInterval( 'P25M' ) )->format( 'Y-m-d' );
        $batches = array(
            array( Faluss_Analytics_Schema::receipts_table(), $wpdb->prepare( 'SELECT id FROM ' . Faluss_Analytics_Schema::quote_identifier( Faluss_Analytics_Schema::receipts_table() ) . ' WHERE expires_at <= %s ORDER BY id ASC LIMIT %d FOR UPDATE', $now, self::BATCH_SIZE ) ),
            array( Faluss_Analytics_Schema::daily_metrics_table(), $wpdb->prepare( 'SELECT id FROM ' . Faluss_Analytics_Schema::quote_identifier( Faluss_Analytics_Schema::daily_metrics_table() ) . ' WHERE aggregate_date < %s ORDER BY id ASC LIMIT %d FOR UPDATE', $cutoff, self::BATCH_SIZE ) ),
            array( Faluss_Analytics_Schema::daily_objects_table(), $wpdb->prepare( 'SELECT id FROM ' . Faluss_Analytics_Schema::quote_identifier( Faluss_Analytics_Schema::daily_objects_table() ) . ' WHERE aggregate_date < %s ORDER BY id ASC LIMIT %d FOR UPDATE', $cutoff, self::BATCH_SIZE ) ),
        );
        foreach ( $batches as $batch ) {
            $rows = $wpdb->get_results( $batch[1], ARRAY_A );
            if ( self::database_has_error() || ! is_array( $rows ) || count( $rows ) > self::BATCH_SIZE ) { self::rollback(); return false; }
            foreach ( $rows as $row ) {
                if ( ! isset( $row['id'] ) || ! ctype_digit( (string) $row['id'] ) || 1 !== $wpdb->delete( $batch[0], array( 'id' => (int) $row['id'] ), array( '%d' ) ) || self::database_has_error() ) {
                    self::rollback();
                    return false;
                }
            }
        }
        if ( ! self::commit() ) { self::rollback(); return false; }
        return true;
    }

    private static function start_transaction() { global $wpdb; return false !== $wpdb->query( 'START TRANSACTION' ) && ! self::database_has_error(); }
    private static function commit() { global $wpdb; return false !== $wpdb->query( 'COMMIT' ) && ! self::database_has_error(); }
    private static function rollback() { global $wpdb; return false !== $wpdb->query( 'ROLLBACK' ) && ! self::database_has_error(); }
    private static function database_has_error() { global $wpdb; return ! isset( $wpdb->last_error ) || ! is_string( $wpdb->last_error ) || '' !== $wpdb->last_error; }
}
