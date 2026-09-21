<?php

/**
 * Browser-shaped administration contract: inspect the full rendered member page,
 * submit its real forms to the registered admin-post action, then reread storage.
 */
final class Faluss_Subscriptions_Test_Redirect extends RuntimeException {
    public $location;
    public function __construct( $location ) { parent::__construct( 'redirect' ); $this->location = $location; }
}

$sub01a_hooks = array();
$sub01a_stripe_adapter = null;
function add_action( $hook, $callback, $priority = 10 ) { global $sub01a_hooks; $sub01a_hooks[ $hook ][] = array( 'callback' => $callback, 'priority' => $priority ); }
function has_action( $hook, $callback = false ) { global $sub01a_hooks; foreach ( (array) ( $sub01a_hooks[ $hook ] ?? array() ) as $registered ) { if ( false === $callback || $registered['callback'] === $callback ) { return $registered['priority']; } } return false; }
function sub01a_admin_post_do_action( $hook ) { global $sub01a_hooks; foreach ( (array) ( $sub01a_hooks[ $hook ] ?? array() ) as $registered ) { call_user_func( $registered['callback'] ); } }
function apply_filters( $hook, $value ) { global $sub01a_stripe_adapter; return 'faluss_subscriptions_stripe_adapter' === $hook && $sub01a_stripe_adapter instanceof Faluss_Subscriptions_Stripe_Adapter ? $sub01a_stripe_adapter : $value; }
function register_activation_hook( $file, $callback ) {}
function register_deactivation_hook( $file, $callback ) {}
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.test/wp-content/plugins/faluss-subscriptions/'; }
function current_user_can( $capability ) { return 'manage_faluss_subscriptions' === $capability; }
function get_current_user_id() { return 91; }
function wp_verify_nonce( $nonce, $action ) { return 'test-nonce' === $nonce && 'faluss_subscriptions_admin' === $action ? 1 : false; }
function check_admin_referer( $action ) { if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', $action ) ) { throw new RuntimeException( 'invalid nonce' ); } return 1; }
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="test-nonce">'; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function wp_parse_url( $value ) { return parse_url( $value ); }
function wp_safe_redirect( $location ) { throw new Faluss_Subscriptions_Test_Redirect( $location ); }
function wp_redirect( $location ) { throw new Faluss_Subscriptions_Test_Redirect( $location ); }
function nocache_headers() {}
function wp_die( $message, $status = 0 ) { throw new RuntimeException( (string) $message . ':' . (int) $status ); }
function esc_html( $value ) { return (string) $value; }
function esc_html__( $value ) { return (string) $value; }
function esc_attr( $value ) { return (string) $value; }
function esc_attr__( $value ) { return (string) $value; }
function esc_url( $value ) { return (string) $value; }
function esc_url_raw( $value ) { return (string) $value; }

require __DIR__ . '/faluss-subscriptions-sub01a-persistence-contract-test.php';
define( 'FALUSS_STRIPE_TEST_PRICE_PRO_MONTHLY', 'price_sandboxmonthly' );
define( 'FALUSS_STRIPE_TEST_PRICE_PRO_ANNUAL', 'price_sandboxannual' );
define( 'FALUSS_STRIPE_TEST_PRO_PRODUCT_ID', 'prod_sandbox' );
define( 'FALUSS_STRIPE_TAX_ENABLED', true );
require_once __DIR__ . '/RuntimeBootstrap.php';

function sub01a_admin_assert( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); } }
function sub01a_form_tags_are_separate( $html ) {
    preg_match_all( '/<\\/?form\\b[^>]*>/i', $html, $matches );
    $depth = 0;
    foreach ( $matches[0] as $tag ) {
        if ( 0 === strpos( strtolower( $tag ), '</form' ) ) { --$depth; }
        else { ++$depth; if ( $depth > 1 ) { return false; } }
        if ( $depth < 0 ) { return false; }
    }
    return 0 === $depth;
}
function sub01a_find_form( $html, $mutation ) {
    $pattern = '/<form\\b(?=[^>]*data-faluss-subscriptions-mutation="' . preg_quote( $mutation, '/' ) . '")[^>]*>.*?<\\/form>/si';
    return 1 === preg_match( $pattern, $html, $matches ) ? $matches[0] : '';
}
function sub01a_hidden_value( $html, $name ) {
    $pattern = '/<input\\b(?=[^>]*\\bname="' . preg_quote( $name, '/' ) . '")[^>]*\\bvalue="([^"]*)"[^>]*>/i';
    return 1 === preg_match( $pattern, $html, $matches ) ? html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' ) : '';
}
function sub01a_dispatch_form( $fields ) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = $fields;
    try {
        sub01a_admin_post_do_action( 'admin_post_' . (string) ( $fields['action'] ?? '' ) );
    } catch ( Faluss_Subscriptions_Test_Redirect $redirect ) {
        return $redirect->location;
    }
    return '';
}

$member_id = '55555555-5555-4555-8555-555555555555';
$_GET = array( 'page' => 'faluss-subscriptions', 'tab' => 'member', 'faluss_id' => $member_id );
$_POST = array();
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
Faluss_Subscriptions_Admin::render_page();
$member_html = ob_get_clean();

sub01a_admin_assert( false !== has_action( 'admin_post_faluss_subscriptions_admin', array( 'Faluss_Subscriptions_Admin', 'handle_post' ) ), 'The bootstrap must register the exact admin-post mutation hook.' );
sub01a_admin_assert( sub01a_form_tags_are_separate( $member_html ), 'The rendered member page must not contain nested or unclosed forms.' );
$grant_form = sub01a_find_form( $member_html, 'grant_pro' );
$trial_form = sub01a_find_form( $member_html, 'override_trial_eligibility' );
sub01a_admin_assert( '' !== $grant_form && '' !== $trial_form, 'The full member page must expose distinct grant and trial-override forms.' );
foreach ( array( $grant_form, $trial_form ) as $mutation_form ) {
    sub01a_admin_assert( false !== strpos( $mutation_form, 'method="post"' ) && false !== strpos( $mutation_form, 'action="https://example.test/wp-admin/admin-post.php"' ), 'Every mutation form must POST directly to admin-post.php.' );
    sub01a_admin_assert( 'faluss_subscriptions_admin' === sub01a_hidden_value( $mutation_form, 'action' ) && 'test-nonce' === sub01a_hidden_value( $mutation_form, '_wpnonce' ) && $member_id === sub01a_hidden_value( $mutation_form, 'faluss_id' ), 'Every mutation form must submit the exact WordPress action, nonce and Faluss ID.' );
    sub01a_admin_assert( false !== strpos( $mutation_form, 'type="submit"' ), 'Every mutation form must have an explicit submit button.' );
}
sub01a_admin_assert( false !== strpos( $grant_form, 'name="expires_at"' ) && false !== strpos( $grant_form, 'name="reason"' ) && '' !== sub01a_hidden_value( $grant_form, 'operation_reference' ), 'The grant form must carry expiration, reason and an operation reference.' );

$_GET = array( 'page' => 'faluss-subscriptions', 'tab' => 'diagnostics' );
ob_start();
Faluss_Subscriptions_Admin::render_page();
$diagnostics_html = ob_get_clean();
sub01a_admin_assert( false !== strpos( $diagnostics_html, 'Câblage administratif' ) && false !== strpos( $diagnostics_html, 'admin-post.php' ) && false !== strpos( $diagnostics_html, 'Autonomes' ) && false !== strpos( $diagnostics_html, 'Transaction disponible' ), 'Diagnostics must verify non-destructively the routed hook, endpoint, separated forms and transaction capability.' );

$grant_redirect = sub01a_dispatch_form(
    array(
        'action' => sub01a_hidden_value( $grant_form, 'action' ),
        'faluss_subscriptions_action' => sub01a_hidden_value( $grant_form, 'faluss_subscriptions_action' ),
        'return_tab' => sub01a_hidden_value( $grant_form, 'return_tab' ),
        'faluss_id' => sub01a_hidden_value( $grant_form, 'faluss_id' ),
        'operation_reference' => sub01a_hidden_value( $grant_form, 'operation_reference' ),
        '_wpnonce' => sub01a_hidden_value( $grant_form, '_wpnonce' ),
        'expires_at' => gmdate( 'Y-m-d\\TH:i', time() + DAY_IN_SECONDS ),
        'reason' => 'Attribution contractuelle',
    )
);
sub01a_admin_assert( false !== strpos( $grant_redirect, 'page=faluss-subscriptions&tab=member' ), 'A successful grant must PRG to the same member tab.' );
$persisted = Faluss_Subscriptions_Repository::entitlements_for_faluss_id( $member_id );
sub01a_admin_assert( 1 === count( $persisted ) && 'admin_grant' === $persisted[0]['source'] && 'active' === $persisted[0]['status'], 'The POST dispatched through the registered handler must persist the admin grant.' );
$decision = Faluss_Subscriptions_Resolver::resolve_for_faluss_id( $member_id );
sub01a_admin_assert( 'pro' === $decision['level'] && 'comped' === $decision['state'], 'An independent resolver reread must return comped after the real form POST.' );
$audit_actions = array_column( $wpdb->rows['audit'], 'action' );
sub01a_admin_assert( in_array( 'admin_grant_succeeded', $audit_actions, true ), 'The real form POST must write the admin_grant_succeeded audit trace.' );

$_GET = array( 'page' => 'faluss-subscriptions', 'tab' => 'member' );
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
Faluss_Subscriptions_Admin::render_page();
$success_html = ob_get_clean();
sub01a_admin_assert( false !== strpos( $success_html, 'Faluss Max a été attribué jusqu’au' ), 'The redirected member page must show one visible success notice.' );
$revoke_form = sub01a_find_form( $success_html, 'revoke_grant' );
sub01a_admin_assert( '' !== $revoke_form && false !== strpos( $revoke_form, 'action="https://example.test/wp-admin/admin-post.php"' ) && false !== strpos( $revoke_form, 'type="submit"' ), 'The rendered revoke form must independently post to admin-post.php.' );
$revoke_redirect = sub01a_dispatch_form(
    array(
        'action' => sub01a_hidden_value( $revoke_form, 'action' ),
        'faluss_subscriptions_action' => sub01a_hidden_value( $revoke_form, 'faluss_subscriptions_action' ),
        'return_tab' => sub01a_hidden_value( $revoke_form, 'return_tab' ),
        'faluss_id' => sub01a_hidden_value( $revoke_form, 'faluss_id' ),
        'grant_id' => sub01a_hidden_value( $revoke_form, 'grant_id' ),
        '_wpnonce' => sub01a_hidden_value( $revoke_form, '_wpnonce' ),
        'reason' => 'Fin de l’attribution contractuelle',
    )
);
sub01a_admin_assert( false !== strpos( $revoke_redirect, 'page=faluss-subscriptions&tab=member' ), 'A revocation must PRG to the same member tab.' );
$after_revoke = Faluss_Subscriptions_Resolver::resolve_for_faluss_id( $member_id );
sub01a_admin_assert( 'free' === $after_revoke['level'], 'A revocation posted by its real form must return the member to free.' );
sub01a_admin_assert( in_array( 'admin_pro_revoked', array_column( $wpdb->rows['audit'], 'action' ), true ), 'The real revoke POST must be audited.' );

$invalid_expiration = sub01a_dispatch_form(
    array(
        'action' => sub01a_hidden_value( $grant_form, 'action' ),
        'faluss_subscriptions_action' => 'grant_pro',
        'return_tab' => 'member',
        'faluss_id' => $member_id,
        'operation_reference' => sub01a_hidden_value( $grant_form, 'operation_reference' ),
        '_wpnonce' => 'test-nonce',
        'expires_at' => gmdate( 'Y-m-d\\TH:i', time() - HOUR_IN_SECONDS ),
        'reason' => 'Date volontairement invalide',
    )
);
sub01a_admin_assert( false !== strpos( $invalid_expiration, 'page=faluss-subscriptions&tab=member' ) && in_array( 'admin_grant_invalid_expiration', array_column( $wpdb->rows['audit'], 'action' ), true ), 'A handler-reached invalid expiration must redirect safely and leave an explicit audit trace.' );
$invalid_nonce = sub01a_dispatch_form(
    array(
        'action' => sub01a_hidden_value( $grant_form, 'action' ),
        'faluss_subscriptions_action' => 'grant_pro',
        'return_tab' => 'member',
        'faluss_id' => $member_id,
        'operation_reference' => sub01a_hidden_value( $grant_form, 'operation_reference' ),
        '_wpnonce' => 'invalid-nonce',
        'expires_at' => gmdate( 'Y-m-d\\TH:i', time() + DAY_IN_SECONDS ),
        'reason' => 'Nonce volontairement invalide',
    )
);
sub01a_admin_assert( false !== strpos( $invalid_nonce, 'page=faluss-subscriptions&tab=member' ) && in_array( 'admin_grant_invalid_nonce', array_column( $wpdb->rows['audit'], 'action' ), true ), 'A handler-reached invalid nonce must redirect safely and leave an explicit audit trace.' );

final class Faluss_Subscriptions_Sandbox_Success_Adapter extends Faluss_Subscriptions_Stripe_Adapter {
    public $calls = array();
    public function __construct() {}
    public function retrieve_price( $price_id ) {
        $this->calls[] = array( 'retrieve_price', $price_id );
        return array( 'id' => $price_id, 'active' => true, 'currency' => 'eur', 'unit_amount' => 'price_sandboxannual' === $price_id ? 9900 : 999, 'tax_behavior' => 'inclusive', 'product' => 'prod_sandbox', 'recurring' => array( 'interval' => 'price_sandboxannual' === $price_id ? 'year' : 'month', 'interval_count' => 1 ) );
    }
    public function create_customer( $faluss_id ) { $this->calls[] = array( 'create_customer', $faluss_id ); return array( 'id' => 'cus_sandboxcheckout' ); }
    public function create_checkout_session( $parameters, $idempotency_key ) { $this->calls[] = array( 'create_checkout_session', $parameters, $idempotency_key ); return array( 'id' => 'cs_sandboxcheckout', 'url' => 'https://checkout.stripe.com/c/pay/cs_sandboxcheckout', 'expires_at' => time() + HOUR_IN_SECONDS ); }
}

final class Faluss_Subscriptions_Sandbox_Rejected_Adapter extends Faluss_Subscriptions_Stripe_Adapter {
    public $calls = array();
    public function __construct() {}
    public function retrieve_price( $price_id ) { $this->calls[] = array( 'retrieve_price', $price_id ); return new WP_Error( 'stripe_price_catalogue_mismatch' ); }
    public function create_customer( $faluss_id ) { $this->calls[] = array( 'create_customer', $faluss_id ); return new WP_Error( 'unexpected_customer_creation' ); }
}

final class Faluss_Subscriptions_Sandbox_Tax_Location_Adapter extends Faluss_Subscriptions_Stripe_Adapter {
    public $calls = array();
    public function __construct() {}
    public function retrieve_price( $price_id ) {
        $this->calls[] = array( 'retrieve_price', $price_id );
        return array( 'id' => $price_id, 'active' => true, 'currency' => 'eur', 'unit_amount' => 'price_sandboxannual' === $price_id ? 9900 : 999, 'tax_behavior' => 'inclusive', 'product' => 'prod_sandbox', 'recurring' => array( 'interval' => 'price_sandboxannual' === $price_id ? 'year' : 'month', 'interval_count' => 1 ) );
    }
    public function create_customer( $faluss_id ) { $this->calls[] = array( 'create_customer', $faluss_id ); return array( 'id' => 'cus_sandboxtaxlocation' ); }
    public function create_checkout_session( $parameters, $idempotency_key ) { $this->calls[] = array( 'create_checkout_session', $parameters, $idempotency_key ); return new WP_Error( 'stripe_customer_tax_location_invalid' ); }
}

$_GET = array( 'page' => 'faluss-subscriptions', 'tab' => 'sandbox' );
$_POST = array();
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
Faluss_Subscriptions_Admin::render_page();
$sandbox_html = ob_get_clean();
$sandbox_checkout_form = sub01a_find_form( $sandbox_html, 'sandbox_checkout' );
sub01a_admin_assert( false !== has_action( 'admin_post_faluss_subscriptions_sandbox_checkout', array( 'Faluss_Subscriptions_Admin', 'handle_sandbox_checkout_post' ) ), 'Sandbox Checkout must have its own registered admin-post handler.' );
sub01a_admin_assert( '' !== $sandbox_checkout_form && 'faluss_subscriptions_sandbox_checkout' === sub01a_hidden_value( $sandbox_checkout_form, 'action' ) && 'sandbox_checkout' === sub01a_hidden_value( $sandbox_checkout_form, 'faluss_subscriptions_intent' ) && false === strpos( $sandbox_checkout_form, 'faluss_subscriptions_action' ), 'Sandbox HTML must post the explicit Checkout action and intent, never the administrative entitlement operation.' );

$sandbox_member_id = '66666666-6666-4666-8666-666666666666';
$sub01a_stripe_adapter = new Faluss_Subscriptions_Sandbox_Success_Adapter();
$sandbox_redirect = sub01a_dispatch_form(
    array(
        'action' => sub01a_hidden_value( $sandbox_checkout_form, 'action' ),
        'faluss_subscriptions_intent' => sub01a_hidden_value( $sandbox_checkout_form, 'faluss_subscriptions_intent' ),
        'faluss_id' => $sandbox_member_id,
        'period' => 'monthly',
        '_wpnonce' => sub01a_hidden_value( $sandbox_checkout_form, '_wpnonce' ),
    )
);
sub01a_admin_assert( 'https://checkout.stripe.com/c/pay/cs_sandboxcheckout' === $sandbox_redirect, 'The real sandbox form must route through its dedicated handler and redirect only to the simulated Stripe Checkout session.' );
sub01a_admin_assert( 1 === count( $wpdb->rows['customers'] ) && 1 === count( $wpdb->rows['checkout_sessions'] ) && 'open' === $wpdb->rows['checkout_sessions'][0]['session_status'], 'A successful sandbox Checkout must persist only its linked Customer and pending Checkout session.' );
sub01a_admin_assert( 0 === count( array_filter( $wpdb->rows['entitlements'], static function( $row ) use ( $sandbox_member_id ) { return $sandbox_member_id === ( $row['faluss_id'] ?? '' ); } ) ) && 0 === count( array_filter( $wpdb->rows['trials'], static function( $row ) use ( $sandbox_member_id ) { return $sandbox_member_id === ( $row['faluss_id'] ?? '' ); } ) ) && 0 === count( array_filter( $wpdb->rows['subscriptions'], static function( $row ) use ( $sandbox_member_id ) { return $sandbox_member_id === ( $row['faluss_id'] ?? '' ); } ) ), 'Opening Checkout must not create an entitlement, active trial or subscription before a signed webhook.' );
sub01a_admin_assert( in_array( 'stripe_checkout_created', array_column( $wpdb->rows['audit'], 'action' ), true ) && false !== strpos( implode( '|', array_column( $sub01a_stripe_adapter->calls, 0 ) ), 'create_checkout_session' ), 'A simulated Stripe session must be created and audited through the dedicated sandbox handler.' );
$sandbox_checkout_calls = array_values( array_filter( $sub01a_stripe_adapter->calls, static function( $call ) { return 'create_checkout_session' === ( $call[0] ?? '' ); } ) );
$sandbox_checkout_parameters = $sandbox_checkout_calls[0][1] ?? array();
sub01a_admin_assert( 1 === count( $sandbox_checkout_calls ) && 'required' === ( $sandbox_checkout_parameters['billing_address_collection'] ?? '' ) && 'auto' === ( $sandbox_checkout_parameters['customer_update']['address'] ?? '' ) && ! empty( $sandbox_checkout_calls[0][2] ), 'Checkout must collect and persist the billing address on its linked Stripe Customer while retaining an idempotency key.' );

$rejected_member_id = '77777777-7777-4777-8777-777777777777';
$sub01a_stripe_adapter = new Faluss_Subscriptions_Sandbox_Rejected_Adapter();
$rejected_redirect = sub01a_dispatch_form(
    array(
        'action' => sub01a_hidden_value( $sandbox_checkout_form, 'action' ),
        'faluss_subscriptions_intent' => sub01a_hidden_value( $sandbox_checkout_form, 'faluss_subscriptions_intent' ),
        'faluss_id' => $rejected_member_id,
        'period' => 'monthly',
        '_wpnonce' => sub01a_hidden_value( $sandbox_checkout_form, '_wpnonce' ),
    )
);
$rejected_audits = array_values( array_filter( $wpdb->rows['audit'], static function( $row ) use ( $rejected_member_id ) { return $rejected_member_id === ( $row['faluss_id'] ?? '' ); } ) );
sub01a_admin_assert( false !== strpos( $rejected_redirect, 'page=faluss-subscriptions&tab=sandbox-test' ) && 1 === count( $rejected_audits ) && 'stripe_checkout_rejected' === $rejected_audits[0]['action'] && 'sandbox' === $rejected_audits[0]['source'] && 'stripe_price_catalogue_mismatch' === ( json_decode( $rejected_audits[0]['next_state'], true )['cause'] ?? '' ) && 'stripe_checkout_rejected' === ( $sub01a_transients['faluss_subscriptions_admin_notice_91']['code'] ?? '' ) && 'stripe_price_catalogue_mismatch' === ( $sub01a_transients['faluss_subscriptions_admin_notice_91']['context']['cause'] ?? '' ), 'A pre-Stripe Checkout rejection must be safely classified, audited and surfaced without an administrative-rights fallback.' );
sub01a_admin_assert( 1 === count( $wpdb->rows['customers'] ) && 1 === count( $wpdb->rows['checkout_sessions'] ) && 0 === count( array_filter( $wpdb->rows['entitlements'], static function( $row ) use ( $rejected_member_id ) { return $rejected_member_id === ( $row['faluss_id'] ?? '' ); } ) ), 'A rejected sandbox Checkout must not create a Customer, Checkout, entitlement or right for its Faluss ID.' );

$tax_location_member_id = '99999999-9999-4999-8999-999999999999';
$sub01a_stripe_adapter = new Faluss_Subscriptions_Sandbox_Tax_Location_Adapter();
$tax_location_redirect = sub01a_dispatch_form(
    array(
        'action' => sub01a_hidden_value( $sandbox_checkout_form, 'action' ),
        'faluss_subscriptions_intent' => sub01a_hidden_value( $sandbox_checkout_form, 'faluss_subscriptions_intent' ),
        'faluss_id' => $tax_location_member_id,
        'period' => 'monthly',
        '_wpnonce' => sub01a_hidden_value( $sandbox_checkout_form, '_wpnonce' ),
    )
);
$tax_location_audits = array_values( array_filter( $wpdb->rows['audit'], static function( $row ) use ( $tax_location_member_id ) { return $tax_location_member_id === ( $row['faluss_id'] ?? '' ); } ) );
$tax_location_audit = $tax_location_audits[0] ?? array();
sub01a_admin_assert( false !== strpos( $tax_location_redirect, 'page=faluss-subscriptions&tab=sandbox-test' ) && 'stripe_checkout_rejected' === ( $tax_location_audit['action'] ?? '' ) && 'stripe_customer_tax_location_invalid' === ( json_decode( $tax_location_audit['next_state'] ?? '{}', true )['cause'] ?? '' ) && 'stripe_customer_tax_location_invalid' === ( $sub01a_transients['faluss_subscriptions_admin_notice_91']['context']['cause'] ?? '' ), 'The Stripe Tax Customer-address failure must remain a dedicated safe Checkout rejection.' );
sub01a_admin_assert( 0 === count( array_filter( $wpdb->rows['entitlements'], static function( $row ) use ( $tax_location_member_id ) { return $tax_location_member_id === ( $row['faluss_id'] ?? '' ); } ) ) && 0 === count( array_filter( $wpdb->rows['trials'], static function( $row ) use ( $tax_location_member_id ) { return $tax_location_member_id === ( $row['faluss_id'] ?? '' ); } ) ) && 0 === count( array_filter( $wpdb->rows['subscriptions'], static function( $row ) use ( $tax_location_member_id ) { return $tax_location_member_id === ( $row['faluss_id'] ?? '' ); } ) ), 'A Stripe Tax Checkout rejection must never create a right before a signed webhook.' );

$legacy_member_id = '88888888-8888-4888-8888-888888888888';
$legacy_redirect = sub01a_dispatch_form(
    array(
        'action' => 'faluss_subscriptions_admin',
        'faluss_subscriptions_action' => 'sandbox_checkout',
        'faluss_id' => $legacy_member_id,
        'period' => 'monthly',
        '_wpnonce' => 'test-nonce',
    )
);
$legacy_audits = array_values( array_filter( $wpdb->rows['audit'], static function( $row ) use ( $legacy_member_id ) { return $legacy_member_id === ( $row['faluss_id'] ?? '' ); } ) );
sub01a_admin_assert( false !== strpos( $legacy_redirect, 'page=faluss-subscriptions&tab=sandbox-test' ) && 1 === count( $legacy_audits ) && 'stripe_checkout_rejected' === $legacy_audits[0]['action'] && 'sandbox_checkout_legacy_route' === ( json_decode( $legacy_audits[0]['next_state'], true )['cause'] ?? '' ) && 0 === count( array_filter( $wpdb->rows['entitlements'], static function( $row ) use ( $legacy_member_id ) { return $legacy_member_id === ( $row['faluss_id'] ?? '' ); } ) ), 'The former shared sandbox operation must be rejected and can never fall through to admin_grant.' );

echo "SUB-01A admin-post contract: OK\n";
