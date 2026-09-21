<?php

/**
 * SUB-01B browser-return contract.
 *
 * The browser return is intentionally only a validated PRG controller. It
 * never grants rights: the signed Stripe webhook remains authoritative.
 */

final class Faluss_Subscriptions_Return_Redirect extends RuntimeException {
	public $location;

	public function __construct( $location ) {
		parent::__construct( 'redirect' );
		$this->location = $location;
	}
}

function current_user_can( $capability ) { return 'manage_faluss_subscriptions' === $capability; }
function get_current_user_id() { return 91; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 ); }
function wp_safe_redirect( $location ) { throw new Faluss_Subscriptions_Return_Redirect( $location ); }
function nocache_headers() { global $sub01b_return_nocache_calls; ++$sub01b_return_nocache_calls; }

require __DIR__ . '/faluss-subscriptions-sub01a-persistence-contract-test.php';
require_once $plugin . '/includes/class-faluss-subscriptions-stripe-config.php';
require_once $plugin . '/includes/class-faluss-subscriptions-admin.php';
require_once $plugin . '/includes/class-faluss-subscriptions-returns.php';

function sub01b_returns_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function sub01b_returns_checkout( $faluss_id, $state, $provider_session, $expires_at ) {
	$checkout = Faluss_Subscriptions_Repository::create_checkout(
		array(
			'faluss_id'                  => $faluss_id,
			'billing_interval'           => 'monthly',
			'provider_customer_reference' => 'cus_sub01b_returns',
			'opaque_state'                => $state,
			'idempotency_key'             => hash( 'sha256', 'returns|' . $state ),
		)
	);
	sub01b_returns_assert( is_array( $checkout ), 'A local Checkout return record must be created for the return controller contract.' );
	$marked = Faluss_Subscriptions_Repository::mark_checkout_provider_session( $checkout['id'], $provider_session, $expires_at );
	sub01b_returns_assert( is_array( $marked ), 'The return controller contract requires a local provider-session binding.' );
	return $marked;
}

function sub01b_returns_dispatch( $query ) {
	$_GET = $query;
	ob_start();
	try {
		Faluss_Subscriptions_Returns::render();
	} catch ( Faluss_Subscriptions_Return_Redirect $redirect ) {
		return array( $redirect->location, ob_get_clean() );
	}
	return array( '', ob_get_clean() );
}

function sub01b_returns_no_entitlement( $faluss_id ) {
	global $wpdb;
	foreach ( $wpdb->rows['entitlements'] as $entitlement ) {
		if ( $faluss_id === $entitlement['faluss_id'] && 'active' === $entitlement['status'] ) {
			return false;
		}
	}
	return true;
}

/** Browser acknowledgement must leave every canonical billing record untouched. */
function sub01b_returns_business_snapshot() {
	global $wpdb;
	$tables = array( 'checkout_sessions', 'subscriptions', 'trials', 'entitlements', 'audit', 'events' );
	return array_intersect_key( $wpdb->rows, array_flip( $tables ) );
}

$sub01b_return_nocache_calls = 0;
$sandbox_url                 = 'https://example.test/wp-admin/admin.php?page=faluss-subscriptions&tab=sandbox-test';
$success_faluss_id           = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$success_state               = str_repeat( 'a', 64 );
$success_session             = 'cs_test_sub01b_return_success';
sub01b_returns_checkout( $success_faluss_id, $success_state, $success_session, gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) );
$before_open_return = sub01b_returns_business_snapshot();

list( $location, $html ) = sub01b_returns_dispatch(
	array(
		'faluss_subscriptions_return' => 'checkout',
		'kind'                         => 'success',
		'state'                        => $success_state,
		'session_id'                   => $success_session,
		'redirect'                     => 'https://attacker.example/never',
	)
);
sub01b_returns_assert( $sandbox_url === $location, 'A valid Checkout browser return must PRG only to the canonical sandbox-test tab.' );
sub01b_returns_assert( '' === $html, 'The Checkout browser return must not render an autonomous technical HTML page.' );
$notice = Faluss_Subscriptions_Admin_Notices::consume( 91 );
sub01b_returns_assert( is_array( $notice ) && 'success' === $notice['type'] && 'checkout_return_completed' === $notice['code'] && array() === $notice['context'], 'A valid return must set the one-time, webhook-qualified Checkout completion notice without return data.' );
sub01b_returns_assert( null === Faluss_Subscriptions_Admin_Notices::consume( 91 ), 'The Checkout completion notice must be consumed exactly once.' );
sub01b_returns_assert( false === strpos( $location, $success_state ) && false === strpos( $location, $success_session ) && false === strpos( $location, $success_faluss_id ), 'The final PRG URL must not retain state, provider session or Faluss ID.' );
sub01b_returns_assert( sub01b_returns_no_entitlement( $success_faluss_id ), 'A successful browser return must not grant an entitlement before the signed webhook.' );
sub01b_returns_assert( $before_open_return === sub01b_returns_business_snapshot(), 'A valid browser return before webhook synchronization must not write Checkout, subscription, trial, entitlement, event or audit records.' );

// Simulate the real signed-webhook sequence before the browser arrives: the
// local Checkout is completed and linked, then the canonical trialing
// subscription is resolved. The browser is allowed only to acknowledge this
// already-persisted result.
$trialing_faluss_id = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
$trialing_state     = str_repeat( 'e', 64 );
$trialing_session   = 'cs_test_sub01b_return_trialing';
$trialing_checkout  = sub01b_returns_checkout( $trialing_faluss_id, $trialing_state, $trialing_session, gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) );
$trialing_reference = 'sub_sub01b_return_trialing';
$linked_checkout    = Faluss_Subscriptions_Repository::link_checkout_subscription( $trialing_checkout['checkout_uuid'], $trialing_faluss_id, 'cus_sub01b_returns', $trialing_reference );
sub01b_returns_assert( is_array( $linked_checkout ) && 'completed' === $linked_checkout['session_status'], 'A signed webhook simulation must link its local Checkout before browser acknowledgement.' );
$activated_trial = Faluss_Subscriptions_Trials::activate_verified_trial( $trialing_faluss_id, $trialing_reference, 'return-trial-payment-fingerprint', gmdate( 'Y-m-d H:i:s' ) );
sub01b_returns_assert( is_array( $activated_trial ) && 'trialing' === $activated_trial['trial_state'], 'A signed webhook simulation must create the verified trial before the browser return.' );
$synced_subscription = Faluss_Subscriptions_Repository::upsert_provider_subscription(
	array(
		'faluss_id'                       => $trialing_faluss_id,
		'provider'                        => 'stripe',
		'provider_customer_reference'     => 'cus_sub01b_returns',
		'provider_subscription_reference' => $trialing_reference,
		'plan_key'                        => Faluss_Subscriptions_Catalog::PRO,
		'billing_interval'                => 'monthly',
		'provider_status'                 => 'trialing',
		'normalized_state'                => 'trialing',
		'trial_starts_at'                 => gmdate( 'Y-m-d H:i:s' ),
		'trial_ends_at'                   => gmdate( 'Y-m-d H:i:s', time() + ( 15 * DAY_IN_SECONDS ) ),
		'period_starts_at'                => gmdate( 'Y-m-d H:i:s' ),
		'period_ends_at'                  => gmdate( 'Y-m-d H:i:s', time() + ( 15 * DAY_IN_SECONDS ) ),
		'cancel_at_period_end'            => false,
	)
);
sub01b_returns_assert( is_array( $synced_subscription ) && 'trialing' === $synced_subscription['normalized_state'], 'A signed webhook simulation must persist the canonical trialing subscription.' );
$resolved_trialing = Faluss_Subscriptions_Resolver::resolve_for_faluss_id( $trialing_faluss_id );
sub01b_returns_assert( 'pro' === $resolved_trialing['level'] && 'trialing' === $resolved_trialing['state'] && 'subscription_trialing' === $resolved_trialing['reason'], 'The authoritative local state must already resolve Faluss Max trialing before the browser return.' );
$before_trialing_return = sub01b_returns_business_snapshot();

list( $location, $html ) = sub01b_returns_dispatch( array( 'faluss_subscriptions_return' => 'checkout', 'kind' => 'success', 'state' => $trialing_state, 'session_id' => $trialing_session ) );
$notice = Faluss_Subscriptions_Admin_Notices::consume( 91 );
sub01b_returns_assert( $sandbox_url === $location && '' === $html && is_array( $notice ) && 'success' === $notice['type'] && 'checkout_return_trialing' === $notice['code'] && array() === $notice['context'], 'A valid return after signed webhook synchronization must acknowledge the canonical trialing state without exposing data.' );
sub01b_returns_assert( false === strpos( $location, $trialing_state ) && false === strpos( $location, $trialing_session ) && false === strpos( $location, $trialing_faluss_id ), 'The trialing acknowledgement PRG URL must remove state, provider session and Faluss ID.' );
sub01b_returns_assert( $before_trialing_return === sub01b_returns_business_snapshot(), 'A valid browser return after webhook synchronization must not change the already-resolved subscription, trial, entitlement, Checkout, event or audit.' );

// Replaying the same browser URL is harmless: it keeps the same local
// acknowledgement and cannot create a second business decision or audit.
list( $location, $html ) = sub01b_returns_dispatch( array( 'faluss_subscriptions_return' => 'checkout', 'kind' => 'success', 'state' => $trialing_state, 'session_id' => $trialing_session ) );
$notice = Faluss_Subscriptions_Admin_Notices::consume( 91 );
sub01b_returns_assert( $sandbox_url === $location && '' === $html && is_array( $notice ) && 'checkout_return_trialing' === $notice['code'] && array() === $notice['context'] && $before_trialing_return === sub01b_returns_business_snapshot(), 'A replayed browser return must remain presentation-only, with no duplicate business write or audit.' );

$cancel_faluss_id = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$cancel_state     = str_repeat( 'b', 64 );
sub01b_returns_checkout( $cancel_faluss_id, $cancel_state, 'cs_test_sub01b_return_cancel', gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) );
list( $location, $html ) = sub01b_returns_dispatch( array( 'faluss_subscriptions_return' => 'checkout', 'kind' => 'cancel', 'state' => $cancel_state ) );
$notice = Faluss_Subscriptions_Admin_Notices::consume( 91 );
sub01b_returns_assert( $sandbox_url === $location && '' === $html && is_array( $notice ) && 'checkout_return_cancelled' === $notice['code'] && array() === $notice['context'], 'A cancelled Checkout return must safely return to the sandbox with a neutral notice.' );
sub01b_returns_assert( sub01b_returns_no_entitlement( $cancel_faluss_id ), 'A cancelled Checkout return must not modify rights.' );

$expired_faluss_id = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
$expired_state     = str_repeat( 'c', 64 );
sub01b_returns_checkout( $expired_faluss_id, $expired_state, 'cs_test_sub01b_return_expired', gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
list( $location, $html ) = sub01b_returns_dispatch( array( 'faluss_subscriptions_return' => 'checkout', 'kind' => 'success', 'state' => $expired_state, 'session_id' => 'cs_test_sub01b_return_expired' ) );
$notice = Faluss_Subscriptions_Admin_Notices::consume( 91 );
sub01b_returns_assert( $sandbox_url === $location && '' === $html && is_array( $notice ) && 'checkout_return_expired' === $notice['code'] && array() === $notice['context'], 'An expired Checkout return must safely PRG with no technical provider detail.' );
sub01b_returns_assert( sub01b_returns_no_entitlement( $expired_faluss_id ), 'An expired Checkout return must not modify rights.' );

$invalid_faluss_id = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
$invalid_state     = str_repeat( 'd', 64 );
sub01b_returns_checkout( $invalid_faluss_id, $invalid_state, 'cs_test_sub01b_return_valid', gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) );
list( $location, $html ) = sub01b_returns_dispatch( array( 'faluss_subscriptions_return' => 'checkout', 'kind' => 'success', 'state' => $invalid_state, 'session_id' => 'cs_test_sub01b_return_wrong' ) );
$notice = Faluss_Subscriptions_Admin_Notices::consume( 91 );
sub01b_returns_assert( $sandbox_url === $location && '' === $html && is_array( $notice ) && 'checkout_return_session_invalid' === $notice['code'] && array() === $notice['context'], 'A mismatched provider session must safely PRG with a specific non-sensitive notice.' );
sub01b_returns_assert( sub01b_returns_no_entitlement( $invalid_faluss_id ), 'An invalid provider session must not modify rights.' );

list( $location, $html ) = sub01b_returns_dispatch( array( 'faluss_subscriptions_return' => 'checkout', 'kind' => 'success', 'state' => 'not-a-state', 'session_id' => 'cs_test_invalid' ) );
$notice = Faluss_Subscriptions_Admin_Notices::consume( 91 );
sub01b_returns_assert( $sandbox_url === $location && '' === $html && is_array( $notice ) && 'checkout_return_invalid' === $notice['code'] && array() === $notice['context'], 'An invalid state must safely PRG with no raw Stripe detail.' );

$before_invalid_returns = sub01b_returns_business_snapshot();
list( $location, $html ) = sub01b_returns_dispatch( array( 'faluss_subscriptions_return' => 'checkout', 'kind' => 'success', 'session_id' => 'cs_test_missing_state' ) );
$notice = Faluss_Subscriptions_Admin_Notices::consume( 91 );
sub01b_returns_assert( $sandbox_url === $location && '' === $html && is_array( $notice ) && 'checkout_return_invalid' === $notice['code'] && array() === $notice['context'], 'A browser return without state must safely PRG with no sensitive context.' );
list( $location, $html ) = sub01b_returns_dispatch( array( 'faluss_subscriptions_return' => 'checkout', 'kind' => 'success', 'state' => str_repeat( 'f', 64 ), 'session_id' => 'cs_test_unknown_state' ) );
$notice = Faluss_Subscriptions_Admin_Notices::consume( 91 );
sub01b_returns_assert( $sandbox_url === $location && '' === $html && is_array( $notice ) && 'checkout_return_invalid' === $notice['code'] && array() === $notice['context'], 'An altered or unknown opaque state must safely PRG with no sensitive context.' );
sub01b_returns_assert( $before_invalid_returns === sub01b_returns_business_snapshot(), 'Invalid, absent or altered browser returns must not change a Checkout, subscription, trial, entitlement, event or audit.' );
sub01b_returns_assert( $sub01b_return_nocache_calls >= 5, 'Each browser return must be non-cacheable before its PRG.' );

$returns_source = file_get_contents( $plugin . '/includes/class-faluss-subscriptions-returns.php' );
$admin_source   = file_get_contents( $plugin . '/includes/class-faluss-subscriptions-admin.php' );
sub01b_returns_assert( false !== strpos( $returns_source, "'open', 'completed'" ) && false === stripos( $returns_source, '<!doctype' ) && false === strpos( $returns_source, 'Faluss_Subscriptions_Entitlements::' ) && false === strpos( $returns_source, 'Faluss_Subscriptions_Billing::' ) && false === strpos( $returns_source, 'Faluss_Subscriptions_Audit::' ), 'The return controller must accept the linked completed Checkout but must not render a standalone page, call Stripe, grant rights or write business audit.' );
sub01b_returns_assert( false !== strpos( $admin_source, 'Checkout test terminé. Faluss Max est actuellement en période d’essai.' ) && false !== strpos( $admin_source, 'Checkout test terminé. L’état définitif sera confirmé par Stripe et apparaîtra dans Membre.' ), 'The administrator notices must distinguish an already-canonical trialing state from the neutral pre-webhook acknowledgement.' );

echo "SUB-01B returns contract: OK\n";
