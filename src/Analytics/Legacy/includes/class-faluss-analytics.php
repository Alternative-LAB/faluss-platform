<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Hub-only composition root for the private Analytics engine. */
final class Faluss_Analytics {
    const NODE_ID = 'hub-node';
    const APP_KEY = 'faluss-hub';
    const ORIGIN = 'https://faluss.com';
    const DESTINATION = 'analytics.events';
    const CONSUMER_KEY = 'faluss-analytics.aggregate-v1';

    private static $validators_registered = false;
    private static $consumer_registered = false;
    private static $runtime_conflict = false;

    public static function boot() {
        if ( ! function_exists( 'add_action' ) ) {
            return;
        }
        add_action( 'plugins_loaded', array( __CLASS__, 'register_runtime' ), 60 );
        add_action( 'faluss_federation_ready', array( __CLASS__, 'register_runtime' ), 40 );
    }

    public static function is_local_hub() {
        if ( ! class_exists( 'Faluss_Federation_Crypto' ) || ! method_exists( 'Faluss_Federation_Crypto', 'local_identity' ) ) {
            return false;
        }
        try {
            $identity = Faluss_Federation_Crypto::local_identity();
        } catch ( Throwable $throwable ) {
            return false;
        }
        return ! is_wp_error( $identity )
            && self::NODE_ID === ( $identity['node_id'] ?? null )
            && self::APP_KEY === ( $identity['app_key'] ?? null )
            && self::ORIGIN === ( $identity['origin'] ?? null );
    }

    /** Register exactly once; any partial or foreign collision keeps the runtime closed. */
    public static function register_runtime() {
        if ( self::$runtime_conflict ) {
            return false;
        }
        if ( ! self::is_local_hub() || ! Faluss_Analytics_Schema::is_ready() || ! class_exists( 'Faluss_Events' ) || ! class_exists( 'Faluss_Events_Engine' ) || ! method_exists( 'Faluss_Events', 'register_payload_validator' ) || ! method_exists( 'Faluss_Events_Engine', 'register_consumer' ) ) {
            return false;
        }
        if ( ! self::$validators_registered ) {
            foreach ( Faluss_Analytics_Event_Validator::payload_contracts() as $document_type ) {
                $registered = Faluss_Events::register_payload_validator( $document_type, '1.0.0', array( 'Faluss_Analytics_Event_Validator', 'validate_payload' ) );
                if ( is_wp_error( $registered ) ) {
                    self::$runtime_conflict = true;
                    return false;
                }
            }
            self::$validators_registered = true;
        }
        if ( ! self::$consumer_registered ) {
            $registered = Faluss_Events_Engine::register_consumer( self::consumer_descriptor() );
            if ( is_wp_error( $registered ) ) {
                self::$runtime_conflict = true;
                return false;
            }
            self::$consumer_registered = true;
        }
        return true;
    }

    public static function consumer_descriptor() {
        return array(
            'consumer_key' => self::CONSUMER_KEY,
            'destination' => self::DESTINATION,
            'target_node_id' => self::NODE_ID,
            'target_app_key' => self::APP_KEY,
            'sources' => array(
                array( 'node_id' => 'hub-node', 'app_key' => 'faluss-hub', 'capability_key' => 'faluss-hub.events', 'catalog_version' => '1.0.0' ),
                array( 'node_id' => 'me-node', 'app_key' => 'faluss-me', 'capability_key' => 'faluss-me.events', 'catalog_version' => '1.0.0' ),
            ),
            'callback' => array( 'Faluss_Analytics_Consumer', 'consume' ),
        );
    }

    public static function summary( $trusted_faluss_id, $from = null, $to = null ) {
        return Faluss_Analytics_Read_Model::summary( $trusted_faluss_id, $from, $to );
    }

    public static function delete_subject( $trusted_faluss_id ) {
        return self::is_local_hub() ? Faluss_Analytics_Consumer::delete_subject( $trusted_faluss_id ) : new WP_Error( 'faluss_events_retryable' );
    }

    public static function runtime_state() {
        return array(
            'local_hub' => self::is_local_hub(),
            'schema_ready' => Faluss_Analytics_Schema::is_ready(),
            'validators_registered' => self::$validators_registered,
            'consumer_registered' => self::$consumer_registered,
            'conflict' => self::$runtime_conflict,
        );
    }
}
