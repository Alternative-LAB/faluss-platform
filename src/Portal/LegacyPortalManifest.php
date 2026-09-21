<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/** Owner-only public capability manifest for the Faluss Hub node. */
final class Faluss_Portal_Manifest {
    public static function boot() {
        add_action( 'plugins_loaded', array( __CLASS__, 'register_provider' ), 30 );
        if ( did_action( 'plugins_loaded' ) ) {
            self::register_provider();
        }
    }

    /** @return true|false|WP_Error */
    public static function register_provider() {
        if ( ! class_exists( 'Faluss_Federation_Providers' ) || ! class_exists( 'Faluss_Federation_Crypto' ) || ! method_exists( 'Faluss_Federation_Providers', 'register_manifest_provider' ) || ! method_exists( 'Faluss_Federation_Providers', 'has_manifest_contract_validator' ) || ! Faluss_Federation_Crypto::transport_ready() || ! Faluss_Federation_Providers::has_manifest_contract_validator( 'faluss.app-capability-manifest', '1.0.0' ) ) {
            return false;
        }
        $identity = Faluss_Federation_Crypto::local_identity();
        if ( is_wp_error( $identity ) || 'hub-node' !== ( $identity['node_id'] ?? null ) || 'faluss-hub' !== ( $identity['app_key'] ?? null ) ) {
            return false;
        }
        return Faluss_Federation_Providers::register_manifest_provider( 'faluss-hub', array( __CLASS__, 'provide' ) );
    }

    public static function provide( $context ) {
        if ( ! self::valid_context( $context ) ) {
            return new WP_Error( 'faluss_portal_manifest_refused' );
        }
        return array(
            'payload_contract' => array( 'document_type' => 'faluss.app-capability-manifest', 'contract_version' => '1.0.0' ),
            'payload' => self::manifest(),
        );
    }

    public static function manifest() {
        return array(
            'manifest_version' => '1.0.0',
            'app_key' => 'faluss-hub',
            'capability_namespace' => 'faluss-hub',
            'owner' => array( 'engine' => 'faluss-hub', 'authority' => 'faluss.com' ),
            'product_state' => 'active',
            'canonical_origins' => array( 'https://faluss.com' ),
            'public_presentation' => array(
                'display_name' => 'Faluss Hub',
                'summary' => 'Le portail central permettant au membre de retrouver ses applications et les services Faluss qui lui sont accessibles.',
            ),
            'official_asset' => null,
            'capabilities' => array(
                array(
                    'capability_key' => 'faluss-hub.daily-reward',
                    'interfaces' => array( 'delegated_action' ),
                    'requested_bindings' => array( array( 'interface' => 'delegated_action', 'slot' => 'portal.apps.card_action' ) ),
                    'read_model_contract' => array( 'document_type' => 'daily-reward.status', 'contract_version' => '1.0.0' ),
                    'symbolic_actions' => array( array( 'action_key' => 'faluss-hub.daily-reward.claim', 'kind' => 'delegated_action' ) ),
                    'compatibility' => array( 'minimum_consumer_version' => '1.0.0', 'compatible_with' => array( '1.0.0' ), 'deprecated' => false, 'sunset_at' => null, 'replacement_capability_key' => null ),
                ),
                array(
                    'capability_key' => 'faluss-hub.events',
                    'interfaces' => array( 'event_source' ),
                    'requested_bindings' => array( array( 'interface' => 'event_source', 'slot' => 'analytics.events' ) ),
                    'read_model_contract' => null,
                    'symbolic_actions' => array(),
                    'compatibility' => array( 'minimum_consumer_version' => '1.0.0', 'compatible_with' => array( '1.0.0' ), 'deprecated' => false, 'sunset_at' => null, 'replacement_capability_key' => null ),
                ),
            ),
            'compatibility' => array( 'minimum_consumer_version' => '1.0.0', 'compatible_with' => array( '1.0.0' ), 'deprecated' => false, 'sunset_at' => null, 'replacement_capability_key' => null ),
        );
    }

    private static function valid_context( $context ) {
        return is_array( $context )
            && 'manifest.read' === ( $context['operation'] ?? null )
            && null === ( $context['subject_context'] ?? null )
            && is_array( $context['parameters'] ?? null )
            && ! array_diff( array( 'app_key', 'requested_manifest_version' ), array_keys( $context['parameters'] ) )
            && ! array_diff( array_keys( $context['parameters'] ), array( 'app_key', 'requested_manifest_version' ) )
            && 'faluss-hub' === $context['parameters']['app_key']
            && '1.0.0' === $context['parameters']['requested_manifest_version']
            && 'faluss-hub' === ( $context['recipient']['app_key'] ?? null );
    }
}
