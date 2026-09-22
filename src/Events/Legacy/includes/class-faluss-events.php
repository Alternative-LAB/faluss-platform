<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Closed local provider and payload-validator registries plus the Federation adapter. */
final class Faluss_Events {
    private static $catalog_providers = array();
    private static $payload_validators = array();
    private static $provider_conflict = false;
    private static $payload_validator_conflict = false;
    private static $federation_validator_registered = false;
    private static $federation_provider_keys = array();
    private static $federation_publish_registered = false;

    public static function boot() {
        if ( function_exists( 'add_action' ) ) {
            add_action( 'faluss_federation_ready', array( __CLASS__, 'register_federation_integration' ), 20 );
            if ( function_exists( 'did_action' ) && did_action( 'plugins_loaded' ) ) {
                self::register_federation_integration();
            } else {
                add_action( 'plugins_loaded', array( __CLASS__, 'register_federation_integration' ), 40 );
            }
        }
    }

    /** Loading registers only the exact catalog validator and any already trusted local descriptors. */
    public static function register_federation_integration() {
        if ( ! class_exists( 'Faluss_Federation_Providers' ) || ! method_exists( 'Faluss_Federation_Providers', 'register_event_catalog_contract_validator' ) || ! method_exists( 'Faluss_Federation_Providers', 'register_event_catalog_provider' ) || ! method_exists( 'Faluss_Federation_Providers', 'register_event_publish_adapter' ) ) {
            return false;
        }
        if ( ! self::$federation_validator_registered ) {
            $registered = Faluss_Federation_Providers::register_event_catalog_contract_validator( Faluss_Events_Catalog_Validator::DOCUMENT_TYPE, Faluss_Events_Catalog_Validator::CONTRACT_VERSION, array( 'Faluss_Events_Catalog_Validator', 'validate_federation_payload' ) );
            if ( is_wp_error( $registered ) ) {
                return false;
            }
            self::$federation_validator_registered = true;
        }
        if ( ! self::$federation_publish_registered ) {
            $registered = Faluss_Federation_Providers::register_event_publish_adapter( array( __CLASS__, 'validate_publish_request' ), array( __CLASS__, 'receive_published_event' ), array( __CLASS__, 'validate_publish_response' ) );
            if ( is_wp_error( $registered ) ) {
                return false;
            }
            self::$federation_publish_registered = true;
        }
        foreach ( self::$catalog_providers as $key => $entry ) {
            if ( isset( self::$federation_provider_keys[ $key ] ) ) {
                continue;
            }
            $result = Faluss_Federation_Providers::register_event_catalog_provider(
                array( 'owner_app_key' => $entry['owner_app_key'], 'capability_key' => $entry['capability_key'], 'catalog_version' => $entry['catalog_version'] ),
                array( __CLASS__, 'provide_catalog_for_federation' )
            );
            if ( is_wp_error( $result ) ) {
                self::$provider_conflict = true;
                return false;
            }
            self::$federation_provider_keys[ $key ] = true;
        }
        return true;
    }

    /** Federation request adapter: structural envelope validation only. */
    public static function validate_publish_request( $request ) {
        return self::exact_keys( $request, array( 'protocol_version', 'message_type', 'request_id', 'operation', 'sender', 'recipient', 'issued_at', 'expires_at', 'nonce', 'subject_context', 'parameters' ) )
            && 'event.publish' === $request['operation']
            && null === $request['subject_context']
            && self::exact_keys( $request['parameters'], array( 'event' ) )
            && Faluss_Events_Envelope_Validator::validate_transport( $request['parameters']['event'] );
    }

    /** Called only after Federation authentication, policy and replay consumption. */
    public static function receive_published_event( $context ) {
        if ( ! self::valid_publish_context( $context ) || ! class_exists( 'Faluss_Events_Engine' ) ) {
            return new WP_Error( 'faluss_events_incompatible' );
        }
        $sender = array( 'node_id' => $context['sender']['node_id'], 'app_key' => $context['sender']['app_key'] );
        $recipient = array( 'node_id' => $context['recipient']['node_id'], 'app_key' => $context['recipient']['app_key'] );
        $accepted = Faluss_Events_Engine::accept_inbound_event( $context['parameters']['event'], $sender, $recipient );
        if ( is_wp_error( $accepted ) ) {
            return $accepted;
        }
        return array( 'event_id' => $accepted['event_id'], 'event_sha256' => $accepted['event_sha256'], 'disposition' => ! empty( $accepted['existing'] ) ? 'existing' : 'accepted' );
    }

    /** Client and receiver share one exact acknowledgement validator. */
    public static function validate_publish_response( $payload, $contract, $request ) {
        if ( ! self::exact_contract( $contract, 'faluss.event-acceptance', '1.0.0' ) || ! self::exact_keys( $payload, array( 'event_id', 'event_sha256', 'disposition' ) ) || ! in_array( $payload['disposition'], array( 'accepted', 'existing' ), true ) || ! is_string( $payload['event_sha256'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $payload['event_sha256'] ) || ! self::validate_publish_request( $request ) ) {
            return false;
        }
        $event = $request['parameters']['event'];
        $canonical = Faluss_Events_Canonicalizer::canonicalize_event( $event );
        return is_string( $canonical ) && $payload['event_id'] === $event['event_id'] && hash_equals( $payload['event_sha256'], hash( 'sha256', $canonical ) );
    }

    /** Trusted owner PHP registers exactly one app/capability/catalog tuple. */
    public static function register_catalog_provider( $descriptor ) {
        $keys = array( 'owner_app_key', 'capability_key', 'catalog_version', 'catalog_provider', 'cap_manifest_provider' );
        if ( ! self::exact_keys( $descriptor, $keys ) || ! self::is_app_key( $descriptor['owner_app_key'] ) || ! self::is_capability_key( $descriptor['capability_key'], $descriptor['owner_app_key'] ) || ! self::is_semver( $descriptor['catalog_version'] ) || ! is_callable( $descriptor['catalog_provider'] ) || ! is_callable( $descriptor['cap_manifest_provider'] ) ) {
            return new WP_Error( 'faluss_events_provider_refused' );
        }
        $key = self::catalog_key( $descriptor['owner_app_key'], $descriptor['capability_key'], $descriptor['catalog_version'] );
        if ( isset( self::$catalog_providers[ $key ] ) ) {
            self::$provider_conflict = true;
            return new WP_Error( 'faluss_events_provider_refused' );
        }
        self::$catalog_providers[ $key ] = $descriptor;
        if ( class_exists( 'Faluss_Federation_Providers' ) && ! self::register_federation_integration() ) {
            return new WP_Error( 'faluss_events_provider_refused' );
        }
        return true;
    }

    /** Trusted PHP registers specialized payload semantics; network documents never select callbacks. */
    public static function register_payload_validator( $document_type, $contract_version, $validator ) {
        if ( ! self::is_document_type( $document_type ) || ! self::is_semver( $contract_version ) || ! is_callable( $validator ) ) {
            return new WP_Error( 'faluss_events_validator_refused' );
        }
        $key = $document_type . "\x1F" . $contract_version;
        if ( isset( self::$payload_validators[ $key ] ) ) {
            self::$payload_validator_conflict = true;
            return new WP_Error( 'faluss_events_validator_refused' );
        }
        self::$payload_validators[ $key ] = $validator;
        return true;
    }

    public static function validate_event( $event, $catalog ) {
        if ( 'registered' !== self::payload_validator_state( $event ) ) {
            return false;
        }
        $key = ( $event['payload_contract']['document_type'] ?? '' ) . "\x1F" . ( $event['payload_contract']['contract_version'] ?? '' );
        $validator = self::$payload_validators[ $key ] ?? null;
        return is_callable( $validator ) && Faluss_Events_Envelope_Validator::validate( $event, $catalog, $validator );
    }

    /** Distinguish an invalid contract selector from an unavailable trusted validator. */
    public static function payload_validator_state( $event ) {
        if ( self::$payload_validator_conflict ) {
            return 'unavailable';
        }
        $contract = is_array( $event ) ? ( $event['payload_contract'] ?? null ) : null;
        if ( ! self::exact_keys( $contract, array( 'document_type', 'contract_version' ) ) || ! self::is_document_type( $contract['document_type'] ) || ! self::is_semver( $contract['contract_version'] ) ) {
            return 'invalid';
        }
        $key = $contract['document_type'] . "\x1F" . $contract['contract_version'];
        return is_callable( self::$payload_validators[ $key ] ?? null ) ? 'registered' : 'unavailable';
    }

    /** Federation invokes this adapter only after exact descriptor resolution in its separate registry. */
    public static function provide_catalog_for_federation( $context ) {
        if ( self::$provider_conflict ) {
            return new WP_Error( 'faluss_events_temporarily_unavailable' );
        }
        if ( ! self::valid_event_catalog_context( $context ) ) {
            return new WP_Error( 'faluss_events_incompatible' );
        }
        $parameters = $context['parameters'];
        $key = self::catalog_key( $parameters['owner_app_key'], $parameters['capability_key'], $parameters['catalog_version'] );
        $entry = self::$catalog_providers[ $key ] ?? null;
        if ( ! is_array( $entry ) ) {
            return new WP_Error( 'faluss_events_not_available' );
        }
        $manifest_context = array(
            'operation' => 'manifest.read',
            'parameters' => array( 'app_key' => $parameters['owner_app_key'], 'requested_manifest_version' => '1.0.0' ),
            'subject_context' => null,
            'sender' => $context['sender'],
            'recipient' => $context['recipient'],
        );
        try {
            $manifest_result = call_user_func( $entry['cap_manifest_provider'], $manifest_context );
            $catalog_result = call_user_func( $entry['catalog_provider'], $context );
        } catch ( Throwable $throwable ) {
            return new WP_Error( 'faluss_events_temporarily_unavailable' );
        }
        if ( is_wp_error( $manifest_result ) || is_wp_error( $catalog_result ) ) {
            return new WP_Error( 'faluss_events_temporarily_unavailable' );
        }
        if ( ! self::provider_result_shape( $manifest_result ) || ! self::provider_result_shape( $catalog_result ) || ! self::exact_contract( $manifest_result['payload_contract'], 'faluss.app-capability-manifest', '1.0.0' ) || ! self::exact_contract( $catalog_result['payload_contract'], Faluss_Events_Catalog_Validator::DOCUMENT_TYPE, Faluss_Events_Catalog_Validator::CONTRACT_VERSION ) ) {
            return new WP_Error( 'faluss_events_incompatible' );
        }
        if ( ! class_exists( 'Faluss_Apps_Registry_Manifest_Validator' ) || ! Faluss_Events_Catalog_Validator::validate_federation_payload( $catalog_result['payload'], $catalog_result['payload_contract'], $context ) || ! Faluss_Events_Catalog_Validator::validate_against_manifest( $catalog_result['payload'], $manifest_result['payload'], $manifest_result['payload_contract'], $manifest_context ) ) {
            return new WP_Error( 'faluss_events_incompatible' );
        }
        return array( 'payload_contract' => $catalog_result['payload_contract'], 'payload' => $catalog_result['payload'] );
    }

    /** Signed manifest first, signed catalog second, then independent and cross validation without cache or fallback. */
    public static function read_remote_catalog( $peer_node_id, $peer_app_key, $owner_app_key, $capability_key, $catalog_version = '1.0.0' ) {
        if ( ! class_exists( 'Faluss_Federation_Client' ) || ! class_exists( 'Faluss_Federation_Crypto' ) || ! class_exists( 'Faluss_Apps_Registry_Manifest_Validator' ) || ! method_exists( 'Faluss_Federation_Client', 'manifest_read' ) || ! method_exists( 'Faluss_Federation_Client', 'event_catalog_read' ) ) {
            return self::failure();
        }
        $manifest_response = Faluss_Federation_Client::manifest_read( $peer_node_id, $peer_app_key, '1.0.0' );
        if ( ! self::valid_remote_response( $manifest_response, $peer_node_id, $peer_app_key, 'faluss.app-capability-manifest', '1.0.0' ) ) {
            return self::failure();
        }
        $identity = Faluss_Federation_Crypto::local_identity();
        if ( is_wp_error( $identity ) ) {
            return self::failure();
        }
        $manifest_context = array(
            'operation' => 'manifest.read',
            'parameters' => array( 'app_key' => $owner_app_key, 'requested_manifest_version' => '1.0.0' ),
            'subject_context' => null,
            'sender' => array( 'node_id' => $identity['node_id'], 'app_key' => $identity['app_key'] ),
            'recipient' => array( 'node_id' => $peer_node_id, 'app_key' => $peer_app_key ),
        );
        if ( true !== Faluss_Apps_Registry_Manifest_Validator::validate( $manifest_response['payload'], $manifest_response['payload_contract'], $manifest_context ) ) {
            return self::failure();
        }
        $catalog_response = Faluss_Federation_Client::event_catalog_read( $peer_node_id, $peer_app_key, $owner_app_key, $capability_key, $catalog_version );
        $catalog_context = array(
            'operation' => 'event_catalog.read',
            'parameters' => array( 'owner_app_key' => $owner_app_key, 'capability_key' => $capability_key, 'catalog_version' => $catalog_version ),
            'subject_context' => null,
            'sender' => $manifest_context['sender'],
            'recipient' => $manifest_context['recipient'],
        );
        if ( ! self::valid_remote_response( $catalog_response, $peer_node_id, $peer_app_key, Faluss_Events_Catalog_Validator::DOCUMENT_TYPE, Faluss_Events_Catalog_Validator::CONTRACT_VERSION ) || ! Faluss_Events_Catalog_Validator::validate_federation_payload( $catalog_response['payload'], $catalog_response['payload_contract'], $catalog_context ) || ! Faluss_Events_Catalog_Validator::validate_against_manifest( $catalog_response['payload'], $manifest_response['payload'], $manifest_response['payload_contract'], $manifest_context ) ) {
            return self::failure();
        }
        return $catalog_response['payload'];
    }

    /** Resolve only a catalog returned by an explicitly registered trusted local provider. */
    public static function resolve_local_catalog( $node_id, $app_key, $capability_key, $catalog_version = '1.0.0' ) {
        if ( ! self::is_app_key( $node_id ) || ! self::is_app_key( $app_key ) || ! self::is_capability_key( $capability_key, $app_key ) || ! self::is_semver( $catalog_version ) ) {
            return self::failure();
        }
        $context = array(
            'operation' => 'event_catalog.read',
            'parameters' => array( 'owner_app_key' => $app_key, 'capability_key' => $capability_key, 'catalog_version' => $catalog_version ),
            'subject_context' => null,
            'sender' => array( 'node_id' => $node_id, 'app_key' => $app_key ),
            'recipient' => array( 'node_id' => $node_id, 'app_key' => $app_key ),
        );
        $result = self::provide_catalog_for_federation( $context );
        return is_wp_error( $result ) || ! is_array( $result['payload'] ?? null ) ? self::failure() : $result['payload'];
    }

    private static function valid_remote_response( $response, $peer_node_id, $peer_app_key, $document_type, $contract_version ) {
        if ( is_wp_error( $response ) || ! is_array( $response ) || 'success' !== ( $response['status'] ?? null ) || ! array_key_exists( 'error', $response ) || null !== $response['error'] || ! self::exact_contract( $response['payload_contract'] ?? null, $document_type, $contract_version ) || ! is_array( $response['payload'] ?? null ) || ( $response['responder']['node_id'] ?? null ) !== $peer_node_id || ( $response['responder']['app_key'] ?? null ) !== $peer_app_key ) {
            return false;
        }
        $generated = self::timestamp( $response['generated_at'] ?? null );
        $expires = self::timestamp( $response['expires_at'] ?? null );
        return false !== $generated && false !== $expires && $generated <= time() + 60 && $expires > time() && $expires > $generated && $expires - $generated <= 300;
    }

    private static function valid_event_catalog_context( $context ) {
        return self::exact_keys( $context, array( 'operation', 'parameters', 'subject_context', 'sender', 'recipient' ) )
            && 'event_catalog.read' === $context['operation']
            && null === $context['subject_context']
            && self::exact_keys( $context['parameters'], array( 'owner_app_key', 'capability_key', 'catalog_version' ) )
            && self::is_app_key( $context['parameters']['owner_app_key'] )
            && self::is_capability_key( $context['parameters']['capability_key'], $context['parameters']['owner_app_key'] )
            && self::is_semver( $context['parameters']['catalog_version'] )
            && is_array( $context['sender'] )
            && is_array( $context['recipient'] )
            && $context['parameters']['owner_app_key'] === ( $context['recipient']['app_key'] ?? null );
    }

    private static function valid_publish_context( $context ) {
        if ( ! self::exact_keys( $context, array( 'operation', 'parameters', 'subject_context', 'sender', 'recipient' ) ) || 'event.publish' !== $context['operation'] || null !== $context['subject_context'] || ! self::exact_keys( $context['parameters'], array( 'event' ) ) || ! is_array( $context['sender'] ) || ! is_array( $context['recipient'] ) ) {
            return false;
        }
        $event = $context['parameters']['event'];
        return Faluss_Events_Envelope_Validator::validate_transport( $event ) && ( $event['source']['node_id'] ?? null ) === ( $context['sender']['node_id'] ?? null ) && ( $event['source']['app_key'] ?? null ) === ( $context['sender']['app_key'] ?? null ) && ( $event['source']['owner'] ?? null ) === ( $context['sender']['app_key'] ?? null );
    }

    private static function provider_result_shape( $result ) { return self::exact_keys( $result, array( 'payload_contract', 'payload' ) ) && is_array( $result['payload_contract'] ) && is_array( $result['payload'] ); }
    private static function exact_contract( $contract, $type, $version ) { return self::exact_keys( $contract, array( 'document_type', 'contract_version' ) ) && $type === $contract['document_type'] && $version === $contract['contract_version']; }
    private static function catalog_key( $owner, $capability, $version ) { return implode( "\x1F", array( $owner, $capability, $version ) ); }
    private static function is_app_key( $value ) { return is_string( $value ) && '*' !== $value && 1 === preg_match( '/^[a-z][a-z0-9-]{1,63}$/D', $value ); }
    private static function is_capability_key( $value, $owner ) { return is_string( $value ) && strlen( $value ) <= 512 && '*' !== $value && 1 === preg_match( '/^[a-z][a-z0-9-]{1,63}(?:\.[a-z][a-z0-9-]{1,63}){1,7}$/D', $value ) && 0 === strpos( $value, $owner . '.' ); }
    private static function is_document_type( $value ) { return is_string( $value ) && strlen( $value ) <= 512 && 1 === preg_match( '/^[a-z][a-z0-9-]{1,63}(?:\.[a-z][a-z0-9-]{1,63}){1,7}$/D', $value ); }
    private static function is_semver( $value ) { return is_string( $value ) && strlen( $value ) <= 32 && 1 === preg_match( '/^[1-9][0-9]*\.[0-9]+\.[0-9]+$/D', $value ); }
    private static function exact_keys( $value, $expected ) { if ( ! is_array( $value ) ) { return false; } $actual = array_keys( $value ); sort( $actual, SORT_STRING ); sort( $expected, SORT_STRING ); return $actual === $expected; }
    private static function failure() { return new WP_Error( 'faluss_events_unavailable' ); }
    private static function timestamp( $value ) {
        if ( ! is_string( $value ) || 1 !== preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D', $value ) ) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $value, new DateTimeZone( 'UTC' ) );
        $errors = DateTimeImmutable::getLastErrors();
        return false !== $date && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $date->format( 'Y-m-d\TH:i:s\Z' ) === $value ? $date->getTimestamp() : false;
    }
}
