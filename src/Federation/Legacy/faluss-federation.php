<?php
/**
 * Plugin Name: Faluss Federation
 * Description: Private, signed server-to-server Federation transport.
 * Version: 0.3.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Faluss
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// EVT-01B.2B upgrade base — Version: 0.2.0; schema remains 1 without migration.

define( 'FALUSS_FEDERATION_VERSION', '0.3.0' );
define( 'FALUSS_FEDERATION_SCHEMA_VERSION', '1' );
define( 'FALUSS_FEDERATION_DIR', plugin_dir_path( __FILE__ ) );

require_once FALUSS_FEDERATION_DIR . 'includes/class-faluss-federation-schema.php';
require_once FALUSS_FEDERATION_DIR . 'includes/class-faluss-federation-crypto.php';
require_once FALUSS_FEDERATION_DIR . 'includes/class-faluss-federation-policy.php';
require_once FALUSS_FEDERATION_DIR . 'includes/class-faluss-federation-providers.php';
require_once FALUSS_FEDERATION_DIR . 'includes/class-faluss-federation-server.php';
require_once FALUSS_FEDERATION_DIR . 'includes/class-faluss-federation-client.php';
require_once FALUSS_FEDERATION_DIR . 'includes/class-faluss-federation-admin.php';

final class Faluss_Federation {
    public static function boot() {
        register_activation_hook( __FILE__, array( 'Faluss_Federation_Schema', 'activate' ) );
        add_action( 'plugins_loaded', array( __CLASS__, 'load' ) );
    }

    /** The plugin stays administrable when Sodium or protected constants are absent. */
    public static function load() {
        Faluss_Federation_Providers::boot();
        if ( function_exists( 'do_action' ) ) {
            do_action( 'faluss_federation_ready' );
        }
        Faluss_Federation_Server::boot();
        Faluss_Federation_Admin::boot();
    }
}

Faluss_Federation::boot();
