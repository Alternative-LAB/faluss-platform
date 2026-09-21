<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Central rights registry. It records level decisions but grants no product-local feature in SUB-01A. */
final class Faluss_Subscriptions_Entitlements {
    const ADMIN_PRIORITY = 300;

    /** @return array<string,mixed>|WP_Error */
    public static function grant_temporary_pro( $faluss_id, $expires_at, $reason, $actor_user_id, $operation_reference ) {
        global $wpdb;
        $faluss_id = self::faluss_id( $faluss_id );
        $expires_at = self::future_utc( $expires_at );
        $reason = self::bounded( $reason, 191 );
        $operation_reference = self::bounded( $operation_reference, 191 );
        if ( ! Faluss_Subscriptions_Schema::is_ready() ) {
            return self::error( 'schema_not_ready' );
        }
        if ( ! $faluss_id ) { return self::error( 'admin_grant_invalid_subject' ); }
        if ( ! $expires_at ) { return self::error( 'admin_grant_invalid_expiration' ); }
        if ( '' === $reason || '' === $operation_reference ) { return self::error( 'admin_grant_failed' ); }
        $table = Faluss_Subscriptions_Schema::quote_identifier( Faluss_Subscriptions_Schema::entitlements_table() );
        if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
            return self::error( 'admin_grant_failed' );
        }
        try {
            $existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE source_reference=%s LIMIT 1 FOR UPDATE', $operation_reference ), ARRAY_A );
            if ( is_array( $existing ) ) {
                $wpdb->query( 'COMMIT' );
                return (string) $existing['faluss_id'] === $faluss_id ? $existing : self::error( 'admin_grant_reference_conflict' );
            }
            $now = gmdate( 'Y-m-d H:i:s' );
            $record = array(
                'entitlement_uuid' => wp_generate_uuid4(), 'faluss_id' => $faluss_id, 'entitlement_key' => Faluss_Subscriptions_Catalog::PRO_ENTITLEMENT,
                'entitlement_value' => 'pro', 'source' => 'admin_grant', 'source_reference' => $operation_reference, 'priority' => self::ADMIN_PRIORITY,
                'starts_at' => $now, 'expires_at' => $expires_at, 'status' => 'active', 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
            );
            $written = $wpdb->insert( Faluss_Subscriptions_Schema::entitlements_table(), $record, array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' ) );
            if ( false === $written ) {
                $wpdb->query( 'ROLLBACK' );
                return self::error( 'admin_grant_persistence_failed' );
            }
            $record['id'] = (int) $wpdb->insert_id;
            $saved = self::grant_by_id( $record['id'] );
            if ( ! self::saved_grant_matches( $saved, $record ) ) {
                $wpdb->query( 'ROLLBACK' );
                return self::error( 'admin_grant_persistence_failed' );
            }
            $decision = Faluss_Subscriptions_Resolver::resolve_for_faluss_id( $faluss_id );
            if ( 'pro' !== ( $decision['level'] ?? '' ) || 'comped' !== ( $decision['state'] ?? '' ) ) {
                $wpdb->query( 'ROLLBACK' );
                return self::error( 'admin_grant_resolution_failed' );
            }
            $audit = Faluss_Subscriptions_Audit::record( $actor_user_id, 'admin_grant_succeeded', $faluss_id, 'admin_grant', array(), self::audit_state( $saved ), $reason );
            if ( is_wp_error( $audit ) || false === $audit ) {
                $wpdb->query( 'ROLLBACK' );
                return self::error( 'admin_grant_audit_failed' );
            }
            if ( false === $wpdb->query( 'COMMIT' ) ) {
                $wpdb->query( 'ROLLBACK' );
                return self::error( 'admin_grant_persistence_failed' );
            }
            $saved = self::grant_by_id( $record['id'] );
            if ( ! self::saved_grant_matches( $saved, $record ) ) {
                return self::error( 'admin_grant_persistence_failed' );
            }
            self::invalidate_decision( $faluss_id );
            return $saved;
        } catch ( Exception $exception ) {
            $wpdb->query( 'ROLLBACK' );
            return self::error( 'admin_grant_failed' );
        }
    }

    /** Used by the admin handler before it calls the write service, using the site timezone. */
    public static function is_valid_future_expiration( $value ) {
        return null !== self::future_utc( $value );
    }

    /** @return array<string,mixed>|WP_Error */
    public static function revoke_admin_grant( $grant_id, $reason, $actor_user_id ) {
        global $wpdb;
        $grant_id = absint( $grant_id );
        $reason = self::bounded( $reason, 191 );
        if ( ! Faluss_Subscriptions_Schema::is_ready() ) { return self::error( 'schema_not_ready' ); }
        if ( ! $grant_id || '' === $reason ) { return self::error( 'admin_grant_revoke_invalid' ); }
        $table = Faluss_Subscriptions_Schema::quote_identifier( Faluss_Subscriptions_Schema::entitlements_table() );
        if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return self::error( 'admin_grant_revoke_conflict' ); }
        try {
            $grant = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE id=%d AND source=%s LIMIT 1 FOR UPDATE', $grant_id, 'admin_grant' ), ARRAY_A );
            if ( ! is_array( $grant ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'admin_grant_missing' ); }
            if ( 'revoked' === $grant['status'] ) { $wpdb->query( 'COMMIT' ); return $grant; }
            $now = gmdate( 'Y-m-d H:i:s' );
            $updated = $wpdb->update( Faluss_Subscriptions_Schema::entitlements_table(), array( 'status' => 'revoked', 'version' => (int) $grant['version'] + 1, 'updated_at' => $now ), array( 'id' => $grant_id, 'version' => (int) $grant['version'] ), array( '%s', '%d', '%s' ), array( '%d', '%d' ) );
            if ( false === $updated || 0 === $updated ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'admin_grant_revoke_conflict' ); }
            $after = self::grant_by_id( $grant_id );
            if ( ! is_array( $after ) || 'revoked' !== ( $after['status'] ?? '' ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'admin_grant_revoke_conflict' ); }
            $audit = Faluss_Subscriptions_Audit::record( $actor_user_id, 'admin_pro_revoked', $grant['faluss_id'], 'admin_grant', self::audit_state( $grant ), self::audit_state( $after ), $reason );
            if ( is_wp_error( $audit ) || false === $audit ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'admin_grant_revoke_audit_failed' ); }
            if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'admin_grant_revoke_conflict' ); }
            $after = self::grant_by_id( $grant_id );
            if ( ! is_array( $after ) || 'revoked' !== ( $after['status'] ?? '' ) ) { return self::error( 'admin_grant_revoke_conflict' ); }
            self::invalidate_decision( $grant['faluss_id'] );
            return $after;
        } catch ( Exception $exception ) {
            $wpdb->query( 'ROLLBACK' );
            return self::error( 'admin_grant_revoke_conflict' );
        }
    }

    private static function future_utc( $value ) {
        if ( ! is_string( $value ) || '' === trim( $value ) ) { return null; }
        $value = trim( $value );
        $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
        $format = 1 === preg_match( '/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}$/', $value ) ? '!Y-m-d\\TH:i:s' : '!Y-m-d\\TH:i';
        $date = DateTimeImmutable::createFromFormat( $format, $value, $timezone );
        $errors = DateTimeImmutable::getLastErrors();
        if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) ) { return null; }
        $utc = $date->setTimezone( new DateTimeZone( 'UTC' ) );
        $now = function_exists( 'current_time' ) ? (int) current_time( 'timestamp', true ) : time();
        return $utc->getTimestamp() > $now ? $utc->format( 'Y-m-d H:i:s' ) : null;
    }
    private static function grant_by_id( $grant_id ) { global $wpdb; return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Faluss_Subscriptions_Schema::quote_identifier( Faluss_Subscriptions_Schema::entitlements_table() ) . ' WHERE id=%d LIMIT 1', $grant_id ), ARRAY_A ); }
    private static function saved_grant_matches( $saved, $record ) { return is_array( $saved ) && (int) ( $saved['id'] ?? 0 ) === (int) $record['id'] && (string) ( $saved['faluss_id'] ?? '' ) === (string) $record['faluss_id'] && (string) ( $saved['source_reference'] ?? '' ) === (string) $record['source_reference'] && 'active' === ( $saved['status'] ?? '' ) && (string) ( $saved['expires_at'] ?? '' ) === (string) $record['expires_at']; }
    private static function invalidate_decision( $faluss_id ) { if ( function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( 'decision:' . $faluss_id, 'faluss_subscriptions' ); } }
    private static function faluss_id( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $value ) ? strtolower( $value ) : ''; }
    private static function bounded( $value, $length ) { $value = is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : ''; return function_exists( 'mb_substr' ) ? mb_substr( trim( $value ), 0, $length ) : substr( trim( $value ), 0, $length ); }
    private static function audit_state( $record ) { return is_array( $record ) ? array_intersect_key( $record, array_flip( array( 'id', 'entitlement_key', 'entitlement_value', 'source', 'priority', 'starts_at', 'expires_at', 'status', 'version' ) ) ) : array(); }
    private static function error( $code ) { return new WP_Error( $code, __( 'L’attribution administrative ne peut pas être modifiée.', 'faluss-subscriptions' ) ); }
}
