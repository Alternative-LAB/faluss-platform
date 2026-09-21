<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Closed Me producer validators and the one immutable Federation route to Hub Analytics. */
final class Faluss_Link_Events_Runtime {
    private static $validators_registered = false;
    private static $route_registered = false;
    private static $runtime_conflict = false;

    public static function boot() {
        if ( ! function_exists( 'add_action' ) ) {
            return;
        }
        add_action( 'plugins_loaded', array( __CLASS__, 'register_runtime' ), 60 );
        add_action( 'faluss_federation_ready', array( __CLASS__, 'register_runtime' ), 50 );
        if ( function_exists( 'did_action' ) && did_action( 'plugins_loaded' ) ) {
            self::register_runtime();
        }
    }

    /** Register local validators first, then resume the route only when transport is ready. */
    public static function register_runtime() {
        if ( self::$runtime_conflict ) {
            return false;
        }
        if ( ! self::is_local_me()
            || ! class_exists( 'Faluss_Events' )
            || ! class_exists( 'Faluss_Events_Engine' )
            || ! class_exists( 'Faluss_Events_Schema' )
            || ! class_exists( 'Faluss_Link_Events_Catalog' )
            || ! method_exists( 'Faluss_Events', 'register_payload_validator' )
            || ! method_exists( 'Faluss_Events', 'resolve_local_catalog' )
            || ! method_exists( 'Faluss_Events_Engine', 'register_delivery_route' )
            || ! method_exists( 'Faluss_Events_Schema', 'is_ready' )
            || ! Faluss_Events_Schema::is_ready() ) {
            return false;
        }
        if ( ! Faluss_Link_Events_Catalog::register_provider() ) {
            return false;
        }
        $catalog = Faluss_Events::resolve_local_catalog( 'me-node', 'faluss-me', 'faluss-me.events', '1.0.0' );
        if ( is_wp_error( $catalog ) || Faluss_Link_Events_Catalog::catalog() !== $catalog ) {
            self::$runtime_conflict = true;
            return false;
        }
        if ( ! self::$validators_registered && ! self::register_validators() ) {
            self::$runtime_conflict = true;
            return false;
        }
        if ( self::$route_registered ) {
            return true;
        }
        if ( ! class_exists( 'Faluss_Federation_Crypto' ) || ! method_exists( 'Faluss_Federation_Crypto', 'transport_ready' ) || ! Faluss_Federation_Crypto::transport_ready() ) {
            return false;
        }
        $result = Faluss_Events_Engine::register_delivery_route( self::route_descriptor() );
        if ( is_wp_error( $result ) ) {
            self::$runtime_conflict = true;
            return false;
        }
        self::$route_registered = true;
        return true;
    }

    public static function route_descriptor() {
        return array(
            'source_node_id' => 'me-node',
            'source_app_key' => 'faluss-me',
            'source_capability_key' => 'faluss-me.events',
            'catalog_version' => '1.0.0',
            'destination' => 'analytics.events',
            'mode' => 'federation',
            'target_node_id' => 'hub-node',
            'target_app_key' => 'faluss-hub',
        );
    }

    public static function payload_contracts() {
        return array(
            'faluss-me.card-viewed',
            'faluss-me.link-clicked',
            'faluss-me.collection-opened',
        );
    }

    /** Validate only the producer-owned empty payload and its exact immutable selector/source. */
    public static function validate_payload( $payload, $contract, $event ) {
        if ( array() !== $payload
            || ! self::exact_keys( $contract, array( 'document_type', 'contract_version' ) )
            || '1.0.0' !== $contract['contract_version']
            || ! is_array( $event )
            || ! self::exact_keys( $event['source'] ?? null, array( 'node_id', 'app_key', 'owner', 'capability_key', 'catalog_version' ) ) ) {
            return false;
        }
        $source = $event['source'];
        if ( 'me-node' !== $source['node_id']
            || 'faluss-me' !== $source['app_key']
            || 'faluss-me' !== $source['owner']
            || 'faluss-me.events' !== $source['capability_key']
            || '1.0.0' !== $source['catalog_version'] ) {
            return false;
        }
        $mapping = array(
            'faluss-me.card.viewed' => 'faluss-me.card-viewed',
            'faluss-me.link.clicked' => 'faluss-me.link-clicked',
            'faluss-me.collection.opened' => 'faluss-me.collection-opened',
        );
        $event_type = $event['event_type'] ?? null;
        return is_string( $event_type )
            && isset( $mapping[ $event_type ] )
            && $mapping[ $event_type ] === $contract['document_type']
            && self::exact_keys( $event['payload_contract'] ?? null, array( 'document_type', 'contract_version' ) )
            && $contract['document_type'] === $event['payload_contract']['document_type']
            && $contract['contract_version'] === $event['payload_contract']['contract_version'];
    }

    public static function runtime_state() {
        return array(
            'validators_registered' => self::$validators_registered,
            'route_registered' => self::$route_registered,
            'conflict' => self::$runtime_conflict,
        );
    }

    private static function register_validators() {
        foreach ( self::payload_contracts() as $document_type ) {
            $result = Faluss_Events::register_payload_validator( $document_type, '1.0.0', array( __CLASS__, 'validate_payload' ) );
            if ( is_wp_error( $result ) ) {
                return false;
            }
        }
        self::$validators_registered = true;
        return true;
    }

    private static function is_local_me() {
        if ( ! class_exists( 'Faluss_Federation_Crypto' ) || ! method_exists( 'Faluss_Federation_Crypto', 'local_identity' ) ) {
            return false;
        }
        try {
            $identity = Faluss_Federation_Crypto::local_identity();
        } catch ( Throwable $throwable ) {
            return false;
        }
        return ! is_wp_error( $identity )
            && 'me-node' === ( $identity['node_id'] ?? null )
            && 'faluss-me' === ( $identity['app_key'] ?? null )
            && 'https://faluss.me' === ( $identity['origin'] ?? null );
    }

    private static function exact_keys( $value, $expected ) {
        if ( ! is_array( $value ) ) {
            return false;
        }
        $actual = array_keys( $value );
        sort( $actual, SORT_STRING );
        sort( $expected, SORT_STRING );
        return $actual === $expected;
    }
}
