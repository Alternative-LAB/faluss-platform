<?php
/**
 * Plugin Name: Faluss Analytics
 * Description: Agrégation privée et minimale des événements Analytics Faluss.
 * Version: 0.1.1
 * Requires at least: 6.4
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'FALUSS_ANALYTICS_VERSION', '0.1.1' );
define( 'FALUSS_ANALYTICS_SCHEMA_VERSION', '1' );

require_once __DIR__ . '/includes/class-faluss-analytics-schema.php';
require_once __DIR__ . '/includes/class-faluss-analytics-event-validator.php';
require_once __DIR__ . '/includes/class-faluss-analytics-consumer.php';
require_once __DIR__ . '/includes/class-faluss-analytics-read-model.php';
require_once __DIR__ . '/includes/class-faluss-analytics-retention.php';
require_once __DIR__ . '/includes/class-faluss-analytics.php';

register_activation_hook( __FILE__, array( 'Faluss_Analytics_Schema', 'activate' ) );
register_activation_hook( __FILE__, array( 'Faluss_Analytics_Retention', 'activate' ) );
if ( function_exists( 'register_deactivation_hook' ) ) {
    register_deactivation_hook( __FILE__, array( 'Faluss_Analytics_Retention', 'deactivate' ) );
}
add_action( 'plugins_loaded', array( 'Faluss_Analytics_Schema', 'maybe_upgrade' ), 5 );

Faluss_Analytics::boot();
Faluss_Analytics_Retention::boot();
