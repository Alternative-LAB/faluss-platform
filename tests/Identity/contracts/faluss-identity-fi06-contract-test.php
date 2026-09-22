<?php

define( 'ABSPATH', __DIR__ . '/' );

class WP_User {
    public $ID;
    public $roles;
    public function __construct( $id, $roles ) { $this->ID = $id; $this->roles = $roles; }
}

$fi06_options = array(); $fi06_users = array(); $fi06_meta = array(); $fi06_filters = array(); $fi06_is_admin = false; $fi06_current_user = null;
function absint( $value ) { return abs( (int) $value ); }
function is_admin() { global $fi06_is_admin; return $fi06_is_admin; }
function is_user_logged_in() { global $fi06_current_user; return $fi06_current_user instanceof WP_User; }
function wp_get_current_user() { global $fi06_current_user; return $fi06_current_user; }
function get_userdata( $user_id ) { global $fi06_users; return $fi06_users[ (int) $user_id ] ?? false; }
function get_option( $key, $default = false ) { global $fi06_options; return $fi06_options[ $key ] ?? $default; }
function update_option( $key, $value ) { global $fi06_options; $fi06_options[ $key ] = $value; return true; }
function update_user_meta( $user_id, $key, $value ) { global $fi06_meta; $fi06_meta[] = array( (int) $user_id, $key, $value ); return true; }
function add_filter( $hook, $callback, $priority = 10 ) { global $fi06_filters; $fi06_filters[ $hook ][] = $callback; }

final class Faluss_Identity_Schema {
    public static function get_status() { return array( 'ready' => true ); }
    public static function get_table_names() { return array( 'profiles' => 'wp_faluss_identity_profiles' ); }
}
class FI06_WPDB {
    public $profile_ids = array(); public $profile_exists = array(); public $prepared_args = array();
    public function prepare( $query, ...$args ) { $this->prepared_args = $args; return $query; }
    public function get_col( $query ) { return $this->profile_ids; }
    public function get_var( $query ) { $id = (int) ( $this->prepared_args[0] ?? 0 ); return ! empty( $this->profile_exists[ $id ] ) ? '1' : null; }
}
function fi06_assert( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); } }

$root = dirname( __DIR__, 3 );
$preferences = file_get_contents( $root . '/src/Identity/Legacy/includes/class-faluss-identity-front-preferences.php' );
$plugin = file_get_contents( $root . '/src/Identity/Legacy/includes/class-faluss-identity-plugin.php' );
$passwordless = file_get_contents( $root . '/src/Identity/Legacy/includes/class-faluss-identity-passwordless.php' );
foreach ( array( 'show_admin_bar', 'show_admin_bar_front', 'MIGRATION_VERSION', 'migrate_fi06', 'SELECT wp_user_id FROM', "array( 'subscriber' )", 'is_eligible_user' ) as $needle ) { fi06_assert( false !== strpos( $preferences, $needle ), 'FI-06 front preference invariant is missing: ' . $needle ); }
fi06_assert( false === strpos( $preferences, 'elementor' ), 'FI-06 must not invent or alter Elementor user metadata.' );
fi06_assert( false !== strpos( $plugin, 'Faluss_Identity_Front_Preferences::boot' ) && false !== strpos( $plugin, 'Faluss_Identity_Front_Preferences::migrate_fi06' ), 'FI-06 must boot its front guard and migration.' );
fi06_assert( false !== strpos( $passwordless, 'Faluss_Identity_Front_Preferences::enforce_for_user' ) && strpos( $passwordless, 'Faluss_Identity_Registry::activate_for_wp_user' ) < strpos( $passwordless, 'Faluss_Identity_Front_Preferences::enforce_for_user' ), 'Passwordless creation and recovery must enforce the front preference only after Identity activation.' );

global $wpdb, $fi06_users, $fi06_meta, $fi06_current_user, $fi06_is_admin;
$wpdb = new FI06_WPDB();
$wpdb->profile_ids = array( 11, 12, 13, 11 );
$wpdb->profile_exists = array( 11 => true, 12 => true, 13 => true );
$fi06_users = array(
    11 => new WP_User( 11, array( 'subscriber' ) ),
    12 => new WP_User( 12, array( 'administrator' ) ),
    13 => new WP_User( 13, array( 'subscriber', 'customer' ) ),
    14 => new WP_User( 14, array( 'subscriber' ) ),
);

require_once $root . '/src/Identity/Legacy/includes/class-faluss-identity-front-preferences.php';
Faluss_Identity_Front_Preferences::boot();
fi06_assert( ! empty( $fi06_filters['show_admin_bar'] ), 'The front toolbar guard must be registered.' );
fi06_assert( Faluss_Identity_Front_Preferences::migrate_fi06(), 'The bounded FI-06 migration must complete for a ready Identity schema.' );
fi06_assert( array( array( 11, 'show_admin_bar_front', 'false' ) ) === $fi06_meta, 'Only the sole subscriber drawn from the Identity profile list receives the disabled toolbar preference.' );
fi06_assert( '1' === $fi06_options[ Faluss_Identity_Front_Preferences::OPTION_VERSION ], 'The FI-06 migration records its own version only after completion.' );
$writes = count( $fi06_meta );
fi06_assert( Faluss_Identity_Front_Preferences::migrate_fi06() && $writes === count( $fi06_meta ), 'The FI-06 migration is idempotent.' );

$fi06_current_user = $fi06_users[11];
fi06_assert( false === Faluss_Identity_Front_Preferences::hide_front_admin_bar( true ), 'An eligible Faluss subscriber is denied the front toolbar even if the preference is toggled back on.' );
$fi06_current_user = $fi06_users[12];
fi06_assert( true === Faluss_Identity_Front_Preferences::hide_front_admin_bar( true ), 'An administrator with a Faluss profile keeps the WordPress toolbar.' );
$fi06_current_user = $fi06_users[13];
fi06_assert( true === Faluss_Identity_Front_Preferences::hide_front_admin_bar( true ), 'A subscriber combined with another role is not modified.' );
$fi06_current_user = $fi06_users[14];
fi06_assert( true === Faluss_Identity_Front_Preferences::hide_front_admin_bar( true ), 'A subscriber without an Identity profile is not modified.' );
$fi06_current_user = $fi06_users[11]; $fi06_is_admin = true;
fi06_assert( true === Faluss_Identity_Front_Preferences::hide_front_admin_bar( true ), 'WordPress administration remains unaffected.' );

echo "FI-06 front preferences contract: OK\n";
