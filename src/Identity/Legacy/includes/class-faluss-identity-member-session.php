<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Sets a fixed, bounded WordPress session only for ordinary active Faluss
 * members. OTP and OAuth browser challenges keep their own short TTLs.
 */
final class Faluss_Identity_Member_Session {

    const TTL = 28800;

    public static function boot() {
        add_filter( 'auth_cookie_expiration', array( __CLASS__, 'member_cookie_expiration' ), PHP_INT_MAX, 3 );
    }

    /**
     * Do not change administrators, role combinations or technical users.
     * Normal member sessions have a fixed eight-hour server deadline; no
     * code in this plugin reissues them in the background.
     *
     * @param int  $expiration Current WordPress expiration in seconds.
     * @param int  $user_id WordPress user ID.
     * @param bool $remember Whether WordPress was asked for a persistent cookie.
     * @return int
     */
    public static function member_cookie_expiration( $expiration, $user_id, $remember ) {
        unset( $remember );
        return self::is_normal_active_member( $user_id ) ? self::TTL : $expiration;
    }

    /** Return the current member's verified WordPress session deadline. */
    public static function current_member_deadline() {
        $user_id = get_current_user_id();
        if ( ! self::is_normal_active_member( $user_id ) ) {
            return null;
        }
        $cookie = wp_parse_auth_cookie( '', 'logged_in' );
        if ( ! is_array( $cookie ) || ! isset( $cookie['expiration'], $cookie['token'] )
            || ! ctype_digit( (string) $cookie['expiration'] ) || ! is_string( $cookie['token'] ) || '' === $cookie['token'] ) {
            return null;
        }
        $session = WP_Session_Tokens::get_instance( $user_id )->get( $cookie['token'] );
        if ( ! is_array( $session ) || ! isset( $session['expiration'] ) || ! is_numeric( $session['expiration'] ) ) {
            return null;
        }
        $deadline = min( (int) $cookie['expiration'], (int) $session['expiration'] );
        return $deadline > time() ? $deadline : null;
    }

    private static function is_normal_active_member( $user_id ) {
        $user = get_userdata( (int) $user_id );
        if ( ! $user instanceof WP_User || array( 'subscriber' ) !== array_values( (array) $user->roles ) ) {
            return false;
        }
        return null !== Faluss_Identity_Registry::get_active_for_wp_user( $user->ID );
    }
}
