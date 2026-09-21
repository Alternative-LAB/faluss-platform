<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Closed Hub owner route from its registered catalog to the local Analytics consumer. */
final class Faluss_Portal_Events_Runtime {
    private static $route_registered = false;
    private static $runtime_conflict = false;

    public static function boot() {
        if ( ! function_exists( 'add_action' ) ) {
            return;
        }
        add_action( 'plugins_loaded', array( __CLASS__, 'register_runtime' ), 70 );
        add_action( 'faluss_federation_ready', array( __CLASS__, 'register_runtime' ), 50 );
        if ( function_exists( 'did_action' ) && did_action( 'plugins_loaded' ) ) {
            self::register_runtime();
        }
    }

    /** Register only after the owner catalog and exact Hub Analytics target are ready. */
    public static function register_runtime() {
        if ( self::$runtime_conflict ) {
            return false;
        }
        if ( self::$route_registered ) {
            return true;
        }
        if ( ! self::is_local_hub()
            || ! class_exists( 'Faluss_Events' )
            || ! class_exists( 'Faluss_Events_Engine' )
            || ! class_exists( 'Faluss_Events_Schema' )
            || ! class_exists( 'Faluss_Portal_Events_Catalog' )
            || ! method_exists( 'Faluss_Events', 'resolve_local_catalog' )
            || ! method_exists( 'Faluss_Events_Engine', 'register_delivery_route' )
            || ! method_exists( 'Faluss_Events_Schema', 'is_ready' )
            || ! Faluss_Events_Schema::is_ready() ) {
            return false;
        }
        if ( ! Faluss_Portal_Events_Catalog::register_provider() ) {
            return false;
        }
        $catalog = Faluss_Events::resolve_local_catalog( 'hub-node', 'faluss-hub', 'faluss-hub.events', '1.0.0' );
        if ( is_wp_error( $catalog ) || Faluss_Portal_Events_Catalog::catalog() !== $catalog ) {
            self::$runtime_conflict = true;
            return false;
        }
        if ( ! \Faluss\Platform\Portal\PortalAnalyticsAdapter::registerRuntime() ) {
            $state = \Faluss\Platform\Portal\PortalAnalyticsAdapter::runtimeState();
            if ( true === ( $state['conflict'] ?? false ) ) {
                self::$runtime_conflict = true;
            }
            return false;
        }
        $state = \Faluss\Platform\Portal\PortalAnalyticsAdapter::runtimeState();
        if ( array(
            'local_hub' => true,
            'schema_ready' => true,
            'validators_registered' => true,
            'consumer_registered' => true,
            'conflict' => false,
        ) !== $state ) {
            self::$runtime_conflict = true;
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
            'source_node_id' => 'hub-node',
            'source_app_key' => 'faluss-hub',
            'source_capability_key' => 'faluss-hub.events',
            'catalog_version' => '1.0.0',
            'destination' => 'analytics.events',
            'mode' => 'local',
            'target_node_id' => 'hub-node',
            'target_app_key' => 'faluss-hub',
        );
    }

    public static function runtime_state() {
        return array( 'route_registered' => self::$route_registered, 'conflict' => self::$runtime_conflict );
    }

    private static function is_local_hub() {
        if ( ! class_exists( 'Faluss_Federation_Crypto' ) || ! method_exists( 'Faluss_Federation_Crypto', 'local_identity' ) ) {
            return false;
        }
        try {
            $identity = Faluss_Federation_Crypto::local_identity();
        } catch ( Throwable $throwable ) {
            return false;
        }
        return ! is_wp_error( $identity )
            && 'hub-node' === ( $identity['node_id'] ?? null )
            && 'faluss-hub' === ( $identity['app_key'] ?? null )
            && 'https://faluss.com' === ( $identity['origin'] ?? null );
    }
}
