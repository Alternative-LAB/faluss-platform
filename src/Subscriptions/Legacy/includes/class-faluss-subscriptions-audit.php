<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Immutable, minimised record of administrative and service mutations. */
final class Faluss_Subscriptions_Audit {
    /** @return bool|WP_Error */
    public static function record( $actor_user_id, $action, $faluss_id, $source, $previous_state, $next_state, $justification = null ) {
        global $wpdb;
        if ( ! Faluss_Subscriptions_Schema::is_ready() ) {
            return self::error( 'schema_not_ready' );
        }
        $action = self::bounded( $action, 80 );
        $source = self::bounded( $source, 32 );
        $faluss_id = self::faluss_id_or_null( $faluss_id );
        if ( '' === $action || '' === $source ) {
            return self::error( 'audit_record_invalid' );
        }
        $written = $wpdb->insert(
            Faluss_Subscriptions_Schema::audit_table(),
            array(
                'audit_uuid' => wp_generate_uuid4(),
                'actor_user_id' => $actor_user_id > 0 ? (int) $actor_user_id : null,
                'action' => $action,
                'faluss_id' => $faluss_id,
                'source' => $source,
                'previous_state' => self::state_json( $previous_state ),
                'next_state' => self::state_json( $next_state ),
                'justification' => self::bounded( $justification, 191 ),
                'created_at' => gmdate( 'Y-m-d H:i:s' ),
            ),
            array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );
        return false === $written ? self::error( 'audit_record_failed' ) : true;
    }

    /** @return array<int,array<string,mixed>> */
    public static function recent( $faluss_id = '', $limit = 50 ) {
        global $wpdb;
        $limit = max( 1, min( 100, (int) $limit ) );
        $table = Faluss_Subscriptions_Schema::audit_table();
        if ( self::valid_faluss_id( $faluss_id ) ) {
            return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Faluss_Subscriptions_Schema::quote_identifier( $table ) . ' WHERE faluss_id=%s ORDER BY id DESC LIMIT %d', $faluss_id, $limit ), ARRAY_A );
        }
        return (array) $wpdb->get_results( 'SELECT * FROM ' . Faluss_Subscriptions_Schema::quote_identifier( $table ) . ' ORDER BY id DESC LIMIT ' . $limit, ARRAY_A );
    }

    private static function state_json( $state ) {
        if ( ! is_array( $state ) ) {
            return null;
        }
        $clean = self::clean_state( $state );
        return $clean ? wp_json_encode( $clean ) : null;
    }

    /** Never put payment, raw provider payload, secrets or personal data in audit states. */
    private static function clean_state( $state ) {
        $clean = array();
        foreach ( $state as $key => $value ) {
            $key = self::bounded( $key, 64 );
            if ( '' === $key || preg_match( '/email|secret|token|card|payment|payload|fingerprint/i', $key ) ) {
                continue;
            }
            if ( is_array( $value ) ) {
                $clean[ $key ] = self::clean_state( $value );
            } elseif ( is_scalar( $value ) || null === $value ) {
                $clean[ $key ] = self::bounded( (string) $value, 191 );
            }
        }
        return $clean;
    }

    private static function bounded( $value, $length ) {
        $value = is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : '';
        return function_exists( 'mb_substr' ) ? mb_substr( trim( $value ), 0, $length ) : substr( trim( $value ), 0, $length );
    }

    private static function valid_faluss_id( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $value );
    }

    private static function faluss_id_or_null( $value ) { return self::valid_faluss_id( $value ) ? strtolower( $value ) : null; }
    private static function error( $code ) { return new WP_Error( $code, __( 'Le journal d’audit ne peut pas être enregistré.', 'faluss-subscriptions' ) ); }
}
