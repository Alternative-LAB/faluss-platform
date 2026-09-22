<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Sets a fixed, bounded WordPress session only for ordinary active Faluss
 * members. OTP and OAuth browser challenges keep their own short TTLs.
 */
final class Faluss_Identity_Member_Session {

    const TTL = 3600;

    public static function boot() {
        add_filter( 'auth_cookie_expiration', array( __CLASS__, 'member_cookie_expiration' ), PHP_INT_MAX, 3 );
    }

    /**
     * Do not change administrators, role combinations, technical users, or
     * remember-me semantics. Normal member sessions remain fixed at one hour:
     * no code in this plugin reissues them in the background.
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

    private static function is_normal_active_member( $user_id ) {
        $user = get_userdata( (int) $user_id );
        if ( ! $user instanceof WP_User || array( 'subscriber' ) !== array_values( (array) $user->roles ) ) {
            return false;
        }
        return null !== Faluss_Identity_Registry::get_active_for_wp_user( $user->ID );
    }
}
