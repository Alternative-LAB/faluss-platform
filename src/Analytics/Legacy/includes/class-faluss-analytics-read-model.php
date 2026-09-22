<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Private PHP-only analytics.summary 1.0.0 read model. */
final class Faluss_Analytics_Read_Model {
    const MAX_DAYS = 800;
    const MAX_OBJECTS = 200;
    const MAX_SAFE_INTEGER = 9007199254740991;

    public static function summary( $trusted_faluss_id, $from = null, $to = null ) {
        $now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
        $default_to = $now;
        $default_from = $now->setTime( 0, 0, 0 )->sub( new DateInterval( 'P29D' ) );
        $requested_from = null === $from ? $default_from : self::parse_utc( $from );
        $requested_to = null === $to ? $default_to : self::parse_utc( $to );
        $fallback = array( 'from' => self::format_utc( $default_from ), 'to' => self::format_utc( $default_to ) );
        if ( ( null === $from ) !== ( null === $to ) || ! $requested_from || ! $requested_to || $requested_from > $requested_to || ! self::period_within_limit( $requested_from, $requested_to ) || $requested_from > $now ) {
            return self::empty_document( 'not_supported', $fallback, $fallback, $now );
        }
        $requested = array( 'from' => self::format_utc( $requested_from ), 'to' => self::format_utc( $requested_to ) );
        $cutoff = $now->sub( new DateInterval( 'P25M' ) )->setTime( 0, 0, 0 );
        $produced_from = $requested_from < $cutoff ? $cutoff : $requested_from;
        $produced_to = $requested_to > $now ? $now : $requested_to;
        if ( $produced_from > $produced_to ) {
            return self::empty_document( 'not_supported', $requested, $requested, $now );
        }
        $produced = array( 'from' => self::format_utc( $produced_from ), 'to' => self::format_utc( $produced_to ) );
        if ( ! class_exists( 'Faluss_Analytics' ) || ! Faluss_Analytics::is_local_hub() ) {
            return self::empty_document( 'unavailable', $requested, $produced, $now );
        }
        $subject_hash = Faluss_Analytics_Consumer::subject_hash( $trusted_faluss_id );
        if ( ! is_string( $subject_hash ) ) {
            return self::empty_document( 'not_supported', $requested, $produced, $now );
        }
        if ( ! Faluss_Analytics_Schema::is_ready() ) {
            return self::empty_document( 'unavailable', $requested, $produced, $now );
        }
        $rows = self::snapshot_rows( $subject_hash, $produced_from->format( 'Y-m-d' ), $produced_to->format( 'Y-m-d' ) );
        if ( ! is_array( $rows ) ) {
            return self::empty_document( 'unavailable', $requested, $produced, $now );
        }
        $aggregated = self::aggregate_rows( $rows['metrics'], $rows['objects'] );
        if ( ! is_array( $aggregated ) ) {
            return self::empty_document( 'unavailable', $requested, $produced, $now );
        }
        if ( empty( $aggregated['totals'] ) ) {
            return self::empty_document( 'empty', $requested, $produced, $now );
        }
        return self::base_document( 'ready', $requested, $produced, $now ) + $aggregated;
    }

    private static function snapshot_rows( $subject_hash, $from_date, $to_date ) {
        global $wpdb;
        if ( false === $wpdb->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' ) || self::database_has_error() ) { return null; }
        $metrics = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT aggregate_date,metric_key,metric_value FROM ' . Faluss_Analytics_Schema::quote_identifier( Faluss_Analytics_Schema::daily_metrics_table() ) . ' WHERE subject_identity_sha256 = %s AND aggregate_date >= %s AND aggregate_date <= %s ORDER BY aggregate_date ASC,metric_key ASC',
                $subject_hash,
                $from_date,
                $to_date
            ),
            ARRAY_A
        );
        if ( self::database_has_error() || ! is_array( $metrics ) || count( $metrics ) > self::MAX_DAYS * 6 ) {
            self::rollback();
            return null;
        }
        $objects = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT object_type,object_reference,SUM(metric_value) AS metric_value FROM ' . Faluss_Analytics_Schema::quote_identifier( Faluss_Analytics_Schema::daily_objects_table() ) . ' WHERE subject_identity_sha256 = %s AND aggregate_date >= %s AND aggregate_date <= %s GROUP BY object_type,object_reference ORDER BY metric_value DESC,object_type ASC,object_reference ASC LIMIT %d',
                $subject_hash,
                $from_date,
                $to_date,
                self::MAX_OBJECTS
            ),
            ARRAY_A
        );
        if ( self::database_has_error() || ! is_array( $objects ) || count( $objects ) > self::MAX_OBJECTS || false === $wpdb->query( 'COMMIT' ) || self::database_has_error() ) {
            self::rollback();
            return null;
        }
        return array( 'metrics' => $metrics, 'objects' => $objects );
    }

    private static function aggregate_rows( $metrics, $objects ) {
        $allowed = self::metric_event_types();
        $totals = array();
        $daily = array();
        foreach ( $metrics as $row ) {
            $date = $row['aggregate_date'] ?? null;
            $metric = $row['metric_key'] ?? null;
            $value = self::integer( $row['metric_value'] ?? null );
            if ( ! is_string( $date ) || 1 !== preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date ) || ! isset( $allowed[ $metric ] ) || ! is_int( $value ) || $value < 1 ) { return null; }
            $totals[ $metric ] = ( $totals[ $metric ] ?? 0 ) + $value;
            $daily[ $date ][ $metric ] = $value;
            if ( $totals[ $metric ] > self::MAX_SAFE_INTEGER ) { return null; }
        }
        ksort( $totals, SORT_STRING ); ksort( $daily, SORT_STRING );
        $daily_series = array();
        foreach ( $daily as $date => $date_totals ) { ksort( $date_totals, SORT_STRING ); $daily_series[] = array( 'date' => $date, 'totals' => $date_totals ); }
        $event_breakdowns = array();
        foreach ( $allowed as $metric => $event_type ) {
            if ( isset( $totals[ $metric ] ) ) { $event_breakdowns[] = array( 'event_type' => $event_type, 'total' => $totals[ $metric ] ); }
        }
        $object_breakdowns = array();
        foreach ( $objects as $row ) {
            $type = $row['object_type'] ?? null;
            $reference = $row['object_reference'] ?? null;
            $value = self::integer( $row['metric_value'] ?? null );
            if ( ! in_array( $type, array( 'app', 'link', 'collection' ), true ) || ! is_string( $reference ) || 1 !== preg_match( '/^an01_' . preg_quote( $type, '/' ) . '_[a-f0-9]{64}$/D', $reference ) || ! is_int( $value ) || $value < 1 ) { return null; }
            $object_breakdowns[] = array( 'object_type' => $type, 'object_reference' => $reference, 'total' => $value );
        }
        return array( 'totals' => $totals, 'daily_series' => $daily_series, 'breakdowns' => array( 'by_event_type' => $event_breakdowns, 'by_object_reference' => $object_breakdowns ) );
    }

    private static function empty_document( $status, $requested, $produced, $now ) {
        return self::base_document( $status, $requested, $produced, $now ) + array( 'totals' => array(), 'daily_series' => array(), 'breakdowns' => array( 'by_event_type' => array(), 'by_object_reference' => array() ) );
    }

    private static function base_document( $status, $requested, $produced, $now ) {
        return array(
            'document_type' => 'analytics.summary',
            'contract_version' => '1.0.0',
            'status' => $status,
            'requested_period' => $requested,
            'produced_period' => $produced,
            'measurement_semantics' => array( 'raw_views' => 'qualified_event_count', 'unique_visitors' => 'not_supported' ),
            'unique_visitors' => array( 'status' => 'not_supported', 'total' => null, 'daily_series' => array() ),
            'freshness' => array( 'generated_at' => self::format_utc( $now ), 'max_age_seconds' => 300, 'stale_behavior' => 'refresh_from_owner' ),
            'source' => array( 'owner' => 'faluss-analytics', 'node_id' => 'hub-node', 'hosting_app' => 'faluss-hub', 'destination' => 'analytics.events', 'consumer_key' => 'faluss-analytics.aggregate-v1' ),
            'compatibility' => array( 'minimum_consumer_version' => '1.0.0', 'compatible_with' => array( '1.0.0' ), 'deprecated' => false, 'sunset_at' => null ),
        );
    }

    private static function metric_event_types() {
        return array(
            'hub.app.opens' => 'faluss-hub.app.opened',
            'hub.daily_reward.claims' => 'faluss-hub.daily-reward.claimed',
            'hub.portal.raw_views' => 'faluss-hub.portal.viewed',
            'me.card.raw_views' => 'faluss-me.card.viewed',
            'me.collection.opens' => 'faluss-me.collection.opened',
            'me.link.clicks' => 'faluss-me.link.clicked',
        );
    }

    private static function period_within_limit( $from, $to ) {
        $days = (int) $from->setTime( 0, 0, 0 )->diff( $to->setTime( 0, 0, 0 ) )->format( '%a' ) + 1;
        return $days <= self::MAX_DAYS;
    }
    private static function parse_utc( $value ) {
        if ( ! is_string( $value ) || 1 !== preg_match( '/^[0-9]{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])T(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]Z$/D', $value ) ) { return null; }
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $value, new DateTimeZone( 'UTC' ) );
        $errors = DateTimeImmutable::getLastErrors();
        return false !== $date && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && self::format_utc( $date ) === $value ? $date : null;
    }
    private static function format_utc( $date ) { return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ); }
    private static function integer( $value ) { if ( is_int( $value ) ) { return $value >= 0 && $value <= self::MAX_SAFE_INTEGER ? $value : null; } if ( ! is_string( $value ) || ! ctype_digit( $value ) || strlen( $value ) > 16 ) { return null; } $integer = (int) $value; return $integer <= self::MAX_SAFE_INTEGER ? $integer : null; }
    private static function rollback() { global $wpdb; return false !== $wpdb->query( 'ROLLBACK' ); }
    private static function database_has_error() { global $wpdb; return ! isset( $wpdb->last_error ) || ! is_string( $wpdb->last_error ) || '' !== $wpdb->last_error; }
}
