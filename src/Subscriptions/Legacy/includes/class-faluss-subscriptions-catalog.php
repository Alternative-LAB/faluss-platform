<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Server-owned, versioned catalogue. Prices are never administratively editable. */
final class Faluss_Subscriptions_Catalog {
    const VERSION = '1';
    const FREE = 'free';
    const PRO = 'pro';
    const PRO_ENTITLEMENT = 'faluss.pro';
    const TRIAL_DAYS = 15;
    const GRACE_DAYS = 7;

    /** @return array<string,array<string,mixed>> */
    public static function plans() {
        return array(
            self::FREE => array(
                'key' => self::FREE,
                'public_name' => 'Faluss Gratuit',
                'currency' => 'EUR',
                'periods' => array(),
                'amount_cents' => 0,
                'trial_days' => 0,
                'card_required' => false,
                'auto_renew' => false,
                'commercially_active' => true,
            ),
            self::PRO => array(
                'key' => self::PRO,
                'public_name' => 'Faluss Max',
                'currency' => 'EUR',
                'periods' => array(
                    'monthly' => array( 'amount_cents' => 999 ),
                    'annual'  => array( 'amount_cents' => 9900 ),
                ),
                'trial_days' => self::TRIAL_DAYS,
                'card_required' => true,
                'auto_renew' => true,
                // SUB-01B is testable through the protected administrator sandbox;
                // no public sale surface is enabled before SUB-01C.
                'commercially_active' => true,
            ),
        );
    }

    /** @return array<string,mixed>|null */
    public static function plan( $key ) {
        $plans = self::plans();
        return isset( $plans[ $key ] ) ? $plans[ $key ] : null;
    }

    public static function valid_plan( $key ) {
        return is_string( $key ) && null !== self::plan( $key );
    }

    public static function valid_period( $plan_key, $period ) {
        $plan = self::plan( $plan_key );
        return is_array( $plan ) && isset( $plan['periods'][ $period ] );
    }

    /** @return array<int,string> */
    public static function normalized_states() {
        return array( 'free', 'trialing', 'active', 'canceling', 'past_due', 'suspended', 'expired', 'comped', 'revoked' );
    }

    /** @return array<int,string> */
    public static function entitlement_sources() {
        return array( 'free', 'trial', 'subscription', 'admin_grant', 'permanent_purchase', 'cosmetic_ownership', 'compliance_override' );
    }
}
