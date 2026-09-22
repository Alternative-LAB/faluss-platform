<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Ed25519-only primitive facade. Private buffers never leave this class. */
final class Faluss_Federation_Crypto {
    const PATH = '/wp-json/faluss-federation/v1/exchange';

    public static function sodium_available() {
        $required_constants = array( 'SODIUM_CRYPTO_SIGN_SEEDBYTES', 'SODIUM_CRYPTO_SIGN_KEYPAIRBYTES', 'SODIUM_CRYPTO_SIGN_SECRETKEYBYTES', 'SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES', 'SODIUM_CRYPTO_SIGN_BYTES' );
        $required_functions = array( 'sodium_crypto_sign_seed_keypair', 'sodium_crypto_sign_secretkey', 'sodium_crypto_sign_publickey', 'sodium_crypto_sign_detached', 'sodium_crypto_sign_verify_detached', 'sodium_memzero' );
        $constant_states = array();
        $function_states = array();

        foreach ( $required_constants as $constant ) {
            $constant_states[] = defined( $constant );
        }
        foreach ( $required_functions as $function ) {
            $function_states[] = function_exists( $function );
        }

        return self::sodium_requirements_met( extension_loaded( 'sodium' ), $constant_states, $function_states );
    }

    public static function auto_test() {
        if ( ! self::sodium_available() ) {
            return false;
        }
        return self::run_auto_test();
    }

    /** Run the primitive self-test after sodium_available() has closed the gate. */
    private static function run_auto_test() {
        $seed = null;
        $pair = null;
        $secret = null;
        $result = false;
        $cleaned = false;
        try {
            $seed = random_bytes( SODIUM_CRYPTO_SIGN_SEEDBYTES );
            $pair = sodium_crypto_sign_seed_keypair( $seed );
            $secret = sodium_crypto_sign_secretkey( $pair );
            $public = sodium_crypto_sign_publickey( $pair );
            $message = 'faluss-federation-ed25519-self-test-v1';
            $signature = sodium_crypto_sign_detached( $message, $secret );
            $result = sodium_crypto_sign_verify_detached( $signature, $message, $public ) && ! sodium_crypto_sign_verify_detached( $signature, $message . 'x', $public );
        } catch ( Throwable $throwable ) {
            $result = false;
        } finally {
            $secret_cleaned = self::wipe( $secret );
            $pair_cleaned = self::wipe( $pair );
            $seed_cleaned = self::wipe( $seed );
            $cleaned = $secret_cleaned && $pair_cleaned && $seed_cleaned;
        }
        return $cleaned && $result;
    }

    /** @return array<string,string>|WP_Error */
    public static function local_identity() {
        if ( ! self::sodium_available() || ! self::auto_test() ) {
            return new WP_Error( 'faluss_federation_sodium_unavailable' );
        }
        $names = array( 'FALUSS_FEDERATION_LOCAL_NODE_ID', 'FALUSS_FEDERATION_LOCAL_APP_KEY', 'FALUSS_FEDERATION_LOCAL_ORIGIN', 'FALUSS_FEDERATION_LOCAL_KEY_ID', 'FALUSS_FEDERATION_LOCAL_KEY_VALID_FROM', 'FALUSS_FEDERATION_LOCAL_KEY_VALID_UNTIL', 'FALUSS_FEDERATION_PRIVATE_SEED' );
        foreach ( $names as $name ) {
            if ( ! defined( $name ) || ! is_string( constant( $name ) ) || '' === constant( $name ) ) {
                return new WP_Error( 'faluss_federation_invalid_local_config' );
            }
        }
        $node = constant( 'FALUSS_FEDERATION_LOCAL_NODE_ID' );
        $app = constant( 'FALUSS_FEDERATION_LOCAL_APP_KEY' );
        $origin = constant( 'FALUSS_FEDERATION_LOCAL_ORIGIN' );
        $key_id = constant( 'FALUSS_FEDERATION_LOCAL_KEY_ID' );
        $from = constant( 'FALUSS_FEDERATION_LOCAL_KEY_VALID_FROM' );
        $until = constant( 'FALUSS_FEDERATION_LOCAL_KEY_VALID_UNTIL' );
        if ( ! self::is_node( $node ) || ! self::is_node( $app ) || ! self::is_key_id( $key_id ) || ! self::is_canonical_origin( $origin ) || ! self::origin_matches_wordpress( $origin ) || ! self::valid_key_period( $from, $until ) ) {
            return new WP_Error( 'faluss_federation_invalid_local_config' );
        }
        $seed = self::base64url_decode( constant( 'FALUSS_FEDERATION_PRIVATE_SEED' ), 32 );
        if ( is_wp_error( $seed ) ) {
            return new WP_Error( 'faluss_federation_invalid_local_config' );
        }
        $pair = null;
        $result = new WP_Error( 'faluss_federation_fail_closed' );
        $cleaned = false;
        try {
            $pair = sodium_crypto_sign_seed_keypair( $seed );
            $public = sodium_crypto_sign_publickey( $pair );
            $result = array( 'node_id' => $node, 'app_key' => $app, 'origin' => $origin, 'key_id' => $key_id, 'valid_from' => $from, 'valid_until' => $until, 'public_key' => self::base64url_encode( $public ) );
        } catch ( Throwable $throwable ) {
            $result = new WP_Error( 'faluss_federation_invalid_local_config' );
        } finally {
            $pair_cleaned = self::wipe( $pair );
            $seed_cleaned = self::wipe( $seed );
            $cleaned = $pair_cleaned && $seed_cleaned;
        }
        return $cleaned ? $result : new WP_Error( 'faluss_federation_fail_closed' );
    }

    public static function transport_ready() {
        if ( ! Faluss_Federation_Schema::is_ready() || ! self::sodium_available() || ! self::auto_test() ) {
            return false;
        }
        $identity = self::local_identity();
        return ! is_wp_error( $identity ) && Faluss_Federation_Policy::has_usable_peer();
    }

    /** @return string|WP_Error */
    public static function sign( $message ) {
        if ( ! is_string( $message ) || ! self::is_canonical_string( $message ) || ! self::sodium_available() ) {
            return new WP_Error( 'faluss_federation_fail_closed' );
        }
        $seed = defined( 'FALUSS_FEDERATION_PRIVATE_SEED' ) ? self::base64url_decode( constant( 'FALUSS_FEDERATION_PRIVATE_SEED' ), 32 ) : new WP_Error( 'faluss_federation_fail_closed' );
        if ( is_wp_error( $seed ) ) {
            return new WP_Error( 'faluss_federation_fail_closed' );
        }
        $pair = null;
        $secret = null;
        $result = new WP_Error( 'faluss_federation_fail_closed' );
        $cleaned = false;
        try {
            $pair = sodium_crypto_sign_seed_keypair( $seed );
            $secret = sodium_crypto_sign_secretkey( $pair );
            $result = self::base64url_encode( sodium_crypto_sign_detached( $message, $secret ) );
        } catch ( Throwable $throwable ) {
            $result = new WP_Error( 'faluss_federation_fail_closed' );
        } finally {
            $secret_cleaned = self::wipe( $secret );
            $pair_cleaned = self::wipe( $pair );
            $seed_cleaned = self::wipe( $seed );
            $cleaned = $secret_cleaned && $pair_cleaned && $seed_cleaned;
        }
        return $cleaned ? $result : new WP_Error( 'faluss_federation_fail_closed' );
    }

    public static function verify( $message, $signature, $public_key ) {
        if ( ! is_string( $message ) || ! self::is_canonical_string( $message ) || ! self::is_signature( $signature ) || ! self::sodium_available() ) {
            return false;
        }
        $decoded_signature = self::base64url_decode( $signature, 64 );
        $decoded_public = self::base64url_decode( $public_key, 32 );
        if ( is_wp_error( $decoded_signature ) || is_wp_error( $decoded_public ) ) {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached( $decoded_signature, $message, $decoded_public );
        } catch ( Throwable $throwable ) {
            return false;
        }
    }

    public static function base64url_encode( $value ) {
        return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
    }

    /** @return string|WP_Error */
    public static function base64url_decode( $value, $expected_bytes ) {
        if ( ! is_string( $value ) || ! is_int( $expected_bytes ) || $expected_bytes < 1 || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/D', $value ) || 1 === strlen( $value ) % 4 ) {
            return new WP_Error( 'faluss_federation_invalid_encoding' );
        }
        $padded = strtr( $value, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $value ) % 4 ) % 4 );
        $decoded = base64_decode( $padded, true );
        if ( ! is_string( $decoded ) || $expected_bytes !== strlen( $decoded ) || ! hash_equals( $value, self::base64url_encode( $decoded ) ) ) {
            return new WP_Error( 'faluss_federation_invalid_encoding' );
        }
        return $decoded;
    }

    public static function is_nonce( $value ) {
        return is_string( $value ) && 43 === strlen( $value ) && ! is_wp_error( self::base64url_decode( $value, 32 ) );
    }

    public static function is_signature( $value ) {
        return is_string( $value ) && 86 === strlen( $value ) && ! is_wp_error( self::base64url_decode( $value, 64 ) );
    }

    /** @return string|WP_Error */
    public static function format_utc_timestamp( $timestamp ) {
        if ( ! is_int( $timestamp ) || $timestamp < 0 ) {
            return new WP_Error( 'faluss_federation_invalid_timestamp' );
        }
        $value = gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
        $parsed = self::parse_utc_timestamp( $value );
        return is_wp_error( $parsed ) || $timestamp !== $parsed ? new WP_Error( 'faluss_federation_invalid_timestamp' ) : $value;
    }

    /** @return int|WP_Error */
    public static function parse_utc_timestamp( $value ) {
        if ( ! is_string( $value ) || 20 !== strlen( $value ) || 1 !== preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D', $value ) ) {
            return new WP_Error( 'faluss_federation_invalid_timestamp' );
        }
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $value, new DateTimeZone( 'UTC' ) );
        $errors = DateTimeImmutable::getLastErrors();
        if ( false === $date || ( is_array( $errors ) && ( 0 !== $errors['warning_count'] || 0 !== $errors['error_count'] ) ) || $value !== $date->format( 'Y-m-d\TH:i:s\Z' ) ) {
            return new WP_Error( 'faluss_federation_invalid_timestamp' );
        }
        return $date->getTimestamp();
    }

    public static function is_utc_timestamp( $value ) {
        return ! is_wp_error( self::parse_utc_timestamp( $value ) );
    }

    public static function request_canonical( $request, $raw_body ) {
        return self::canonical_join( array( $request['protocol_version'] ?? '', 'POST', self::PATH, $request['sender']['node_id'] ?? '', $request['recipient']['node_id'] ?? '', $request['sender']['key_id'] ?? '', $request['issued_at'] ?? '', $request['expires_at'] ?? '', $request['nonce'] ?? '', hash( 'sha256', $raw_body ) ) );
    }

    public static function response_canonical( $response, $http_status, $raw_body ) {
        return self::canonical_join( array( $response['protocol_version'] ?? '', (string) $http_status, $response['request_id'] ?? '', $response['request_body_sha256'] ?? '', $response['responder']['node_id'] ?? '', $response['recipient']['node_id'] ?? '', $response['responder']['key_id'] ?? '', $response['generated_at'] ?? '', $response['expires_at'] ?? '', hash( 'sha256', $raw_body ) ) );
    }

    /** @return string|WP_Error */
    public static function canonical_join( $components ) {
        if ( ! is_array( $components ) || empty( $components ) ) {
            return new WP_Error( 'faluss_federation_noncanonical' );
        }
        foreach ( $components as $component ) {
            if ( ! is_string( $component ) || false !== strpos( $component, "\r" ) || false !== strpos( $component, "\n" ) || 0 === strpos( $component, "\xEF\xBB\xBF" ) ) {
                return new WP_Error( 'faluss_federation_noncanonical' );
            }
        }
        $canonical = implode( "\n", $components );
        return self::is_canonical_string( $canonical ) ? $canonical : new WP_Error( 'faluss_federation_noncanonical' );
    }

    public static function is_canonical_origin( $origin ) {
        $parts = wp_parse_url( $origin );
        return is_string( $origin ) && is_array( $parts ) && 'https' === ( $parts['scheme'] ?? null ) && isset( $parts['host'] ) && ! isset( $parts['port'] ) && ! isset( $parts['path'] ) && ! isset( $parts['query'] ) && ! isset( $parts['fragment'] ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && $origin === 'https://' . $parts['host'];
    }

    public static function is_uuid( $value ) { return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value ); }
    public static function is_node( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-z][a-z0-9-]{1,63}$/D', $value ); }
    public static function is_key_id( $value ) { return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{7,127}$/D', $value ); }
    public static function is_sha256( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value ); }
    public static function is_semver( $value ) { return is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*\.[0-9]+\.[0-9]+$/D', $value ); }

    private static function valid_key_period( $from, $until ) {
        $start = self::parse_utc_timestamp( $from );
        $end = self::parse_utc_timestamp( $until );
        $now = time();
        return ! is_wp_error( $start ) && ! is_wp_error( $end ) && $end > $start && $start <= $now && $end >= $now;
    }

    private static function origin_matches_wordpress( $origin ) {
        $home = untrailingslashit( home_url( '/' ) );
        return is_string( $home ) && hash_equals( $origin, $home );
    }

    private static function is_canonical_string( $value ) {
        return is_string( $value ) && '' !== $value && false === strpos( $value, "\r" ) && 0 !== strpos( $value, "\xEF\xBB\xBF" ) && "\n" !== substr( $value, -1 );
    }

    /**
     * Keep the native extension decision independently testable without adding
     * a runtime override capable of accepting sodium_compat.
     */
    private static function sodium_requirements_met( $native_loaded, array $constant_states, array $function_states ) {
        return true === $native_loaded && ! in_array( false, $constant_states, true ) && ! in_array( false, $function_states, true );
    }

    /** @return bool Whether the required cleanup completed. */
    private static function wipe( &$value ) {
        $cleaned = true;
        try {
            if ( is_string( $value ) && '' !== $value ) {
                if ( ! function_exists( 'sodium_memzero' ) ) {
                    $cleaned = false;
                } else {
                    sodium_memzero( $value );
                }
            }
        } catch ( Throwable $throwable ) {
            $cleaned = false;
        }
        $value = null;
        return $cleaned;
    }
}
