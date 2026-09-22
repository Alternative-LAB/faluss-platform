<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Production validator for faluss.event 1.0.0. It executes only a trusted callback supplied by Faluss Events. */
final class Faluss_Events_Envelope_Validator {
    private const ROOT_KEYS = array( 'contract_version', 'event_id', 'event_type', 'event_version', 'source', 'source_event_reference', 'occurred_at', 'produced_at', 'subject_context', 'actor_context', 'object_context', 'destinations', 'payload_contract', 'payload' );
    private const DESTINATIONS = array( 'analytics.events', 'quests.events', 'progression.events' );
    private const FORBIDDEN_PAYLOAD_KEYS = array( 'metadata', 'meta', 'context', 'properties', 'faluss_id', 'subject_faluss_id', 'actor_faluss_id', 'wp_user_id', 'email', 'login', 'name', 'display_name', 'pseudonym', 'handle', 'url', 'raw_url', 'domain', 'slug', 'title', 'label', 'content', 'ip', 'user_agent', 'cookie', 'session', 'otp', 'authorization_code', 'nonce', 'signature', 'public_key', 'private_key', 'stripe_id', 'stripe_customer', 'stripe_payment', 'stripe_payload', 'card_data', 'card_number', 'private_content', 'bio', 'link_content', 'media_path', 'media_file', 'pf_balance', 'idempotency_key', 'economic_idempotency_key', 'command', 'authorization', 'entitlement', 'reward' );

    public static function validate( $event, $catalog, $payload_validator ) {
        if ( ! is_callable( $payload_validator ) || ! self::validate_transport( $event ) || ! Faluss_Events_Catalog_Validator::validate( $catalog ) ) {
            return false;
        }
        $source = $event['source'];
        if ( ! self::exact_keys( $source, array( 'node_id', 'app_key', 'owner', 'capability_key', 'catalog_version' ) ) || $source['node_id'] !== $catalog['node_id'] || $source['app_key'] !== $catalog['app_key'] || $source['owner'] !== $catalog['owner'] || $source['capability_key'] !== $catalog['capability_key'] || $source['catalog_version'] !== $catalog['catalog_version'] || $source['owner'] !== $source['app_key'] || 0 !== strpos( $event['event_type'], $source['app_key'] . '.' ) || 0 !== strpos( $source['capability_key'], $source['app_key'] . '.' ) ) {
            return false;
        }
        $definition = self::definition( $catalog, $event['event_type'], $event['event_version'] );
        if ( null === $definition ) {
            return false;
        }
        $occurred = strtotime( $event['occurred_at'] );
        $produced = strtotime( $event['produced_at'] );
        if ( false === $occurred || false === $produced || $produced < $occurred || $produced - $occurred > $definition['max_delivery_delay_seconds'] || ! self::valid_subject( $event['subject_context'], $definition['subject_policy'] ) || ! self::valid_actor( $event['actor_context'], $definition['allowed_actor_types'] ) || ! self::valid_object( $event['object_context'], $definition['object_policy'] ) ) {
            return false;
        }
        if ( array_diff( $event['destinations'], $definition['allowed_destinations'] ) || ! self::same_payload_contract( $event['payload_contract'], $definition['payload_contract'] ) ) {
            return false;
        }
        try {
            return true === call_user_func( $payload_validator, $event['payload'], $event['payload_contract'], $event );
        } catch ( Throwable $throwable ) {
            return false;
        }
    }

    /** Validate only the closed transport envelope; catalog and payload semantics remain separate. */
    public static function validate_transport( $event ) {
        if ( ! self::exact_keys( $event, self::ROOT_KEYS ) || '1.0.0' !== $event['contract_version'] || ! self::is_uuid_v4( $event['event_id'] ) || ! self::is_namespaced_key( $event['event_type'] ) || ! self::is_semver( $event['event_version'] ) || ! self::opaque_reference( $event['source_event_reference'] ) ) {
            return false;
        }
        $source = $event['source'];
        if ( ! self::exact_keys( $source, array( 'node_id', 'app_key', 'owner', 'capability_key', 'catalog_version' ) ) || ! self::is_app_key( $source['node_id'] ) || ! self::is_app_key( $source['app_key'] ) || $source['owner'] !== $source['app_key'] || ! self::is_namespaced_key( $source['capability_key'] ) || 0 !== strpos( $source['capability_key'], $source['app_key'] . '.' ) || ! self::is_semver( $source['catalog_version'] ) || 0 !== strpos( $event['event_type'], $source['app_key'] . '.' ) ) {
            return false;
        }
        if ( ! self::strict_utc( $event['occurred_at'] ) || ! self::strict_utc( $event['produced_at'] ) ) {
            return false;
        }
        $occurred = strtotime( $event['occurred_at'] );
        $produced = strtotime( $event['produced_at'] );
        if ( false === $occurred || false === $produced || $produced < $occurred || ! self::valid_subject_transport( $event['subject_context'] ) || ! self::valid_actor( $event['actor_context'], array( 'member', 'system', 'anonymous' ) ) || ! self::valid_object_transport( $event['object_context'] ) ) {
            return false;
        }
        $contract = $event['payload_contract'];
        return self::is_list( $event['destinations'] ) && ! empty( $event['destinations'] ) && count( $event['destinations'] ) <= 3 && count( $event['destinations'] ) === count( array_unique( $event['destinations'], SORT_STRING ) ) && ! array_diff( $event['destinations'], self::DESTINATIONS ) && self::exact_keys( $contract, array( 'document_type', 'contract_version' ) ) && self::is_namespaced_key( $contract['document_type'] ) && self::is_semver( $contract['contract_version'] ) && self::valid_payload( $event['payload'] );
    }

    private static function definition( $catalog, $type, $version ) {
        foreach ( $catalog['event_types'] as $definition ) {
            if ( $type === $definition['event_type'] && $version === $definition['event_version'] ) {
                return $definition;
            }
        }
        return null;
    }

    private static function valid_subject( $subject, $policy ) {
        if ( null === $subject ) {
            return 'required' !== $policy;
        }
        return 'forbidden' !== $policy && self::exact_keys( $subject, array( 'subject_type', 'subject_faluss_id' ) ) && 'faluss_member' === $subject['subject_type'] && self::is_uuid_v4( $subject['subject_faluss_id'] );
    }

    private static function valid_subject_transport( $subject ) {
        return null === $subject || ( self::exact_keys( $subject, array( 'subject_type', 'subject_faluss_id' ) ) && 'faluss_member' === $subject['subject_type'] && self::is_uuid_v4( $subject['subject_faluss_id'] ) );
    }

    private static function valid_actor( $actor, $allowed_types ) {
        if ( ! self::exact_keys( $actor, array( 'actor_type', 'actor_faluss_id', 'anonymous_reference', 'anonymous_scope' ) ) || ! in_array( $actor['actor_type'], $allowed_types, true ) ) {
            return false;
        }
        if ( 'member' === $actor['actor_type'] ) {
            return self::is_uuid_v4( $actor['actor_faluss_id'] ) && null === $actor['anonymous_reference'] && null === $actor['anonymous_scope'];
        }
        if ( 'system' === $actor['actor_type'] ) {
            return null === $actor['actor_faluss_id'] && null === $actor['anonymous_reference'] && null === $actor['anonymous_scope'];
        }
        if ( 'anonymous' !== $actor['actor_type'] || null !== $actor['actor_faluss_id'] ) {
            return false;
        }
        return null === $actor['anonymous_reference'] ? null === $actor['anonymous_scope'] : self::opaque_reference( $actor['anonymous_reference'] ) && in_array( $actor['anonymous_scope'], array( 'request', 'daily' ), true );
    }

    private static function valid_object( $object, $policy ) {
        if ( null === $object ) {
            return 'required' !== $policy['presence'];
        }
        return 'forbidden' !== $policy['presence'] && self::exact_keys( $object, array( 'object_type', 'object_reference' ) ) && is_string( $object['object_type'] ) && 1 === preg_match( '/^[a-z][a-z0-9_]{1,63}$/D', $object['object_type'] ) && in_array( $object['object_type'], $policy['allowed_types'], true ) && self::opaque_reference( $object['object_reference'] );
    }

    private static function valid_object_transport( $object ) {
        return null === $object || ( self::exact_keys( $object, array( 'object_type', 'object_reference' ) ) && is_string( $object['object_type'] ) && 1 === preg_match( '/^[a-z][a-z0-9_]{1,63}$/D', $object['object_type'] ) && self::opaque_reference( $object['object_reference'] ) );
    }

    private static function valid_payload( $payload ) {
        if ( ! is_array( $payload ) || ( ! empty( $payload ) && self::is_list( $payload ) ) ) {
            return false;
        }
        $encoded = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $encoded ) || strlen( $encoded ) > 8192 ) {
            return false;
        }
        $fields = 0;
        return self::payload_walk( $payload, 0, $fields, true );
    }

    private static function same_payload_contract( $actual, $expected ) {
        $keys = array( 'document_type', 'contract_version' );
        return self::exact_keys( $actual, $keys )
            && self::exact_keys( $expected, $keys )
            && $actual['document_type'] === $expected['document_type']
            && $actual['contract_version'] === $expected['contract_version'];
    }

    private static function payload_walk( $value, $depth, &$fields, $object_context = false ) {
        if ( $depth > 4 || is_float( $value ) ) {
            return false;
        }
        if ( is_string( $value ) ) {
            return self::text_length( $value ) <= 256 && ! self::sensitive_string( $value );
        }
        if ( is_int( $value ) ) {
            return $value >= -9007199254740991 && $value <= 9007199254740991;
        }
        if ( is_bool( $value ) || null === $value ) {
            return true;
        }
        if ( ! is_array( $value ) ) {
            return false;
        }
        $is_list = self::is_list( $value );
        if ( ! $object_context && $is_list ) {
            if ( count( $value ) > 32 ) {
                return false;
            }
            foreach ( $value as $child ) {
                if ( ! self::payload_walk( $child, $depth + 1, $fields ) ) {
                    return false;
                }
            }
            return true;
        }
        if ( count( $value ) > 32 ) {
            return false;
        }
        foreach ( $value as $key => $child ) {
            if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $key ) || in_array( $key, self::FORBIDDEN_PAYLOAD_KEYS, true ) ) {
                return false;
            }
            $fields++;
            if ( $fields > 128 || ! self::payload_walk( $child, $depth + 1, $fields ) ) {
                return false;
            }
        }
        return true;
    }

    private static function sensitive_string( $value ) {
        return 1 === preg_match( '/(?:https?:\/\/|(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\b|[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}|(?:^|[^0-9])(?:[0-9]{1,3}\.){3}[0-9]{1,3}(?:$|[^0-9])|Mozilla\/|<\?php|<script|javascript:|\b(?:cookie|session|bearer|stripe|password|secret|signature)\b)/i', $value ) || self::is_uuid_v4( $value );
    }

    private static function opaque_reference( $value ) {
        return is_string( $value ) && strlen( $value ) >= 8 && strlen( $value ) <= 128 && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]{7,127}$/D', $value ) && ! self::sensitive_string( $value );
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
    private static function is_uuid_v4( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value ); }
    private static function is_semver( $value ) { return is_string( $value ) && strlen( $value ) <= 32 && 1 === preg_match( '/^[1-9][0-9]*\.[0-9]+\.[0-9]+$/D', $value ); }
    private static function is_namespaced_key( $value ) { return is_string( $value ) && strlen( $value ) <= 512 && 1 === preg_match( '/^[a-z][a-z0-9-]{1,63}(?:\.[a-z][a-z0-9-]{1,63}){1,7}$/D', $value ); }
    private static function is_app_key( $value ) { return is_string( $value ) && '*' !== $value && 1 === preg_match( '/^[a-z][a-z0-9-]{1,63}$/D', $value ); }
    private static function text_length( $value ) { return 1 === preg_match( '//u', $value ) ? preg_match_all( '/./us', $value, $matches ) : 257; }

    private static function strict_utc( $value ) {
        if ( ! is_string( $value ) || 1 !== preg_match( '/^[0-9]{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])T(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]Z$/D', $value ) ) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $value, new DateTimeZone( 'UTC' ) );
        $errors = DateTimeImmutable::getLastErrors();
        return false !== $date && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $date->format( 'Y-m-d\TH:i:s\Z' ) === $value;
    }
}
