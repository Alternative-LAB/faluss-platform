<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Closed server-side sender. Destinations and operations are never browser input. */
final class Faluss_Federation_Client {
    const MAX_RESPONSE = 65536;
    private const TRACE_STAGES = array( 'client_transport_unavailable', 'client_operation', 'client_identity', 'client_peer', 'client_peer_operation', 'client_origin', 'client_nonce', 'client_envelope', 'client_serialization', 'client_canonical', 'client_signature', 'client_http_error', 'client_http_0', 'client_http_2xx', 'client_http_3xx', 'client_http_4xx', 'client_http_5xx', 'client_http_other', 'client_response_http_status', 'client_response_body', 'client_response_headers', 'client_response_hash', 'client_response_json', 'client_response_shape', 'client_response_binding', 'client_response_freshness', 'client_response_status', 'client_response_key', 'client_response_canonical', 'client_response_signature', 'client_response_payload' );

    public static function diagnostic_read( $peer_node_id, $peer_app_key ) {
        return self::call( $peer_node_id, $peer_app_key, 'diagnostic.read', array(), null );
    }

    public static function manifest_read( $peer_node_id, $peer_app_key, $requested_manifest_version = null ) {
        $parameters = array( 'app_key' => $peer_app_key );
        if ( null !== $requested_manifest_version ) {
            $parameters['requested_manifest_version'] = $requested_manifest_version;
        }
        return self::call( $peer_node_id, $peer_app_key, 'manifest.read', $parameters, null );
    }

    public static function read_model_read( $peer_node_id, $peer_app_key, $owner_app_key, $capability_key, $document_type, $contract_version, $audience, $subject_faluss_id = null ) {
        $subject = null;
        if ( null !== $subject_faluss_id ) {
            $subject = array( 'subject_faluss_id' => $subject_faluss_id );
        }
        return self::call( $peer_node_id, $peer_app_key, 'read_model.read', array( 'owner_app_key' => $owner_app_key, 'capability_key' => $capability_key, 'document_type' => $document_type, 'contract_version' => $contract_version, 'audience' => $audience ), $subject );
    }

    public static function event_catalog_read( $peer_node_id, $peer_app_key, $owner_app_key, $capability_key, $catalog_version ) {
        return self::call( $peer_node_id, $peer_app_key, 'event_catalog.read', array( 'owner_app_key' => $owner_app_key, 'capability_key' => $capability_key, 'catalog_version' => $catalog_version ), null );
    }

    public static function event_publish( $peer_node_id, $peer_app_key, $event ) {
        return self::call( $peer_node_id, $peer_app_key, 'event.publish', array( 'event' => $event ), null );
    }

    /** The only network path; every value comes from closed facade parameters and stored policy. */
    private static function call( $peer_node_id, $peer_app_key, $operation, $parameters, $subject_context ) {
        if ( ! Faluss_Federation_Crypto::transport_ready() ) {
            self::trace( 'client_transport_unavailable' );
            return new WP_Error( 'faluss_federation_fail_closed' );
        }
        if ( ! in_array( $operation, Faluss_Federation_Policy::operations(), true ) ) {
            self::trace( 'client_operation' );
            return new WP_Error( 'faluss_federation_fail_closed' );
        }
        $identity = Faluss_Federation_Crypto::local_identity();
        $peer = Faluss_Federation_Policy::find_outbound_peer( $peer_node_id, $peer_app_key );
        if ( is_wp_error( $identity ) ) {
            self::trace( 'client_identity' );
            return new WP_Error( 'faluss_federation_fail_closed' );
        }
        if ( is_wp_error( $peer ) ) {
            self::trace( 'client_peer' );
            return new WP_Error( 'faluss_federation_fail_closed' );
        }
        if ( ! in_array( $operation, $peer['operations'], true ) ) {
            self::trace( 'client_peer_operation' );
            return new WP_Error( 'faluss_federation_fail_closed' );
        }
        if ( 'event_catalog.read' === $operation && ( null !== $subject_context || ! Faluss_Federation_Crypto::is_node( $parameters['owner_app_key'] ?? '' ) || ( $parameters['owner_app_key'] ?? null ) !== $peer['peer_app_key'] || ! is_string( $parameters['capability_key'] ?? null ) || 1 !== preg_match( '/^[a-z][a-z0-9-]{1,63}(?:\.[a-z][a-z0-9-]{1,63}){1,7}$/D', $parameters['capability_key'] ) || 0 !== strpos( $parameters['capability_key'], $parameters['owner_app_key'] . '.' ) || ! Faluss_Federation_Crypto::is_semver( $parameters['catalog_version'] ?? '' ) || ! in_array( $parameters['owner_app_key'], $peer['owner_apps'], true ) || ! in_array( $parameters['capability_key'], $peer['capabilities'], true ) ) ) {
            self::trace( 'client_peer_operation' );
            return new WP_Error( 'faluss_federation_fail_closed' );
        }
        if ( 'event.publish' === $operation ) {
            $event = $parameters['event'] ?? null;
            if ( null !== $subject_context || ! Faluss_Federation_Providers::validate_event_publish_request( array( 'protocol_version' => '1', 'message_type' => 'request', 'request_id' => '00000000-0000-4000-8000-000000000000', 'operation' => $operation, 'sender' => array( 'node_id' => $identity['node_id'], 'app_key' => $identity['app_key'], 'key_id' => $identity['key_id'] ), 'recipient' => array( 'node_id' => $peer['peer_node_id'], 'app_key' => $peer['peer_app_key'] ), 'issued_at' => '2000-01-01T00:00:00Z', 'expires_at' => '2000-01-01T00:05:00Z', 'nonce' => str_repeat( 'A', 43 ), 'subject_context' => null, 'parameters' => array( 'event' => $event ) ) ) || ( $event['source']['node_id'] ?? null ) !== $identity['node_id'] || ( $event['source']['app_key'] ?? null ) !== $identity['app_key'] || ( $event['source']['owner'] ?? null ) !== $identity['app_key'] ) {
                self::trace( 'client_peer_operation' );
                return new WP_Error( 'faluss_federation_fail_closed' );
            }
        }
        if ( ! Faluss_Federation_Crypto::is_canonical_origin( $peer['canonical_origin'] ) ) {
            self::trace( 'client_origin' );
            return new WP_Error( 'faluss_federation_fail_closed' );
        }
        try {
            $nonce = Faluss_Federation_Crypto::base64url_encode( random_bytes( 32 ) );
        } catch ( Exception $exception ) {
            self::trace( 'client_nonce' );
            return new WP_Error( 'faluss_federation_fail_closed' );
        }
        $request = self::build_request( $identity, $peer, $operation, $parameters, $subject_context, $nonce, time() );
        if ( is_wp_error( $request ) ) {
            self::trace( 'client_envelope' );
            return new WP_Error( 'faluss_federation_fail_closed' );
        }
        $raw = wp_json_encode( $request, JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $raw ) ) {
            self::trace( 'client_serialization' );
            return new WP_Error( 'faluss_federation_fail_closed' );
        }
        $canonical = Faluss_Federation_Crypto::request_canonical( $request, $raw );
        if ( is_wp_error( $canonical ) ) {
            self::trace( 'client_canonical' );
            return new WP_Error( 'faluss_federation_fail_closed' );
        }
        $signature = Faluss_Federation_Crypto::sign( $canonical );
        if ( is_wp_error( $signature ) ) {
            self::trace( 'client_signature' );
            return new WP_Error( 'faluss_federation_fail_closed' );
        }
        if ( strlen( $raw ) > Faluss_Federation_Server::MAX_BODY ) {
            self::trace( 'client_serialization' );
            return new WP_Error( 'faluss_federation_fail_closed' );
        }
        $response = self::send_raw( $peer['canonical_origin'], $identity, $raw, $signature );
        if ( is_wp_error( $response ) ) {
            self::trace( 'client_http_error' );
            return new WP_Error( 'faluss_federation_transport_failed' );
        }
        $http_status = (int) wp_remote_retrieve_response_code( $response );
        self::trace_http_status( $http_status );
        if ( 0 !== $http_status && 200 > $http_status ) {
            return new WP_Error( 'faluss_federation_transport_failed' );
        }
        return self::validate_response( $response, $request, $raw, $peer );
    }

    /** @return array<string,mixed>|WP_Error */
    private static function build_request( $identity, $peer, $operation, $parameters, $subject_context, $nonce, $now ) {
        $issued_at = Faluss_Federation_Crypto::format_utc_timestamp( $now );
        $expires_at = Faluss_Federation_Crypto::format_utc_timestamp( $now + 300 );
        if ( is_wp_error( $issued_at ) || is_wp_error( $expires_at ) ) {
            return new WP_Error( 'faluss_federation_fail_closed' );
        }
        return array(
            'protocol_version' => '1',
            'message_type' => 'request',
            'request_id' => wp_generate_uuid4(),
            'operation' => $operation,
            'sender' => array( 'node_id' => $identity['node_id'], 'app_key' => $identity['app_key'], 'key_id' => $identity['key_id'] ),
            'recipient' => array( 'node_id' => $peer['peer_node_id'], 'app_key' => $peer['peer_app_key'] ),
            'issued_at' => $issued_at,
            'expires_at' => $expires_at,
            'nonce' => $nonce,
            'subject_context' => $subject_context,
            'parameters' => $parameters,
        );
    }

    private static function send_raw( $canonical_origin, $identity, $raw, $signature ) {
        return wp_remote_post( $canonical_origin . Faluss_Federation_Crypto::PATH, array(
            'method' => 'POST',
            'timeout' => 3,
            'redirection' => 0,
            'sslverify' => true,
            'limit_response_size' => self::MAX_RESPONSE,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-Faluss-Federation-Key-Id' => $identity['key_id'],
                'X-Faluss-Federation-Content-SHA256' => hash( 'sha256', $raw ),
                'X-Faluss-Federation-Signature' => $signature,
            ),
            'body' => $raw,
        ) );
    }

    /** Verify raw response bytes, binding, peer key, status and specialized payload before returning it. */
    private static function validate_response( $http_response, $request, $request_raw, $peer ) {
        $http_status = (int) wp_remote_retrieve_response_code( $http_response );
        $raw = wp_remote_retrieve_body( $http_response );
        if ( ! in_array( $http_status, array( 200, 400, 403, 404, 409, 503 ), true ) ) {
            self::trace( 'client_response_http_status' );
            return new WP_Error( 'faluss_federation_invalid_response' );
        }
        if ( ! is_string( $raw ) || '' === $raw || strlen( $raw ) > self::MAX_RESPONSE ) {
            self::trace( 'client_response_body' );
            return new WP_Error( 'faluss_federation_invalid_response' );
        }
        $headers = self::response_headers( $http_response );
        if ( is_wp_error( $headers ) ) {
            self::trace( 'client_response_headers' );
            return new WP_Error( 'faluss_federation_invalid_response' );
        }
        if ( ! hash_equals( $headers['X-Faluss-Federation-Content-SHA256'], hash( 'sha256', $raw ) ) ) {
            self::trace( 'client_response_hash' );
            return new WP_Error( 'faluss_federation_invalid_response' );
        }
        try {
            $response = json_decode( $raw, true, 16, JSON_THROW_ON_ERROR );
        } catch ( Exception $exception ) {
            self::trace( 'client_response_json' );
            return new WP_Error( 'faluss_federation_invalid_response' );
        }
        if ( ! self::valid_response_shape( $response ) ) {
            self::trace( 'client_response_shape' );
            return new WP_Error( 'faluss_federation_invalid_response' );
        }
        if ( $headers['X-Faluss-Federation-Key-Id'] !== $response['responder']['key_id'] || $response['request_id'] !== $request['request_id'] || ! hash_equals( $response['request_body_sha256'], hash( 'sha256', $request_raw ) ) || $response['responder']['node_id'] !== $peer['peer_node_id'] || $response['responder']['app_key'] !== $peer['peer_app_key'] || $response['recipient']['node_id'] !== $request['sender']['node_id'] || $response['recipient']['app_key'] !== $request['sender']['app_key'] ) {
            self::trace( 'client_response_binding' );
            return new WP_Error( 'faluss_federation_invalid_response' );
        }
        if ( ! self::response_is_fresh( $response, $request ) ) {
            self::trace( 'client_response_freshness' );
            return new WP_Error( 'faluss_federation_invalid_response' );
        }
        if ( self::status_http( $response['status'] ) !== $http_status ) {
            self::trace( 'client_response_status' );
            return new WP_Error( 'faluss_federation_invalid_response' );
        }
        $reply_key = Faluss_Federation_Policy::find_peer( $peer['peer_node_id'], $peer['peer_app_key'], $response['responder']['key_id'] );
        $canonical = Faluss_Federation_Crypto::response_canonical( $response, $http_status, $raw );
        if ( is_wp_error( $reply_key ) ) {
            self::trace( 'client_response_key' );
            return new WP_Error( 'faluss_federation_invalid_response' );
        }
        if ( is_wp_error( $canonical ) ) {
            self::trace( 'client_response_canonical' );
            return new WP_Error( 'faluss_federation_invalid_response' );
        }
        if ( ! Faluss_Federation_Crypto::verify( $canonical, $headers['X-Faluss-Federation-Signature'], $reply_key['public_key'] ) ) {
            self::trace( 'client_response_signature' );
            return new WP_Error( 'faluss_federation_invalid_response' );
        }
        if ( 'success' === $response['status'] && ! Faluss_Federation_Providers::validate_received_payload( $response, $request ) ) {
            self::trace( 'client_response_payload' );
            return new WP_Error( 'faluss_federation_incompatible_response' );
        }
        return $response;
    }

    private static function trace_http_status( $status ) {
        if ( 0 === $status ) {
            self::trace( 'client_http_0' );
        } elseif ( $status >= 200 && $status < 300 ) {
            self::trace( 'client_http_2xx' );
        } elseif ( $status >= 300 && $status < 400 ) {
            self::trace( 'client_http_3xx' );
        } elseif ( $status >= 400 && $status < 500 ) {
            self::trace( 'client_http_4xx' );
        } elseif ( $status >= 500 && $status < 600 ) {
            self::trace( 'client_http_5xx' );
        } else {
            self::trace( 'client_http_other' );
        }
    }

    private static function trace( $stage ) {
        if ( ! defined( 'FALUSS_FEDERATION_DIAGNOSTIC_TRACE' ) || true !== FALUSS_FEDERATION_DIAGNOSTIC_TRACE || ! in_array( $stage, self::TRACE_STAGES, true ) || ! function_exists( 'error_log' ) ) {
            return;
        }
        try {
            error_log( '[Faluss Federation trace] side=client stage=' . $stage );
        } catch ( Throwable $throwable ) {
            return;
        }
    }

    private static function response_headers( $response ) {
        $headers = wp_remote_retrieve_headers( $response );
        $wanted = array( 'content-type' => 'Content-Type', 'cache-control' => 'Cache-Control', 'x-content-type-options' => 'X-Content-Type-Options', 'x-faluss-federation-key-id' => 'X-Faluss-Federation-Key-Id', 'x-faluss-federation-content-sha256' => 'X-Faluss-Federation-Content-SHA256', 'x-faluss-federation-signature' => 'X-Faluss-Federation-Signature' );
        $result = array();
        foreach ( $wanted as $lower => $canonical ) {
            $value = self::single_header( $headers, $lower );
            if ( ! is_string( $value ) || '' === $value ) {
                return new WP_Error( 'faluss_federation_invalid_response_headers' );
            }
            $result[ $canonical ] = $value;
        }
        if ( 'application/json' !== strtolower( trim( $result['Content-Type'] ) ) || 'private, no-store' !== strtolower( trim( $result['Cache-Control'] ) ) || 'nosniff' !== strtolower( trim( $result['X-Content-Type-Options'] ) ) || ! Faluss_Federation_Crypto::is_key_id( $result['X-Faluss-Federation-Key-Id'] ) || ! Faluss_Federation_Crypto::is_sha256( $result['X-Faluss-Federation-Content-SHA256'] ) || ! Faluss_Federation_Crypto::is_signature( $result['X-Faluss-Federation-Signature'] ) ) {
            return new WP_Error( 'faluss_federation_invalid_response_headers' );
        }
        return $result;
    }

    private static function single_header( $headers, $name ) {
        if ( is_array( $headers ) ) {
            $matches = array();
            foreach ( $headers as $header_name => $value ) {
                if ( strtolower( (string) $header_name ) === $name ) {
                    $matches[] = $value;
                }
            }
            if ( 1 !== count( $matches ) || is_array( $matches[0] ) ) {
                return null;
            }
            return is_string( $matches[0] ) ? $matches[0] : null;
        }
        if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
            $all = $headers->getAll();
            return self::single_header( $all, $name );
        }
        return null;
    }

    private static function valid_response_shape( $response ) {
        $keys = array( 'protocol_version', 'message_type', 'request_id', 'request_body_sha256', 'responder', 'recipient', 'generated_at', 'expires_at', 'status', 'payload_contract', 'payload', 'error' );
        if ( ! is_array( $response ) || ! self::bounded_value( $response, 0 ) || array_diff( $keys, array_keys( $response ) ) || array_diff( array_keys( $response ), $keys ) || '1' !== ( $response['protocol_version'] ?? null ) || 'response' !== ( $response['message_type'] ?? null ) || ! Faluss_Federation_Crypto::is_uuid( $response['request_id'] ?? '' ) || ! Faluss_Federation_Crypto::is_sha256( $response['request_body_sha256'] ?? '' ) || ! self::identity_shape( $response['responder'] ?? null, true ) || ! self::identity_shape( $response['recipient'] ?? null, false ) || ! Faluss_Federation_Crypto::is_utc_timestamp( $response['generated_at'] ?? null ) || ! Faluss_Federation_Crypto::is_utc_timestamp( $response['expires_at'] ?? null ) || ! in_array( $response['status'] ?? '', array( 'success', 'empty', 'not_available', 'not_authorized', 'incompatible', 'temporarily_unavailable', 'invalid_request', 'replay_rejected' ), true ) ) {
            return false;
        }
        if ( 'success' === $response['status'] ) {
            return is_array( $response['payload_contract'] ) && is_array( $response['payload'] ) && ! empty( $response['payload'] ) && null === $response['error'];
        }
        if ( 'empty' === $response['status'] ) {
            return null === $response['payload_contract'] && array() === $response['payload'] && null === $response['error'];
        }
        return null === $response['payload_contract'] && array() === $response['payload'] && self::error_shape( $response['error'], $response['status'] );
    }

    private static function identity_shape( $identity, $with_key ) {
        $keys = $with_key ? array( 'node_id', 'app_key', 'key_id' ) : array( 'node_id', 'app_key' );
        return is_array( $identity ) && ! array_diff( $keys, array_keys( $identity ) ) && ! array_diff( array_keys( $identity ), $keys ) && Faluss_Federation_Crypto::is_node( $identity['node_id'] ?? '' ) && Faluss_Federation_Crypto::is_node( $identity['app_key'] ?? '' ) && ( ! $with_key || Faluss_Federation_Crypto::is_key_id( $identity['key_id'] ?? '' ) );
    }

    private static function response_is_fresh( $response, $request ) {
        $generated = Faluss_Federation_Crypto::parse_utc_timestamp( $response['generated_at'] );
        $expires = Faluss_Federation_Crypto::parse_utc_timestamp( $response['expires_at'] );
        $issued = Faluss_Federation_Crypto::parse_utc_timestamp( $request['issued_at'] );
        $now = time();
        return ! is_wp_error( $generated ) && ! is_wp_error( $expires ) && ! is_wp_error( $issued ) && $expires > $generated && $expires - $generated <= 300 && $generated <= $now + 60 && $expires >= $now - 60 && $generated >= $issued - 60;
    }
    private static function error_shape( $error, $status ) { return is_array( $error ) && array( 'code', 'message' ) === array_keys( $error ) && $status === $error['code'] && is_string( $error['message'] ) && '' !== $error['message'] && strlen( $error['message'] ) <= 160 && false === strpos( $error['message'], "\r" ) && false === strpos( $error['message'], "\n" ) && 1 !== preg_match( '/faluss_id|secret|session|payment|@/i', $error['message'] ); }

    private static function status_http( $status ) {
        $map = array( 'success' => 200, 'empty' => 200, 'not_available' => 404, 'not_authorized' => 403, 'incompatible' => 409, 'temporarily_unavailable' => 503, 'invalid_request' => 400, 'replay_rejected' => 409 );
        return $map[ $status ] ?? 0;
    }

    private static function bounded_value( $value, $depth ) {
        if ( $depth > 16 ) { return false; }
        if ( is_string( $value ) ) { return strlen( $value ) <= 4096; }
        if ( ! is_array( $value ) ) { return is_null( $value ) || is_bool( $value ) || is_int( $value ) || is_float( $value ); }
        if ( count( $value ) > 128 ) { return false; }
        foreach ( $value as $child ) { if ( ! self::bounded_value( $child, $depth + 1 ) ) { return false; } }
        return true;
    }
}
