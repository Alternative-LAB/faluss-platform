<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Bounded transactional event purge with minimal thirty-day receipts. */
final class Faluss_Events_Retention {
    const HOOK = 'faluss_events_run_retention';
    const CRON_SCHEDULE = 'faluss_events_every_minute';
    const BATCH_SIZE = 50;
    const TOMBSTONE_SECONDS = 2592000;

    public static function boot() {
        if ( ! function_exists( 'add_action' ) ) {
            return;
        }
        add_action( 'plugins_loaded', array( __CLASS__, 'schedule' ), 20 );
        add_action( self::HOOK, array( __CLASS__, 'run' ) );
    }

    public static function activate() { self::schedule(); }

    public static function deactivate() {
        if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
            wp_clear_scheduled_hook( self::HOOK );
        }
    }

    public static function schedule() {
        if ( ! Faluss_Events_Schema::is_ready() || ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
            return;
        }
        if ( false === wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + 60, self::CRON_SCHEDULE, self::HOOK );
        }
    }

    /** Cron entrypoint. No callback, transport or business provider is invoked. */
    public static function run() {
        global $wpdb;
        if ( ! Faluss_Events_Schema::is_ready() ) {
            return false;
        }
        $lock = 'faluss_evt_retention_' . substr( hash( 'sha256', (string) $wpdb->prefix ), 0, 40 );
        if ( ! self::acquire_lock( $lock ) ) {
            return false;
        }
        $result = false;
        try {
            $now = gmdate( 'Y-m-d H:i:s' );
            $result = self::purge_expired_events( $now ) && self::purge_expired_tombstones( $now );
        } catch ( Throwable $throwable ) {
            self::rollback();
            $result = false;
        }
        return self::release_lock( $lock ) && $result;
    }

    /** Shared with workers so an event cannot be purged between expiry reread and external execution. */
    public static function acquire_event_lock( $event_id ) {
        $lock = self::event_lock_name( $event_id );
        return is_string( $lock ) && self::acquire_lock( $lock ) ? $lock : false;
    }

    public static function release_event_lock( $lock ) {
        return is_string( $lock ) && 1 === preg_match( '/^faluss_evt_event_id_[a-f0-9]{40}$/D', $lock ) && self::release_lock( $lock );
    }

    private static function purge_expired_events( $now ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT event_id FROM ' . Faluss_Events_Schema::quote_identifier( Faluss_Events_Schema::events_table() ) . ' WHERE retention_until <= %s ORDER BY id ASC LIMIT %d', $now, self::BATCH_SIZE ), ARRAY_A );
        if ( self::database_has_error() || ! is_array( $rows ) || count( $rows ) > self::BATCH_SIZE ) {
            return false;
        }
        foreach ( $rows as $row ) {
            $event_id = $row['event_id'] ?? null;
            $lock = self::acquire_event_lock( $event_id );
            if ( false === $lock ) {
                return false;
            }
            $purged = false;
            try {
                $purged = self::purge_event_transaction( $event_id, $now );
            } catch ( Throwable $throwable ) {
                self::rollback();
            }
            $released = self::release_event_lock( $lock );
            if ( ! $released || ! $purged ) {
                return false;
            }
        }
        return true;
    }

    private static function purge_event_transaction( $event_id, $now ) {
        global $wpdb;
        if ( ! self::is_uuid( $event_id ) || ! self::start_transaction() ) {
            return false;
        }
        $event = $wpdb->get_row( $wpdb->prepare( 'SELECT event_id,source_identity_sha256,event_sha256,retention_until FROM ' . Faluss_Events_Schema::quote_identifier( Faluss_Events_Schema::events_table() ) . ' WHERE event_id = %s FOR UPDATE', $event_id ), ARRAY_A );
        if ( self::database_has_error() || ! self::valid_event_row( $event, $event_id ) || $event['retention_until'] > $now ) {
            self::rollback();
            return false;
        }
        $tombstones = $wpdb->get_results( $wpdb->prepare( 'SELECT tombstone_uuid,event_id,source_identity_sha256,event_sha256,purged_at,expires_at,created_at FROM ' . Faluss_Events_Schema::quote_identifier( Faluss_Events_Schema::tombstones_table() ) . ' WHERE event_id = %s OR source_identity_sha256 = %s FOR UPDATE', $event_id, $event['source_identity_sha256'] ), ARRAY_A );
        if ( self::database_has_error() || ! is_array( $tombstones ) || count( $tombstones ) > 1 ) {
            self::rollback();
            return false;
        }
        if ( empty( $tombstones ) ) {
            $uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : '';
            $purged_timestamp = strtotime( $now . ' UTC' );
            $expires_at = false === $purged_timestamp ? false : gmdate( 'Y-m-d H:i:s', $purged_timestamp + self::TOMBSTONE_SECONDS );
            if ( ! self::is_uuid( $uuid ) || false === $expires_at || 1 !== $wpdb->insert( Faluss_Events_Schema::tombstones_table(), array( 'tombstone_uuid' => $uuid, 'event_id' => $event_id, 'source_identity_sha256' => $event['source_identity_sha256'], 'event_sha256' => $event['event_sha256'], 'purged_at' => $now, 'expires_at' => $expires_at, 'created_at' => $now ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) ) || self::database_has_error() ) {
                self::rollback();
                return false;
            }
        } elseif ( ! self::matching_tombstone( $tombstones[0], $event ) ) {
            self::rollback();
            return false;
        }
        foreach ( array( Faluss_Events_Schema::outbox_table(), Faluss_Events_Schema::inbox_table(), Faluss_Events_Schema::consumer_deliveries_table() ) as $table ) {
            if ( false === $wpdb->delete( $table, array( 'event_id' => $event_id ), array( '%s' ) ) || self::database_has_error() ) {
                self::rollback();
                return false;
            }
        }
        if ( 1 !== $wpdb->delete( Faluss_Events_Schema::events_table(), array( 'event_id' => $event_id ), array( '%s' ) ) || self::database_has_error() || ! self::commit() ) {
            self::rollback();
            return false;
        }
        return true;
    }

    /** Tombstone expiry is deliberately separate from event purge. */
    private static function purge_expired_tombstones( $now ) {
        global $wpdb;
        if ( ! self::start_transaction() ) {
            return false;
        }
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id FROM ' . Faluss_Events_Schema::quote_identifier( Faluss_Events_Schema::tombstones_table() ) . ' WHERE expires_at <= %s ORDER BY id ASC LIMIT %d FOR UPDATE', $now, self::BATCH_SIZE ), ARRAY_A );
        if ( self::database_has_error() || ! is_array( $rows ) || count( $rows ) > self::BATCH_SIZE ) {
            self::rollback();
            return false;
        }
        foreach ( $rows as $row ) {
            if ( ! isset( $row['id'] ) || ! is_numeric( $row['id'] ) || (int) $row['id'] < 1 || 1 !== $wpdb->delete( Faluss_Events_Schema::tombstones_table(), array( 'id' => (int) $row['id'] ), array( '%d' ) ) || self::database_has_error() ) {
                self::rollback();
                return false;
            }
        }
        if ( ! self::commit() ) {
            self::rollback();
            return false;
        }
        return true;
    }

    private static function valid_event_row( $row, $event_id ) {
        return is_array( $row ) && $event_id === ( $row['event_id'] ?? null ) && self::is_hash( $row['source_identity_sha256'] ?? null ) && self::is_hash( $row['event_sha256'] ?? null ) && self::is_sql_utc( $row['retention_until'] ?? null );
    }

    private static function matching_tombstone( $row, $event ) {
        return is_array( $row ) && self::is_uuid( $row['tombstone_uuid'] ?? null ) && ( $row['event_id'] ?? null ) === $event['event_id'] && ( $row['source_identity_sha256'] ?? null ) === $event['source_identity_sha256'] && ( $row['event_sha256'] ?? null ) === $event['event_sha256'] && self::is_sql_utc( $row['purged_at'] ?? null ) && self::is_sql_utc( $row['expires_at'] ?? null ) && self::is_sql_utc( $row['created_at'] ?? null ) && $row['created_at'] === $row['purged_at'] && self::TOMBSTONE_SECONDS === strtotime( $row['expires_at'] . ' UTC' ) - strtotime( $row['purged_at'] . ' UTC' );
    }

    private static function event_lock_name( $event_id ) {
        return self::is_uuid( $event_id ) ? 'faluss_evt_event_id_' . substr( hash( 'sha256', $event_id ), 0, 40 ) : false;
    }

    private static function acquire_lock( $lock ) {
        global $wpdb;
        try {
            $result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $lock, 0 ) );
        } catch ( Throwable $throwable ) {
            return false;
        }
        return ( 1 === $result || '1' === $result ) && ! self::database_has_error();
    }

    private static function release_lock( $lock ) {
        global $wpdb;
        try {
            $result = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        } catch ( Throwable $throwable ) {
            return false;
        }
        return ( 1 === $result || '1' === $result ) && ! self::database_has_error();
    }

    private static function is_uuid( $value ) { return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value ); }
    private static function is_hash( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value ); }
    private static function is_sql_utc( $value ) { return is_string( $value ) && 1 === preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value ) && false !== strtotime( $value . ' UTC' ); }
    private static function start_transaction() { global $wpdb; return false !== $wpdb->query( 'START TRANSACTION' ) && ! self::database_has_error(); }
    private static function commit() { global $wpdb; return false !== $wpdb->query( 'COMMIT' ) && ! self::database_has_error(); }
    private static function rollback() { global $wpdb; return false !== $wpdb->query( 'ROLLBACK' ) && ! self::database_has_error(); }
    private static function database_has_error() { global $wpdb; return ! isset( $wpdb->last_error ) || ! is_string( $wpdb->last_error ) || '' !== $wpdb->last_error; }
}
