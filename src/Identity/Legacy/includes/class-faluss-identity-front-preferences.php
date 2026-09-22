<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * FI-06 keeps the WordPress front toolbar out of Faluss subscriber sessions.
 * It stores no identity data and never changes a WordPress role.
 */
final class Faluss_Identity_Front_Preferences {

    const OPTION_VERSION = 'faluss_identity_front_preferences_version';
    const MIGRATION_VERSION = '1';

    public static function boot() {
        add_filter( 'show_admin_bar', array( __CLASS__, 'hide_front_admin_bar' ), 999 );
    }

    /**
     * A second front-end guard keeps an accidental profile preference change
     * from reintroducing the toolbar. WordPress administration is untouched.
     */
    public static function hide_front_admin_bar( $show ) {
        if ( is_admin() || ! is_user_logged_in() ) {
            return $show;
        }
        return self::is_eligible_user( wp_get_current_user() ) ? false : $show;
    }

    /** Applies the persisted WordPress preference when a passwordless proof activates an Identity profile. */
    public static function enforce_for_user( $user ) {
        if ( self::is_eligible_user( $user ) ) {
            update_user_meta( (int) $user->ID, 'show_admin_bar_front', 'false' );
        }
    }

    /**
     * Versioned, additive and idempotent. It starts from Identity profiles,
     * never from the global WordPress user list.
     */
    public static function migrate_fi06() {
        if ( self::MIGRATION_VERSION === (string) get_option( self::OPTION_VERSION, '' ) ) {
            return true;
        }
        if ( ! self::schema_ready() ) {
            return false;
        }
        global $wpdb;
        $table = self::profiles_table();
        if ( '' === $table || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) ) {
            return false;
        }
        $user_ids = $wpdb->get_col( 'SELECT wp_user_id FROM ' . self::quote_identifier( $table ) );
        if ( ! is_array( $user_ids ) ) {
            return false;
        }
        foreach ( array_unique( array_map( 'absint', $user_ids ) ) as $user_id ) {
            if ( $user_id < 1 ) {
                continue;
            }
            $user = get_userdata( $user_id );
            if ( self::is_exact_subscriber( $user ) ) {
                update_user_meta( $user_id, 'show_admin_bar_front', 'false' );
            }
        }
        update_option( self::OPTION_VERSION, self::MIGRATION_VERSION, false );
        return true;
    }

    private static function is_eligible_user( $user ) {
        return $user instanceof WP_User && self::is_exact_subscriber( $user ) && self::has_identity_profile( (int) $user->ID );
    }

    private static function is_exact_subscriber( $user ) {
        return $user instanceof WP_User && array( 'subscriber' ) === array_values( is_array( $user->roles ) ? $user->roles : array() );
    }

    private static function has_identity_profile( $user_id ) {
        global $wpdb;
        $table = self::profiles_table();
        if ( $user_id < 1 || '' === $table || ! self::schema_ready() || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
            return false;
        }
        return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::quote_identifier( $table ) . ' WHERE wp_user_id = %d LIMIT 1', $user_id ) );
    }

    private static function schema_ready() {
        $status = Faluss_Identity_Schema::get_status();
        return ! empty( $status['ready'] );
    }

    private static function profiles_table() {
        $tables = Faluss_Identity_Schema::get_table_names();
        return isset( $tables['profiles'] ) ? (string) $tables['profiles'] : '';
    }

    private static function quote_identifier( $identifier ) {
        return chr( 96 ) . str_replace( chr( 96 ), chr( 96 ) . chr( 96 ), $identifier ) . chr( 96 );
    }
}
