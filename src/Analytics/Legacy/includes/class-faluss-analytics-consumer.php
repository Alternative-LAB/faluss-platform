<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Idempotent transactional aggregation callback used only by Faluss Events. */
final class Faluss_Analytics_Consumer {
    const RECEIPT_SECONDS = 2592000;
    const MAX_SAFE_INTEGER = 9007199254740991;

    public static function consume( $context ) {
        if ( ! Faluss_Analytics_Schema::is_ready() || ! self::valid_context( $context ) || ! Faluss_Analytics_Event_Validator::validate_event( $context['event'] ) || ! class_exists( 'Faluss_Events_Canonicalizer' ) ) {
            return self::permanent();
        }
        $event = $context['event'];
        $expected_idempotency = self::worker_idempotency_key( $event['event_id'], $context['destination'], $context['consumer_key'] );
        if ( ! hash_equals( $expected_idempotency, $context['idempotency_key'] ) ) {
            return self::permanent();
        }
        $canonical = Faluss_Events_Canonicalizer::canonicalize_event( $event );
        if ( is_wp_error( $canonical ) ) {
            return self::permanent();
        }
        $definition = Faluss_Analytics_Event_Validator::definition( $event['event_type'] );
        $subject_hash = self::subject_hash( $event['subject_context']['subject_faluss_id'] );
        if ( ! is_string( $subject_hash ) || ! is_array( $definition ) ) {
            return self::permanent();
        }
        $material = $event['event_id'] . "\x1F" . hash( 'sha256', $context['idempotency_key'] );
        $locks = array( self::lock_name( 'event', $material ), self::lock_name( 'subject', $subject_hash ) );
        if ( ! is_string( $locks[0] ) || ! is_string( $locks[1] ) || $locks[0] === $locks[1] ) {
            return self::retryable();
        }
        sort( $locks, SORT_STRING );
        if ( ! self::acquire_locks( $locks, 2 ) ) {
            return self::retryable();
        }
        try {
            $result = self::aggregate_transaction(
                $event,
                $definition,
                hash( 'sha256', $canonical ),
                hash( 'sha256', $context['idempotency_key'] ),
                $subject_hash
            );
        } catch ( Throwable $throwable ) {
            self::rollback();
            $result = self::retryable();
        }
        if ( ! self::release_locks( $locks ) ) {
            return self::retryable();
        }
        return $result;
    }

    /** Delete member aggregates while retaining receipts until their normal expiry. */
    public static function delete_subject( $trusted_faluss_id ) {
        $subject_hash = self::subject_hash( $trusted_faluss_id );
        if ( ! is_string( $subject_hash ) ) {
            return self::permanent();
        }
        if ( ! Faluss_Analytics_Schema::is_ready() ) {
            return self::retryable();
        }
        $lock = self::lock_name( 'subject', $subject_hash );
        if ( ! is_string( $lock ) || ! self::acquire_lock( $lock, 2 ) ) {
            return self::retryable();
        }
        $result = self::retryable();
        try {
            if ( ! self::start_transaction() ) {
                $result = self::retryable();
            } else {
                global $wpdb;
                $metrics = $wpdb->delete( Faluss_Analytics_Schema::daily_metrics_table(), array( 'subject_identity_sha256' => $subject_hash ), array( '%s' ) );
                $objects = false === $metrics || self::database_has_error() ? false : $wpdb->delete( Faluss_Analytics_Schema::daily_objects_table(), array( 'subject_identity_sha256' => $subject_hash ), array( '%s' ) );
                if ( false === $metrics || false === $objects || self::database_has_error() || ! self::commit() ) {
                    self::rollback();
                } else {
                    $result = true;
                }
            }
        } catch ( Throwable $throwable ) {
            self::rollback();
        }
        if ( ! self::release_lock( $lock ) ) {
            return self::retryable();
        }
        return $result;
    }

    public static function subject_hash( $faluss_id ) {
        return self::uuid( $faluss_id ) ? hash( 'sha256', "faluss-analytics:subject:v1\n" . strtolower( $faluss_id ) ) : null;
    }

    private static function aggregate_transaction( $event, $definition, $event_hash, $idempotency_hash, $subject_hash ) {
        global $wpdb;
        if ( ! self::start_transaction() ) { return self::retryable(); }
        $receipts = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT event_id,idempotency_sha256,event_sha256,subject_identity_sha256 FROM ' . Faluss_Analytics_Schema::quote_identifier( Faluss_Analytics_Schema::receipts_table() ) . ' WHERE event_id = %s OR idempotency_sha256 = %s FOR UPDATE',
                $event['event_id'],
                $idempotency_hash
            ),
            ARRAY_A
        );
        if ( self::database_has_error() || ! is_array( $receipts ) || count( $receipts ) > 1 ) {
            self::rollback();
            return self::retryable();
        }
        if ( 1 === count( $receipts ) ) {
            $receipt = $receipts[0];
            $exact = $event['event_id'] === ( $receipt['event_id'] ?? null )
                && hash_equals( $idempotency_hash, (string) ( $receipt['idempotency_sha256'] ?? '' ) )
                && hash_equals( $event_hash, (string) ( $receipt['event_sha256'] ?? '' ) )
                && hash_equals( $subject_hash, (string) ( $receipt['subject_identity_sha256'] ?? '' ) );
            if ( ! $exact ) {
                self::rollback();
                return self::permanent();
            }
            if ( ! self::commit() ) {
                self::rollback();
                return self::retryable();
            }
            return true;
        }

        $processed_timestamp = time();
        $now = gmdate( 'Y-m-d H:i:s', $processed_timestamp );
        $date = substr( $event['occurred_at'], 0, 10 );
        if ( ! self::increment_metric( $subject_hash, $date, $definition['metric_key'], $now ) ) {
            self::rollback();
            return self::retryable();
        }
        if ( null !== $definition['object_type'] && ! self::increment_object( $subject_hash, $date, $definition['object_type'], $event['object_context']['object_reference'], $now ) ) {
            self::rollback();
            return self::retryable();
        }
        $uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : '';
        if ( ! self::uuid( $uuid ) ) {
            self::rollback();
            return self::retryable();
        }
        $written = $wpdb->insert(
            Faluss_Analytics_Schema::receipts_table(),
            array(
                'receipt_uuid' => $uuid,
                'event_id' => $event['event_id'],
                'idempotency_sha256' => $idempotency_hash,
                'event_sha256' => $event_hash,
                'subject_identity_sha256' => $subject_hash,
                'processed_at' => $now,
                'expires_at' => gmdate( 'Y-m-d H:i:s', $processed_timestamp + self::RECEIPT_SECONDS ),
                'created_at' => $now,
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );
        if ( 1 !== $written || self::database_has_error() || ! self::commit() ) {
            self::rollback();
            return self::retryable();
        }
        return true;
    }

    private static function increment_metric( $subject_hash, $date, $metric_key, $now ) {
        global $wpdb;
        $query = $wpdb->prepare(
            'INSERT INTO ' . Faluss_Analytics_Schema::quote_identifier( Faluss_Analytics_Schema::daily_metrics_table() ) . ' (subject_identity_sha256,aggregate_date,metric_key,metric_value,created_at,updated_at) VALUES (%s,%s,%s,%d,%s,%s) ON DUPLICATE KEY UPDATE metric_value = metric_value + 1,updated_at = VALUES(updated_at)',
            $subject_hash,
            $date,
            $metric_key,
            1,
            $now,
            $now
        );
        $written = $wpdb->query( $query );
        if ( ! in_array( $written, array( 1, 2 ), true ) || self::database_has_error() ) { return false; }
        $value = $wpdb->get_var( $wpdb->prepare( 'SELECT metric_value FROM ' . Faluss_Analytics_Schema::quote_identifier( Faluss_Analytics_Schema::daily_metrics_table() ) . ' WHERE subject_identity_sha256 = %s AND aggregate_date = %s AND metric_key = %s FOR UPDATE', $subject_hash, $date, $metric_key ) );
        return self::safe_positive_integer( $value ) && ! self::database_has_error();
    }

    private static function increment_object( $subject_hash, $date, $object_type, $object_reference, $now ) {
        global $wpdb;
        $query = $wpdb->prepare(
            'INSERT INTO ' . Faluss_Analytics_Schema::quote_identifier( Faluss_Analytics_Schema::daily_objects_table() ) . ' (subject_identity_sha256,aggregate_date,object_type,object_reference,metric_value,created_at,updated_at) VALUES (%s,%s,%s,%s,%d,%s,%s) ON DUPLICATE KEY UPDATE metric_value = metric_value + 1,updated_at = VALUES(updated_at)',
            $subject_hash,
            $date,
            $object_type,
            $object_reference,
            1,
            $now,
            $now
        );
        $written = $wpdb->query( $query );
        if ( ! in_array( $written, array( 1, 2 ), true ) || self::database_has_error() ) { return false; }
        $value = $wpdb->get_var( $wpdb->prepare( 'SELECT metric_value FROM ' . Faluss_Analytics_Schema::quote_identifier( Faluss_Analytics_Schema::daily_objects_table() ) . ' WHERE subject_identity_sha256 = %s AND aggregate_date = %s AND object_type = %s AND object_reference = %s FOR UPDATE', $subject_hash, $date, $object_type, $object_reference ) );
        return self::safe_positive_integer( $value ) && ! self::database_has_error();
    }

    private static function valid_context( $context ) {
        if ( ! self::exact_keys( $context, array( 'event', 'delivery_uuid', 'destination', 'consumer_key', 'attempt', 'idempotency_key' ) ) ) { return false; }
        return is_array( $context['event'] )
            && self::uuid( $context['delivery_uuid'] )
            && 'analytics.events' === $context['destination']
            && 'faluss-analytics.aggregate-v1' === $context['consumer_key']
            && is_int( $context['attempt'] ) && $context['attempt'] >= 1 && $context['attempt'] <= 8
            && is_string( $context['idempotency_key'] ) && 1 === preg_match( '/^faluss-event-consumer:[a-f0-9]{64}$/D', $context['idempotency_key'] );
    }

    private static function worker_idempotency_key( $event_id, $destination, $consumer_key ) {
        return 'faluss-event-consumer:' . hash( 'sha256', strlen( $event_id ) . ':' . $event_id . ';' . strlen( $destination ) . ':' . $destination . ';' . strlen( $consumer_key ) . ':' . $consumer_key );
    }

    private static function lock_name( $scope, $material ) {
        return is_string( $material ) && '' !== $material && in_array( $scope, array( 'event', 'subject' ), true ) ? 'faluss_an_' . $scope . '_' . substr( hash( 'sha256', $material ), 0, 40 ) : null;
    }
    private static function acquire_lock( $lock, $wait ) { global $wpdb; $value = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $lock, $wait ) ); return ( 1 === $value || '1' === $value ) && ! self::database_has_error(); }
    private static function release_lock( $lock ) { global $wpdb; $value = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); return ( 1 === $value || '1' === $value ) && ! self::database_has_error(); }
    private static function acquire_locks( $locks, $wait ) {
        $acquired = array();
        foreach ( $locks as $lock ) {
            if ( ! self::acquire_lock( $lock, $wait ) ) { self::release_locks( $acquired ); return false; }
            $acquired[] = $lock;
        }
        return true;
    }
    private static function release_locks( $locks ) {
        $released = true;
        foreach ( array_reverse( $locks ) as $lock ) { if ( ! self::release_lock( $lock ) ) { $released = false; } }
        return $released;
    }
    private static function start_transaction() { global $wpdb; return false !== $wpdb->query( 'START TRANSACTION' ) && ! self::database_has_error(); }
    private static function commit() { global $wpdb; return false !== $wpdb->query( 'COMMIT' ) && ! self::database_has_error(); }
    private static function rollback() { global $wpdb; return false !== $wpdb->query( 'ROLLBACK' ) && ! self::database_has_error(); }
    private static function database_has_error() { global $wpdb; return ! isset( $wpdb->last_error ) || ! is_string( $wpdb->last_error ) || '' !== $wpdb->last_error; }
    private static function safe_positive_integer( $value ) { return ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) && (int) $value >= 1 && (int) $value <= self::MAX_SAFE_INTEGER; }
    private static function uuid( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value ); }
    private static function exact_keys( $value, $expected ) { if ( ! is_array( $value ) || self::is_list( $value ) ) { return false; } $actual = array_keys( $value ); sort( $actual, SORT_STRING ); sort( $expected, SORT_STRING ); return $actual === $expected; }
    private static function is_list( $value ) { return is_array( $value ) && ( array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 ) ); }
    private static function retryable() { return new WP_Error( 'faluss_events_retryable' ); }
    private static function permanent() { return new WP_Error( 'faluss_events_permanent' ); }
}
