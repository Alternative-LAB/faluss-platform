<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Faluss_Identity_Registry {

    /**
     * Returns an existing identity or creates one pending internal profile.
     * This primitive is not attached to a public request.
     *
     * @param int $wp_user_id Local WordPress user ID.
     * @return string|null
     */
    public static function get_or_create_for_wp_user( $wp_user_id ) {
        global $wpdb;

        $wp_user_id = (int) $wp_user_id;
        if ( $wp_user_id < 1 || ! self::schema_is_ready() || ! self::local_user_exists( $wp_user_id ) ) {
            return null;
        }

        $table = self::profiles_table();
        $existing = self::find_by_wp_user_id( $table, $wp_user_id );
        if ( null !== $existing ) {
            return $existing;
        }

        for ( $attempt = 0; $attempt < 3; ++$attempt ) {
            $faluss_id = self::generate_faluss_id();
            if ( null === $faluss_id ) {
                return null;
            }

            $inserted = $wpdb->query(
                $wpdb->prepare(
                    'INSERT INTO ' . self::quote_identifier( $table ) . ' (faluss_id, wp_user_id, status, created_at) VALUES (%s, %d, %s, %s)',
                    $faluss_id,
                    $wp_user_id,
                    'pending',
                    current_time( 'mysql', true )
                )
            );
            if ( 1 === $inserted ) {
                return $faluss_id;
            }

            // A concurrent request may have won the unique wp_user_id insert.
            $existing = self::find_by_wp_user_id( $table, $wp_user_id );
            if ( null !== $existing ) {
                return $existing;
            }
        }

        return null;
    }

    public static function is_valid_faluss_id( $faluss_id ) {
        return is_string( $faluss_id ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $faluss_id );
    }

    /**
     * Activates the profile only after a successful identity proof. A suspended
     * or otherwise unknown profile fails closed and is never silently revived.
     *
     * @param int $wp_user_id Local WordPress user ID.
     * @return string|null Active Faluss ID.
     */
    public static function activate_for_wp_user( $wp_user_id ) {
        global $wpdb;

        $wp_user_id = (int) $wp_user_id;
        if ( $wp_user_id < 1 || ! self::schema_is_ready() || ! self::local_user_exists( $wp_user_id ) ) {
            return null;
        }

        $faluss_id = self::get_or_create_for_wp_user( $wp_user_id );
        if ( null === $faluss_id ) {
            return null;
        }

        $table = self::profiles_table();
        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . self::quote_identifier( $table ) . ' SET status = %s, updated_at = %s WHERE wp_user_id = %d AND status IN (%s, %s)',
                'active',
                current_time( 'mysql', true ),
                $wp_user_id,
                'pending',
                'active'
            )
        );
        if ( false === $updated ) {
            return null;
        }

        $active = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT faluss_id FROM ' . self::quote_identifier( $table ) . ' WHERE wp_user_id = %d AND status = %s',
                $wp_user_id,
                'active'
            )
        );

        return self::is_valid_faluss_id( $active ) ? $active : null;
    }

    /**
     * Resolves an already active local Identity session to its stable Faluss ID.
     * It never creates or activates a profile as a side effect.
     *
     * @param int $wp_user_id Local WordPress user ID.
     * @return string|null
     */
    public static function get_active_for_wp_user( $wp_user_id ) {
        global $wpdb;

        $wp_user_id = (int) $wp_user_id;
        if ( $wp_user_id < 1 || ! self::schema_is_ready() ) {
            return null;
        }
        $faluss_id = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT faluss_id FROM ' . self::quote_identifier( self::profiles_table() ) . ' WHERE wp_user_id = %d AND status = %s',
                $wp_user_id,
                'active'
            )
        );
        return self::is_valid_faluss_id( $faluss_id ) ? $faluss_id : null;
    }

    private static function schema_is_ready() {
        $status = Faluss_Identity_Schema::get_status();
        return ! empty( $status['ready'] );
    }

    private static function local_user_exists( $wp_user_id ) {
        global $wpdb;

        if ( ! isset( $wpdb->users ) || ! method_exists( $wpdb, 'get_var' ) ) {
            return false;
        }
        return (int) $wp_user_id === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT ID FROM ' . self::quote_identifier( $wpdb->users ) . ' WHERE ID = %d', $wp_user_id ) );
    }

    private static function profiles_table() {
        $tables = Faluss_Identity_Schema::get_table_names();
        return isset( $tables['profiles'] ) ? $tables['profiles'] : '';
    }

    private static function find_by_wp_user_id( $table, $wp_user_id ) {
        global $wpdb;

        $faluss_id = $wpdb->get_var( $wpdb->prepare( 'SELECT faluss_id FROM ' . self::quote_identifier( $table ) . ' WHERE wp_user_id = %d', $wp_user_id ) );
        return self::is_valid_faluss_id( $faluss_id ) ? $faluss_id : null;
    }

    private static function generate_faluss_id() {
        try {
            $bytes = random_bytes( 16 );
        } catch ( Exception $exception ) {
            return null;
        }

        $bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
        $bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
        $hex = bin2hex( $bytes );

        return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
    }

    private static function quote_identifier( $identifier ) {
        return chr( 96 ) . str_replace( chr( 96 ), chr( 96 ) . chr( 96 ), $identifier ) . chr( 96 );
    }
}
