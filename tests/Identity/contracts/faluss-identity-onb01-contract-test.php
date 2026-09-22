<?php

define( 'ABSPATH', __DIR__ . '/' );

$onb_transients = array();
$onb_template_id = 0;

class WP_Post {
    public $post_type = 'page';
    public $post_status = 'publish';
}

class Onb01_Elementor_Plugin {}
class_alias( 'Onb01_Elementor_Plugin', 'Elementor\\Plugin' );

function home_url( $path = '/' ) { return 'https://faluss.me' . ( '/' === $path ? '/' : '/' . ltrim( $path, '/' ) ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function untrailingslashit( $value ) { return rtrim( $value, '/\\' ); }
function wp_unslash( $value ) { return $value; }
function get_query_var( $key ) { return ''; }
function wp_salt( $scheme = '' ) { return 'onb-test-salt-' . $scheme; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function set_transient( $key, $value, $ttl ) { global $onb_transients; $onb_transients[ $key ] = $value; return $ttl > 0; }
function get_transient( $key ) { global $onb_transients; return $onb_transients[ $key ] ?? false; }
function delete_transient( $key ) { global $onb_transients; unset( $onb_transients[ $key ] ); return true; }
function get_option( $option, $default = false ) { global $onb_template_id; return 'faluss_identity_onboarding_template_id' === $option ? $onb_template_id : $default; }
function get_post( $post_id ) { return 77 === (int) $post_id ? new WP_Post() : null; }
function get_post_meta( $post_id, $key, $single = false ) { return 77 === (int) $post_id && '_elementor_data' === $key ? '[]' : ''; }
function get_permalink( $post_id ) { return 77 === (int) $post_id ? 'https://faluss.me/start/' : false; }

require_once dirname( __DIR__, 3 ) . '/src/Identity/Legacy/includes/class-faluss-identity-onboarding.php';

function onb01_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
        exit( 1 );
    }
}

function onb01_private( $method, ...$arguments ) {
    $reflection = new ReflectionMethod( 'Faluss_Identity_Onboarding', $method );
    $reflection->setAccessible( true );
    return $reflection->invoke( null, ...$arguments );
}

// The only browser handle is opaque and its server record pins an allowlisted
// intent plus a local return. It carries neither an e-mail nor a Faluss ID.
$flow = onb01_private( 'create_login_flow', 'unlock_teaser', 'https://faluss.me/origin' );
onb01_assert( 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/D', $flow ), 'The login flow is an opaque 256-bit browser handle.' );
$context = Faluss_Identity_Onboarding::login_flow_context( $flow );
onb01_assert( is_array( $context ) && 'unlock_teaser' === $context['intent'] && 'https://faluss.me/origin' === $context['return_to'], 'A valid server-backed unlock flow preserves only its safe local return.' );
onb01_assert( 'https://faluss.me/' === Faluss_Identity_Onboarding::safe_local_return( 'https://attacker.example/return' ), 'External onboarding returns fail closed.' );
onb01_assert( 'https://faluss.me/' === Faluss_Identity_Onboarding::safe_local_return( '//attacker.example/return' ), 'Protocol-relative onboarding returns fail closed.' );
onb01_assert( '' === onb01_private( 'create_login_flow', 'not_allowed', '/origin' ), 'Unknown intents cannot create login state.' );

// The selected Elementor page, not a fixed route slug, is the canonical
// generic destination for members who still need their onboarding decision.
$onb_template_id = 77;
onb01_assert( 'https://faluss.me/start/' === Faluss_Identity_Onboarding::onboarding_url(), 'The configured Elementor page URL is the canonical onboarding destination.' );
$_SERVER['REQUEST_URI'] = '/start/';
onb01_assert( true === onb01_private( 'is_onboarding_request' ), 'The configured onboarding page remains eligible for the existing no-cache protection.' );
$_SERVER['REQUEST_URI'] = '/ordinary-page/';
onb01_assert( false === onb01_private( 'is_onboarding_request' ), 'The onboarding cache exclusion remains limited to its canonical surface.' );
$fresh_requires_onboarding = onb01_private( 'requires_onboarding', false, array( 'choice' => 'unknown', 'next_step' => 'choice' ) );
$complete_requires_onboarding = onb01_private( 'requires_onboarding', false, array( 'choice' => 'no_card', 'next_step' => 'complete' ) );
onb01_assert( true === $fresh_requires_onboarding && false === $complete_requires_onboarding, 'Only an explicit completed ONB-01 state can end the onboarding interception.' );
onb01_assert( 'https://faluss.me/start/' === onb01_private( 'resolve_authenticated_destination', null, 'https://faluss.me/', $fresh_requires_onboarding ), 'A fresh Navigation login from the home page opens the configured onboarding page.' );
onb01_assert( 'https://faluss.me/' === onb01_private( 'resolve_authenticated_destination', null, 'https://faluss.me/', $complete_requires_onboarding ), 'A completed member keeps the generic Navigation return.' );
onb01_assert( 'https://faluss.me/origin' === onb01_private( 'resolve_authenticated_destination', array( 'intent' => 'unlock_teaser', 'return_to' => 'https://faluss.me/origin' ), 'https://faluss.me/', $fresh_requires_onboarding ), 'A signed teaser intent stays ahead of onboarding for a fresh member.' );
onb01_assert( 'https://faluss.me/origin?reward=daily' === onb01_private( 'resolve_authenticated_destination', array( 'intent' => 'claim_reward', 'return_to' => 'https://faluss.me/origin?reward=daily' ), 'https://faluss.me/', $fresh_requires_onboarding ), 'A signed reward intent stays ahead of onboarding for a fresh member.' );
$onb_template_id = 0;
onb01_assert( 'https://faluss.me/commencer/' === onb01_private( 'resolve_authenticated_destination', null, 'https://faluss.me/', $fresh_requires_onboarding ), 'The legacy route is used only when no onboarding page is configured.' );
$onb_template_id = 77;

$root = dirname( __DIR__, 3 );
$onboarding = file_get_contents( $root . '/src/Identity/Legacy/includes/class-faluss-identity-onboarding.php' );
$schema = file_get_contents( $root . '/src/Identity/Legacy/includes/class-faluss-identity-schema.php' );
$profile = file_get_contents( $root . '/src/Identity/Legacy/includes/class-faluss-identity-public-profile.php' );
$passwordless = file_get_contents( $root . '/src/Identity/Legacy/includes/class-faluss-identity-passwordless.php' );
$navigation = file_get_contents( $root . '/src/Identity/Legacy/includes/class-faluss-identity-navigation.php' );
$plugin = file_get_contents( $root . '/src/Identity/Legacy/includes/class-faluss-identity-plugin.php' );
$widget = file_get_contents( $root . '/src/Identity/Legacy/includes/class-faluss-identity-onboarding-elementor-widget.php' );
$css = file_get_contents( $root . '/assets/css/faluss-identity-onboarding.css' );
$js = file_get_contents( $root . '/assets/js/faluss-identity-onboarding.js' );
$link = file_get_contents( $root . '/src/Link/LegacyLinkService.php' );

foreach ( array( "const ONB01_VERSION = '5'", 'get_onb01_schema', 'onboarding_choice', 'onboarding_slug_status', 'onboarding_next_step', 'onboarding_flow_version', 'migrate_onb01', 'ALTER TABLE' ) as $needle ) {
    onb01_assert( false !== strpos( $schema, $needle ), 'The minimal, additive and versioned onboarding state is missing: ' . $needle );
}
onb01_assert( false === strpos( $schema, 'faluss_identity_onboarding' ), 'ONB-01 does not create a duplicate onboarding registry table.' );
foreach ( array( 'reserve_public_slug', 'START TRANSACTION', 'FOR UPDATE', 'public_slug = %s', 'admin_override_public_slug', "current_user_can( 'manage_options' )", 'commencer', 'mon-faluss', 'oauth' ) as $needle ) {
    onb01_assert( false !== strpos( $profile, $needle ), 'Public slug ownership, atomicity, or route protection is missing: ' . $needle );
}
onb01_assert( false !== strpos( $profile, "'draft'" ) && false !== strpos( $profile, "'[]'" ), 'Final reservation creates only a minimal unpublished FI-03 record.' );
foreach ( array( 'unlock_teaser', 'claim_reward', 'create_card', 'generic_login', 'FLOW_TTL', 'hash_hmac', 'set_transient', 'delete_transient', 'safe_local_return', 'render_route', 'DONOTCACHEPAGE', 'litespeed_control_set_nocache', 'handle_choice_ajax', 'handle_availability_ajax', 'handle_reserve_slug_ajax', 'record_state', 'current_member_onboarding_state' ) as $needle ) {
    onb01_assert( false !== strpos( $onboarding, $needle ), 'The bounded onboarding flow is missing: ' . $needle );
}
foreach ( array( 'CARD_WIZARD_STEPS', 'card_wizard_context', 'current_member_has_completed_public_profile', "'wizard_name'", "'wizard_finish'" ) as $needle ) {
    onb01_assert( false !== strpos( $onboarding, $needle ), 'ONB-01 must retain the bounded resumable state used by ONB-02: ' . $needle );
}
foreach ( array( 'configured_onboarding_url', 'get_permalink', 'current_member_requires_onboarding', 'resolve_authenticated_destination', 'is_selected_onboarding_request', 'is_onboarding_route', 'return self::is_onboarding_route( $request ) || self::is_selected_onboarding_request()', 'if ( ! self::is_onboarding_route() )', 'return $requires_onboarding ? self::onboarding_url() : $fallback;' ) as $needle ) {
    onb01_assert( false !== strpos( $onboarding, $needle ), 'Configured onboarding URLs and their generic post-authentication priority are missing: ' . $needle );
}
onb01_assert( false === strpos( $onboarding, 'is_default_member_destination' ), 'A generic local return is never allowed to bypass an unfinished onboarding decision.' );
onb01_assert( false === strpos( $onboarding, 'data-faluss-id=' ) && false === strpos( $onboarding, 'name="faluss_id"' ) && false === strpos( $onboarding, "['faluss_id']" ), 'Onboarding markup and request input never expose a Faluss ID.' );
onb01_assert( false !== strpos( $passwordless, 'post_authentication_redirect' ) && false !== strpos( $passwordless, 'is_authorization_return' ) && strpos( $passwordless, 'is_authorization_return' ) < strpos( $passwordless, 'Faluss_Identity_Onboarding::after_passwordless_authentication' ), 'Passwordless keeps SSO local authorization ahead of onboarding routing.' );
onb01_assert( false !== strpos( $passwordless, 'render_flow_field' ) && false !== strpos( $passwordless, 'onboarding_flow_context' ), 'Passwordless retains the opaque server flow through e-mail and OTP stages.' );
onb01_assert( false !== strpos( $navigation, 'current_local_return_url' ) && false !== strpos( $navigation, 'my_faluss_url' ) && false !== strpos( $navigation, 'Faluss_Identity_Onboarding::onboarding_url' ), 'Navigation keeps a generic local return while onboarding resolves the member state server-side.' );
foreach ( array( "'unlock_teaser'", "'claim_reward'", 'LinkIdentityAdapter::loginUrl', 'teaser_login_url', 'daily_reward_login_url' ) as $needle ) {
    onb01_assert( false !== strpos( $link, $needle ), 'Faluss Link preserves its card return through a typed onboarding intent: ' . $needle );
}
foreach ( array( "'Faluss_Identity_Onboarding', 'register_assets'", 'class-faluss-identity-onboarding-elementor-widget.php', 'migrate_onb01' ) as $needle ) {
    onb01_assert( false !== strpos( $plugin, $needle ), 'The ONB-01 route, assets, migration, or Elementor widget is not booted: ' . $needle );
}
foreach ( array( "return 'faluss_identity_onboarding'", 'get_style_depends', 'get_script_depends', 'Group_Control_Typography', 'Group_Control_Border', 'Group_Control_Box_Shadow', "'hover'" ) as $needle ) {
    onb01_assert( false !== strpos( $widget, $needle ), 'The Onboarding Faluss Elementor controls are incomplete: ' . $needle );
}
foreach ( array( '#FFFDF5', '#FFFFFF', '#080808', 'Outfit', '--faluss-action', 'prefers-reduced-motion', '.faluss-identity-onboarding' ) as $needle ) {
    onb01_assert( false !== strpos( $css, $needle ), 'The scoped Faluss UI foundation is incomplete: ' . $needle );
}
foreach ( array( 'WeakSet', 'data-onboarding-choice', 'faluss_identity_onboarding_availability', 'faluss_identity_onboarding_reserve_slug', 'elementor/frontend/init', 'frontend/element_ready/faluss_identity_onboarding.default' ) as $needle ) {
    onb01_assert( false !== strpos( $js, $needle ), 'The idempotent dynamic onboarding interaction is incomplete: ' . $needle );
}

echo 'ONB-01 post-authentication integration contract: OK' . PHP_EOL;
