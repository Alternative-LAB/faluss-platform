<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** One-time, per-administrator PRG notices. No mutation result is placed in a URL. */
final class Faluss_Subscriptions_Admin_Notices {
    const PREFIX = 'faluss_subscriptions_admin_notice_';
    const TTL = 600;

    public static function add( $user_id, $type, $code, $context = array() ) {
        $user_id = absint( $user_id );
        if ( ! $user_id || ! in_array( $type, array( 'success', 'warning', 'error' ), true ) || ! is_string( $code ) ) {
            return false;
        }
        return set_transient(
            self::key( $user_id ),
            array(
                'type' => $type,
                'code' => sanitize_key( $code ),
                'context' => is_array( $context ) ? $context : array(),
            ),
            self::TTL
        );
    }

    /** Returns the notice exactly once for the administrator who caused it. */
    public static function consume( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return null;
        }
        $key = self::key( $user_id );
        $notice = get_transient( $key );
        delete_transient( $key );
        return is_array( $notice ) ? $notice : null;
    }

    private static function key( $user_id ) {
        return self::PREFIX . $user_id;
    }
}
