<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The single private receiver. It reads the raw signed body exactly once. */
final class Faluss_Federation_Server {
    const NAMESPACE = 'faluss-federation/v1';
    const ROUTE = '/exchange';
    const MAX_BODY = 65536;
    private const TRACE_STAGES = array( 'server_request_type', 'server_method', 'server_ssl', 'server_query', 'server_content_type', 'server_content_encoding', 'server_body', 'server_headers', 'server_body_hash', 'server_json', 'server_shape', 'server_identity', 'server_key_binding', 'server_peer', 'server_canonical', 'server_signature', 'server_freshness' );

    public static function boot() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
        add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_pre_serialized' ), 10, 4 );
    }

    public static function register_route() {
        if ( ! Faluss_Federation_Crypto::transport_ready() ) {
            return;
        }
        register_rest_route( self::NAMESPACE, self::ROUTE, array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'handle' ),
            'permission_callback' => '__return_true',
        ) );
    }

    public static function handle( $request ) {
        $started = microtime( true );
        if ( ! $request instanceof WP_REST_Request ) {
            return self::pre_auth_reject( 'server_request_type' );
        }
        if ( 'POST' !== $request->get_method() ) {
            return self::pre_auth_reject( 'server_method' );
        }
        if ( ! is_ssl() ) {
            return self::pre_auth_reject( 'server_ssl' );
        }
        if ( ! empty( $request->get_query_params() ) ) {
            return self::pre_auth_reject( 'server_query' );
        }
        if ( ! self::content_type_is_json( $request ) ) {
            return self::pre_auth_reject( 'server_content_type' );
        }
        if ( ! self::content_encoding_is_identity( $request ) ) {
            return self::pre_auth_reject( 'server_content_encoding' );
        }
        $raw_body = $request->get_body();
        if ( ! is_string( $raw_body ) || '' === $raw_body || strlen( $raw_body ) > self::MAX_BODY ) {
            return self::pre_auth_reject( 'server_body' );
        }
        $headers = self::request_headers( $request );
        if ( is_wp_error( $headers ) ) {
            return self::pre_auth_reject( 'server_headers' );
        }
        $body_hash = hash( 'sha256', $raw_body );
        if ( ! hash_equals( $headers['X-Faluss-Federation-Content-SHA256'], $body_hash ) ) {
            return self::pre_auth_reject( 'server_body_hash' );
        }
        try {
            $message = json_decode( $raw_body, true, 16, JSON_THROW_ON_ERROR );
        } catch ( Exception $exception ) {
            return self::pre_auth_reject( 'server_json' );
        }
        if ( ! self::valid_request( $message ) ) {
            return self::pre_auth_reject( 'server_shape' );
        }
        $identity = Faluss_Federation_Crypto::local_identity();
        if ( is_wp_error( $identity ) ) {
            return self::pre_auth_reject( 'server_identity' );
        }
        if ( $headers['X-Faluss-Federation-Key-Id'] !== $message['sender']['key_id'] ) {
            return self::pre_auth_reject( 'server_key_binding' );
        }
        $peer = Faluss_Federation_Policy::find_peer( $message['sender']['node_id'], $message['sender']['app_key'], $message['sender']['key_id'] );
        $canonical = Faluss_Federation_Crypto::request_canonical( $message, $raw_body );
        if ( is_wp_error( $peer ) ) {
            return self::pre_auth_reject( 'server_peer' );
        }
        if ( is_wp_error( $canonical ) ) {
            return self::pre_auth_reject( 'server_canonical' );
        }
        if ( ! Faluss_Federation_Crypto::verify( $canonical, $headers['X-Faluss-Federation-Signature'], $peer['public_key'] ) ) {
            return self::pre_auth_reject( 'server_signature' );
        }
        if ( ! self::request_is_fresh( $message ) ) {
            return self::pre_auth_reject( 'server_freshness' );
        }
        $allowed = Faluss_Federation_Policy::allow_incoming( $message, $peer, $identity );
        if ( is_wp_error( $allowed ) ) {
            return self::authenticated_response( self::failure_status( $allowed ), $message, $body_hash, $identity, $started );
        }
        $consumed = Faluss_Federation_Schema::consume_replay_and_limit( $message, $body_hash, self::rate_limit( $message['operation'] ) );
        $result = self::dispatch_after_consumption( $consumed, $message, $identity );
        if ( is_wp_error( $result ) ) {
            return self::authenticated_response( self::consume_failure_status( $result ), $message, $body_hash, $identity, $started );
        }
        return self::authenticated_response( $result, $message, $body_hash, $identity, $started );
    }

    private static function dispatch_after_consumption( $consumed, $message, $identity ) {
        if ( is_wp_error( $consumed ) ) {
            return $consumed;
        }
        return true === $consumed ? Faluss_Federation_Providers::dispatch( $message, $identity ) : new WP_Error( 'faluss_federation_fail_closed' );
    }

    /** Emits pre-serialized Federation JSON only for the exact Federation route. */
    public static function serve_pre_serialized( $served, $result, $request, $server ) {
        if ( ! $request instanceof WP_REST_Request || '/' . self::NAMESPACE . self::ROUTE !== $request->get_route() || ! $result instanceof WP_REST_Response ) {
            return $served;
        }
        $data = $result->get_data();
        if ( ! is_array( $data ) || ! isset( $data['_faluss_federation_raw_json'] ) || ! is_string( $data['_faluss_federation_raw_json'] ) ) {
            return $served;
        }
        foreach ( $result->get_headers() as $name => $value ) {
            header( $name . ': ' . $value, true );
        }
        status_header( $result->get_status() );
        echo $data['_faluss_federation_raw_json']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- signed JSON bytes must not be re-encoded.
        return true;
    }

    private static function authenticated_response( $result, $request, $request_body_hash, $identity, $started ) {
        if ( is_string( $result ) ) {
            $result = self::failure( $result );
        }
        $keys = array( 'status', 'payload_contract', 'payload', 'error' );
        if ( ! is_array( $result ) || ! self::has_keys( $result, $keys ) || ! self::only_keys( $result, $keys ) || ! self::valid_provider_result( $result, $request ) ) {
            $result = self::failure( 'incompatible' );
        }
        $status = $result['status'];
        $http_status = self::http_status( $status );
        $response = self::build_response_envelope( $result, $request, $request_body_hash, $identity, time() );
        if ( is_wp_error( $response ) ) {
            return self::pre_auth_reject();
        }
        $raw = wp_json_encode( $response, JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $raw ) || strlen( $raw ) > self::MAX_BODY ) {
            $status = 'temporarily_unavailable';
            $http_status = self::http_status( $status );
            $response['status'] = $status;
            $response['payload_contract'] = null;
            $response['payload'] = array();
            $response['error'] = array( 'code' => $status, 'message' => 'Request could not be completed.' );
            $raw = wp_json_encode( $response, JSON_UNESCAPED_SLASHES );
        }
        $canonical = Faluss_Federation_Crypto::response_canonical( $response, $http_status, $raw );
        $signature = is_wp_error( $canonical ) ? new WP_Error( 'faluss_federation_fail_closed' ) : Faluss_Federation_Crypto::sign( $canonical );
        if ( ! is_string( $raw ) || is_wp_error( $signature ) ) {
            return self::pre_auth_reject();
        }
        $duration = (int) round( ( microtime( true ) - $started ) * 1000 );
        $audit = 'event.publish' === $request['operation']
            ? array( 'sender_node_id' => $request['sender']['node_id'], 'recipient_node_id' => $identity['node_id'], 'operation_name' => $request['operation'], 'capability_key' => $request['parameters']['event']['source']['capability_key'] ?? null, 'result_code' => $status, 'duration_ms' => $duration )
            : array( 'request_id' => $request['request_id'], 'sender_node_id' => $request['sender']['node_id'], 'recipient_node_id' => $identity['node_id'], 'operation_name' => $request['operation'], 'capability_key' => $request['parameters']['capability_key'] ?? null, 'result_code' => $status, 'opaque_code' => 'receiver', 'duration_ms' => $duration );
        Faluss_Federation_Schema::audit( $audit );
        $response_object = new WP_REST_Response( array( '_faluss_federation_raw_json' => $raw ), $http_status );
        $response_object->header( 'Content-Type', 'application/json' );
        $response_object->header( 'Cache-Control', 'private, no-store' );
        $response_object->header( 'X-Content-Type-Options', 'nosniff' );
        $response_object->header( 'X-Faluss-Federation-Key-Id', $identity['key_id'] );
        $response_object->header( 'X-Faluss-Federation-Content-SHA256', hash( 'sha256', $raw ) );
        $response_object->header( 'X-Faluss-Federation-Signature', $signature );
        return $response_object;
    }

    /** @return array<string,mixed>|WP_Error */
    private static function build_response_envelope( $result, $request, $request_body_hash, $identity, $now ) {
        $generated_at = Faluss_Federation_Crypto::format_utc_timestamp( $now );
        $expires_at = Faluss_Federation_Crypto::format_utc_timestamp( $now + 300 );
        if ( is_wp_error( $generated_at ) || is_wp_error( $expires_at ) ) {
            return new WP_Error( 'faluss_federation_fail_closed' );
        }
        return array(
            'protocol_version' => '1',
            'message_type' => 'response',
            'request_id' => $request['request_id'],
            'request_body_sha256' => $request_body_hash,
            'responder' => array( 'node_id' => $identity['node_id'], 'app_key' => $identity['app_key'], 'key_id' => $identity['key_id'] ),
            'recipient' => array( 'node_id' => $request['sender']['node_id'], 'app_key' => $request['sender']['app_key'] ),
            'generated_at' => $generated_at,
            'expires_at' => $expires_at,
            'status' => $result['status'],
            'payload_contract' => $result['payload_contract'],
            'payload' => $result['payload'],
            'error' => $result['error'],
        );
    }

    private static function pre_auth_reject( $stage = null ) {
        if ( is_string( $stage ) ) {
            self::trace( $stage );
        }
        return new WP_REST_Response( array( 'code' => 'invalid_request', 'message' => 'Request rejected.' ), 400 );
    }

    private static function trace( $stage ) {
        if ( ! defined( 'FALUSS_FEDERATION_DIAGNOSTIC_TRACE' ) || true !== FALUSS_FEDERATION_DIAGNOSTIC_TRACE || ! in_array( $stage, self::TRACE_STAGES, true ) || ! function_exists( 'error_log' ) ) {
            return;
        }
        try {
            error_log( '[Faluss Federation trace] side=server stage=' . $stage );
        } catch ( Throwable $throwable ) {
            return;
        }
    }

    private static function request_headers( $request ) {
        $wanted = array( 'X-Faluss-Federation-Key-Id', 'X-Faluss-Federation-Content-SHA256', 'X-Faluss-Federation-Signature' );
        $out = array();
        foreach ( $wanted as $canonical ) {
            $values = $request->get_header_as_array( $canonical );
            if ( ! is_array( $values ) || 1 !== count( $values ) || ! is_string( $values[0] ) || '' === $values[0] || false !== strpos( $values[0], ',' ) ) {
                return new WP_Error( 'faluss_federation_invalid_headers' );
            }
            $out[ $canonical ] = $values[0];
        }
        return Faluss_Federation_Crypto::is_key_id( $out['X-Faluss-Federation-Key-Id'] ) && Faluss_Federation_Crypto::is_sha256( $out['X-Faluss-Federation-Content-SHA256'] ) && Faluss_Federation_Crypto::is_signature( $out['X-Faluss-Federation-Signature'] ) ? $out : new WP_Error( 'faluss_federation_invalid_headers' );
    }

    private static function content_type_is_json( $request ) {
        return 'application/json' === strtolower( trim( (string) $request->get_header( 'content-type' ) ) );
    }

    private static function content_encoding_is_identity( $request ) {
        $encoding = strtolower( trim( (string) $request->get_header( 'content-encoding' ) ) );
        return '' === $encoding || 'identity' === $encoding;
    }

    private static function valid_request( $request ) {
        $keys = array( 'protocol_version', 'message_type', 'request_id', 'operation', 'sender', 'recipient', 'issued_at', 'expires_at', 'nonce', 'subject_context', 'parameters' );
        if ( ! is_array( $request ) || array_diff( $keys, array_keys( $request ) ) || array_diff( array_keys( $request ), $keys ) || ! self::bounded_value( $request, 0 ) || '1' !== $request['protocol_version'] || 'request' !== $request['message_type'] || ! Faluss_Federation_Crypto::is_uuid( $request['request_id'] ) || ! in_array( $request['operation'], Faluss_Federation_Policy::operations(), true ) || ! Faluss_Federation_Crypto::is_nonce( $request['nonce'] ) || ! self::valid_date( $request['issued_at'] ) || ! self::valid_date( $request['expires_at'] ) || ! self::valid_sender( $request['sender'] ) || ! self::valid_recipient( $request['recipient'] ) ) {
            return false;
        }
        if ( 'diagnostic.read' === $request['operation'] ) {
            return null === $request['subject_context'] && array() === $request['parameters'];
        }
        if ( 'manifest.read' === $request['operation'] ) {
            return null === $request['subject_context'] && self::only_keys( $request['parameters'], array( 'app_key', 'requested_manifest_version' ) ) && isset( $request['parameters']['app_key'] ) && Faluss_Federation_Crypto::is_node( $request['parameters']['app_key'] ) && ( ! isset( $request['parameters']['requested_manifest_version'] ) || null === $request['parameters']['requested_manifest_version'] || Faluss_Federation_Crypto::is_semver( $request['parameters']['requested_manifest_version'] ) );
        }
        if ( 'event_catalog.read' === $request['operation'] ) {
            $parameters = $request['parameters'];
            return null === $request['subject_context'] && self::only_keys( $parameters, array( 'owner_app_key', 'capability_key', 'catalog_version' ) ) && self::has_keys( $parameters, array( 'owner_app_key', 'capability_key', 'catalog_version' ) ) && Faluss_Federation_Crypto::is_node( $parameters['owner_app_key'] ) && is_string( $parameters['capability_key'] ) && 1 === preg_match( '/^[a-z][a-z0-9-]{1,63}(?:\.[a-z][a-z0-9-]{1,63}){1,7}$/D', $parameters['capability_key'] ) && 0 === strpos( $parameters['capability_key'], $parameters['owner_app_key'] . '.' ) && Faluss_Federation_Crypto::is_semver( $parameters['catalog_version'] );
        }
        if ( 'event.publish' === $request['operation'] ) {
            return null === $request['subject_context'] && self::only_keys( $request['parameters'], array( 'event' ) ) && self::has_keys( $request['parameters'], array( 'event' ) ) && Faluss_Federation_Providers::validate_event_publish_request( $request );
        }
        $parameters = $request['parameters'];
        $subject = $request['subject_context'];
        return self::only_keys( $parameters, array( 'owner_app_key', 'capability_key', 'document_type', 'contract_version', 'audience' ) ) && self::has_keys( $parameters, array( 'owner_app_key', 'capability_key', 'document_type', 'contract_version', 'audience' ) ) && Faluss_Federation_Crypto::is_node( $parameters['owner_app_key'] ) && is_string( $parameters['capability_key'] ) && 1 === preg_match( '/^[a-z][a-z0-9-]{1,63}(?:\.[a-z][a-z0-9_.-]{1,127})+$/D', $parameters['capability_key'] ) && is_string( $parameters['document_type'] ) && 1 === preg_match( '/^[a-z][a-z0-9-]{1,63}(?:\.[a-z][a-z0-9-]{1,63})+$/D', $parameters['document_type'] ) && Faluss_Federation_Crypto::is_semver( $parameters['contract_version'] ) && in_array( $parameters['audience'], Faluss_Federation_Policy::audiences(), true ) && ( null === $subject || ( self::only_keys( $subject, array( 'subject_faluss_id' ) ) && Faluss_Federation_Crypto::is_uuid( $subject['subject_faluss_id'] ?? '' ) ) );
    }

    private static function request_is_fresh( $request ) {
        $issued = Faluss_Federation_Crypto::parse_utc_timestamp( $request['issued_at'] );
        $expires = Faluss_Federation_Crypto::parse_utc_timestamp( $request['expires_at'] );
        $now = time();
        return ! is_wp_error( $issued ) && ! is_wp_error( $expires ) && $expires > $issued && $expires - $issued <= 300 && $issued <= $now + 60 && $expires >= $now - 60;
    }

    private static function valid_provider_result( $result, $request ) {
        $failures = array( 'not_available', 'not_authorized', 'incompatible', 'temporarily_unavailable', 'invalid_request', 'replay_rejected' );
        if ( 'success' === $result['status'] ) {
            return is_array( $result['payload_contract'] ) && is_array( $result['payload'] ) && ! empty( $result['payload'] ) && null === $result['error'] && self::bounded_value( $result['payload_contract'], 0 ) && self::bounded_value( $result['payload'], 0 );
        }
        if ( 'empty' === $result['status'] ) {
            return null === $result['payload_contract'] && array() === $result['payload'] && null === $result['error'];
        }
        return in_array( $result['status'], $failures, true ) && null === $result['payload_contract'] && array() === $result['payload'] && self::error_shape( $result['error'], $result['status'] );
    }

    private static function failure( $status ) { return array( 'status' => $status, 'payload_contract' => null, 'payload' => array(), 'error' => array( 'code' => $status, 'message' => 'Request could not be completed.' ) ); }
    private static function failure_status( $error ) { return 'faluss_federation_incompatible' === $error->get_error_code() ? 'incompatible' : ( 'faluss_federation_invalid_request' === $error->get_error_code() ? 'invalid_request' : 'not_authorized' ); }
    private static function consume_failure_status( $error ) { return 'faluss_federation_replay_rejected' === $error->get_error_code() ? 'replay_rejected' : 'temporarily_unavailable'; }
    private static function http_status( $status ) { $map = array( 'success' => 200, 'empty' => 200, 'not_available' => 404, 'not_authorized' => 403, 'incompatible' => 409, 'temporarily_unavailable' => 503, 'invalid_request' => 400, 'replay_rejected' => 409 ); return $map[ $status ] ?? 400; }
    private static function rate_limit( $operation ) {
        if ( 'diagnostic.read' === $operation ) { return 30; }
        if ( 'manifest.read' === $operation ) { return 60; }
        if ( 'event.publish' === $operation ) { return 600; }
        return 600;
    }
    private static function valid_date( $value ) { return Faluss_Federation_Crypto::is_utc_timestamp( $value ); }
    private static function valid_sender( $value ) { return self::only_keys( $value, array( 'node_id', 'app_key', 'key_id' ) ) && self::has_keys( $value, array( 'node_id', 'app_key', 'key_id' ) ) && Faluss_Federation_Crypto::is_node( $value['node_id'] ) && Faluss_Federation_Crypto::is_node( $value['app_key'] ) && Faluss_Federation_Crypto::is_key_id( $value['key_id'] ); }
    private static function valid_recipient( $value ) { return self::only_keys( $value, array( 'node_id', 'app_key' ) ) && self::has_keys( $value, array( 'node_id', 'app_key' ) ) && Faluss_Federation_Crypto::is_node( $value['node_id'] ) && Faluss_Federation_Crypto::is_node( $value['app_key'] ); }
    private static function only_keys( $value, $keys ) { return is_array( $value ) && ! array_diff( array_keys( $value ), $keys ); }
    private static function has_keys( $value, $keys ) { foreach ( $keys as $key ) { if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) { return false; } } return true; }
    private static function bounded_value( $value, $depth ) { if ( $depth > 16 ) { return false; } if ( is_string( $value ) ) { return strlen( $value ) <= 4096; } if ( ! is_array( $value ) ) { return is_null( $value ) || is_bool( $value ) || is_int( $value ) || is_float( $value ); } if ( count( $value ) > 128 ) { return false; } foreach ( $value as $child ) { if ( ! self::bounded_value( $child, $depth + 1 ) ) { return false; } } return true; }
    private static function error_shape( $error, $status ) { return is_array( $error ) && array( 'code', 'message' ) === array_keys( $error ) && $status === $error['code'] && is_string( $error['message'] ) && '' !== $error['message'] && strlen( $error['message'] ) <= 160 && false === strpos( $error['message'], "\r" ) && false === strpos( $error['message'], "\n" ) && 1 !== preg_match( '/faluss_id|secret|session|payment|@/i', $error['message'] ); }
}
