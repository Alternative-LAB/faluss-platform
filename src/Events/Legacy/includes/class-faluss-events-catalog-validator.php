<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Production validator for faluss.event-source-catalog 1.0.0. */
final class Faluss_Events_Catalog_Validator {
    const DOCUMENT_TYPE = 'faluss.event-source-catalog';
    const CONTRACT_VERSION = '1.0.0';
    const RUNTIME_CONTRACT_VERSION = '1.0.0';

    private const ROOT_KEYS = array( 'contract_version', 'document_type', 'catalog_version', 'node_id', 'app_key', 'owner', 'owner_engine', 'capability_key', 'capability_interface', 'event_types', 'compatibility' );
    private const DEFINITION_KEYS = array( 'event_type', 'event_version', 'payload_contract', 'subject_policy', 'allowed_actor_types', 'object_policy', 'allowed_destinations', 'max_delivery_delay_seconds', 'data_classification', 'max_retention_seconds', 'member_result_visibility', 'lifecycle' );
    private const DESTINATIONS = array( 'analytics.events', 'quests.events', 'progression.events' );
    private const FORBIDDEN_KEYS = array( 'transport_url', 'endpoint', 'public_key', 'private_key', 'secret', 'signature', 'php_callback', 'class_name', 'function_name', 'script', 'html', 'css', 'asset', 'executable_content' );

    public static function validate( $catalog ) {
        if ( ! self::exact_keys( $catalog, self::ROOT_KEYS ) || self::contains_forbidden_content( $catalog ) ) {
            return false;
        }
        if ( self::CONTRACT_VERSION !== $catalog['contract_version'] || self::DOCUMENT_TYPE !== $catalog['document_type'] || ! self::is_semver( $catalog['catalog_version'] ) || ! self::is_node( $catalog['node_id'] ) || ! self::is_app_key( $catalog['app_key'] ) || ! self::is_app_key( $catalog['owner'] ) || ! self::is_app_key( $catalog['owner_engine'] ) || ! self::is_namespaced_key( $catalog['capability_key'] ) ) {
            return false;
        }
        if ( $catalog['owner'] !== $catalog['app_key'] || 0 !== strpos( $catalog['capability_key'], $catalog['app_key'] . '.' ) || 'event_source' !== $catalog['capability_interface'] || ! self::valid_compatibility( $catalog['compatibility'] ) ) {
            return false;
        }
        if ( ! self::is_list( $catalog['event_types'] ) || empty( $catalog['event_types'] ) || count( $catalog['event_types'] ) > 64 ) {
            return false;
        }
        $types = array();
        $pairs = array();
        foreach ( $catalog['event_types'] as $definition ) {
            if ( ! self::valid_definition( $definition, $catalog['app_key'] ) ) {
                return false;
            }
            $type = $definition['event_type'];
            $pair = $type . "\x1F" . $definition['event_version'];
            if ( isset( $types[ $type ] ) || isset( $pairs[ $pair ] ) ) {
                return false;
            }
            $types[ $type ] = true;
            $pairs[ $pair ] = true;
        }
        return true;
    }

    /** Federation payload validation stays independent from local producer registration. */
    public static function validate_federation_payload( $catalog, $payload_contract, $request_context ) {
        return self::exact_keys( $payload_contract, array( 'document_type', 'contract_version' ) )
            && self::DOCUMENT_TYPE === $payload_contract['document_type']
            && self::CONTRACT_VERSION === $payload_contract['contract_version']
            && self::validate( $catalog )
            && self::exact_keys( $request_context, array( 'operation', 'parameters', 'subject_context', 'sender', 'recipient' ) )
            && 'event_catalog.read' === $request_context['operation']
            && null === $request_context['subject_context']
            && self::exact_keys( $request_context['parameters'], array( 'owner_app_key', 'capability_key', 'catalog_version' ) )
            && $catalog['owner'] === $request_context['parameters']['owner_app_key']
            && $catalog['capability_key'] === $request_context['parameters']['capability_key']
            && $catalog['catalog_version'] === $request_context['parameters']['catalog_version']
            && is_array( $request_context['recipient'] )
            && $catalog['node_id'] === ( $request_context['recipient']['node_id'] ?? null )
            && $catalog['app_key'] === ( $request_context['recipient']['app_key'] ?? null );
    }

    /** Require a valid CAP manifest, the exact event_source capability and every requested destination binding. */
    public static function validate_against_manifest( $catalog, $manifest, $manifest_contract, $manifest_context ) {
        if ( ! self::validate( $catalog ) || ! class_exists( 'Faluss_Apps_Registry_Manifest_Validator' ) || ! method_exists( 'Faluss_Apps_Registry_Manifest_Validator', 'validate' ) ) {
            return false;
        }
        try {
            if ( true !== Faluss_Apps_Registry_Manifest_Validator::validate( $manifest, $manifest_contract, $manifest_context ) ) {
                return false;
            }
        } catch ( Throwable $throwable ) {
            return false;
        }
        if ( ! is_array( $manifest ) || $catalog['app_key'] !== ( $manifest['app_key'] ?? null ) || $catalog['owner'] !== ( $manifest['owner']['engine'] ?? null ) || $catalog['app_key'] !== ( $manifest['capability_namespace'] ?? null ) || ! self::usable_compatibility( $catalog['compatibility'], 'minimum_runtime_version' ) || ! self::usable_cap_compatibility( $manifest['compatibility'] ?? null ) ) {
            return false;
        }
        $capability = null;
        foreach ( $manifest['capabilities'] as $candidate ) {
            if ( is_array( $candidate ) && $catalog['capability_key'] === ( $candidate['capability_key'] ?? null ) ) {
                $capability = $candidate;
                break;
            }
        }
        if ( ! is_array( $capability ) || ! in_array( 'event_source', $capability['interfaces'] ?? array(), true ) || ! self::usable_cap_compatibility( $capability['compatibility'] ?? null ) ) {
            return false;
        }
        $bindings = array();
        foreach ( $capability['requested_bindings'] as $binding ) {
            if ( is_array( $binding ) && 'event_source' === ( $binding['interface'] ?? null ) && is_string( $binding['slot'] ?? null ) ) {
                $bindings[ $binding['slot'] ] = true;
            }
        }
        foreach ( self::catalog_destinations( $catalog ) as $destination ) {
            if ( empty( $bindings[ $destination ] ) ) {
                return false;
            }
        }
        return true;
    }

    private static function valid_definition( $definition, $app_key ) {
        if ( ! self::exact_keys( $definition, self::DEFINITION_KEYS ) || ! self::is_namespaced_key( $definition['event_type'] ) || 0 !== strpos( $definition['event_type'], $app_key . '.' ) || ! self::is_semver( $definition['event_version'] ) || ! self::valid_payload_contract( $definition['payload_contract'], $app_key ) || ! in_array( $definition['subject_policy'], array( 'required', 'optional', 'forbidden' ), true ) ) {
            return false;
        }
        $actors = $definition['allowed_actor_types'];
        if ( ! self::is_list( $actors ) || empty( $actors ) || count( $actors ) > 3 || count( $actors ) !== count( array_unique( $actors, SORT_STRING ) ) || array_diff( $actors, array( 'member', 'system', 'anonymous' ) ) ) {
            return false;
        }
        $object = $definition['object_policy'];
        if ( ! self::exact_keys( $object, array( 'presence', 'allowed_types' ) ) || ! in_array( $object['presence'], array( 'required', 'optional', 'forbidden' ), true ) || ! self::is_list( $object['allowed_types'] ) || count( $object['allowed_types'] ) > 32 || count( $object['allowed_types'] ) !== count( array_unique( $object['allowed_types'], SORT_STRING ) ) || ( ( 'forbidden' === $object['presence'] ) !== empty( $object['allowed_types'] ) ) ) {
            return false;
        }
        foreach ( $object['allowed_types'] as $type ) {
            if ( ! self::is_object_type( $type ) ) {
                return false;
            }
        }
        $destinations = $definition['allowed_destinations'];
        if ( ! self::is_list( $destinations ) || empty( $destinations ) || count( $destinations ) > 3 || count( $destinations ) !== count( array_unique( $destinations, SORT_STRING ) ) || array_diff( $destinations, self::DESTINATIONS ) ) {
            return false;
        }
        if ( ! is_int( $definition['max_delivery_delay_seconds'] ) || $definition['max_delivery_delay_seconds'] < 1 || $definition['max_delivery_delay_seconds'] > 604800 || ! in_array( $definition['data_classification'], array( 'operational', 'pseudonymous', 'personal' ), true ) || ! is_int( $definition['max_retention_seconds'] ) || $definition['max_retention_seconds'] < 0 || $definition['max_retention_seconds'] > 31536000 || ! in_array( $definition['member_result_visibility'], array( 'aggregate_only', 'own_subject_only', 'never' ), true ) ) {
            return false;
        }
        $lifecycle = $definition['lifecycle'];
        return self::exact_keys( $lifecycle, array( 'deprecated', 'sunset_at', 'replacement_event_type' ) )
            && is_bool( $lifecycle['deprecated'] )
            && ( null === $lifecycle['sunset_at'] || self::strict_utc( $lifecycle['sunset_at'] ) )
            && ( null === $lifecycle['replacement_event_type'] || ( self::is_namespaced_key( $lifecycle['replacement_event_type'] ) && 0 === strpos( $lifecycle['replacement_event_type'], $app_key . '.' ) ) );
    }

    private static function valid_payload_contract( $contract, $app_key ) {
        return self::exact_keys( $contract, array( 'document_type', 'contract_version' ) ) && self::is_namespaced_key( $contract['document_type'] ) && 0 === strpos( $contract['document_type'], $app_key . '.' ) && self::is_semver( $contract['contract_version'] );
    }

    private static function valid_compatibility( $compatibility ) {
        if ( ! self::exact_keys( $compatibility, array( 'minimum_runtime_version', 'compatible_with', 'deprecated', 'sunset_at', 'replacement_catalog_version' ) ) || ! self::is_semver( $compatibility['minimum_runtime_version'] ) || ! self::is_list( $compatibility['compatible_with'] ) || empty( $compatibility['compatible_with'] ) || count( $compatibility['compatible_with'] ) > 32 || count( $compatibility['compatible_with'] ) !== count( array_unique( $compatibility['compatible_with'], SORT_STRING ) ) || ! is_bool( $compatibility['deprecated'] ) || ( null !== $compatibility['sunset_at'] && ! self::strict_utc( $compatibility['sunset_at'] ) ) || ( null !== $compatibility['replacement_catalog_version'] && ! self::is_semver( $compatibility['replacement_catalog_version'] ) ) ) {
            return false;
        }
        foreach ( $compatibility['compatible_with'] as $version ) {
            if ( ! self::is_semver( $version ) ) {
                return false;
            }
        }
        return true;
    }

    private static function usable_compatibility( $compatibility, $minimum_key ) {
        if ( ! is_array( $compatibility ) || true === ( $compatibility['deprecated'] ?? null ) || ! is_string( $compatibility[ $minimum_key ] ?? null ) || version_compare( self::RUNTIME_CONTRACT_VERSION, $compatibility[ $minimum_key ], '<' ) || ! in_array( self::RUNTIME_CONTRACT_VERSION, $compatibility['compatible_with'] ?? array(), true ) ) {
            return false;
        }
        return null === ( $compatibility['sunset_at'] ?? null ) || self::future_timestamp( $compatibility['sunset_at'] );
    }

    private static function usable_cap_compatibility( $compatibility ) {
        return self::usable_compatibility( $compatibility, 'minimum_consumer_version' );
    }

    private static function catalog_destinations( $catalog ) {
        $destinations = array();
        foreach ( $catalog['event_types'] as $definition ) {
            foreach ( $definition['allowed_destinations'] as $destination ) {
                $destinations[ $destination ] = true;
            }
        }
        return array_keys( $destinations );
    }

    private static function contains_forbidden_content( $value ) {
        if ( is_string( $value ) ) {
            return 1 === preg_match( '/(?:https?:\/\/|<\?php|<script|javascript:|\b(?:eval|function|callback|endpoint|code|class|script|html|css|asset|private[_ -]?key|public[_ -]?key|secret|signature)\b)/i', $value );
        }
        if ( ! is_array( $value ) ) {
            return false;
        }
        foreach ( $value as $key => $child ) {
            if ( is_string( $key ) && in_array( strtolower( $key ), self::FORBIDDEN_KEYS, true ) ) {
                return true;
            }
            if ( self::contains_forbidden_content( $child ) ) {
                return true;
            }
        }
        return false;
    }

    private static function exact_keys( $value, $expected ) {
        if ( ! is_array( $value ) || self::is_list( $value ) ) {
            return false;
        }
        $actual = array_keys( $value );
        sort( $actual, SORT_STRING );
        sort( $expected, SORT_STRING );
        return $actual === $expected;
    }

    private static function is_list( $value ) { return is_array( $value ) && ( array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 ) ); }
    private static function is_node( $value ) { return self::is_app_key( $value ); }
    private static function is_app_key( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-z][a-z0-9-]{1,63}$/D', $value ) && '*' !== $value; }
    private static function is_semver( $value ) { return is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*\.[0-9]+\.[0-9]+$/D', $value ) && strlen( $value ) <= 32; }
    private static function is_namespaced_key( $value ) { return is_string( $value ) && strlen( $value ) <= 512 && 1 === preg_match( '/^[a-z][a-z0-9-]{1,63}(?:\.[a-z][a-z0-9-]{1,63}){1,7}$/D', $value ); }
    private static function is_object_type( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-z][a-z0-9_]{1,63}$/D', $value ); }

    private static function strict_utc( $value ) {
        if ( ! is_string( $value ) || 1 !== preg_match( '/^[0-9]{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])T(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]Z$/D', $value ) ) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $value, new DateTimeZone( 'UTC' ) );
        $errors = DateTimeImmutable::getLastErrors();
        return false !== $date && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $date->format( 'Y-m-d\TH:i:s\Z' ) === $value;
    }

    private static function future_timestamp( $value ) {
        $timestamp = is_string( $value ) ? strtotime( $value ) : false;
        return false !== $timestamp && $timestamp > time();
    }
}
