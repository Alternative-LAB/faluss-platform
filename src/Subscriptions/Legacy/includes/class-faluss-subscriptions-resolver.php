<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Deterministic, read-time resolution. No cron is required for expiration. */
final class Faluss_Subscriptions_Resolver {
    const CALCULATION_VERSION = '2';

    /** @return array<string,mixed> */
    public static function resolve_for_faluss_id( $faluss_id, $now = null ) {
        if ( ! self::valid_faluss_id( $faluss_id ) || ! Faluss_Subscriptions_Schema::is_ready() ) {
            return self::free_decision( 'identity_or_schema_unavailable' );
        }
        $trial = Faluss_Subscriptions_Repository::trial_for_faluss_id( $faluss_id );
        return self::resolve_records(
            Faluss_Subscriptions_Repository::subscriptions_for_faluss_id( $faluss_id ),
            is_array( $trial ) ? array( $trial ) : array(),
            Faluss_Subscriptions_Repository::entitlements_for_faluss_id( $faluss_id ),
            $now
        );
    }

    /**
     * Pure decision function, also used by contracts and future trusted adapters.
     * @param array<int,array<string,mixed>> $subscriptions
     * @param array<int,array<string,mixed>> $trials
     * @param array<int,array<string,mixed>> $entitlements
     * @return array<string,mixed>
     */
    public static function resolve_records( $subscriptions, $trials, $entitlements, $now = null ) {
        $now = self::utc( $now ) ?: gmdate( 'Y-m-d H:i:s' );
        $reasons = array();
        $sources = array();
        foreach ( (array) $entitlements as $entitlement ) {
            if ( ! self::effective_row( $entitlement, $now ) || Faluss_Subscriptions_Catalog::PRO_ENTITLEMENT !== ( $entitlement['entitlement_key'] ?? '' ) ) { continue; }
            if ( 'compliance_override' === ( $entitlement['source'] ?? '' ) && in_array( $entitlement['entitlement_value'] ?? '', array( 'deny', 'revoked' ), true ) ) {
                return self::free_decision( 'compliance_override', array( self::source( $entitlement, 'revoked' ) ), 'revoked' );
            }
        }
        $candidates = array();
        foreach ( (array) $entitlements as $entitlement ) {
            if ( ! self::effective_row( $entitlement, $now ) || Faluss_Subscriptions_Catalog::PRO_ENTITLEMENT !== ( $entitlement['entitlement_key'] ?? '' ) || 'pro' !== ( $entitlement['entitlement_value'] ?? '' ) ) { continue; }
            $priority = max( 0, (int) ( $entitlement['priority'] ?? 0 ) );
            $candidates[] = array( 'priority' => $priority, 'state' => 'admin_grant' === ( $entitlement['source'] ?? '' ) ? 'comped' : 'active', 'expires_at' => $entitlement['expires_at'] ?? null, 'source' => self::source( $entitlement, 'pro' ), 'reason' => 'entitlement_active' );
        }
        foreach ( (array) $trials as $trial ) {
            if ( 'trialing' === ( $trial['trial_state'] ?? '' ) && self::future( $trial['expires_at'] ?? null, $now ) && empty( $trial['revoked_at'] ) && ! self::trial_canceled_by_provider( $subscriptions, $trial ) ) {
                $candidates[] = array( 'priority' => 100, 'state' => 'trialing', 'expires_at' => $trial['expires_at'], 'source' => array( 'source' => 'trial', 'reference' => $trial['trial_uuid'] ?? '', 'expires_at' => $trial['expires_at'] ), 'reason' => 'trial_valid' );
            } elseif ( ! empty( $trial['expires_at'] ) && ! self::future( $trial['expires_at'], $now ) ) {
                $reasons[] = 'trial_expired';
            }
        }
        foreach ( (array) $subscriptions as $subscription ) {
            $state = $subscription['normalized_state'] ?? '';
            $source = array( 'source' => 'subscription', 'reference' => $subscription['subscription_uuid'] ?? '', 'expires_at' => null );
            $verified_trial = self::verified_trial_for_subscription( $trials, $subscription, $now );
            if ( 'trialing' === $state && is_array( $verified_trial ) ) {
                $source['expires_at'] = $verified_trial['expires_at'];
                $candidates[] = array( 'priority' => 200, 'state' => 'trialing', 'expires_at' => $verified_trial['expires_at'], 'source' => $source, 'reason' => 'subscription_trialing' );
            } elseif ( in_array( $state, array( 'active', 'canceling' ), true ) && self::future( $subscription['period_ends_at'] ?? null, $now ) ) {
                $source['expires_at'] = $subscription['period_ends_at'];
                $candidates[] = array( 'priority' => 200, 'state' => $state, 'expires_at' => $subscription['period_ends_at'], 'source' => $source, 'reason' => 'subscription_' . $state );
            } elseif ( 'past_due' === $state && self::future( self::past_due_grace_end( $subscription ), $now ) ) {
                $source['expires_at'] = self::past_due_grace_end( $subscription );
                $candidates[] = array( 'priority' => 200, 'state' => 'past_due', 'expires_at' => self::past_due_grace_end( $subscription ), 'source' => $source, 'reason' => 'subscription_grace_valid' );
            } elseif ( 'past_due' === $state ) {
                $reasons[] = 'grace_expired';
            } elseif ( in_array( $state, array( 'expired', 'suspended', 'revoked' ), true ) || ! self::future( $subscription['period_ends_at'] ?? null, $now ) ) {
                $reasons[] = 'subscription_not_effective';
            }
        }
        if ( ! $candidates ) {
            return self::free_decision( $reasons ? $reasons[0] : 'no_effective_right' );
        }
        usort( $candidates, static function( $left, $right ) {
            $priority = (int) $right['priority'] <=> (int) $left['priority'];
            if ( 0 !== $priority ) { return $priority; }
            return strcmp( (string) ( $right['expires_at'] ?? '' ), (string) ( $left['expires_at'] ?? '' ) );
        } );
        $selected = $candidates[0];
        return array(
            'calculation_version' => self::CALCULATION_VERSION,
            'level' => 'pro',
            'state' => $selected['state'],
            // This is the subscription level only; no Faluss Link feature is enabled by SUB-01A.
            'entitlements' => array( Faluss_Subscriptions_Catalog::PRO_ENTITLEMENT => true ),
            'effective_sources' => array( $selected['source'] ),
            'expires_at' => $selected['expires_at'],
            'reason' => $selected['reason'],
        );
    }

    private static function free_decision( $reason, $sources = array(), $state = 'free' ) {
        return array( 'calculation_version' => self::CALCULATION_VERSION, 'level' => 'free', 'state' => $state, 'entitlements' => array( Faluss_Subscriptions_Catalog::PRO_ENTITLEMENT => false ), 'effective_sources' => $sources, 'expires_at' => null, 'reason' => $reason );
    }
    private static function effective_row( $row, $now ) { return is_array( $row ) && 'active' === ( $row['status'] ?? '' ) && self::started( $row['starts_at'] ?? null, $now ) && ! ( isset( $row['expires_at'] ) && null !== $row['expires_at'] && '' !== $row['expires_at'] && ! self::future( $row['expires_at'], $now ) ); }
    private static function source( $row, $kind ) { return array( 'source' => $row['source'] ?? $kind, 'reference' => $row['source_reference'] ?? ( $row['entitlement_uuid'] ?? '' ), 'expires_at' => $row['expires_at'] ?? null ); }
    /** Never retain Pro longer than the central seven-day grace window, even for imported records. */
    private static function past_due_grace_end( $subscription ) {
        // SUB-01B anchors grace on the first payment failure. Legacy SUB-01A
        // records without the new field fall back to their historical period end.
        $started = $subscription['grace_started_at'] ?? ( $subscription['period_ends_at'] ?? null );
        if ( ! is_string( $started ) || '' === $started ) { return null; }
        $maximum = gmdate( 'Y-m-d H:i:s', strtotime( '+' . Faluss_Subscriptions_Catalog::GRACE_DAYS . ' days', strtotime( $started ) ) );
        $requested = $subscription['grace_ends_at'] ?? null;
        return is_string( $requested ) && '' !== $requested && strcmp( $requested, $maximum ) < 0 ? $requested : $maximum;
    }
    /** A trialing subscription is effective only with its matching verified card trial. */
    private static function verified_trial_for_subscription( $trials, $subscription, $now ) {
        $reference = $subscription['provider_subscription_reference'] ?? '';
        if ( ! is_string( $reference ) || '' === $reference ) { return null; }
        foreach ( (array) $trials as $trial ) {
            if ( is_array( $trial ) && 'trialing' === ( $trial['trial_state'] ?? '' ) && empty( $trial['revoked_at'] ) && self::future( $trial['expires_at'] ?? null, $now ) && $reference === ( $trial['verification_reference'] ?? '' ) ) { return $trial; }
        }
        return null;
    }
    /** A canceled Stripe subscription invalidates only its matching trial. */
    private static function trial_canceled_by_provider( $subscriptions, $trial ) {
        $reference = $trial['verification_reference'] ?? '';
        if ( ! is_string( $reference ) || '' === $reference ) { return false; }
        foreach ( (array) $subscriptions as $subscription ) {
            if ( is_array( $subscription ) && 'stripe' === ( $subscription['provider'] ?? '' ) && 'canceled' === ( $subscription['provider_status'] ?? '' ) && $reference === ( $subscription['provider_subscription_reference'] ?? '' ) ) { return true; }
        }
        return false;
    }
    private static function started( $value, $now ) { return is_string( $value ) && '' !== $value && strcmp( $value, $now ) <= 0; }
    private static function future( $value, $now ) { return is_string( $value ) && '' !== $value && strcmp( $value, $now ) > 0; }
    private static function utc( $value ) { if ( ! is_string( $value ) || '' === trim( $value ) ) { return null; } try { return ( new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ); } catch ( Exception $exception ) { return null; } }
    private static function valid_faluss_id( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $value ); }
}
