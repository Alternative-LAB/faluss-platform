<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Persistence boundary. No browser or public route calls this class in SUB-01A. */
final class Faluss_Subscriptions_Repository {
    /** @return array<int,array<string,mixed>> */
    public static function subscriptions_for_faluss_id( $faluss_id ) {
        return self::rows_for_faluss_id( Faluss_Subscriptions_Schema::subscriptions_table(), $faluss_id, 'updated_at DESC,id DESC' );
    }

    /** @return array<string,mixed>|null */
    public static function trial_for_faluss_id( $faluss_id ) {
        global $wpdb;
        if ( ! self::valid_faluss_id( $faluss_id ) ) { return null; }
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Faluss_Subscriptions_Schema::quote_identifier( Faluss_Subscriptions_Schema::trials_table() ) . ' WHERE faluss_id=%s LIMIT 1', strtolower( $faluss_id ) ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /** @return array<int,array<string,mixed>> */
    public static function entitlements_for_faluss_id( $faluss_id ) {
        return self::rows_for_faluss_id( Faluss_Subscriptions_Schema::entitlements_table(), $faluss_id, 'priority DESC,starts_at DESC,id DESC' );
    }

    /** @return array<int,array<string,mixed>> */
    public static function events( $limit = 100, $only_errors = false ) {
        global $wpdb;
        $limit = max( 1, min( 100, (int) $limit ) );
        $table = Faluss_Subscriptions_Schema::quote_identifier( Faluss_Subscriptions_Schema::events_table() );
        $where = $only_errors ? " WHERE processing_status='failed'" : '';
        return (array) $wpdb->get_results( 'SELECT * FROM ' . $table . $where . ' ORDER BY id DESC LIMIT ' . $limit, ARRAY_A );
    }

    /** @return array<string,mixed>|null */
    public static function event_by_id( $event_id ) {
        global $wpdb;
        $event_id = absint( $event_id );
        if ( ! $event_id ) { return null; }
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Faluss_Subscriptions_Schema::quote_identifier( Faluss_Subscriptions_Schema::events_table() ) . ' WHERE id=%d LIMIT 1', $event_id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /** @return array<int,array<string,mixed>> */
    public static function all_subscriptions( $limit = 100 ) {
        global $wpdb;
        $limit = max( 1, min( 250, (int) $limit ) );
        return (array) $wpdb->get_results( 'SELECT * FROM ' . Faluss_Subscriptions_Schema::quote_identifier( Faluss_Subscriptions_Schema::subscriptions_table() ) . ' ORDER BY updated_at ASC,id ASC LIMIT ' . $limit, ARRAY_A );
    }

    /** @return array<string,mixed>|null */
    public static function customer_for_faluss_id( $faluss_id, $provider = 'stripe' ) {
        global $wpdb;
        if ( ! self::valid_faluss_id( $faluss_id ) || ! self::valid_provider( $provider ) ) { return null; }
        $table = Faluss_Subscriptions_Schema::quote_identifier( Faluss_Subscriptions_Schema::customers_table() );
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE faluss_id=%s AND provider=%s LIMIT 1', strtolower( $faluss_id ), strtolower( $provider ) ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public static function customer_for_reference( $reference, $provider = 'stripe' ) {
        global $wpdb;
        $reference = self::bounded( $reference, 191 );
        if ( '' === $reference || ! self::valid_provider( $provider ) ) { return null; }
        $table = Faluss_Subscriptions_Schema::quote_identifier( Faluss_Subscriptions_Schema::customers_table() );
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE provider=%s AND provider_customer_reference=%s LIMIT 1', strtolower( $provider ), $reference ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /** Canonical customer persistence is unique per opaque Faluss ID and provider. */
    public static function record_customer( $faluss_id, $provider, $reference, $mode ) {
        global $wpdb;
        $faluss_id = self::valid_faluss_id( $faluss_id ) ? strtolower( $faluss_id ) : '';
        $provider = strtolower( self::bounded( $provider, 32 ) );
        $reference = self::bounded( $reference, 191 );
        $mode = self::bounded( $mode, 8 );
        if ( ! Faluss_Subscriptions_Schema::is_ready() || '' === $faluss_id || ! self::valid_provider( $provider ) || '' === $reference || ! in_array( $mode, array( 'test', 'live' ), true ) ) { return self::error( 'billing_customer_invalid' ); }
        $existing = self::customer_for_faluss_id( $faluss_id, $provider );
        if ( is_array( $existing ) ) {
            return (string) $existing['provider_customer_reference'] === $reference ? $existing : self::error( 'billing_customer_conflict' );
        }
        $now = gmdate( 'Y-m-d H:i:s' );
        $written = $wpdb->insert( Faluss_Subscriptions_Schema::customers_table(), array( 'faluss_id' => $faluss_id, 'provider' => $provider, 'provider_customer_reference' => $reference, 'mode' => $mode, 'created_at' => $now, 'updated_at' => $now ) );
        if ( false === $written ) {
            $existing = self::customer_for_faluss_id( $faluss_id, $provider );
            return is_array( $existing ) && (string) $existing['provider_customer_reference'] === $reference ? $existing : self::error( 'billing_customer_conflict' );
        }
        return self::customer_for_faluss_id( $faluss_id, $provider );
    }

    /** @return array<string,mixed>|null */
    public static function checkout_for_state( $opaque_state ) {
        global $wpdb;
        if ( ! is_string( $opaque_state ) || '' === $opaque_state ) { return null; }
        $hash = hash( 'sha256', $opaque_state );
        $table = Faluss_Subscriptions_Schema::quote_identifier( Faluss_Subscriptions_Schema::checkout_sessions_table() );
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE return_state_hash=%s LIMIT 1', $hash ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public static function checkout_for_provider_session( $reference ) {
        global $wpdb;
        $reference = self::bounded( $reference, 191 );
        if ( '' === $reference ) { return null; }
        $table = Faluss_Subscriptions_Schema::quote_identifier( Faluss_Subscriptions_Schema::checkout_sessions_table() );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE provider='stripe' AND provider_session_reference=%s LIMIT 1", $reference ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public static function checkout_for_uuid( $checkout_uuid ) {
        global $wpdb;
        $checkout_uuid = self::bounded( $checkout_uuid, 36 );
        if ( ! self::valid_uuid( $checkout_uuid ) ) { return null; }
        $table = Faluss_Subscriptions_Schema::quote_identifier( Faluss_Subscriptions_Schema::checkout_sessions_table() );
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE checkout_uuid=%s LIMIT 1', strtolower( $checkout_uuid ) ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * Link a locally-created Checkout only after trusted server code has
     * revalidated the matching Stripe Customer and subscription.
     *
     * @return array<string,mixed>|WP_Error
     */
    public static function link_checkout_subscription( $checkout_uuid, $faluss_id, $customer_reference, $subscription_reference ) {
        global $wpdb;
        $checkout = self::checkout_for_uuid( $checkout_uuid );
        $faluss_id = self::valid_faluss_id( $faluss_id ) ? strtolower( $faluss_id ) : '';
        $customer_reference = self::bounded( $customer_reference, 191 );
        $subscription_reference = self::bounded( $subscription_reference, 191 );
        if ( ! is_array( $checkout ) || '' === $faluss_id || '' === $customer_reference || '' === $subscription_reference
            || 'stripe' !== ( $checkout['provider'] ?? '' ) || $faluss_id !== ( $checkout['faluss_id'] ?? '' )
            || $customer_reference !== ( $checkout['provider_customer_reference'] ?? '' ) || empty( $checkout['provider_session_reference'] ) ) {
            return self::error( 'checkout_subscription_link_invalid' );
        }
        if ( $subscription_reference === ( $checkout['provider_subscription_reference'] ?? '' ) && in_array( $checkout['session_status'] ?? '', array( 'completed', 'trial_ineligible_pending', 'trial_ineligible' ), true ) ) { return $checkout; }
        if ( ! in_array( $checkout['session_status'] ?? '', array( 'open', 'completed' ), true ) ) { return self::error( 'checkout_subscription_link_invalid' ); }
        if ( ! empty( $checkout['provider_subscription_reference'] ) && $subscription_reference !== ( $checkout['provider_subscription_reference'] ?? '' ) ) {
            return self::error( 'checkout_subscription_link_conflict' );
        }
        $written = $wpdb->update( Faluss_Subscriptions_Schema::checkout_sessions_table(), array( 'provider_subscription_reference' => $subscription_reference, 'session_status' => 'completed', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $checkout['id'] ) );
        return false === $written ? self::error( 'checkout_subscription_link_failed' ) : ( self::checkout_for_uuid( $checkout_uuid ) ?: self::error( 'checkout_subscription_link_failed' ) );
    }

    /**
     * Atomically claims an already-linked Checkout before a worker can ask
     * Stripe to cancel an ineligible trial. The conditional status update is
     * the durable cross-request mutex: exactly one worker can move completed
     * to trial_ineligible_pending for one Checkout/subscription pair.
     *
     * @return array{claimed:bool,checkout:array<string,mixed>}|WP_Error
     */
    public static function claim_checkout_trial_ineligibility( $checkout_uuid, $faluss_id, $customer_reference, $subscription_reference ) {
        global $wpdb;
        $checkout = self::checkout_for_uuid( $checkout_uuid );
        $faluss_id = self::valid_faluss_id( $faluss_id ) ? strtolower( $faluss_id ) : '';
        $customer_reference = self::bounded( $customer_reference, 191 );
        $subscription_reference = self::bounded( $subscription_reference, 191 );
        if ( ! is_array( $checkout ) || '' === $faluss_id || '' === $customer_reference || '' === $subscription_reference
            || 'stripe' !== ( $checkout['provider'] ?? '' ) || $faluss_id !== ( $checkout['faluss_id'] ?? '' )
            || $customer_reference !== ( $checkout['provider_customer_reference'] ?? '' ) || $subscription_reference !== ( $checkout['provider_subscription_reference'] ?? '' ) ) {
            return self::error( 'checkout_trial_ineligible_invalid' );
        }
        $written = $wpdb->update(
            Faluss_Subscriptions_Schema::checkout_sessions_table(),
            array( 'session_status' => 'trial_ineligible_pending', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ),
            array( 'id' => (int) $checkout['id'], 'session_status' => 'completed' )
        );
        if ( false === $written ) { return self::error( 'checkout_trial_ineligible_claim_failed' ); }
        $current = self::checkout_for_uuid( $checkout_uuid );
        if ( ! is_array( $current ) || $faluss_id !== ( $current['faluss_id'] ?? '' ) || $customer_reference !== ( $current['provider_customer_reference'] ?? '' ) || $subscription_reference !== ( $current['provider_subscription_reference'] ?? '' ) ) {
            return self::error( 'checkout_trial_ineligible_claim_failed' );
        }
        if ( 1 === (int) $written && 'trial_ineligible_pending' === ( $current['session_status'] ?? '' ) ) {
            return array( 'claimed' => true, 'checkout' => $current );
        }
        if ( in_array( $current['session_status'] ?? '', array( 'trial_ineligible_pending', 'trial_ineligible' ), true ) ) {
            return array( 'claimed' => false, 'checkout' => $current );
        }
        return self::error( 'checkout_trial_ineligible_claim_failed' );
    }

    /**
     * Finalizes the durable claim after its single Stripe cancellation attempt.
     * A failed or already-completed provider request remains terminal locally:
     * replaying signed webhooks must not issue another remote DELETE.
     *
     * @return array<string,mixed>|WP_Error
     */
    public static function finalize_checkout_trial_ineligibility( $checkout_uuid, $faluss_id, $customer_reference, $subscription_reference ) {
        global $wpdb;
        $checkout = self::checkout_for_uuid( $checkout_uuid );
        $faluss_id = self::valid_faluss_id( $faluss_id ) ? strtolower( $faluss_id ) : '';
        $customer_reference = self::bounded( $customer_reference, 191 );
        $subscription_reference = self::bounded( $subscription_reference, 191 );
        if ( ! is_array( $checkout ) || '' === $faluss_id || '' === $customer_reference || '' === $subscription_reference
            || 'stripe' !== ( $checkout['provider'] ?? '' ) || $faluss_id !== ( $checkout['faluss_id'] ?? '' )
            || $customer_reference !== ( $checkout['provider_customer_reference'] ?? '' ) || $subscription_reference !== ( $checkout['provider_subscription_reference'] ?? '' ) ) {
            return self::error( 'checkout_trial_ineligible_invalid' );
        }
        if ( 'trial_ineligible' === ( $checkout['session_status'] ?? '' ) ) { return $checkout; }
        if ( 'trial_ineligible_pending' !== ( $checkout['session_status'] ?? '' ) ) { return self::error( 'checkout_trial_ineligible_invalid' ); }
        $written = $wpdb->update(
            Faluss_Subscriptions_Schema::checkout_sessions_table(),
            array( 'session_status' => 'trial_ineligible', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ),
            array( 'id' => (int) $checkout['id'], 'session_status' => 'trial_ineligible_pending' )
        );
        if ( false === $written ) { return self::error( 'checkout_trial_ineligible_failed' ); }
        $current = self::checkout_for_uuid( $checkout_uuid );
        if ( is_array( $current ) && 'trial_ineligible' === ( $current['session_status'] ?? '' ) ) { return $current; }
        return self::error( 'checkout_trial_ineligible_failed' );
    }

    /**
     * Locates only a locally-created, still-open Stripe Checkout for a known
     * Customer. This enables a later signed customer/payment event to resume
     * verification without trusting the event snapshot or a browser return.
     *
     * @return array<string,mixed>|null
     */
    public static function open_checkout_for_customer_reference( $reference ) {
        global $wpdb;
        $reference = self::bounded( $reference, 191 );
        if ( '' === $reference ) { return null; }
        $table = Faluss_Subscriptions_Schema::quote_identifier( Faluss_Subscriptions_Schema::checkout_sessions_table() );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE provider='stripe' AND provider_customer_reference=%s AND session_status='open' ORDER BY id DESC LIMIT 1", $reference ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /** @return array<string,mixed>|WP_Error */
    public static function create_checkout( $record ) {
        global $wpdb;
        if ( ! is_array( $record ) || ! self::valid_faluss_id( $record['faluss_id'] ?? '' ) || ! Faluss_Subscriptions_Catalog::valid_period( Faluss_Subscriptions_Catalog::PRO, $record['billing_interval'] ?? '' ) ) { return self::error( 'checkout_invalid' ); }
        $state = $record['opaque_state'] ?? '';
        $key = $record['idempotency_key'] ?? '';
        if ( ! is_string( $state ) || strlen( $state ) < 32 || ! is_string( $key ) || strlen( $key ) < 32 ) { return self::error( 'checkout_invalid' ); }
        $now = gmdate( 'Y-m-d H:i:s' );
        $data = array(
            'checkout_uuid' => wp_generate_uuid4(), 'faluss_id' => strtolower( $record['faluss_id'] ), 'provider' => 'stripe', 'plan_key' => Faluss_Subscriptions_Catalog::PRO,
            'billing_interval' => $record['billing_interval'], 'provider_customer_reference' => self::bounded( $record['provider_customer_reference'] ?? '', 191 ), 'provider_session_reference' => null, 'provider_subscription_reference' => null,
            'return_state_hash' => hash( 'sha256', $state ), 'idempotency_key_hash' => hash( 'sha256', $key ), 'session_status' => 'creating', 'expires_at' => null, 'created_at' => $now, 'updated_at' => $now,
        );
        if ( '' === $data['provider_customer_reference'] ) { return self::error( 'checkout_invalid' ); }
        $written = $wpdb->insert( Faluss_Subscriptions_Schema::checkout_sessions_table(), $data );
        if ( false === $written ) { return self::error( 'checkout_conflict' ); }
        return self::checkout_for_state( $state ) ?: self::error( 'checkout_record_failed' );
    }

    /** @return array<string,mixed>|WP_Error */
    public static function mark_checkout_provider_session( $id, $session_reference, $expires_at = null ) {
        global $wpdb;
        $id = absint( $id ); $session_reference = self::bounded( $session_reference, 191 );
        if ( ! $id || '' === $session_reference ) { return self::error( 'checkout_invalid' ); }
        $written = $wpdb->update( Faluss_Subscriptions_Schema::checkout_sessions_table(), array( 'provider_session_reference' => $session_reference, 'session_status' => 'open', 'expires_at' => self::utc_or_null( $expires_at ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $id ) );
        return false === $written ? self::error( 'checkout_record_failed' ) : self::checkout_for_provider_session( $session_reference );
    }

    public static function update_event_status( $event_id, $status, $error_code = null ) {
        global $wpdb;
        $event_id = absint( $event_id ); $status = self::bounded( $status, 24 );
        if ( ! $event_id || ! in_array( $status, array( 'received', 'processing', 'processed', 'ignored', 'failed' ), true ) ) { return false; }
        $data = array( 'processing_status' => $status, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) );
        if ( in_array( $status, array( 'processed', 'ignored' ), true ) ) { $data['processed_at'] = gmdate( 'Y-m-d H:i:s' ); $data['last_error'] = null; }
        if ( 'failed' === $status ) { $data['last_error'] = self::nullable( $error_code, 191 ); }
        return false !== $wpdb->query( $wpdb->prepare( 'UPDATE ' . Faluss_Subscriptions_Schema::quote_identifier( Faluss_Subscriptions_Schema::events_table() ) . ' SET processing_status=%s,attempt_count=attempt_count+1,processed_at=%s,last_error=%s,updated_at=%s WHERE id=%d', $data['processing_status'], $data['processed_at'] ?? null, $data['last_error'] ?? null, $data['updated_at'], $event_id ) );
    }

    /**
     * Idempotence foundation for SUB-01B. Only a payload hash is stored; raw provider data never is.
     * @return array<string,mixed>|WP_Error
     */
    public static function record_event( $provider, $provider_event_id, $event_type, $payload_hash ) {
        global $wpdb;
        if ( ! Faluss_Subscriptions_Schema::is_ready() || ! self::valid_provider( $provider ) || ! self::bounded( $provider_event_id, 191 ) || ! self::bounded( $event_type, 80 ) || ! self::valid_hash( $payload_hash ) ) {
            return self::error( 'event_invalid' );
        }
        $provider = strtolower( self::bounded( $provider, 32 ) );
        $provider_event_id = self::bounded( $provider_event_id, 191 );
        $existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Faluss_Subscriptions_Schema::quote_identifier( Faluss_Subscriptions_Schema::events_table() ) . ' WHERE provider=%s AND provider_event_id=%s LIMIT 1', $provider, $provider_event_id ), ARRAY_A );
        if ( is_array( $existing ) ) {
            return array( 'event' => $existing, 'idempotent' => true );
        }
        $now = gmdate( 'Y-m-d H:i:s' );
        $written = $wpdb->insert(
            Faluss_Subscriptions_Schema::events_table(),
            array( 'provider' => $provider, 'provider_event_id' => $provider_event_id, 'event_type' => self::bounded( $event_type, 80 ), 'processing_status' => 'received', 'attempt_count' => 0, 'payload_hash' => strtolower( $payload_hash ), 'received_at' => $now, 'created_at' => $now, 'updated_at' => $now ),
            array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
        );
        if ( false === $written ) {
            $existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Faluss_Subscriptions_Schema::quote_identifier( Faluss_Subscriptions_Schema::events_table() ) . ' WHERE provider=%s AND provider_event_id=%s LIMIT 1', $provider, $provider_event_id ), ARRAY_A );
            return is_array( $existing ) ? array( 'event' => $existing, 'idempotent' => true ) : self::error( 'event_record_failed' );
        }
        $event = array( 'id' => (int) $wpdb->insert_id, 'provider' => $provider, 'provider_event_id' => $provider_event_id, 'processing_status' => 'received' );
        Faluss_Subscriptions_Audit::record( 0, 'provider_event_recorded', null, 'provider', array(), array( 'provider' => $provider, 'event_type' => self::bounded( $event_type, 80 ), 'processing_status' => 'received' ), null );
        return array( 'event' => $event, 'idempotent' => false );
    }

    /** Future trusted payment adapter only; administration must never call this. */
    public static function upsert_provider_subscription( $record ) {
        global $wpdb;
        if ( ! Faluss_Subscriptions_Schema::is_ready() || ! is_array( $record ) || ! self::valid_faluss_id( $record['faluss_id'] ?? '' ) || ! self::valid_provider( $record['provider'] ?? '' ) || ! Faluss_Subscriptions_Catalog::valid_plan( $record['plan_key'] ?? '' ) || ! in_array( $record['normalized_state'] ?? '', Faluss_Subscriptions_Catalog::normalized_states(), true ) ) {
            return self::error( 'subscription_invalid' );
        }
        $interval = $record['billing_interval'] ?? null;
        if ( Faluss_Subscriptions_Catalog::PRO === $record['plan_key'] && ! Faluss_Subscriptions_Catalog::valid_period( $record['plan_key'], $interval ) ) {
            return self::error( 'subscription_interval_invalid' );
        }
        $provider = strtolower( self::bounded( $record['provider'], 32 ) );
        $reference = self::bounded( $record['provider_subscription_reference'] ?? '', 191 );
        if ( '' === $reference ) { return self::error( 'subscription_reference_missing' ); }
        $now = gmdate( 'Y-m-d H:i:s' );
        $existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Faluss_Subscriptions_Schema::quote_identifier( Faluss_Subscriptions_Schema::subscriptions_table() ) . ' WHERE provider=%s AND provider_subscription_reference=%s LIMIT 1', $provider, $reference ), ARRAY_A );
        $period_ends_at = self::utc_or_null( $record['period_ends_at'] ?? null );
        $grace_started_at = self::utc_or_null( $record['grace_started_at'] ?? null );
        if ( 'past_due' === $record['normalized_state'] && is_array( $existing ) && 'past_due' === ( $existing['normalized_state'] ?? '' ) && ! empty( $existing['grace_started_at'] ) ) {
            $grace_started_at = $existing['grace_started_at'];
        }
        $grace_ends_at = 'past_due' === $record['normalized_state'] ? self::capped_grace_end( $grace_started_at, self::utc_or_null( $record['grace_ends_at'] ?? null ) ) : self::utc_or_null( $record['grace_ends_at'] ?? null );
        if ( 'past_due' === $record['normalized_state'] && ! $grace_ends_at ) { return self::error( 'subscription_grace_invalid' ); }
        $data = array(
            'faluss_id' => strtolower( $record['faluss_id'] ), 'provider' => $provider,
            'provider_customer_reference' => self::nullable( $record['provider_customer_reference'] ?? null, 191 ), 'provider_subscription_reference' => $reference,
            'plan_key' => $record['plan_key'], 'billing_interval' => $interval, 'provider_status' => self::nullable( $record['provider_status'] ?? null, 32 ),
            'normalized_state' => $record['normalized_state'], 'trial_starts_at' => self::utc_or_null( $record['trial_starts_at'] ?? null ), 'trial_ends_at' => self::utc_or_null( $record['trial_ends_at'] ?? null ),
            'period_starts_at' => self::utc_or_null( $record['period_starts_at'] ?? null ), 'period_ends_at' => $period_ends_at, 'grace_started_at' => $grace_started_at, 'grace_ends_at' => $grace_ends_at,
            'cancel_at_period_end' => empty( $record['cancel_at_period_end'] ) ? 0 : 1, 'cancelled_at' => self::utc_or_null( $record['cancelled_at'] ?? null ), 'ended_at' => self::utc_or_null( $record['ended_at'] ?? null ), 'last_synced_at' => $now, 'updated_at' => $now,
        );
        if ( is_array( $existing ) ) {
            $data['version'] = (int) $existing['version'] + 1;
            $written = $wpdb->update( Faluss_Subscriptions_Schema::subscriptions_table(), $data, array( 'id' => (int) $existing['id'], 'version' => (int) $existing['version'] ) );
            if ( false === $written || 0 === $written ) { return self::error( 'subscription_conflict' ); }
            $saved = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Faluss_Subscriptions_Schema::quote_identifier( Faluss_Subscriptions_Schema::subscriptions_table() ) . ' WHERE id=%d', (int) $existing['id'] ), ARRAY_A );
            Faluss_Subscriptions_Audit::record( 0, 'provider_subscription_updated', $record['faluss_id'], 'subscription', self::subscription_audit_state( $existing ), self::subscription_audit_state( $saved ), null );
            return $saved;
        }
        $data = array_merge( array( 'subscription_uuid' => wp_generate_uuid4(), 'version' => 1, 'created_at' => $now ), $data );
        $written = $wpdb->insert( Faluss_Subscriptions_Schema::subscriptions_table(), $data );
        if ( false === $written ) { return self::error( 'subscription_record_failed' ); }
        $saved = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Faluss_Subscriptions_Schema::quote_identifier( Faluss_Subscriptions_Schema::subscriptions_table() ) . ' WHERE id=%d', (int) $wpdb->insert_id ), ARRAY_A );
        Faluss_Subscriptions_Audit::record( 0, 'provider_subscription_recorded', $record['faluss_id'], 'subscription', array(), self::subscription_audit_state( $saved ), null );
        return $saved;
    }

    private static function rows_for_faluss_id( $table, $faluss_id, $order ) {
        global $wpdb;
        if ( ! self::valid_faluss_id( $faluss_id ) ) { return array(); }
        return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Faluss_Subscriptions_Schema::quote_identifier( $table ) . ' WHERE faluss_id=%s ORDER BY ' . $order, strtolower( $faluss_id ) ), ARRAY_A );
    }
    private static function valid_faluss_id( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $value ); }
    private static function valid_uuid( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $value ); }
    private static function valid_provider( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-z][a-z0-9_-]{1,31}$/i', $value ); }
    private static function valid_hash( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/i', $value ); }
    private static function bounded( $value, $length ) { $value = is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : ''; return function_exists( 'mb_substr' ) ? mb_substr( trim( $value ), 0, $length ) : substr( trim( $value ), 0, $length ); }
    private static function nullable( $value, $length ) { $value = self::bounded( $value, $length ); return '' === $value ? null : $value; }
    /** The grace period is a server rule, not an arbitrary provider value. */
    private static function capped_grace_end( $grace_started_at, $requested_grace_end ) {
        if ( ! $grace_started_at ) { return null; }
        $maximum = gmdate( 'Y-m-d H:i:s', strtotime( '+' . Faluss_Subscriptions_Catalog::GRACE_DAYS . ' days', strtotime( $grace_started_at ) ) );
        return $requested_grace_end && strcmp( $requested_grace_end, $maximum ) < 0 ? $requested_grace_end : $maximum;
    }
    private static function subscription_audit_state( $record ) { return is_array( $record ) ? array_intersect_key( $record, array_flip( array( 'subscription_uuid', 'plan_key', 'billing_interval', 'normalized_state', 'trial_starts_at', 'trial_ends_at', 'period_starts_at', 'period_ends_at', 'grace_started_at', 'grace_ends_at', 'cancel_at_period_end', 'cancelled_at', 'ended_at', 'version' ) ) ) : array(); }
    private static function utc_or_null( $value ) { if ( ! is_string( $value ) || '' === trim( $value ) ) { return null; } try { return ( new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ); } catch ( Exception $exception ) { return null; } }
    private static function error( $code ) { return new WP_Error( $code, __( 'L’enregistrement central ne peut pas être modifié.', 'faluss-subscriptions' ) ); }
}
