<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Trial lifecycle: only trusted server-side payment adapters may activate a trial. */
final class Faluss_Subscriptions_Trials {
    /**
     * Creates the only trial for a Faluss ID after a payment provider has verified a card.
     * This is deliberately not registered as AJAX, REST, shortcode or browser action.
     *
     * @return array<string,mixed>|WP_Error
     */
    public static function activate_verified_trial( $faluss_id, $verification_reference, $payment_fingerprint, $now = null ) {
        global $wpdb;
        $faluss_id = self::faluss_id( $faluss_id );
        $verification_reference = self::bounded( $verification_reference, 191 );
        $payment_fingerprint = self::bounded( $payment_fingerprint, 191 );
        if ( ! Faluss_Subscriptions_Schema::is_ready() || ! $faluss_id || '' === $verification_reference || '' === $payment_fingerprint ) {
            return self::error( 'trial_payment_proof_required' );
        }
        // Persist only a derived fingerprint; never the provider value or card information itself.
        $fingerprint_hash = hash( 'sha256', 'faluss-subscriptions-trial|' . $payment_fingerprint );
        $now = self::utc( $now ) ?: gmdate( 'Y-m-d H:i:s' );
        $expires = gmdate( 'Y-m-d H:i:s', strtotime( '+' . Faluss_Subscriptions_Catalog::TRIAL_DAYS . ' days', strtotime( $now ) ) );
        $locks = self::trial_locks( $faluss_id, $fingerprint_hash );
        if ( ! self::acquire_locks( $locks ) ) { return self::error( 'trial_activation_busy' ); }
        try {
            if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return self::error( 'trial_activation_failed' ); }
            $trials = Faluss_Subscriptions_Schema::quote_identifier( Faluss_Subscriptions_Schema::trials_table() );
            $existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $trials . ' WHERE faluss_id=%s FOR UPDATE', $faluss_id ), ARRAY_A );
            $used_payment = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $trials . ' WHERE payment_fingerprint_hash=%s FOR UPDATE', $fingerprint_hash ), ARRAY_A );
            // Stripe legitimately delivers more than one signed event for a
            // subscription. Once this Faluss ID and provider subscription
            // pair has passed payment verification, replay that canonical
            // decision even if Stripe now exposes the payment method through
            // a different object representation. It is not a new trial.
            if ( is_array( $existing )
                && 'trialing' === (string) $existing['trial_state']
                && $verification_reference === (string) ( $existing['verification_reference'] ?? '' )
                && self::future( $existing['expires_at'] ?? null, $now ) ) {
                $wpdb->query( 'COMMIT' );
                return $existing;
            }
            if ( is_array( $used_payment ) && ( ! is_array( $existing ) || (int) $used_payment['id'] !== (int) $existing['id'] ) ) {
                $wpdb->query( 'ROLLBACK' );
                return self::error( 'trial_payment_method_already_used' );
            }
            if ( is_array( $existing ) && 'eligible' !== (string) $existing['trial_state'] ) {
                $wpdb->query( 'COMMIT' );
                return self::error( 'trial_already_used' );
            }
            $data = array(
                'eligibility_status' => is_array( $existing ) && 'admin_override' === ( $existing['eligibility_status'] ?? '' ) ? 'admin_override' : 'eligible',
                'trial_state' => 'trialing', 'activated_at' => $now, 'expires_at' => $expires, 'consumed_at' => $now,
                'verification_reference' => $verification_reference, 'payment_fingerprint_hash' => $fingerprint_hash, 'updated_at' => $now,
            );
            if ( is_array( $existing ) ) {
                $written = $wpdb->update( Faluss_Subscriptions_Schema::trials_table(), $data, array( 'id' => (int) $existing['id'] ) );
            } else {
                $data = array_merge( array( 'trial_uuid' => wp_generate_uuid4(), 'faluss_id' => $faluss_id, 'revoked_at' => null, 'admin_override_reason' => null, 'admin_override_reference' => null, 'created_at' => $now ), $data );
                $written = $wpdb->insert( Faluss_Subscriptions_Schema::trials_table(), $data );
            }
            if ( false === $written ) {
                $wpdb->query( 'ROLLBACK' );
                return self::error( 'trial_activation_failed' );
            }
            $wpdb->query( 'COMMIT' );
            $trial = Faluss_Subscriptions_Repository::trial_for_faluss_id( $faluss_id );
            Faluss_Subscriptions_Audit::record( 0, 'trial_activated', $faluss_id, 'trial', is_array( $existing ) ? self::audit_state( $existing ) : array(), self::audit_state( $trial ), null );
            return is_array( $trial ) ? $trial : self::error( 'trial_activation_failed' );
        } catch ( Exception $exception ) {
            $wpdb->query( 'ROLLBACK' );
            return self::error( 'trial_activation_failed' );
        } finally {
            self::release_locks( $locks );
        }
    }

    /** Administrative eligibility override; it still cannot activate a trial without verified payment proof. */
    public static function set_eligibility_override( $faluss_id, $reason, $actor_user_id, $operation_reference ) {
        global $wpdb;
        $faluss_id = self::faluss_id( $faluss_id );
        $reason = self::bounded( $reason, 191 );
        $operation_reference = self::bounded( $operation_reference, 191 );
        if ( ! Faluss_Subscriptions_Schema::is_ready() ) {
            return self::error( 'schema_not_ready' );
        }
        if ( ! $faluss_id || '' === $reason || '' === $operation_reference ) {
            return self::error( 'trial_override_invalid' );
        }
        $locks = self::trial_locks( $faluss_id, '' );
        if ( ! self::acquire_locks( $locks ) ) { return self::error( 'trial_override_busy' ); }
        try {
            if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return self::error( 'trial_override_failed' ); }
            $table = Faluss_Subscriptions_Schema::quote_identifier( Faluss_Subscriptions_Schema::trials_table() );
            $by_reference = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE admin_override_reference=%s LIMIT 1 FOR UPDATE', $operation_reference ), ARRAY_A );
            if ( is_array( $by_reference ) ) {
                $wpdb->query( 'COMMIT' );
                return (string) $by_reference['faluss_id'] === $faluss_id ? $by_reference : self::error( 'trial_override_reference_conflict' );
            }
            $existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE faluss_id=%s LIMIT 1 FOR UPDATE', $faluss_id ), ARRAY_A );
            if ( is_array( $existing ) && 'eligible' !== (string) $existing['trial_state'] ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'trial_already_used' ); }
            $now = gmdate( 'Y-m-d H:i:s' );
            if ( is_array( $existing ) ) {
                $written = $wpdb->update( Faluss_Subscriptions_Schema::trials_table(), array( 'eligibility_status' => 'admin_override', 'admin_override_reason' => $reason, 'admin_override_reference' => $operation_reference, 'updated_at' => $now ), array( 'id' => (int) $existing['id'] ) );
            } else {
                $written = $wpdb->insert( Faluss_Subscriptions_Schema::trials_table(), array( 'trial_uuid' => wp_generate_uuid4(), 'faluss_id' => $faluss_id, 'eligibility_status' => 'admin_override', 'trial_state' => 'eligible', 'admin_override_reason' => $reason, 'admin_override_reference' => $operation_reference, 'created_at' => $now, 'updated_at' => $now ) );
            }
            if ( false === $written ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'trial_override_failed' ); }
            $trial = Faluss_Subscriptions_Repository::trial_for_faluss_id( $faluss_id );
            if ( ! is_array( $trial ) || (string) ( $trial['faluss_id'] ?? '' ) !== $faluss_id || 'admin_override' !== ( $trial['eligibility_status'] ?? '' ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'trial_override_failed' ); }
            $audit = Faluss_Subscriptions_Audit::record( $actor_user_id, 'trial_eligibility_overridden', $faluss_id, 'admin_grant', is_array( $existing ) ? self::audit_state( $existing ) : array(), self::audit_state( $trial ), $reason );
            if ( is_wp_error( $audit ) || false === $audit ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'trial_override_audit_failed' ); }
            if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'trial_override_failed' ); }
            $trial = Faluss_Subscriptions_Repository::trial_for_faluss_id( $faluss_id );
            return is_array( $trial ) && 'admin_override' === ( $trial['eligibility_status'] ?? '' ) ? $trial : self::error( 'trial_override_failed' );
        } catch ( Exception $exception ) {
            $wpdb->query( 'ROLLBACK' );
            return self::error( 'trial_override_failed' );
        } finally {
            self::release_locks( $locks );
        }
    }

    private static function trial_locks( $faluss_id, $fingerprint_hash ) {
        $locks = array( 'faluss_sub_trial_' . substr( hash( 'sha256', $faluss_id ), 0, 32 ) );
        if ( '' !== $fingerprint_hash ) { $locks[] = 'faluss_sub_trial_' . substr( hash( 'sha256', $fingerprint_hash ), 0, 32 ); }
        sort( $locks, SORT_STRING );
        return array_values( array_unique( $locks ) );
    }
    private static function acquire_locks( $locks ) { global $wpdb; $held = array(); foreach ( $locks as $lock ) { if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $lock, 10 ) ) ) { self::release_locks( $held ); return false; } $held[] = $lock; } return true; }
    private static function release_locks( $locks ) { global $wpdb; foreach ( array_reverse( (array) $locks ) as $lock ) { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); } }
    private static function faluss_id( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $value ) ? strtolower( $value ) : ''; }
    private static function bounded( $value, $length ) { $value = is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : ''; return function_exists( 'mb_substr' ) ? mb_substr( trim( $value ), 0, $length ) : substr( trim( $value ), 0, $length ); }
    private static function utc( $value ) { if ( ! is_string( $value ) || '' === trim( $value ) ) { return null; } try { return ( new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ); } catch ( Exception $exception ) { return null; } }
    private static function future( $value, $now ) { return is_string( $value ) && '' !== $value && strcmp( $value, $now ) > 0; }
    private static function audit_state( $trial ) { return is_array( $trial ) ? array_intersect_key( $trial, array_flip( array( 'trial_state', 'eligibility_status', 'activated_at', 'expires_at', 'revoked_at' ) ) ) : array(); }
    private static function error( $code ) { return new WP_Error( $code, __( 'L’essai ne peut pas être modifié.', 'faluss-subscriptions' ) ); }
}
