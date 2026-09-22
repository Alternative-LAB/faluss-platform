<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Deterministic JSON bytes for the closed EVT-01A PHP value subset. */
final class Faluss_Events_Canonicalizer {
    const MAX_SAFE_INTEGER = 9007199254740991;

    /** @return string|WP_Error */
    public static function canonicalize( $value ) {
        return self::encode_value( $value );
    }

    /** @return string|WP_Error */
    public static function canonicalize_catalog( $catalog ) {
        if ( ! Faluss_Events_Catalog_Validator::validate( $catalog ) ) {
            return self::failure();
        }
        return self::encode_value( $catalog );
    }

    /** The EVT payload root is an object even when represented by an empty PHP array. @return string|WP_Error */
    public static function canonicalize_event( $event ) {
        if ( ! is_array( $event ) || ! array_key_exists( 'payload', $event ) ) {
            return self::failure();
        }
        return self::encode_value( $event, '', 'event' );
    }

    public static function sha256( $canonical_bytes ) {
        return is_string( $canonical_bytes ) ? hash( 'sha256', $canonical_bytes ) : self::failure();
    }

    /** @return string|WP_Error */
    private static function encode_value( $value, $path = '', $mode = 'generic' ) {
        if ( null === $value ) {
            return 'null';
        }
        if ( true === $value ) {
            return 'true';
        }
        if ( false === $value ) {
            return 'false';
        }
        if ( is_int( $value ) ) {
            return $value >= -self::MAX_SAFE_INTEGER && $value <= self::MAX_SAFE_INTEGER ? (string) $value : self::failure();
        }
        if ( is_string( $value ) ) {
            return self::encode_string( $value );
        }
        if ( ! is_array( $value ) ) {
            return self::failure();
        }

        if ( array() === $value && 'event' === $mode && '/payload' === $path ) {
            return '{}';
        }
        if ( self::is_list( $value ) ) {
            $encoded = array();
            foreach ( $value as $index => $child ) {
                $item = self::encode_value( $child, $path . '/' . $index, $mode );
                if ( is_wp_error( $item ) ) {
                    return $item;
                }
                $encoded[] = $item;
            }
            return '[' . implode( ',', $encoded ) . ']';
        }

        $keys = array_keys( $value );
        foreach ( $keys as $key ) {
            if ( ! is_string( $key ) || 1 !== preg_match( '/^[\x20-\x7E]+$/D', $key ) ) {
                return self::failure();
            }
        }
        sort( $keys, SORT_STRING );
        $encoded = array();
        foreach ( $keys as $key ) {
            $encoded_key = self::encode_string( $key );
            $encoded_value = self::encode_value( $value[ $key ], $path . '/' . $key, $mode );
            if ( is_wp_error( $encoded_key ) || is_wp_error( $encoded_value ) ) {
                return self::failure();
            }
            $encoded[] = $encoded_key . ':' . $encoded_value;
        }
        return '{' . implode( ',', $encoded ) . '}';
    }

    /** @return string|WP_Error */
    private static function encode_string( $value ) {
        if ( 1 !== preg_match( '//u', $value ) ) {
            return self::failure();
        }
        $encoded = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS );
        return is_string( $encoded ) ? $encoded : self::failure();
    }

    private static function is_list( $value ) {
        return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
    }

    private static function failure() {
        return new WP_Error( 'faluss_events_invalid_document' );
    }
}
