<?php

$fed01a_assertions = 0;

function fed01a_assert( $condition, $message ) {
    global $fed01a_assertions;
    $fed01a_assertions++;
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
        exit( 1 );
    }
}

function fed01a_only_keys( $value, $keys ) {
    return is_array( $value ) && array() === array_diff( array_keys( $value ), $keys );
}

function fed01a_has_keys( $value, $keys ) {
    if ( ! is_array( $value ) ) {
        return false;
    }
    foreach ( $keys as $key ) {
        if ( ! array_key_exists( $key, $value ) ) {
            return false;
        }
    }
    return true;
}

function fed01a_has_remote_ref( $value ) {
    if ( ! is_array( $value ) ) {
        return false;
    }
    if ( isset( $value['$ref'] ) && ( ! is_string( $value['$ref'] ) || ( 0 !== strpos( $value['$ref'], '#/' ) && 'faluss-event-envelope.schema.json' !== $value['$ref'] ) ) ) {
        return true;
    }
    foreach ( $value as $child ) {
        if ( fed01a_has_remote_ref( $child ) ) {
            return true;
        }
    }
    return false;
}

function fed01a_is_uuid_v4( $value ) {
    return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value );
}

function fed01a_is_key( $value ) {
    return is_string( $value ) && 1 === preg_match( '/^[a-z][a-z0-9-]{1,63}$/D', $value );
}

function fed01a_is_key_id( $value ) {
    return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{7,127}$/D', $value );
}

function fed01a_is_semver( $value ) {
    return is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*\.[0-9]+\.[0-9]+$/D', $value );
}

function fed01a_is_hash( $value ) {
    return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
}

function fed01a_is_date( $value ) {
    return is_string( $value ) && 1 === preg_match( '/Z$/D', $value ) && false !== strtotime( $value );
}

function fed01a_base64url_encode( $value ) {
    return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
}

function fed01a_is_canonical_base64url( $value, $expected_bytes ) {
    if ( ! is_string( $value ) || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/D', $value ) || 1 === strlen( $value ) % 4 ) {
        return false;
    }
    $padded = strtr( $value, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $value ) % 4 ) % 4 );
    $decoded = base64_decode( $padded, true );
    return is_string( $decoded ) && $expected_bytes === strlen( $decoded ) && hash_equals( $value, fed01a_base64url_encode( $decoded ) );
}

function fed01a_is_nonce( $value ) {
    return is_string( $value ) && 43 === strlen( $value ) && fed01a_is_canonical_base64url( $value, 32 );
}

function fed01a_is_signature( $value ) {
    return is_string( $value ) && 86 === strlen( $value ) && fed01a_is_canonical_base64url( $value, 64 );
}

function fed01a_contains_forbidden( $value ) {
    $forbidden = array( 'email', 'password', 'secret', 'session', 'wp_user_id', 'payment', 'ledger', 'table', 'sql', 'php_path' );
    if ( ! is_array( $value ) ) {
        return false;
    }
    foreach ( $value as $key => $child ) {
        if ( is_string( $key ) && in_array( strtolower( str_replace( '-', '_', $key ) ), $forbidden, true ) ) {
            return true;
        }
        if ( fed01a_contains_forbidden( $child ) ) {
            return true;
        }
    }
    return false;
}

function fed01a_canonical_component( $value ) {
    return is_string( $value ) && false === strpos( $value, "\r" ) && false === strpos( $value, "\n" );
}

function fed01a_canonical_join( $components ) {
    if ( ! is_array( $components ) || empty( $components ) ) {
        return null;
    }
    foreach ( $components as $component ) {
        if ( ! fed01a_canonical_component( $component ) ) {
            return null;
        }
    }
    $canonical = implode( "\n", $components );
    return 0 === strpos( $canonical, "\xEF\xBB\xBF" ) || false !== strpos( $canonical, "\r" ) || substr( $canonical, -1 ) === "\n" ? null : $canonical;
}

function fed01a_canonical_request( $request, $raw_body ) {
    return fed01a_canonical_join( array(
        $request['protocol_version'],
        'POST',
        '/wp-json/faluss-federation/v1/exchange',
        $request['sender']['node_id'],
        $request['recipient']['node_id'],
        $request['sender']['key_id'],
        $request['issued_at'],
        $request['expires_at'],
        $request['nonce'],
        hash( 'sha256', $raw_body ),
    ) );
}

function fed01a_canonical_response( $response, $http_status, $raw_body ) {
    return fed01a_canonical_join( array(
        $response['protocol_version'],
        (string) $http_status,
        $response['request_id'],
        $response['request_body_sha256'],
        $response['responder']['node_id'],
        $response['recipient']['node_id'],
        $response['responder']['key_id'],
        $response['generated_at'],
        $response['expires_at'],
        hash( 'sha256', $raw_body ),
    ) );
}

function fed01a_is_canonical_origin( $origin ) {
    $parts = parse_url( $origin );
    return is_array( $parts )
        && 'https' === ( $parts['scheme'] ?? null )
        && isset( $parts['host'] )
        && ! isset( $parts['port'] )
        && ! isset( $parts['path'] )
        && ! isset( $parts['query'] )
        && ! isset( $parts['fragment'] )
        && ! isset( $parts['user'] )
        && ! isset( $parts['pass'] )
        && $origin === 'https://' . $parts['host'];
}

function fed01a_endpoint_is_valid( $method, $url, $expected_origin, $redirect_count ) {
    if ( 'POST' !== $method || 0 !== $redirect_count || ! fed01a_is_canonical_origin( $expected_origin ) ) {
        return false;
    }
    $parts = parse_url( $url );
    return is_array( $parts )
        && 'https' === ( $parts['scheme'] ?? null )
        && isset( $parts['host'] )
        && ! isset( $parts['port'] )
        && ! isset( $parts['query'] )
        && ! isset( $parts['fragment'] )
        && ! isset( $parts['user'] )
        && ! isset( $parts['pass'] )
        && $expected_origin === 'https://' . $parts['host']
        && '/wp-json/faluss-federation/v1/exchange' === ( $parts['path'] ?? null );
}

function fed01a_request_headers_are_valid( $headers, $request, $raw_body ) {
    $required = array( 'X-Faluss-Federation-Key-Id', 'X-Faluss-Federation-Content-SHA256', 'X-Faluss-Federation-Signature' );
    return fed01a_only_keys( $headers, $required )
        && fed01a_has_keys( $headers, $required )
        && $headers['X-Faluss-Federation-Key-Id'] === $request['sender']['key_id']
        && fed01a_is_hash( $headers['X-Faluss-Federation-Content-SHA256'] )
        && hash_equals( $headers['X-Faluss-Federation-Content-SHA256'], hash( 'sha256', $raw_body ) )
        && fed01a_is_signature( $headers['X-Faluss-Federation-Signature'] );
}

function fed01a_response_headers_are_valid( $headers, $response, $raw_body ) {
    $required = array( 'Content-Type', 'Cache-Control', 'X-Content-Type-Options', 'X-Faluss-Federation-Key-Id', 'X-Faluss-Federation-Content-SHA256', 'X-Faluss-Federation-Signature' );
    return fed01a_only_keys( $headers, $required )
        && fed01a_has_keys( $headers, $required )
        && 'application/json' === $headers['Content-Type']
        && 'private, no-store' === $headers['Cache-Control']
        && 'nosniff' === $headers['X-Content-Type-Options']
        && $response['responder']['key_id'] === $headers['X-Faluss-Federation-Key-Id']
        && fed01a_is_hash( $headers['X-Faluss-Federation-Content-SHA256'] )
        && hash_equals( $headers['X-Faluss-Federation-Content-SHA256'], hash( 'sha256', $raw_body ) )
        && fed01a_is_signature( $headers['X-Faluss-Federation-Signature'] );
}

function fed01a_request_is_valid( $request, $headers, $raw_body, $now, &$error ) {
    $root = array( 'protocol_version', 'message_type', 'request_id', 'operation', 'sender', 'recipient', 'issued_at', 'expires_at', 'nonce', 'subject_context', 'parameters' );
    if ( ! fed01a_only_keys( $request, $root ) || ! fed01a_has_keys( $request, $root ) || fed01a_contains_forbidden( $request ) ) {
        $error = 'invalid_request_shape';
        return false;
    }
    if ( '1' !== $request['protocol_version'] || 'request' !== $request['message_type'] || ! fed01a_is_uuid_v4( $request['request_id'] ) || ! in_array( $request['operation'], array( 'diagnostic.read', 'manifest.read', 'read_model.read', 'event_catalog.read' ), true ) || ! fed01a_is_date( $request['issued_at'] ) || ! fed01a_is_date( $request['expires_at'] ) || ! fed01a_is_nonce( $request['nonce'] ) ) {
        $error = 'invalid_request_identity';
        return false;
    }
    if ( ! fed01a_only_keys( $request['sender'], array( 'node_id', 'app_key', 'key_id' ) ) || ! fed01a_has_keys( $request['sender'], array( 'node_id', 'app_key', 'key_id' ) ) || ! fed01a_is_key( $request['sender']['node_id'] ) || ! fed01a_is_key( $request['sender']['app_key'] ) || ! fed01a_is_key_id( $request['sender']['key_id'] ) || ! fed01a_only_keys( $request['recipient'], array( 'node_id', 'app_key' ) ) || ! fed01a_has_keys( $request['recipient'], array( 'node_id', 'app_key' ) ) || ! fed01a_is_key( $request['recipient']['node_id'] ) || ! fed01a_is_key( $request['recipient']['app_key'] ) ) {
        $error = 'invalid_nodes';
        return false;
    }
    $issued = strtotime( $request['issued_at'] );
    $expires = strtotime( $request['expires_at'] );
    if ( $expires <= $issued || $expires - $issued > 300 || $issued > $now + 60 || $expires < $now - 60 || ! fed01a_request_headers_are_valid( $headers, $request, $raw_body ) ) {
        $error = 'invalid_time_or_headers';
        return false;
    }
    if ( 'diagnostic.read' === $request['operation'] ) {
        $valid = null === $request['subject_context'] && array() === $request['parameters'];
    } elseif ( 'manifest.read' === $request['operation'] ) {
        $valid = null === $request['subject_context'] && fed01a_only_keys( $request['parameters'], array( 'app_key', 'requested_manifest_version' ) ) && fed01a_has_keys( $request['parameters'], array( 'app_key' ) ) && fed01a_is_key( $request['parameters']['app_key'] ) && ( ! array_key_exists( 'requested_manifest_version', $request['parameters'] ) || null === $request['parameters']['requested_manifest_version'] || fed01a_is_semver( $request['parameters']['requested_manifest_version'] ) );
    } elseif ( 'event_catalog.read' === $request['operation'] ) {
        $parameters = $request['parameters'];
        $valid = null === $request['subject_context'] && fed01a_only_keys( $parameters, array( 'owner_app_key', 'capability_key', 'catalog_version' ) ) && fed01a_has_keys( $parameters, array( 'owner_app_key', 'capability_key', 'catalog_version' ) ) && fed01a_is_key( $parameters['owner_app_key'] ) && is_string( $parameters['capability_key'] ) && 1 === preg_match( '/^[a-z][a-z0-9-]+(?:\.[a-z][a-z0-9-]+)+$/D', $parameters['capability_key'] ) && 0 === strpos( $parameters['capability_key'], $parameters['owner_app_key'] . '.' ) && fed01a_is_semver( $parameters['catalog_version'] );
    } else {
        $parameters = $request['parameters'];
        $valid = fed01a_only_keys( $parameters, array( 'owner_app_key', 'capability_key', 'document_type', 'contract_version', 'audience' ) ) && fed01a_has_keys( $parameters, array( 'owner_app_key', 'capability_key', 'document_type', 'contract_version', 'audience' ) ) && fed01a_is_key( $parameters['owner_app_key'] ) && is_string( $parameters['capability_key'] ) && 1 === preg_match( '/^[a-z][a-z0-9-]+(?:\.[a-z][a-z0-9_.-]+)+$/D', $parameters['capability_key'] ) && is_string( $parameters['document_type'] ) && 1 === preg_match( '/^[a-z][a-z0-9-]+(?:\.[a-z][a-z0-9-]+)+$/D', $parameters['document_type'] ) && fed01a_is_semver( $parameters['contract_version'] ) && in_array( $parameters['audience'], array( 'private', 'members', 'public' ), true ) && ( null === $request['subject_context'] || ( fed01a_only_keys( $request['subject_context'], array( 'subject_faluss_id' ) ) && fed01a_has_keys( $request['subject_context'], array( 'subject_faluss_id' ) ) && fed01a_is_uuid_v4( $request['subject_context']['subject_faluss_id'] ) ) );
    }
    if ( ! $valid ) {
        $error = 'invalid_operation_parameters';
        return false;
    }
    return true;
}

function fed01a_policy_is_valid( $policy ) {
    $fields = array( 'sender_node_id', 'sender_app_key', 'sender_key_id', 'recipient_node_id', 'recipient_app_key', 'canonical_https_origin', 'operations', 'owner_apps', 'capabilities', 'audiences', 'key_state', 'valid_until' );
    if ( ! fed01a_has_keys( $policy, $fields ) || ! fed01a_is_key( $policy['sender_node_id'] ) || ! fed01a_is_key( $policy['sender_app_key'] ) || ! fed01a_is_key_id( $policy['sender_key_id'] ) || ! fed01a_is_key( $policy['recipient_node_id'] ) || ! fed01a_is_key( $policy['recipient_app_key'] ) || ! fed01a_is_canonical_origin( $policy['canonical_https_origin'] ) || ! in_array( $policy['key_state'], array( 'active', 'rotating' ), true ) || ! fed01a_is_date( $policy['valid_until'] ) ) {
        return false;
    }
    foreach ( array( 'operations', 'owner_apps', 'capabilities', 'audiences' ) as $field ) {
        if ( ! is_array( $policy[ $field ] ) || empty( $policy[ $field ] ) || in_array( '*', $policy[ $field ], true ) ) {
            return false;
        }
    }
    return true;
}

function fed01a_manifest_allows( $manifest, $request ) {
    if ( ! is_array( $manifest ) || 'accepted' !== ( $manifest['status'] ?? null ) || true !== ( $manifest['compatible'] ?? null ) || $request['parameters']['owner_app_key'] !== ( $manifest['owner_app_key'] ?? null ) || ! is_array( $manifest['capabilities'] ?? null ) ) {
        return false;
    }
    foreach ( $manifest['capabilities'] as $capability ) {
        if ( fed01a_has_keys( $capability, array( 'capability_key', 'interfaces', 'document_type', 'contract_version', 'audiences' ) ) && $request['parameters']['capability_key'] === $capability['capability_key'] && 'module_read_model' === ( $capability['interfaces'][0] ?? null ) && $request['parameters']['document_type'] === $capability['document_type'] && $request['parameters']['contract_version'] === $capability['contract_version'] && in_array( $request['parameters']['audience'], $capability['audiences'], true ) ) {
            return true;
        }
    }
    return false;
}

function fed01a_policy_allows( $policy, $request, $manifest, $now ) {
    if ( ! fed01a_policy_is_valid( $policy ) || $policy['valid_until'] < gmdate( 'c', $now ) || $policy['sender_node_id'] !== $request['sender']['node_id'] || $policy['sender_app_key'] !== $request['sender']['app_key'] || $policy['sender_key_id'] !== $request['sender']['key_id'] || $policy['recipient_node_id'] !== $request['recipient']['node_id'] || $policy['recipient_app_key'] !== $request['recipient']['app_key'] || ! in_array( $request['operation'], $policy['operations'], true ) ) {
        return false;
    }
    if ( 'diagnostic.read' === $request['operation'] ) {
        return null === $request['subject_context'] && array() === $request['parameters'];
    }
    if ( 'manifest.read' === $request['operation'] ) {
        return null === $request['subject_context'] && $request['parameters']['app_key'] === $request['recipient']['app_key'] && in_array( $request['parameters']['app_key'], $policy['owner_apps'], true );
    }
    if ( 'event_catalog.read' === $request['operation'] ) {
        return null === $request['subject_context'] && $request['parameters']['owner_app_key'] === $request['recipient']['app_key'] && in_array( $request['parameters']['owner_app_key'], $policy['owner_apps'], true ) && in_array( $request['parameters']['capability_key'], $policy['capabilities'], true );
    }
    return $request['parameters']['owner_app_key'] === $request['recipient']['app_key'] && in_array( $request['parameters']['owner_app_key'], $policy['owner_apps'], true ) && in_array( $request['parameters']['capability_key'], $policy['capabilities'], true ) && in_array( $request['parameters']['audience'], $policy['audiences'], true ) && fed01a_manifest_allows( $manifest, $request );
}

function fed01a_consume_nonce( &$seen, &$request_hashes, $request, $body_hash ) {
    $nonce_key = $request['sender']['node_id'] . "\x1F" . $request['sender']['key_id'] . "\x1F" . $request['nonce'];
    $request_key = $request['sender']['node_id'] . "\x1F" . $request['request_id'];
    if ( isset( $seen[ $nonce_key ] ) || ( isset( $request_hashes[ $request_key ] ) && ! hash_equals( $request_hashes[ $request_key ], $body_hash ) ) ) {
        return false;
    }
    $seen[ $nonce_key ] = true;
    $request_hashes[ $request_key ] = $body_hash;
    return true;
}

function fed01a_response_key_is_valid( $key, $response, $now ) {
    return fed01a_has_keys( $key, array( 'node_id', 'app_key', 'key_id', 'state', 'valid_until' ) )
        && $key['node_id'] === $response['responder']['node_id']
        && $key['app_key'] === $response['responder']['app_key']
        && $key['key_id'] === $response['responder']['key_id']
        && in_array( $key['state'], array( 'active', 'rotating' ), true )
        && fed01a_is_date( $key['valid_until'] )
        && $key['valid_until'] >= gmdate( 'c', $now );
}

function fed01a_public_error_is_valid( $error, $status ) {
    return is_array( $error )
        && fed01a_only_keys( $error, array( 'code', 'message' ) )
        && fed01a_has_keys( $error, array( 'code', 'message' ) )
        && $status === $error['code']
        && is_string( $error['message'] )
        && '' !== $error['message']
        && strlen( $error['message'] ) <= 160
        && false === strpos( $error['message'], "\r" )
        && false === strpos( $error['message'], "\n" )
        && 1 !== preg_match( '/@|faluss_id|secret|session|payment/i', $error['message'] );
}

function fed01a_response_is_valid( $response, $headers, $raw_body, $http_status, $request, $request_hash, $response_key, $now, &$error ) {
    $root = array( 'protocol_version', 'message_type', 'request_id', 'request_body_sha256', 'responder', 'recipient', 'generated_at', 'expires_at', 'status', 'payload_contract', 'payload', 'error' );
    $failures = array( 'not_available', 'not_authorized', 'incompatible', 'temporarily_unavailable', 'invalid_request', 'replay_rejected' );
    if ( ! is_int( $http_status ) || $http_status < 100 || $http_status > 599 || ! fed01a_only_keys( $response, $root ) || ! fed01a_has_keys( $response, $root ) || fed01a_contains_forbidden( $response ) || '1' !== $response['protocol_version'] || 'response' !== $response['message_type'] || $response['request_id'] !== $request['request_id'] || ! hash_equals( $response['request_body_sha256'], $request_hash ) || ! fed01a_is_date( $response['generated_at'] ) || ! fed01a_is_date( $response['expires_at'] ) ) {
        $error = 'invalid_response_envelope';
        return false;
    }
    $generated = strtotime( $response['generated_at'] );
    $expires = strtotime( $response['expires_at'] );
    if ( $expires <= $generated || $expires - $generated > 300 || $generated > $now + 60 || $expires < $now - 60 || $generated < strtotime( $request['issued_at'] ) - 60 ) {
        $error = 'invalid_response_freshness';
        return false;
    }
    if ( ! fed01a_only_keys( $response['responder'], array( 'node_id', 'app_key', 'key_id' ) ) || ! fed01a_has_keys( $response['responder'], array( 'node_id', 'app_key', 'key_id' ) ) || ! fed01a_only_keys( $response['recipient'], array( 'node_id', 'app_key' ) ) || ! fed01a_has_keys( $response['recipient'], array( 'node_id', 'app_key' ) ) || $response['responder']['node_id'] !== $request['recipient']['node_id'] || $response['responder']['app_key'] !== $request['recipient']['app_key'] || $response['recipient']['node_id'] !== $request['sender']['node_id'] || $response['recipient']['app_key'] !== $request['sender']['app_key'] || ! fed01a_response_headers_are_valid( $headers, $response, $raw_body ) || ! fed01a_response_key_is_valid( $response_key, $response, $now ) ) {
        $error = 'invalid_response_binding';
        return false;
    }
    if ( 'success' === $response['status'] ) {
        $valid = is_array( $response['payload_contract'] ) && fed01a_only_keys( $response['payload_contract'], array( 'document_type', 'contract_version' ) ) && fed01a_has_keys( $response['payload_contract'], array( 'document_type', 'contract_version' ) ) && is_string( $response['payload_contract']['document_type'] ) && fed01a_is_semver( $response['payload_contract']['contract_version'] ) && is_array( $response['payload'] ) && ! empty( $response['payload'] ) && null === $response['error'];
    } elseif ( 'empty' === $response['status'] ) {
        $valid = null === $response['payload_contract'] && array() === $response['payload'] && null === $response['error'];
    } elseif ( in_array( $response['status'], $failures, true ) ) {
        $valid = null === $response['payload_contract'] && array() === $response['payload'] && fed01a_public_error_is_valid( $response['error'], $response['status'] );
    } else {
        $valid = false;
    }
    if ( ! $valid ) {
        $error = 'invalid_response_status';
        return false;
    }
    return true;
}

function fed01a_specialized_payload_is_valid( $payload ) {
    return fed01a_only_keys( $payload, array( 'state' ) ) && 'available' === ( $payload['state'] ?? null );
}

function fed01a_request_headers( $request, $raw_body, $signature ) {
    return array(
        'X-Faluss-Federation-Key-Id' => $request['sender']['key_id'],
        'X-Faluss-Federation-Content-SHA256' => hash( 'sha256', $raw_body ),
        'X-Faluss-Federation-Signature' => $signature,
    );
}

function fed01a_response_headers( $response, $raw_body, $signature ) {
    return array(
        'Content-Type' => 'application/json',
        'Cache-Control' => 'private, no-store',
        'X-Content-Type-Options' => 'nosniff',
        'X-Faluss-Federation-Key-Id' => $response['responder']['key_id'],
        'X-Faluss-Federation-Content-SHA256' => hash( 'sha256', $raw_body ),
        'X-Faluss-Federation-Signature' => $signature,
    );
}

$root = dirname( __DIR__, 3 );
$request_schema = json_decode( file_get_contents( $root . '/contracts/faluss-federation-request.schema.json' ), true );
$response_schema = json_decode( file_get_contents( $root . '/contracts/faluss-federation-response.schema.json' ), true );
$contract = file_get_contents( $root . '/docs/FALUSS_FEDERATION_CONTRACT.md' );

fed01a_assert( is_array( $request_schema ) && is_array( $response_schema ), 'both Federation schemas parse as JSON' );
fed01a_assert( ! fed01a_has_remote_ref( $request_schema ) && ! fed01a_has_remote_ref( $response_schema ), 'schemas use only local references except the exact sibling EVT envelope contract' );
fed01a_assert( false === $request_schema['additionalProperties'] && false === $response_schema['additionalProperties'], 'envelopes are closed' );
fed01a_assert( 5 === count( $request_schema['allOf'] ) && 8 === count( $response_schema['allOf'] ), 'operation and each status branch are explicit' );
fed01a_assert( array( 'subject_faluss_id' ) === $request_schema['$defs']['subjectContext']['oneOf'][1]['required'], 'subject context has no second audience authority' );
fed01a_assert( '^[A-Za-z0-9_-]{43}$' === $request_schema['$defs']['nonce']['pattern'], 'schema fixes nonce representation to 43 characters' );
fed01a_assert( 'base64url-no-padding-canonical-64-bytes' === $response_schema['x-fed01a-transport']['signature_encoding'], 'response schema fixes canonical Ed25519 encoding' );
fed01a_assert( 6 === count( $response_schema['x-fed01a-transport']['required_response_headers_exactly_once'] ), 'response schema declares exactly the six required response headers' );
fed01a_assert( 'responder.key_id' === $response_schema['x-fed01a-transport']['required_response_headers_exactly_once']['X-Faluss-Federation-Key-Id'], 'response schema binds key header to responder key id' );
fed01a_assert( 9 === count( $request_schema['x-fed01a-scope'] ) && $request_schema['x-fed01a-scope'] === $response_schema['x-fed01a-scope'], 'historical nine-artifact scope remains machine-readable' );
foreach ( array( 'UTF-8 sans BOM', 'requested_audience', 'origine HTTPS canonique exacte', 'error.code', '32 octets', 'FED-01B', 'aucun plugin' ) as $required_contract_text ) {
    fed01a_assert( false !== strpos( $contract, $required_contract_text ), 'contract documents FED-01A.1 closure: ' . $required_contract_text );
}

$now = strtotime( '2030-01-01T00:00:00Z' );
$nonce = fed01a_base64url_encode( str_repeat( "\0", 32 ) );
$form_signature = fed01a_base64url_encode( str_repeat( "\0", 64 ) );
fed01a_assert( fed01a_is_nonce( $nonce ), 'canonical 32-byte nonce is accepted' );
fed01a_assert( ! fed01a_is_nonce( str_repeat( 'B', 43 ) ), 'base64url-looking noncanonical nonce is rejected' );
fed01a_assert( fed01a_is_signature( $form_signature ), 'canonical 64-byte signature representation is accepted' );
fed01a_assert( ! fed01a_is_signature( str_repeat( 'B', 86 ) ) && ! fed01a_is_signature( str_repeat( 'A', 85 ) ), 'wrong-size or noncanonical signature representation is rejected' );

$request = array(
    'protocol_version' => '1',
    'message_type' => 'request',
    'request_id' => '11111111-1111-4111-8111-111111111111',
    'operation' => 'diagnostic.read',
    'sender' => array( 'node_id' => 'hub-node', 'app_key' => 'faluss-hub', 'key_id' => 'hub-ed25519-202601' ),
    'recipient' => array( 'node_id' => 'me-node', 'app_key' => 'faluss-me' ),
    'issued_at' => '2029-12-31T23:59:00Z',
    'expires_at' => '2030-01-01T00:04:00Z',
    'nonce' => $nonce,
    'subject_context' => null,
    'parameters' => array(),
);
$raw_request = json_encode( $request, JSON_UNESCAPED_SLASHES );
$headers = fed01a_request_headers( $request, $raw_request, $form_signature );
$error = null;
fed01a_assert( fed01a_endpoint_is_valid( 'POST', 'https://faluss.me/wp-json/faluss-federation/v1/exchange', 'https://faluss.me', 0 ), 'configured HTTPS peer origin is accepted' );
fed01a_assert( ! fed01a_endpoint_is_valid( 'POST', 'https://other.example/wp-json/faluss-federation/v1/exchange', 'https://faluss.me', 0 ), 'other syntactically valid HTTPS origin is rejected' );
foreach ( array( array( 'GET', 'https://faluss.me/wp-json/faluss-federation/v1/exchange', 0 ), array( 'POST', 'http://faluss.me/wp-json/faluss-federation/v1/exchange', 0 ), array( 'POST', 'https://faluss.me:8443/wp-json/faluss-federation/v1/exchange', 0 ), array( 'POST', 'https://faluss.me/wp-json/faluss-federation/v1/exchange?a=1', 0 ), array( 'POST', 'https://faluss.me/wp-json/faluss-federation/v1/exchange#x', 0 ), array( 'POST', 'https://user@faluss.me/wp-json/faluss-federation/v1/exchange', 0 ), array( 'POST', 'https://faluss.me/wp-json/faluss-federation/v1/exchange', 1 ) ) as $invalid_endpoint ) {
    fed01a_assert( ! fed01a_endpoint_is_valid( $invalid_endpoint[0], $invalid_endpoint[1], 'https://faluss.me', $invalid_endpoint[2] ), 'noncanonical endpoint is rejected' );
}
fed01a_assert( fed01a_request_is_valid( $request, $headers, $raw_request, $now, $error ), 'diagnostic request with null subject and exact headers is structurally valid' );
$canonical_request = fed01a_canonical_request( $request, $raw_request );
fed01a_assert( is_string( $canonical_request ) && false === strpos( $canonical_request, "\r" ) && "\n" !== substr( $canonical_request, -1 ) && false !== strpos( $canonical_request, "\n" . hash( 'sha256', $raw_request ) ), 'canonical request uses exact LF separators, no final LF and transmitted raw hash' );
fed01a_assert( null === fed01a_canonical_join( array( 'one', "two\nthree" ) ), 'canonical components containing LF are rejected' );
fed01a_assert( null === fed01a_canonical_join( array( 'one', "two\rthree" ) ), 'canonical components containing CR are rejected' );
fed01a_assert( null === fed01a_canonical_join( array( "\xEF\xBB\xBFone", 'two' ) ), 'canonical strings with an UTF-8 BOM are rejected' );

$policy = array(
    'sender_node_id' => 'hub-node',
    'sender_app_key' => 'faluss-hub',
    'sender_key_id' => 'hub-ed25519-202601',
    'recipient_node_id' => 'me-node',
    'recipient_app_key' => 'faluss-me',
    'canonical_https_origin' => 'https://faluss.me',
    'operations' => array( 'diagnostic.read', 'manifest.read', 'read_model.read' ),
    'owner_apps' => array( 'faluss-me' ),
    'capabilities' => array( 'faluss-me.profile.summary' ),
    'audiences' => array( 'private' ),
    'key_state' => 'active',
    'valid_until' => '2030-01-01T01:00:00Z',
);
fed01a_assert( fed01a_policy_allows( $policy, $request, null, $now ), 'diagnostic policy requires exact sender, recipient, no subject and no parameter' );

$manifest_request = $request;
$manifest_request['request_id'] = '22222222-2222-4222-8222-222222222222';
$manifest_request['operation'] = 'manifest.read';
$manifest_request['parameters'] = array( 'app_key' => 'faluss-me' );
$manifest_raw = json_encode( $manifest_request, JSON_UNESCAPED_SLASHES );
$manifest_headers = fed01a_request_headers( $manifest_request, $manifest_raw, $form_signature );
fed01a_assert( fed01a_request_is_valid( $manifest_request, $manifest_headers, $manifest_raw, $now, $error ) && fed01a_policy_allows( $policy, $manifest_request, null, $now ), 'manifest read is allowed only for its exact recipient application' );
$other_manifest = $manifest_request;
$other_manifest['parameters']['app_key'] = 'faluss-fans';
fed01a_assert( ! fed01a_policy_allows( $policy, $other_manifest, null, $now ), 'manifest read for another application is refused' );
$recipient_mismatch_manifest = $manifest_request;
$recipient_mismatch_manifest['recipient']['app_key'] = 'faluss-fans';
fed01a_assert( ! fed01a_policy_allows( $policy, $recipient_mismatch_manifest, null, $now ), 'manifest app key different from recipient application is refused' );

$read_request = $request;
$read_request['request_id'] = '33333333-3333-4333-8333-333333333333';
$read_request['operation'] = 'read_model.read';
$read_request['subject_context'] = array( 'subject_faluss_id' => '44444444-4444-4444-8444-444444444444' );
$read_request['parameters'] = array( 'owner_app_key' => 'faluss-me', 'capability_key' => 'faluss-me.profile.summary', 'document_type' => 'profile.summary', 'contract_version' => '1.0.0', 'audience' => 'private' );
$read_raw = json_encode( $read_request, JSON_UNESCAPED_SLASHES );
$read_headers = fed01a_request_headers( $read_request, $read_raw, $form_signature );
$accepted_manifest = array( 'status' => 'accepted', 'compatible' => true, 'owner_app_key' => 'faluss-me', 'capabilities' => array( array( 'capability_key' => 'faluss-me.profile.summary', 'interfaces' => array( 'module_read_model' ), 'document_type' => 'profile.summary', 'contract_version' => '1.0.0', 'audiences' => array( 'private' ) ) ) );
fed01a_assert( fed01a_request_is_valid( $read_request, $read_headers, $read_raw, $now, $error ) && fed01a_policy_allows( $policy, $read_request, $accepted_manifest, $now ), 'single parameters audience drives exact read-model authorization' );
$requested_audience = $read_request;
$requested_audience['subject_context']['requested_audience'] = 'public';
$requested_audience_raw = json_encode( $requested_audience, JSON_UNESCAPED_SLASHES );
$requested_audience_headers = fed01a_request_headers( $requested_audience, $requested_audience_raw, $form_signature );
fed01a_assert( ! fed01a_request_is_valid( $requested_audience, $requested_audience_headers, $requested_audience_raw, $now, $error ), 'requested_audience is refused everywhere in the subject context' );
$owner_mismatch = $read_request;
$owner_mismatch['parameters']['owner_app_key'] = 'faluss-fans';
fed01a_assert( ! fed01a_policy_allows( $policy, $owner_mismatch, $accepted_manifest, $now ), 'read-model owner different from recipient application is refused' );
$missing_capability = $read_request;
$missing_capability['parameters']['capability_key'] = 'faluss-me.profile.other';
fed01a_assert( ! fed01a_policy_allows( $policy, $missing_capability, $accepted_manifest, $now ), 'capability absent from accepted manifest is refused' );
$missing_interface_manifest = $accepted_manifest;
unset( $missing_interface_manifest['capabilities'][0]['interfaces'] );
fed01a_assert( ! fed01a_policy_allows( $policy, $read_request, $missing_interface_manifest, $now ), 'interface absent from accepted manifest is refused' );
$wrong_document = $read_request;
$wrong_document['parameters']['document_type'] = 'profile.other';
fed01a_assert( ! fed01a_policy_allows( $policy, $wrong_document, $accepted_manifest, $now ), 'document absent from accepted manifest is refused' );
$wrong_version = $read_request;
$wrong_version['parameters']['contract_version'] = '2.0.0';
fed01a_assert( ! fed01a_policy_allows( $policy, $wrong_version, $accepted_manifest, $now ), 'version absent from accepted manifest is refused' );
$wrong_audience = $read_request;
$wrong_audience['parameters']['audience'] = 'public';
fed01a_assert( ! fed01a_policy_allows( $policy, $wrong_audience, $accepted_manifest, $now ), 'audience absent from accepted manifest is refused' );
$wildcard_policy = $policy;
$wildcard_policy['capabilities'] = array( '*' );
fed01a_assert( ! fed01a_policy_is_valid( $wildcard_policy ), 'wildcard permission is refused' );

$seen = array();
$request_hashes = array();
fed01a_assert( fed01a_consume_nonce( $seen, $request_hashes, $read_request, hash( 'sha256', $read_raw ) ), 'nonce is atomically consumed once' );
fed01a_assert( ! fed01a_consume_nonce( $seen, $request_hashes, $read_request, hash( 'sha256', $read_raw ) ), 'replayed nonce is refused' );
$same_sender_other_body = $read_request;
$same_sender_other_body['sender']['key_id'] = 'hub-ed25519-202602';
$same_sender_other_body['nonce'] = fed01a_base64url_encode( str_repeat( "\1", 32 ) );
fed01a_assert( ! fed01a_consume_nonce( $seen, $request_hashes, $same_sender_other_body, str_repeat( 'd', 64 ) ), 'same sender request id with another body is refused after key rotation boundary' );
$other_sender_same_id = $read_request;
$other_sender_same_id['sender'] = array( 'node_id' => 'other-node', 'app_key' => 'faluss-other', 'key_id' => 'other-ed25519-202601' );
$other_sender_same_id['nonce'] = fed01a_base64url_encode( str_repeat( "\2", 32 ) );
fed01a_assert( fed01a_consume_nonce( $seen, $request_hashes, $other_sender_same_id, str_repeat( 'd', 64 ) ), 'same request id from another sender node remains isolated' );

$response = array(
    'protocol_version' => '1',
    'message_type' => 'response',
    'request_id' => $read_request['request_id'],
    'request_body_sha256' => hash( 'sha256', $read_raw ),
    'responder' => array( 'node_id' => 'me-node', 'app_key' => 'faluss-me', 'key_id' => 'me-ed25519-202601' ),
    'recipient' => array( 'node_id' => 'hub-node', 'app_key' => 'faluss-hub' ),
    'generated_at' => '2030-01-01T00:00:00Z',
    'expires_at' => '2030-01-01T00:05:00Z',
    'status' => 'success',
    'payload_contract' => array( 'document_type' => 'profile.summary', 'contract_version' => '1.0.0' ),
    'payload' => array( 'state' => 'available' ),
    'error' => null,
);
$raw_response = json_encode( $response, JSON_UNESCAPED_SLASHES );
$response_headers = fed01a_response_headers( $response, $raw_response, $form_signature );
$response_key = array( 'node_id' => 'me-node', 'app_key' => 'faluss-me', 'key_id' => 'me-ed25519-202601', 'state' => 'active', 'valid_until' => '2030-01-01T01:00:00Z' );
fed01a_assert( fed01a_response_headers_are_valid( $response_headers, $response, $raw_response ), 'response has exactly one well-formed cryptographic header triplet and required safety headers' );
foreach ( array( 'X-Faluss-Federation-Key-Id', 'X-Faluss-Federation-Content-SHA256', 'X-Faluss-Federation-Signature' ) as $header_name ) {
    $missing_header = $response_headers;
    unset( $missing_header[ $header_name ] );
    fed01a_assert( ! fed01a_response_headers_are_valid( $missing_header, $response, $raw_response ), 'missing response cryptographic header is refused: ' . $header_name );
}
$duplicate_signature = $response_headers;
$duplicate_signature['X-Faluss-Federation-Signature'] = array( $form_signature, $form_signature );
fed01a_assert( ! fed01a_response_headers_are_valid( $duplicate_signature, $response, $raw_response ), 'duplicated response signature header is refused' );
$malformed_signature = $response_headers;
$malformed_signature['X-Faluss-Federation-Signature'] = str_repeat( 'B', 86 );
fed01a_assert( ! fed01a_response_headers_are_valid( $malformed_signature, $response, $raw_response ), 'malformed or noncanonical response signature is refused' );
$wrong_response_hash = $response_headers;
$wrong_response_hash['X-Faluss-Federation-Content-SHA256'] = str_repeat( '0', 64 );
fed01a_assert( ! fed01a_response_headers_are_valid( $wrong_response_hash, $response, $raw_response ), 'response hash different from exact raw body is refused' );
$wrong_response_key_header = $response_headers;
$wrong_response_key_header['X-Faluss-Federation-Key-Id'] = 'other-ed25519-202601';
fed01a_assert( ! fed01a_response_headers_are_valid( $wrong_response_key_header, $response, $raw_response ), 'response key header different from responder key id is refused' );
fed01a_assert( fed01a_response_is_valid( $response, $response_headers, $raw_response, 200, $read_request, hash( 'sha256', $read_raw ), $response_key, $now, $error ), 'response bound to exact request, responder, recipient, key and body is accepted' );
fed01a_assert( fed01a_specialized_payload_is_valid( $response['payload'] ), 'valid envelope still requires a valid specialised payload' );
$canonical_response = fed01a_canonical_response( $response, 200, $raw_response );
fed01a_assert( is_string( $canonical_response ) && false === strpos( $canonical_response, "\r" ) && "\n" !== substr( $canonical_response, -1 ) && false !== strpos( $canonical_response, "\n" . hash( 'sha256', $raw_response ) ), 'canonical response signs actual numeric HTTP status and raw response bytes' );

$wrong_responder = $response;
$wrong_responder['responder']['node_id'] = 'other-node';
$wrong_responder_raw = json_encode( $wrong_responder );
fed01a_assert( ! fed01a_response_is_valid( $wrong_responder, fed01a_response_headers( $wrong_responder, $wrong_responder_raw, $form_signature ), $wrong_responder_raw, 200, $read_request, hash( 'sha256', $read_raw ), $response_key, $now, $error ), 'wrong responder node is refused' );
$wrong_request = $response;
$wrong_request['request_id'] = '55555555-5555-4555-8555-555555555555';
$wrong_request_raw = json_encode( $wrong_request );
fed01a_assert( ! fed01a_response_is_valid( $wrong_request, fed01a_response_headers( $wrong_request, $wrong_request_raw, $form_signature ), $wrong_request_raw, 200, $read_request, hash( 'sha256', $read_raw ), $response_key, $now, $error ), 'response for another request id is refused' );
$wrong_request = $response;
$wrong_request['request_body_sha256'] = str_repeat( '0', 64 );
$wrong_request_raw = json_encode( $wrong_request );
fed01a_assert( ! fed01a_response_is_valid( $wrong_request, fed01a_response_headers( $wrong_request, $wrong_request_raw, $form_signature ), $wrong_request_raw, 200, $read_request, hash( 'sha256', $read_raw ), $response_key, $now, $error ), 'response for another request body hash is refused' );
$wrong_responder = $response;
$wrong_responder['responder']['app_key'] = 'faluss-fans';
$wrong_responder_raw = json_encode( $wrong_responder );
fed01a_assert( ! fed01a_response_is_valid( $wrong_responder, fed01a_response_headers( $wrong_responder, $wrong_responder_raw, $form_signature ), $wrong_responder_raw, 200, $read_request, hash( 'sha256', $read_raw ), $response_key, $now, $error ), 'wrong responder application is refused' );
$wrong_recipient = $response;
$wrong_recipient['recipient']['node_id'] = 'other-node';
$wrong_recipient_raw = json_encode( $wrong_recipient );
fed01a_assert( ! fed01a_response_is_valid( $wrong_recipient, fed01a_response_headers( $wrong_recipient, $wrong_recipient_raw, $form_signature ), $wrong_recipient_raw, 200, $read_request, hash( 'sha256', $read_raw ), $response_key, $now, $error ), 'wrong response recipient is refused' );
$future_response = $response;
$future_response['generated_at'] = '2030-01-01T00:01:01Z';
$future_response['expires_at'] = '2030-01-01T00:02:01Z';
$future_raw = json_encode( $future_response );
fed01a_assert( ! fed01a_response_is_valid( $future_response, fed01a_response_headers( $future_response, $future_raw, $form_signature ), $future_raw, 200, $read_request, hash( 'sha256', $read_raw ), $response_key, $now, $error ), 'response more than 60 seconds in the future is refused' );
$expired_response = $response;
$expired_response['generated_at'] = '2029-12-31T23:50:00Z';
$expired_response['expires_at'] = '2029-12-31T23:54:00Z';
$expired_raw = json_encode( $expired_response );
fed01a_assert( ! fed01a_response_is_valid( $expired_response, fed01a_response_headers( $expired_response, $expired_raw, $form_signature ), $expired_raw, 200, $read_request, hash( 'sha256', $read_raw ), $response_key, $now, $error ), 'expired response is refused' );
$before_request_response = $response;
$before_request_response['generated_at'] = '2029-12-31T23:57:59Z';
$before_request_response['expires_at'] = '2030-01-01T00:02:59Z';
$before_raw = json_encode( $before_request_response );
fed01a_assert( ! fed01a_response_is_valid( $before_request_response, fed01a_response_headers( $before_request_response, $before_raw, $form_signature ), $before_raw, 200, $read_request, hash( 'sha256', $read_raw ), $response_key, $now, $error ), 'response generated before the request beyond skew is refused' );

$empty_response = $response;
$empty_response['status'] = 'empty';
$empty_response['payload_contract'] = null;
$empty_response['payload'] = array();
$empty_raw = json_encode( $empty_response );
fed01a_assert( fed01a_response_is_valid( $empty_response, fed01a_response_headers( $empty_response, $empty_raw, $form_signature ), $empty_raw, 200, $read_request, hash( 'sha256', $read_raw ), $response_key, $now, $error ), 'empty response keeps an exactly empty payload and null error' );
foreach ( array( 'not_available', 'not_authorized', 'incompatible', 'temporarily_unavailable', 'invalid_request', 'replay_rejected' ) as $failure_status ) {
    $failure = $empty_response;
    $failure['status'] = $failure_status;
    $failure['error'] = array( 'code' => $failure_status, 'message' => 'Request could not be completed.' );
    $failure_raw = json_encode( $failure );
    fed01a_assert( fed01a_response_is_valid( $failure, fed01a_response_headers( $failure, $failure_raw, $form_signature ), $failure_raw, 400, $read_request, hash( 'sha256', $read_raw ), $response_key, $now, $error ), 'failure status requires matching non-null public error: ' . $failure_status );
}
$invalid_failure = $empty_response;
$invalid_failure['status'] = 'not_authorized';
$invalid_failure['error'] = null;
$invalid_failure_raw = json_encode( $invalid_failure );
fed01a_assert( ! fed01a_response_is_valid( $invalid_failure, fed01a_response_headers( $invalid_failure, $invalid_failure_raw, $form_signature ), $invalid_failure_raw, 403, $read_request, hash( 'sha256', $read_raw ), $response_key, $now, $error ), 'failure without error object is refused' );
$invalid_failure['error'] = array( 'code' => 'invalid_request', 'message' => 'Request could not be completed.' );
$invalid_failure_raw = json_encode( $invalid_failure );
fed01a_assert( ! fed01a_response_is_valid( $invalid_failure, fed01a_response_headers( $invalid_failure, $invalid_failure_raw, $form_signature ), $invalid_failure_raw, 403, $read_request, hash( 'sha256', $read_raw ), $response_key, $now, $error ), 'failure error code different from status is refused' );
$invalid_failure['error'] = array( 'code' => 'not_authorized', 'message' => 'member@example.test' );
$invalid_failure_raw = json_encode( $invalid_failure );
fed01a_assert( ! fed01a_response_is_valid( $invalid_failure, fed01a_response_headers( $invalid_failure, $invalid_failure_raw, $form_signature ), $invalid_failure_raw, 403, $read_request, hash( 'sha256', $read_raw ), $response_key, $now, $error ), 'sensitive data in public error is refused' );

if ( function_exists( 'sodium_crypto_sign_keypair' ) ) {
    $keypair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey( $keypair );
    $public = sodium_crypto_sign_publickey( $keypair );
    $signed_request = fed01a_canonical_request( $request, $raw_request );
    $request_signature = sodium_crypto_sign_detached( $signed_request, $secret );
    fed01a_assert( sodium_crypto_sign_verify_detached( $request_signature, $signed_request, $public ), 'ephemeral Ed25519 request signature verifies' );
    fed01a_assert( ! sodium_crypto_sign_verify_detached( $request_signature, $signed_request . 'x', $public ), 'altered request body binding is refused cryptographically' );
    $signed_response = fed01a_canonical_response( $response, 200, $raw_response );
    $response_signature = sodium_crypto_sign_detached( $signed_response, $secret );
    fed01a_assert( sodium_crypto_sign_verify_detached( $response_signature, $signed_response, $public ), 'ephemeral Ed25519 response signature verifies' );
    foreach ( array( fed01a_canonical_response( $response, 201, $raw_response ), fed01a_canonical_response( $response, 200, $raw_response . ' ' ), fed01a_canonical_response( $wrong_recipient, 200, $wrong_recipient_raw ), fed01a_canonical_response( $wrong_responder, 200, $wrong_responder_raw ) ) as $altered_message ) {
        fed01a_assert( ! sodium_crypto_sign_verify_detached( $response_signature, $altered_message, $public ), 'altered response body, status or routing is refused cryptographically' );
    }
    $altered_signature = $response_signature;
    $altered_signature[0] = chr( ord( $altered_signature[0] ) ^ 1 );
    fed01a_assert( ! sodium_crypto_sign_verify_detached( $altered_signature, $signed_response, $public ), 'altered response signature bytes are refused cryptographically' );
} else {
    fed01a_assert( 'fail_closed' === $request_schema['x-fed01a-transport']['sodium_unavailable'] && 'fail_closed' === $response_schema['x-fed01a-transport']['sodium_unavailable'], 'Sodium absence has only fail-closed behavior for both directions' );
    fwrite( STDOUT, 'Sodium unavailable: no real Ed25519 signing or verification was executed; fail-closed contract asserted.' . PHP_EOL );
}

fwrite( STDOUT, 'FED-01A.1 contract tests: OK (' . $fed01a_assertions . ' assertions).' . PHP_EOL );
