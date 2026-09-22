<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Bounded leased delivery workers. Network and callbacks always run after commit. */
final class Faluss_Events_Workers {
    const OUTBOX_HOOK = 'faluss_events_run_outbox';
    const CONSUMER_HOOK = 'faluss_events_run_consumers';
    const CRON_SCHEDULE = 'faluss_events_every_minute';
    const BATCH_SIZE = 50;
    const MAX_ATTEMPTS = 8;
    const LEASE_SECONDS = 300;
    private const RETRY_DELAYS = array( 60, 300, 900, 3600, 10800, 21600, 43200 );
    private const OUTBOX_CODES = array( 'accepted', 'existing', 'transport', 'not_authorized', 'incompatible', 'not_available', 'temporary', 'invalid_response', 'local', 'expired' );
    private const CONSUMER_CODES = array( 'processed', 'retryable', 'permanent', 'invalid_result', 'event_unavailable', 'consumer_unavailable', 'expired' );

    public static function boot() {
        if ( ! function_exists( 'add_action' ) ) { return; }
        if ( function_exists( 'add_filter' ) ) {
            add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
        }
        add_action( 'plugins_loaded', array( __CLASS__, 'schedule' ), 20 );
        add_action( self::OUTBOX_HOOK, array( __CLASS__, 'run_outbox' ) );
        add_action( self::CONSUMER_HOOK, array( __CLASS__, 'run_consumers' ) );
    }

    public static function cron_schedules( $schedules ) {
        $schedules[ self::CRON_SCHEDULE ] = array( 'interval' => 60, 'display' => 'Faluss Events every minute' );
        return $schedules;
    }

    public static function activate() { self::schedule(); }

    public static function deactivate() {
        if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
            wp_clear_scheduled_hook( self::OUTBOX_HOOK );
            wp_clear_scheduled_hook( self::CONSUMER_HOOK );
        }
    }

    public static function schedule() {
        if ( ! Faluss_Events_Schema::is_ready() || ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) { return; }
        foreach ( array( self::OUTBOX_HOOK, self::CONSUMER_HOOK ) as $hook ) {
            if ( false === wp_next_scheduled( $hook ) ) {
                wp_schedule_event( time() + 60, self::CRON_SCHEDULE, $hook );
            }
        }
    }

    public static function run_outbox() {
        if ( ! Faluss_Events_Engine::route_registry_ready() || ! Faluss_Events_Schema::is_ready() ) { return; }
        self::with_worker_lock( 'outbox', function () {
            $rows = self::claim_outbox();
            foreach ( $rows as $row ) { self::process_outbox( $row ); }
        } );
    }

    public static function run_consumers() {
        if ( ! Faluss_Events_Engine::consumer_registry_ready() || ! Faluss_Events_Schema::is_ready() ) { return; }
        self::with_worker_lock( 'consumer', function () {
            $rows = self::claim_consumers();
            foreach ( $rows as $row ) { self::process_consumer( $row ); }
        } );
    }

    private static function claim_outbox() {
        global $wpdb;
        $now = gmdate( 'Y-m-d H:i:s' );
        $sql = $wpdb->prepare( 'SELECT * FROM ' . Faluss_Events_Schema::quote_identifier( Faluss_Events_Schema::outbox_table() ) . ' WHERE ((status IN (%s,%s) AND next_attempt_at <= %s) OR (status = %s AND lease_expires_at IS NOT NULL AND lease_expires_at <= %s)) ORDER BY id ASC LIMIT %d FOR UPDATE', 'pending', 'retry', $now, 'leased', $now, self::BATCH_SIZE );
        return self::claim_rows( $sql, Faluss_Events_Schema::outbox_table(), 'temporary' );
    }

    private static function claim_consumers() {
        global $wpdb;
        $now = gmdate( 'Y-m-d H:i:s' );
        $sql = $wpdb->prepare( 'SELECT * FROM ' . Faluss_Events_Schema::quote_identifier( Faluss_Events_Schema::consumer_deliveries_table() ) . ' WHERE (status = %s OR (status = %s AND lease_expires_at IS NOT NULL AND lease_expires_at <= %s) OR (status = %s AND lease_expires_at IS NOT NULL AND lease_expires_at <= %s)) ORDER BY id ASC LIMIT %d FOR UPDATE', 'pending', 'retry', $now, 'leased', $now, self::BATCH_SIZE );
        return self::claim_rows( $sql, Faluss_Events_Schema::consumer_deliveries_table(), 'invalid_result' );
    }

    private static function claim_rows( $sql, $table, $exhausted_code ) {
        global $wpdb;
        if ( ! self::start_transaction() ) { return array(); }
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        if ( self::database_has_error() || ! is_array( $rows ) || count( $rows ) > self::BATCH_SIZE ) { self::rollback(); return array(); }
        $claimed = array();
        foreach ( $rows as $row ) {
            $previous_attempt = (int) ( $row['attempt_count'] ?? 0 );
            if ( $previous_attempt >= self::MAX_ATTEMPTS ) {
                $where = array( 'id' => (int) $row['id'], 'status' => $row['status'] );
                $where_formats = array( '%d', '%s' );
                if ( 'leased' === $row['status'] ) {
                    $where['lease_token'] = $row['lease_token'];
                    $where_formats[] = '%s';
                }
                $written = $wpdb->update( $table, array( 'status' => 'dead_letter', 'lease_token' => null, 'lease_expires_at' => null, 'last_result_code' => $exhausted_code ), $where, array( '%s', '%s', '%s', '%s' ), $where_formats );
                if ( 1 !== $written || self::database_has_error() ) { self::rollback(); return array(); }
                continue;
            }
            $lease = self::lease_token();
            if ( ! is_string( $lease ) ) { self::rollback(); return array(); }
            $attempt = $previous_attempt + 1;
            $written = $wpdb->update( $table, array( 'status' => 'leased', 'attempt_count' => $attempt, 'lease_token' => $lease, 'lease_expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::LEASE_SECONDS ) ), array( 'id' => (int) $row['id'] ), array( '%s', '%d', '%s', '%s' ), array( '%d' ) );
            if ( 1 !== $written || self::database_has_error() ) { self::rollback(); return array(); }
            $row['status'] = 'leased'; $row['attempt_count'] = $attempt; $row['lease_token'] = $lease;
            $claimed[] = $row;
        }
        if ( ! self::commit() ) {
            self::rollback();
            return array();
        }

        return $claimed;
    }

    private static function process_outbox( $row ) {
        $lock = Faluss_Events_Retention::acquire_event_lock( $row['event_id'] ?? '' );
        if ( false === $lock ) { self::finish_outbox( $row, false, 'temporary' ); return; }
        try {
            $record = Faluss_Events_Engine::event_record_by_id( $row['event_id'] ?? '' );
            if ( is_wp_error( $record ) ) { self::finish_outbox( $row, false, 'temporary' ); return; }
            if ( self::record_expired( $record ) ) { self::finish_outbox( $row, false, 'expired', true ); return; }
            $event = $record['event'];
            $route = Faluss_Events_Engine::delivery_route( $event, $row );
            if ( is_wp_error( $route ) ) { self::finish_outbox( $row, false, 'not_available' ); return; }
            if ( 'local' === $route['mode'] ) {
                if ( self::complete_local_route( $row, $event, $route ) ) { return; }
                self::finish_outbox( $row, false, 'temporary' );
                return;
            }
            if ( 'federation' !== $route['mode'] || ! class_exists( 'Faluss_Federation_Client' ) || ! method_exists( 'Faluss_Federation_Client', 'event_publish' ) ) { self::finish_outbox( $row, false, 'not_available' ); return; }
            $response = Faluss_Federation_Client::event_publish( $row['target_node_id'], $row['target_app_key'], $event );
            if ( is_wp_error( $response ) ) {
                $code = $response->get_error_code();
                self::finish_outbox( $row, false, 'faluss_federation_incompatible_response' === $code || 'faluss_federation_invalid_response' === $code ? 'invalid_response' : 'transport' );
                return;
            }
            if ( 'success' === ( $response['status'] ?? null ) && is_array( $response['payload'] ?? null ) && in_array( $response['payload']['disposition'] ?? '', array( 'accepted', 'existing' ), true ) ) {
                self::finish_outbox( $row, true, $response['payload']['disposition'] );
                return;
            }
            $status = $response['status'] ?? '';
            $map = array( 'not_authorized' => 'not_authorized', 'incompatible' => 'incompatible', 'not_available' => 'not_available', 'temporarily_unavailable' => 'temporary' );
            self::finish_outbox( $row, false, $map[ $status ] ?? 'invalid_response', in_array( $status, array( 'not_authorized', 'incompatible' ), true ) );
        } finally {
            Faluss_Events_Retention::release_event_lock( $lock );
        }
    }

    private static function complete_local_route( $row, $event, $route ) {
        global $wpdb;
        $consumers = Faluss_Events_Engine::consumers_for_local_route( $event, $row['destination'], $route['target_node_id'], $route['target_app_key'] );
        if ( is_wp_error( $consumers ) || ! self::start_transaction() ) { return false; }
        $current = $wpdb->get_row( $wpdb->prepare( 'SELECT status,lease_token FROM ' . Faluss_Events_Schema::quote_identifier( Faluss_Events_Schema::outbox_table() ) . ' WHERE id = %d FOR UPDATE', (int) $row['id'] ), ARRAY_A );
        if ( self::database_has_error() || ! is_array( $current ) || 'leased' !== ( $current['status'] ?? null ) || ! hash_equals( (string) ( $current['lease_token'] ?? '' ), (string) $row['lease_token'] ) ) { self::rollback(); return false; }
        foreach ( $consumers as $consumer ) {
            $existing = $wpdb->get_var( $wpdb->prepare( 'SELECT delivery_uuid FROM ' . Faluss_Events_Schema::quote_identifier( Faluss_Events_Schema::consumer_deliveries_table() ) . ' WHERE event_id = %s AND destination = %s AND consumer_key = %s FOR UPDATE', $row['event_id'], $row['destination'], $consumer['consumer_key'] ) );
            if ( self::database_has_error() ) { self::rollback(); return false; }
            if ( null === $existing ) {
                $uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : '';
                if ( ! self::is_uuid( $uuid ) || false === $wpdb->insert( Faluss_Events_Schema::consumer_deliveries_table(), array( 'delivery_uuid' => $uuid, 'event_id' => $row['event_id'], 'destination' => $row['destination'], 'consumer_key' => $consumer['consumer_key'], 'status' => 'pending', 'attempt_count' => 0, 'lease_token' => null, 'lease_expires_at' => null, 'last_result_code' => null, 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'processed_at' => null ), array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ) ) || self::database_has_error() ) { self::rollback(); return false; }
            } elseif ( ! self::is_uuid( $existing ) ) {
                self::rollback(); return false;
            }
        }
        $written = $wpdb->update( Faluss_Events_Schema::outbox_table(), array( 'status' => 'delivered', 'lease_token' => null, 'lease_expires_at' => null, 'last_result_code' => 'local', 'delivered_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $row['id'], 'status' => 'leased', 'lease_token' => $row['lease_token'] ), array( '%s', '%s', '%s', '%s', '%s' ), array( '%d', '%s', '%s' ) );
        if ( 1 !== $written || self::database_has_error() || ! self::commit() ) { self::rollback(); return false; }
        return true;
    }

    private static function finish_outbox( $row, $success, $code, $permanent = false ) {
        global $wpdb;
        if ( ! in_array( $code, self::OUTBOX_CODES, true ) ) { $code = 'temporary'; }
        $attempt = (int) $row['attempt_count'];
        $terminal = $success || $permanent || $attempt >= self::MAX_ATTEMPTS;
        $status = $success ? 'delivered' : ( $terminal ? 'dead_letter' : 'retry' );
        $data = array( 'status' => $status, 'lease_token' => null, 'lease_expires_at' => null, 'last_result_code' => $code, 'delivered_at' => $success ? gmdate( 'Y-m-d H:i:s' ) : null );
        if ( 'retry' === $status ) { $data['next_attempt_at'] = gmdate( 'Y-m-d H:i:s', time() + self::retry_delay( $attempt ) ); }
        $formats = array_fill( 0, count( $data ), '%s' );
        $wpdb->update( Faluss_Events_Schema::outbox_table(), $data, array( 'id' => (int) $row['id'], 'status' => 'leased', 'lease_token' => $row['lease_token'] ), $formats, array( '%d', '%s', '%s' ) );
    }

    private static function process_consumer( $row ) {
        $lock = Faluss_Events_Retention::acquire_event_lock( $row['event_id'] ?? '' );
        if ( false === $lock ) { self::finish_consumer( $row, 'event_unavailable' ); return; }
        try {
            $record = Faluss_Events_Engine::event_record_by_id( $row['event_id'] ?? '' );
            if ( is_wp_error( $record ) ) { self::finish_consumer( $row, 'event_unavailable' ); return; }
            if ( self::record_expired( $record ) ) { self::finish_consumer( $row, 'expired', true ); return; }
            $identity = class_exists( 'Faluss_Federation_Crypto' ) ? Faluss_Federation_Crypto::local_identity() : new WP_Error( 'unavailable' );
            if ( is_wp_error( $identity ) ) { self::finish_consumer( $row, 'consumer_unavailable' ); return; }
            $consumer = Faluss_Events_Engine::consumer_descriptor( $record['event'], $row, $identity['node_id'], $identity['app_key'] );
            if ( is_wp_error( $consumer ) ) { self::finish_consumer( $row, 'consumer_unavailable' ); return; }
            $context = array( 'event' => $record['event'], 'delivery_uuid' => $row['delivery_uuid'], 'destination' => $row['destination'], 'consumer_key' => $row['consumer_key'], 'attempt' => (int) $row['attempt_count'], 'idempotency_key' => self::consumer_idempotency_key( $row['event_id'], $row['destination'], $row['consumer_key'] ) );
            try { $result = call_user_func( $consumer['callback'], $context ); } catch ( Throwable $throwable ) { $result = new WP_Error( 'faluss_events_retryable' ); }
            if ( true === $result ) { self::finish_consumer( $row, 'processed' ); return; }
            if ( is_wp_error( $result ) && 'faluss_events_permanent' === $result->get_error_code() ) { self::finish_consumer( $row, 'permanent', true ); return; }
            self::finish_consumer( $row, is_wp_error( $result ) && 'faluss_events_retryable' === $result->get_error_code() ? 'retryable' : 'invalid_result' );
        } finally {
            Faluss_Events_Retention::release_event_lock( $lock );
        }
    }

    private static function finish_consumer( $row, $code, $permanent = false ) {
        global $wpdb;
        if ( ! in_array( $code, self::CONSUMER_CODES, true ) ) { $code = 'invalid_result'; }
        $attempt = (int) $row['attempt_count'];
        $processed = 'processed' === $code;
        $terminal = $processed || $permanent || $attempt >= self::MAX_ATTEMPTS;
        $data = array( 'status' => $processed ? 'processed' : ( $terminal ? 'dead_letter' : 'retry' ), 'lease_token' => null, 'lease_expires_at' => $terminal ? null : gmdate( 'Y-m-d H:i:s', time() + self::retry_delay( $attempt ) ), 'last_result_code' => $code, 'processed_at' => $processed ? gmdate( 'Y-m-d H:i:s' ) : null );
        $wpdb->update( Faluss_Events_Schema::consumer_deliveries_table(), $data, array( 'id' => (int) $row['id'], 'status' => 'leased', 'lease_token' => $row['lease_token'] ), array( '%s', '%s', '%s', '%s', '%s' ), array( '%d', '%s', '%s' ) );
    }

    private static function consumer_idempotency_key( $event_id, $destination, $consumer_key ) {
        return 'faluss-event-consumer:' . hash( 'sha256', strlen( $event_id ) . ':' . $event_id . ';' . strlen( $destination ) . ':' . $destination . ';' . strlen( $consumer_key ) . ':' . $consumer_key );
    }

    private static function record_expired( $record ) {
        return ! is_array( $record ) || ! is_string( $record['retention_until'] ?? null ) || $record['retention_until'] <= gmdate( 'Y-m-d H:i:s' );
    }

    private static function retry_delay( $attempt ) { return self::RETRY_DELAYS[ max( 0, min( 6, (int) $attempt - 1 ) ) ]; }
    private static function lease_token() { try { $raw = random_bytes( 32 ); } catch ( Throwable $throwable ) { return null; } return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); }
    private static function is_uuid( $value ) { return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value ); }

    private static function with_worker_lock( $scope, $callback ) {
        global $wpdb;
        $lock = 'faluss_evt_worker_' . substr( hash( 'sha256', (string) $wpdb->prefix . "\x1F" . $scope ), 0, 40 );
        $acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $lock, 0 ) );
        if ( ( 1 !== $acquired && '1' !== $acquired ) || self::database_has_error() ) { return; }
        try { call_user_func( $callback ); } catch ( Throwable $throwable ) { /* Lease expiry safely recovers unfinished work. */ }
        try { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); } catch ( Throwable $throwable ) { return; }
    }

    private static function start_transaction() { global $wpdb; return false !== $wpdb->query( 'START TRANSACTION' ) && ! self::database_has_error(); }
    private static function commit() { global $wpdb; return false !== $wpdb->query( 'COMMIT' ) && ! self::database_has_error(); }
    private static function rollback() { global $wpdb; return false !== $wpdb->query( 'ROLLBACK' ) && ! self::database_has_error(); }
    private static function database_has_error() { global $wpdb; return ! isset( $wpdb->last_error ) || ! is_string( $wpdb->last_error ) || '' !== $wpdb->last_error; }
}
