<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shared, presentation-only helpers for the Elementor Navigation Faluss widget.
 *
 * The navigation owns no profile data and does not alter the public-route shell.
 */
final class Faluss_Identity_Navigation {

    const STYLE_HANDLE  = 'faluss-identity-navigation';
    const SCRIPT_HANDLE = 'faluss-identity-navigation';

    /**
     * Registers assets early enough for Elementor dependency resolution.
     *
     * @return void
     */
    public static function register_assets() {
        wp_register_style(
            self::STYLE_HANDLE,
            plugins_url( 'assets/css/faluss-identity-navigation.css', FALUSS_IDENTITY_FILE ),
            array(),
            FALUSS_IDENTITY_VERSION
        );
        wp_register_script(
            self::SCRIPT_HANDLE,
            plugins_url( 'assets/js/faluss-identity-navigation.js', FALUSS_IDENTITY_FILE ),
            array(),
            FALUSS_IDENTITY_VERSION,
            true
        );
    }

    /**
     * Returns the current request only when it is a local, non-protocol-relative URL.
     *
     * @return string
     */
    public static function current_local_return_url() {
        $fallback = home_url( '/' );
        $request  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';

        if ( ! is_string( $request ) || '' === $request || '/' !== substr( $request, 0, 1 ) || 0 === strpos( $request, '//' ) ) {
            return $fallback;
        }

        $candidate = home_url( $request );
        return wp_validate_redirect( $candidate, $fallback );
    }

    /**
     * Builds the local login URL while retaining a validated local return path.
     *
     * @return string
     */
    public static function login_url() {
        return add_query_arg( 'redirect_to', self::current_local_return_url(), home_url( '/login/' ) );
    }

    /**
     * Supplies exactly the three navigation actions rendered by each widget instance.
     *
     * @param array $labels Display labels supplied by the Elementor instance.
     * @return array<int, array<string, string>>
     */
    public static function actions( $labels = array() ) {
        $defaults = array(
            'my_faluss' => __( 'Mon Faluss', 'faluss-identity' ),
            'my_list'   => __( 'Ma liste', 'faluss-identity' ),
            'login'     => __( 'Connexion', 'faluss-identity' ),
            'logout'    => __( 'Déconnexion', 'faluss-identity' ),
        );
        $labels = wp_parse_args( is_array( $labels ) ? $labels : array(), $defaults );
        $logged = is_user_logged_in();
        $my_faluss_url = self::my_faluss_url( $logged );

        return array(
            array(
                'key'   => 'my_faluss',
                'label' => (string) $labels['my_faluss'],
                'url'   => $my_faluss_url,
            ),
            array(
                'key'   => 'my_list',
                'label' => (string) $labels['my_list'],
                'url'   => home_url( '/list/' ),
            ),
            array(
                'key'   => 'smart',
                'label' => (string) ( $logged ? $labels['logout'] : $labels['login'] ),
                'url'   => $logged ? wp_logout_url( self::current_local_return_url() ) : self::login_url(),
            ),
        );
    }

    /** A member without a claimed public profile starts the explicit ONB flow. */
    private static function my_faluss_url( $logged ) {
        if ( ! $logged || ! class_exists( 'Faluss_Identity_Registry' ) || ! class_exists( 'Faluss_Identity_Public_Profile' ) || ! class_exists( 'Faluss_Identity_Onboarding' ) ) {
            return home_url( '/mon-faluss/' );
        }
        $faluss_id = Faluss_Identity_Registry::get_active_for_wp_user( get_current_user_id() );
        if ( null === $faluss_id || Faluss_Identity_Public_Profile::has_profile_for_faluss_id( $faluss_id ) ) {
            return home_url( '/mon-faluss/' );
        }
        return Faluss_Identity_Onboarding::onboarding_url();
    }
}
