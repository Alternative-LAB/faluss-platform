<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Local Hub ownership adapter for the trusted apps.registry engine. */
final class Faluss_Portal_Apps_Registry_Adapter {
    private static $relationship_cache = array();
    private static $capability_cache = array();

    public static function boot() {
        if ( did_action( 'plugins_loaded' ) && ! doing_action( 'plugins_loaded' ) ) {
            self::register_source();
            return;
        }
        add_action( 'plugins_loaded', array( __CLASS__, 'register_source' ), 40 );
    }

    /** @return true|false|WP_Error */
    public static function register_source() {
        if ( ! class_exists( 'Faluss_Apps_Registry' ) || ! method_exists( 'Faluss_Apps_Registry', 'register_source' ) || ! class_exists( 'Faluss_Portal_Manifest' ) ) {
            return false;
        }
        return Faluss_Apps_Registry::register_source( array(
            'app_key' => 'faluss-hub',
            'source_type' => 'local_owner',
            'document_type' => 'faluss.app-capability-manifest',
            'contract_version' => '1.0.0',
            'requested_manifest_version' => '1.0.0',
            'owner' => 'faluss-hub',
            'relationship_resolver' => array( __CLASS__, 'relationship' ),
            'capability_resolver' => array( __CLASS__, 'capability' ),
            'manifest_resolver' => array( 'Faluss_Portal_Manifest', 'manifest' ),
        ) );
    }

    /** @return string|WP_Error */
    public static function relationship( $faluss_id ) {
        if ( ! self::is_uuid_v4( $faluss_id ) ) {
            return new WP_Error( 'faluss_apps_registry_unavailable' );
        }
        if ( ! array_key_exists( $faluss_id, self::$relationship_cache ) ) {
            self::$relationship_cache[ $faluss_id ] = 'active';
        }
        return self::$relationship_cache[ $faluss_id ];
    }

    /** @return array<string,mixed>|WP_Error */
    public static function capability( $faluss_id, $capability_key ) {
        if ( ! self::is_uuid_v4( $faluss_id ) || 'faluss-hub.daily-reward' !== $capability_key || ! class_exists( 'Faluss_Portal' ) || ! method_exists( 'Faluss_Portal', 'apps_registry_daily_status' ) ) {
            return new WP_Error( 'faluss_apps_registry_unavailable' );
        }
        $cache_key = $faluss_id . "\x1F" . $capability_key;
        if ( array_key_exists( $cache_key, self::$capability_cache ) ) {
            return self::$capability_cache[ $cache_key ];
        }
        $daily = Faluss_Portal::apps_registry_daily_status( $faluss_id );
        $resolved = self::daily_capability( $daily );
        self::$capability_cache[ $cache_key ] = $resolved;
        return $resolved;
    }

    /** @return array<string,mixed>|WP_Error */
    private static function daily_capability( $daily ) {
        $status = is_array( $daily ) && is_string( $daily['status'] ?? null ) ? $daily['status'] : '';
        if ( in_array( $status, array( 'unavailable', 'not_supported' ), true ) && array( 'status' ) === array_keys( $daily ) ) {
            return self::capability_result( 'not_supported' === $status ? 'not_supported' : 'temporarily_unavailable', $status, array() );
        }
        if ( ! in_array( $status, array( 'claimable', 'claimed' ), true ) || ! self::valid_daily_document( $daily, $status ) ) {
            return new WP_Error( 'faluss_apps_registry_unavailable' );
        }
        $actions = 'claimable' === $status ? array( 'faluss-hub.daily-reward.claim' ) : array();
        return self::capability_result( 'enabled', 'available', $actions, $daily['freshness'] );
    }

    private static function capability_result( $state, $status, $actions, $freshness = null ) {
        if ( null === $freshness ) {
            $freshness = array( 'generated_at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'max_age_seconds' => 60, 'stale_behavior' => 'refresh_from_owner' );
        }
        return array(
            'capability_key' => 'faluss-hub.daily-reward',
            'state' => $state,
            'specialized_read_model' => array(
                'status' => $status,
                'source' => array( 'engine' => 'faluss-hub', 'read_model' => 'hub-daily-reward', 'source_version' => '1.0.0' ),
                'freshness' => $freshness,
            ),
            'allowed_action_keys' => $actions,
        );
    }

    private static function valid_daily_document( $daily, $status ) {
        if ( ! self::exact_keys( $daily, array( 'app_key', 'reward_key', 'owner', 'status', 'reward', 'period', 'delegation', 'freshness', 'source', 'compatibility' ) ) || 'hub' !== $daily['app_key'] || 'hub.daily_accrual' !== $daily['reward_key'] || 'faluss-hub' !== $daily['owner'] ) {
            return false;
        }
        if ( ! self::exact_keys( $daily['reward'], array( 'amount_pf', 'economic_class', 'label' ) )
            || ! is_int( $daily['reward']['amount_pf'] )
            || $daily['reward']['amount_pf'] < 1
            || ! is_string( $daily['reward']['economic_class'] )
            || $daily['reward']['label'] !== $daily['reward']['amount_pf'] . ' PF'
            || ! self::exact_keys( $daily['period'], array( 'type', 'timezone', 'logical_date' ) )
            || 'daily' !== $daily['period']['type']
            || 'Europe/Paris' !== $daily['period']['timezone']
            || ! is_string( $daily['period']['logical_date'] )
            || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $daily['period']['logical_date'] ) ) {
            return false;
        }
        $claimable = 'claimable' === $status;
        if ( ! self::exact_keys( $daily['delegation'], array( 'type', 'action_key', 'target' ) ) || ( $claimable ? 'owner_claim' : 'none' ) !== $daily['delegation']['type'] || ( $claimable ? 'claim-hub-daily' : null ) !== $daily['delegation']['action_key'] || ( $claimable ? 'hub.daily_accrual' : null ) !== $daily['delegation']['target'] ) {
            return false;
        }
        return self::exact_keys( $daily['source'], array( 'type', 'engine', 'read_model', 'source_version' ) )
            && 'owner_daily_reward_read_model' === $daily['source']['type']
            && 'faluss-hub' === $daily['source']['engine']
            && 'hub-daily-reward' === $daily['source']['read_model']
            && '1.0.0' === $daily['source']['source_version']
            && self::valid_freshness( $daily['freshness'] )
            && self::exact_keys( $daily['compatibility'], array( 'minimum_consumer_version', 'backward_compatible_with', 'deprecated', 'sunset_at', 'replacement_reward_key' ) )
            && '1.0.0' === $daily['compatibility']['minimum_consumer_version']
            && array( '1.0.0' ) === $daily['compatibility']['backward_compatible_with']
            && false === $daily['compatibility']['deprecated']
            && null === $daily['compatibility']['sunset_at']
            && null === $daily['compatibility']['replacement_reward_key'];
    }

    private static function valid_freshness( $freshness ) {
        if ( ! self::exact_keys( $freshness, array( 'generated_at', 'max_age_seconds', 'stale_behavior' ) ) || ! is_string( $freshness['generated_at'] ) || ! is_int( $freshness['max_age_seconds'] ) || $freshness['max_age_seconds'] < 1 || $freshness['max_age_seconds'] > 86400 || ! in_array( $freshness['stale_behavior'], array( 'omit', 'refresh_from_owner' ), true ) ) {
            return false;
        }
        $generated = strtotime( $freshness['generated_at'] );
        return false !== $generated && $generated <= time() + 60 && $generated + $freshness['max_age_seconds'] >= time();
    }

    private static function is_uuid_v4( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value );
    }

    private static function exact_keys( $value, $keys ) {
        if ( ! is_array( $value ) ) {
            return false;
        }
        $actual = array_keys( $value );
        sort( $actual, SORT_STRING );
        sort( $keys, SORT_STRING );
        return $actual === $keys;
    }
}
