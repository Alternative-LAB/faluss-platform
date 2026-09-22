<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/** Exact Hub-owned AN-01 catalog provider; registration never accepts or emits an event. */
final class Faluss_Portal_Events_Catalog {
    private static $registered = false;
    private static $conflict = false;

    public static function boot() {
        if ( ! function_exists( 'add_action' ) ) { return; }
        add_action( 'faluss_federation_ready', array( __CLASS__, 'register_provider' ), 30 );
        if ( function_exists( 'did_action' ) && did_action( 'plugins_loaded' ) && ! doing_action( 'plugins_loaded' ) ) {
            self::register_provider();
            return;
        }
        add_action( 'plugins_loaded', array( __CLASS__, 'register_provider' ), 50 );
    }

    public static function register_provider() {
        if ( self::$conflict ) { return false; }
        if ( self::$registered ) { return true; }
        if ( ! class_exists( 'Faluss_Events' ) || ! class_exists( 'Faluss_Events_Catalog_Validator' ) || ! class_exists( 'Faluss_Apps_Registry_Manifest_Validator' ) || ! class_exists( 'Faluss_Federation_Crypto' ) || ! method_exists( 'Faluss_Events', 'register_catalog_provider' ) || ! method_exists( 'Faluss_Federation_Crypto', 'local_identity' ) || ! method_exists( 'Faluss_Federation_Crypto', 'is_key_id' ) ) {
            return false;
        }
        try {
            $identity = Faluss_Federation_Crypto::local_identity();
        } catch ( Throwable $throwable ) {
            return false;
        }
        if ( is_wp_error( $identity ) || 'hub-node' !== ( $identity['node_id'] ?? null ) || 'faluss-hub' !== ( $identity['app_key'] ?? null ) || 'https://faluss.com' !== ( $identity['origin'] ?? null ) ) {
            return false;
        }
        if ( ! self::valid_local_contract() ) {
            self::$conflict = true;
            return false;
        }
        $result = Faluss_Events::register_catalog_provider( self::descriptor() );
        if ( is_wp_error( $result ) ) {
            self::$conflict = true;
            return false;
        }
        self::$registered = true;
        return true;
    }

    public static function provide( $context ) {
        $contract = array( 'document_type' => 'faluss.event-source-catalog', 'contract_version' => '1.0.0' );
        $catalog = self::catalog();
        if ( self::$conflict || ! class_exists( 'Faluss_Events_Catalog_Validator' ) || ! self::valid_context( $context ) || ! self::valid_catalog( $catalog ) || ! Faluss_Events_Catalog_Validator::validate_federation_payload( $catalog, $contract, $context ) ) {
            return new WP_Error( 'faluss_portal_events_catalog_refused' );
        }
        return array( 'payload_contract' => $contract, 'payload' => $catalog );
    }

    public static function descriptor() {
        return array(
            'owner_app_key' => 'faluss-hub',
            'capability_key' => 'faluss-hub.events',
            'catalog_version' => '1.0.0',
            'catalog_provider' => array( __CLASS__, 'provide' ),
            'cap_manifest_provider' => array( 'Faluss_Portal_Manifest', 'provide' ),
        );
    }

    public static function catalog() {
        return array(
            'contract_version' => '1.0.0',
            'document_type' => 'faluss.event-source-catalog',
            'catalog_version' => '1.0.0',
            'node_id' => 'hub-node',
            'app_key' => 'faluss-hub',
            'owner' => 'faluss-hub',
            'owner_engine' => 'faluss-portal',
            'capability_key' => 'faluss-hub.events',
            'capability_interface' => 'event_source',
            'event_types' => array(
                self::event_definition( 'faluss-hub.portal.viewed', 'faluss-hub.portal-viewed', 'forbidden', array() ),
                self::event_definition( 'faluss-hub.app.opened', 'faluss-hub.app-opened', 'required', array( 'app' ) ),
                self::event_definition( 'faluss-hub.daily-reward.claimed', 'faluss-hub.daily-reward-claimed', 'forbidden', array() ),
            ),
            'compatibility' => self::compatibility(),
        );
    }

    private static function event_definition( $event_type, $document_type, $object_presence, $object_types ) {
        return array(
            'event_type' => $event_type,
            'event_version' => '1.0.0',
            'payload_contract' => array( 'document_type' => $document_type, 'contract_version' => '1.0.0' ),
            'subject_policy' => 'required',
            'allowed_actor_types' => array( 'member' ),
            'object_policy' => array( 'presence' => $object_presence, 'allowed_types' => $object_types ),
            'allowed_destinations' => array( 'analytics.events' ),
            'max_delivery_delay_seconds' => 3600,
            'data_classification' => 'personal',
            'max_retention_seconds' => 7776000,
            'member_result_visibility' => 'own_subject_only',
            'lifecycle' => array( 'deprecated' => false, 'sunset_at' => null, 'replacement_event_type' => null ),
        );
    }

    private static function compatibility() {
        return array( 'minimum_runtime_version' => '1.0.0', 'compatible_with' => array( '1.0.0' ), 'deprecated' => false, 'sunset_at' => null, 'replacement_catalog_version' => null );
    }

    private static function valid_local_contract() {
        $catalog_context = self::local_context( 'event_catalog.read' );
        $manifest_context = self::local_context( 'manifest.read' );
        $manifest_context['parameters'] = array( 'app_key' => 'faluss-hub', 'requested_manifest_version' => '1.0.0' );
        return self::valid_catalog( self::catalog() )
            && Faluss_Events_Catalog_Validator::validate_against_manifest( self::catalog(), Faluss_Portal_Manifest::manifest(), array( 'document_type' => 'faluss.app-capability-manifest', 'contract_version' => '1.0.0' ), $manifest_context )
            && self::valid_context( $catalog_context );
    }

    private static function valid_catalog( $catalog ) {
        return Faluss_Events_Catalog_Validator::validate( $catalog ) && self::catalog() === $catalog;
    }

    private static function local_context( $operation ) {
        return array(
            'operation' => $operation,
            'parameters' => array( 'owner_app_key' => 'faluss-hub', 'capability_key' => 'faluss-hub.events', 'catalog_version' => '1.0.0' ),
            'subject_context' => null,
            'sender' => array( 'node_id' => 'hub-node', 'app_key' => 'faluss-hub' ),
            'recipient' => array( 'node_id' => 'hub-node', 'app_key' => 'faluss-hub' ),
        );
    }

    private static function valid_context( $context ) {
        return self::exact_keys( $context, array( 'operation', 'parameters', 'subject_context', 'sender', 'recipient' ) )
            && 'event_catalog.read' === $context['operation']
            && null === $context['subject_context']
            && self::exact_keys( $context['parameters'], array( 'owner_app_key', 'capability_key', 'catalog_version' ) )
            && array( 'owner_app_key' => 'faluss-hub', 'capability_key' => 'faluss-hub.events', 'catalog_version' => '1.0.0' ) === $context['parameters']
            && self::sender( $context['sender'] )
            && self::exact_keys( $context['recipient'], array( 'node_id', 'app_key' ) )
            && array( 'node_id' => 'hub-node', 'app_key' => 'faluss-hub' ) === $context['recipient'];
    }

    private static function sender( $party ) {
        $local = self::exact_keys( $party, array( 'node_id', 'app_key' ) );
        $federated = self::exact_keys( $party, array( 'node_id', 'app_key', 'key_id' ) );
        return ( $local || $federated )
            && is_string( $party['node_id'] ) && 1 === preg_match( '/^[a-z][a-z0-9-]{1,63}$/D', $party['node_id'] )
            && is_string( $party['app_key'] ) && 1 === preg_match( '/^[a-z][a-z0-9-]{1,63}$/D', $party['app_key'] )
            && ( $local || ( class_exists( 'Faluss_Federation_Crypto' ) && method_exists( 'Faluss_Federation_Crypto', 'is_key_id' ) && Faluss_Federation_Crypto::is_key_id( $party['key_id'] ) ) );
    }

    private static function exact_keys( $value, $expected ) {
        if ( ! is_array( $value ) ) { return false; }
        $actual = array_keys( $value ); sort( $actual, SORT_STRING ); sort( $expected, SORT_STRING );
        return $actual === $expected;
    }
}
