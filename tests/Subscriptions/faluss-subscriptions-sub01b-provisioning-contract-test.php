<?php

/**
 * SUB-01B trial provisioning: current Stripe objects are the only authority.
 * Browser returns, administrator grants and raw provider payloads are excluded.
 */

define( 'FALUSS_STRIPE_TEST_PRICE_PRO_MONTHLY', 'price_trialmonthly' );
define( 'FALUSS_STRIPE_TEST_PRICE_PRO_ANNUAL', 'price_trialannual' );
define( 'FALUSS_STRIPE_TEST_PRO_PRODUCT_ID', 'prod_trial' );

$sub01b_provision_adapter = null;
function apply_filters( $hook, $value ) {
	global $sub01b_provision_adapter;
	return 'faluss_subscriptions_stripe_adapter' === $hook && $sub01b_provision_adapter instanceof Faluss_Subscriptions_Stripe_Adapter ? $sub01b_provision_adapter : $value;
}

require __DIR__ . '/faluss-subscriptions-sub01a-persistence-contract-test.php';
require_once $plugin . '/includes/class-faluss-subscriptions-stripe-config.php';
require_once $plugin . '/includes/class-faluss-subscriptions-stripe-sdk.php';
require_once $plugin . '/includes/class-faluss-subscriptions-notifications.php';
require_once $plugin . '/includes/class-faluss-subscriptions-billing.php';

function sub01b_provision_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

final class Faluss_Subscriptions_Provisioning_Adapter extends Faluss_Subscriptions_Stripe_Adapter {
	public $subscription = array();
	public $payment_method = array();
	public $customer = array();
	public $checkout_session = array();
	public $cancelled = 0;
	public $cancel_error_code = '';
	public $concurrent_delivery = false;
	public $delivering_cancellation = false;
	public $concurrent_result = null;

	public function __construct() {}

	public function retrieve_price( $price_id ) {
		return array(
			'id' => $price_id,
			'active' => true,
			'currency' => 'eur',
			'unit_amount' => 'price_trialannual' === $price_id ? 9900 : 999,
			'tax_behavior' => 'inclusive',
			'product' => 'prod_trial',
			'recurring' => array( 'interval' => 'price_trialannual' === $price_id ? 'year' : 'month', 'interval_count' => 1 ),
		);
	}
	public function retrieve_subscription( $subscription_id ) { return self::id( $this->subscription ) === $subscription_id ? $this->subscription : new WP_Error( 'stripe_subscription_missing' ); }
	public function retrieve_payment_method( $payment_method_id ) { return self::id( $this->payment_method ) === $payment_method_id ? $this->payment_method : new WP_Error( 'stripe_payment_method_missing' ); }
	public function retrieve_customer( $customer_id ) { return self::id( $this->customer ) === $customer_id ? $this->customer : new WP_Error( 'stripe_customer_missing' ); }
	public function retrieve_checkout_session( $session_id ) { return self::id( $this->checkout_session ) === $session_id ? $this->checkout_session : new WP_Error( 'stripe_checkout_missing' ); }
	public function cancel_now( $subscription_id ) {
		++$this->cancelled;
		// Re-enter while the outer worker still owns the remote cancellation.
		// This simulates a separately delivered, legitimate Stripe event that
		// races exactly at the former DELETE-before-marker window.
		if ( $this->concurrent_delivery && ! $this->delivering_cancellation ) {
			$this->delivering_cancellation = true;
			$this->concurrent_result = Faluss_Subscriptions_Billing::apply_stripe_subscription( $this->subscription, 'customer.subscription.updated' );
			$this->delivering_cancellation = false;
		}
		return '' !== $this->cancel_error_code ? new WP_Error( $this->cancel_error_code ) : array( 'id' => $subscription_id );
	}
	private static function id( $record ) { return is_array( $record ) ? (string) ( $record['id'] ?? '' ) : ''; }
}

function sub01b_provision_subscription( $faluss_id, $customer, $subscription, $payment_method = '', $status = 'trialing', $checkout_uuid = '' ) {
	$now = time();
	$metadata = array( 'faluss_id' => $faluss_id );
	if ( '' !== $checkout_uuid ) { $metadata['faluss_billing_session'] = $checkout_uuid; }
	return array(
		'id' => $subscription,
		'customer' => $customer,
		'metadata' => $metadata,
		'items' => array( 'data' => array( array( 'price' => array( 'id' => 'price_trialmonthly', 'recurring' => array( 'interval' => 'month' ) ) ) ) ),
		'status' => $status,
		'trial_start' => $now - 60,
		'trial_end' => $now + 1295940,
		'current_period_start' => $now - 60,
		'current_period_end' => $now + 1295940,
		'default_payment_method' => $payment_method,
	);
}

function sub01b_provision_count( $table, $faluss_id ) {
	global $wpdb;
	return count( array_filter( $wpdb->rows[ $table ], static function( $row ) use ( $faluss_id ) { return $faluss_id === ( $row['faluss_id'] ?? '' ); } ) );
}

function sub01b_provision_audit_reasons( $faluss_id ) {
	global $wpdb;
	$reasons = array();
	foreach ( $wpdb->rows['audit'] as $row ) {
		if ( $faluss_id === ( $row['faluss_id'] ?? '' ) && 'stripe_trial_refused' === ( $row['action'] ?? '' ) ) {
			$state = json_decode( $row['next_state'] ?? '{}', true );
			$reasons[] = is_array( $state ) ? ( $state['reason'] ?? '' ) : '';
		}
	}
	return $reasons;
}

function sub01b_provision_audits( $faluss_id, $action ) {
	global $wpdb;
	return array_values( array_filter( $wpdb->rows['audit'], static function( $row ) use ( $faluss_id, $action ) {
		return $faluss_id === ( $row['faluss_id'] ?? '' ) && $action === ( $row['action'] ?? '' );
	} ) );
}

function sub01b_provision_audit_justifications( $faluss_id, $action ) {
	return array_values( array_map( static function( $row ) { return (string) ( $row['justification'] ?? '' ); }, sub01b_provision_audits( $faluss_id, $action ) ) );
}

$sub01b_provision_adapter = new Faluss_Subscriptions_Provisioning_Adapter();
$faluss_id = 'aaaaaaaa-1111-4111-8111-111111111111';
$customer = 'cus_trialvalid';
$subscription = 'sub_trialvalid';
$initial_checkout = null;
Faluss_Subscriptions_Repository::record_customer( $faluss_id, 'stripe', $customer, 'test' );
$initial_checkout = Faluss_Subscriptions_Repository::create_checkout( array( 'faluss_id' => $faluss_id, 'billing_interval' => 'monthly', 'provider_customer_reference' => $customer, 'opaque_state' => str_repeat( 'a', 64 ), 'idempotency_key' => str_repeat( 'b', 64 ) ) );
sub01b_provision_assert( is_array( $initial_checkout ) && is_array( Faluss_Subscriptions_Repository::mark_checkout_provider_session( $initial_checkout['id'], 'cs_trialvalid', gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) ) ), 'A first signed trial contract requires one locally-created Checkout session.' );
$sub01b_provision_adapter->subscription = sub01b_provision_subscription( $faluss_id, $customer, $subscription, '', 'trialing', $initial_checkout['checkout_uuid'] );
$sub01b_provision_adapter->customer = array( 'id' => $customer, 'invoice_settings' => array( 'default_payment_method' => 'pm_trialvalid' ) );
$sub01b_provision_adapter->payment_method = array( 'id' => 'pm_trialvalid', 'customer' => $customer, 'card' => array( 'fingerprint' => 'FpTrialValid1234' ) );
$first = Faluss_Subscriptions_Billing::apply_stripe_subscription( $sub01b_provision_adapter->subscription, 'checkout.session.completed' );
$sub01b_provision_adapter->customer = array( 'id' => $customer, 'invoice_settings' => array( 'default_payment_method' => 'pm_trialvalid_replayed' ) );
$sub01b_provision_adapter->payment_method = array( 'id' => 'pm_trialvalid_replayed', 'customer' => $customer, 'card' => array( 'fingerprint' => 'FpTrialRepresentationChanged' ) );
$second = Faluss_Subscriptions_Billing::apply_stripe_subscription( $sub01b_provision_adapter->subscription, 'customer.subscription.created' );
$decision = Faluss_Subscriptions_Resolver::resolve_for_faluss_id( $faluss_id );
sub01b_provision_assert( is_array( $first ) && is_array( $second ) && 'pro' === $decision['level'] && 'trialing' === $decision['state'] && 'subscription_trialing' === $decision['reason'], 'A signed Stripe trialing subscription with a verified payment method must resolve Faluss Max as technical pro.' );
sub01b_provision_assert( 1 === sub01b_provision_count( 'trials', $faluss_id ) && 1 === sub01b_provision_count( 'subscriptions', $faluss_id ) && 0 === $sub01b_provision_adapter->cancelled && array() === sub01b_provision_audit_reasons( $faluss_id ) && 1 === count( sub01b_provision_audits( $faluss_id, 'stripe_subscription_synced' ) ) && 0 === count( sub01b_provision_audits( $faluss_id, 'provider_subscription_updated' ) ) && 1 === count( array_filter( $wpdb->rows['notifications'], static function( $row ) use ( $faluss_id ) { return $faluss_id === ( $row['faluss_id'] ?? '' ) && 'trial_started' === ( $row['notification_type'] ?? '' ); } ) ), 'Multiple legitimate events for one Checkout must reuse one verified trial/subscription decision without refusal, cancellation, duplicate write or duplicate notification.' );
sub01b_provision_assert( 1 === count( array_filter( $wpdb->rows['audit'], static function( $row ) use ( $faluss_id ) { return $faluss_id === ( $row['faluss_id'] ?? '' ) && 'trial_activated' === ( $row['action'] ?? '' ); } ) ), 'The same provider subscription must activate its verified trial once only.' );
sub01b_provision_assert( $subscription === ( Faluss_Subscriptions_Repository::checkout_for_uuid( $initial_checkout['checkout_uuid'] )['provider_subscription_reference'] ?? '' ) && 'completed' === ( Faluss_Subscriptions_Repository::checkout_for_uuid( $initial_checkout['checkout_uuid'] )['session_status'] ?? '' ), 'A verified Stripe subscription must be linked to its local Checkout once, without relying on the browser return.' );

$proof_missing_id = 'bbbbbbbb-1111-4111-8111-111111111111';
$proof_missing_customer = 'cus_trialmissing';
$sub01b_provision_adapter->subscription = sub01b_provision_subscription( $proof_missing_id, $proof_missing_customer, 'sub_trialmissing' );
$sub01b_provision_adapter->customer = array( 'id' => $proof_missing_customer, 'invoice_settings' => array( 'default_payment_method' => '' ) );
$sub01b_provision_adapter->payment_method = array();
Faluss_Subscriptions_Repository::record_customer( $proof_missing_id, 'stripe', $proof_missing_customer, 'test' );
$proof_missing = Faluss_Subscriptions_Billing::apply_stripe_subscription( $sub01b_provision_adapter->subscription, 'customer.subscription.updated' );
$proof_reasons = sub01b_provision_audit_reasons( $proof_missing_id );
sub01b_provision_assert( is_wp_error( $proof_missing ) && 'payment_proof_missing' === $proof_missing->get_error_code() && array( 'payment_proof_missing' ) === $proof_reasons && 0 === sub01b_provision_count( 'trials', $proof_missing_id ) && 0 === sub01b_provision_count( 'subscriptions', $proof_missing_id ) && 0 === $sub01b_provision_adapter->cancelled, 'Missing server-side payment proof must refuse the trial safely, create no right and keep the valid Stripe trial available for a later signed retry.' );

$fingerprint_missing_id = 'cccccccc-1111-4111-8111-111111111111';
$fingerprint_customer = 'cus_trialfingerprint';
$sub01b_provision_adapter->subscription = sub01b_provision_subscription( $fingerprint_missing_id, $fingerprint_customer, 'sub_trialfingerprint', 'pm_trialfingerprint' );
$sub01b_provision_adapter->customer = array( 'id' => $fingerprint_customer, 'invoice_settings' => array() );
$sub01b_provision_adapter->payment_method = array( 'id' => 'pm_trialfingerprint', 'customer' => $fingerprint_customer, 'card' => array() );
Faluss_Subscriptions_Repository::record_customer( $fingerprint_missing_id, 'stripe', $fingerprint_customer, 'test' );
$fingerprint_missing = Faluss_Subscriptions_Billing::apply_stripe_subscription( $sub01b_provision_adapter->subscription, 'invoice.paid' );
sub01b_provision_assert( is_wp_error( $fingerprint_missing ) && 'payment_fingerprint_missing' === $fingerprint_missing->get_error_code() && array( 'payment_fingerprint_missing' ) === sub01b_provision_audit_reasons( $fingerprint_missing_id ) && 0 === sub01b_provision_count( 'trials', $fingerprint_missing_id ) && 0 === sub01b_provision_count( 'subscriptions', $fingerprint_missing_id ), 'A payment method without a derived card fingerprint must never activate a trial or right.' );

$reconcile_id = 'dddddddd-1111-4111-8111-111111111111';
$reconcile_customer = 'cus_trialreconcile';
$reconcile_session = 'cs_trialreconcile';
$reconcile_subscription = 'sub_trialreconcile';
Faluss_Subscriptions_Repository::record_customer( $reconcile_id, 'stripe', $reconcile_customer, 'test' );
$checkout = Faluss_Subscriptions_Repository::create_checkout( array( 'faluss_id' => $reconcile_id, 'billing_interval' => 'monthly', 'provider_customer_reference' => $reconcile_customer, 'opaque_state' => str_repeat( 'd', 64 ), 'idempotency_key' => str_repeat( 'e', 64 ) ) );
sub01b_provision_assert( is_array( $checkout ) && is_array( Faluss_Subscriptions_Repository::mark_checkout_provider_session( $checkout['id'], $reconcile_session, gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) ) ), 'The recovery contract requires a pre-existing local Checkout session.' );
$sub01b_provision_adapter->subscription = sub01b_provision_subscription( $reconcile_id, $reconcile_customer, $reconcile_subscription );
$sub01b_provision_adapter->customer = array( 'id' => $reconcile_customer, 'invoice_settings' => array( 'default_payment_method' => 'pm_trialreconcile' ) );
$sub01b_provision_adapter->payment_method = array( 'id' => 'pm_trialreconcile', 'customer' => $reconcile_customer, 'card' => array( 'fingerprint' => 'FpTrialReconcile1' ) );
$sub01b_provision_adapter->checkout_session = array( 'id' => $reconcile_session, 'status' => 'complete', 'customer' => $reconcile_customer, 'subscription' => $reconcile_subscription );
$reconciled = Faluss_Subscriptions_Billing::reconcile_checkout( $reconcile_session );
$reconcile_decision = Faluss_Subscriptions_Resolver::resolve_for_faluss_id( $reconcile_id );
sub01b_provision_assert( is_array( $reconciled ) && 'pro' === $reconcile_decision['level'] && 'trialing' === $reconcile_decision['state'] && 0 === sub01b_provision_count( 'entitlements', $reconcile_id ), 'A completed local Checkout may be safely reconciled from Stripe into a valid trial without an administrative grant.' );
sub01b_provision_assert( $reconcile_subscription === ( Faluss_Subscriptions_Repository::checkout_for_uuid( $checkout['checkout_uuid'] )['provider_subscription_reference'] ?? '' ) && 'completed' === ( Faluss_Subscriptions_Repository::checkout_for_uuid( $checkout['checkout_uuid'] )['session_status'] ?? '' ), 'Reconciliation must persist the server-verified Stripe subscription relationship on the existing Checkout.' );

$cancellation_count = $sub01b_provision_adapter->cancelled;
$shared_fingerprint_id = 'eeeeeeee-1111-4111-8111-111111111111';
$shared_fingerprint_customer = 'cus_trialfingerprintfirst';
Faluss_Subscriptions_Repository::record_customer( $shared_fingerprint_id, 'stripe', $shared_fingerprint_customer, 'test' );
$sub01b_provision_adapter->subscription = sub01b_provision_subscription( $shared_fingerprint_id, $shared_fingerprint_customer, 'sub_trialfingerprintfirst' );
$sub01b_provision_adapter->customer = array( 'id' => $shared_fingerprint_customer, 'invoice_settings' => array( 'default_payment_method' => 'pm_trialfingerprintfirst' ) );
$sub01b_provision_adapter->payment_method = array( 'id' => 'pm_trialfingerprintfirst', 'customer' => $shared_fingerprint_customer, 'card' => array( 'fingerprint' => 'FpSharedTrialCard' ) );
sub01b_provision_assert( is_array( Faluss_Subscriptions_Billing::apply_stripe_subscription( $sub01b_provision_adapter->subscription, 'checkout.session.completed' ) ), 'The first identity may activate a verified trial from its attached card.' );

$ineligible_id = 'ffffffff-1111-4111-8111-111111111111';
$ineligible_customer = 'cus_trialfingerprintsecond';
Faluss_Subscriptions_Repository::record_customer( $ineligible_id, 'stripe', $ineligible_customer, 'test' );
$ineligible_checkout = Faluss_Subscriptions_Repository::create_checkout( array( 'faluss_id' => $ineligible_id, 'billing_interval' => 'monthly', 'provider_customer_reference' => $ineligible_customer, 'opaque_state' => str_repeat( 'c', 64 ), 'idempotency_key' => str_repeat( 'd', 64 ) ) );
sub01b_provision_assert( is_array( $ineligible_checkout ) && is_array( Faluss_Subscriptions_Repository::mark_checkout_provider_session( $ineligible_checkout['id'], 'cs_trialfingerprintsecond', gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) ) ), 'A demonstrated trial-ineligibility cancellation requires the matching local Checkout.' );
$sub01b_provision_adapter->subscription = sub01b_provision_subscription( $ineligible_id, $ineligible_customer, 'sub_trialfingerprintsecond', '', 'trialing', $ineligible_checkout['checkout_uuid'] );
$sub01b_provision_adapter->customer = array( 'id' => $ineligible_customer, 'invoice_settings' => array( 'default_payment_method' => 'pm_trialfingerprintsecond' ) );
$sub01b_provision_adapter->payment_method = array( 'id' => 'pm_trialfingerprintsecond', 'customer' => $ineligible_customer, 'card' => array( 'fingerprint' => 'FpSharedTrialCard' ) );
$sub01b_provision_adapter->concurrent_delivery = true;
$ineligible = Faluss_Subscriptions_Billing::apply_stripe_subscription( $sub01b_provision_adapter->subscription, 'customer.subscription.created' );
$sub01b_provision_adapter->concurrent_delivery = false;
$cancellation_audits = sub01b_provision_audits( $ineligible_id, 'stripe_subscription_canceled_for_trial_ineligibility' );
$cancellation_state = $cancellation_audits ? json_decode( $cancellation_audits[0]['next_state'] ?? '{}', true ) : array();
$ineligible_decision = Faluss_Subscriptions_Resolver::resolve_for_faluss_id( $ineligible_id );
sub01b_provision_assert( is_wp_error( $ineligible ) && is_array( $sub01b_provision_adapter->concurrent_result ) && 'payment_fingerprint_already_consumed' === $ineligible->get_error_code() && $cancellation_count + 1 === $sub01b_provision_adapter->cancelled && 1 === count( $cancellation_audits ) && array( 'payment_fingerprint_already_consumed' ) === sub01b_provision_audit_reasons( $ineligible_id ) && array( 'payment_fingerprint_already_consumed' ) === sub01b_provision_audit_justifications( $ineligible_id, 'stripe_trial_refused' ) && array( 'payment_fingerprint_already_consumed' ) === sub01b_provision_audit_justifications( $ineligible_id, 'stripe_subscription_canceled_for_trial_ineligibility' ) && 'payment_fingerprint_already_consumed' === ( $cancellation_state['reason'] ?? '' ) && 'requested' === ( $cancellation_state['outcome'] ?? '' ) && 'trial_ineligible' === ( Faluss_Subscriptions_Repository::checkout_for_uuid( $ineligible_checkout['checkout_uuid'] )['session_status'] ?? '' ) && 'free' === $ineligible_decision['level'] && false === $ineligible_decision['entitlements']['faluss.pro'] && 0 === sub01b_provision_count( 'trials', $ineligible_id ) && 0 === sub01b_provision_count( 'subscriptions', $ineligible_id ) && 0 === sub01b_provision_count( 'entitlements', $ineligible_id ), 'Concurrent deliveries for the same ineligible Checkout must acquire one durable claim, issue one DELETE and audit one non-empty safe reason without granting Pro.' );
$ineligible_replay = Faluss_Subscriptions_Billing::apply_stripe_subscription( $sub01b_provision_adapter->subscription, 'customer.subscription.updated' );
sub01b_provision_assert( is_array( $ineligible_replay ) && $cancellation_count + 1 === $sub01b_provision_adapter->cancelled && 1 === count( sub01b_provision_audits( $ineligible_id, 'stripe_subscription_canceled_for_trial_ineligibility' ) ) && 1 === count( sub01b_provision_audit_reasons( $ineligible_id ) ), 'A second legitimate event for an already rejected local Checkout must reuse the safe ineligibility decision without another cancellation or refusal audit.' );

$not_found_id = 'abababab-1111-4111-8111-111111111111';
$not_found_customer = 'cus_trialalreadycanceled';
Faluss_Subscriptions_Repository::record_customer( $not_found_id, 'stripe', $not_found_customer, 'test' );
$not_found_checkout = Faluss_Subscriptions_Repository::create_checkout( array( 'faluss_id' => $not_found_id, 'billing_interval' => 'monthly', 'provider_customer_reference' => $not_found_customer, 'opaque_state' => str_repeat( 'b', 64 ), 'idempotency_key' => str_repeat( 'c', 64 ) ) );
sub01b_provision_assert( is_array( $not_found_checkout ) && is_array( Faluss_Subscriptions_Repository::mark_checkout_provider_session( $not_found_checkout['id'], 'cs_trialalreadycanceled', gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) ) ), 'A recovered Stripe 404 case still requires the matching local Checkout.' );
$sub01b_provision_adapter->subscription = sub01b_provision_subscription( $not_found_id, $not_found_customer, 'sub_trialalreadycanceled', '', 'trialing', $not_found_checkout['checkout_uuid'] );
$sub01b_provision_adapter->customer = array( 'id' => $not_found_customer, 'invoice_settings' => array( 'default_payment_method' => 'pm_trialalreadycanceled' ) );
$sub01b_provision_adapter->payment_method = array( 'id' => 'pm_trialalreadycanceled', 'customer' => $not_found_customer, 'card' => array( 'fingerprint' => 'FpSharedTrialCard' ) );
$sub01b_provision_adapter->cancel_error_code = 'stripe_subscription_not_found';
$not_found_cancellations = $sub01b_provision_adapter->cancelled;
$not_found = Faluss_Subscriptions_Billing::apply_stripe_subscription( $sub01b_provision_adapter->subscription, 'customer.subscription.updated' );
$not_found_audits = sub01b_provision_audits( $not_found_id, 'stripe_subscription_canceled_for_trial_ineligibility' );
$not_found_state = $not_found_audits ? json_decode( $not_found_audits[0]['next_state'] ?? '{}', true ) : array();
$not_found_replay = Faluss_Subscriptions_Billing::apply_stripe_subscription( $sub01b_provision_adapter->subscription, 'invoice.paid' );
$sub01b_provision_adapter->cancel_error_code = '';
$not_found_decision = Faluss_Subscriptions_Resolver::resolve_for_faluss_id( $not_found_id );
sub01b_provision_assert( is_wp_error( $not_found ) && is_array( $not_found_replay ) && $not_found_cancellations + 1 === $sub01b_provision_adapter->cancelled && 1 === count( $not_found_audits ) && 1 === count( sub01b_provision_audits( $not_found_id, 'stripe_trial_refused' ) ) && 'already_canceled' === ( $not_found_state['outcome'] ?? '' ) && array( 'payment_fingerprint_already_consumed' ) === sub01b_provision_audit_justifications( $not_found_id, 'stripe_subscription_canceled_for_trial_ineligibility' ) && 'trial_ineligible' === ( Faluss_Subscriptions_Repository::checkout_for_uuid( $not_found_checkout['checkout_uuid'] )['session_status'] ?? '' ) && 'free' === $not_found_decision['level'] && false === $not_found_decision['entitlements']['faluss.pro'] && 0 === sub01b_provision_count( 'trials', $not_found_id ) && 0 === sub01b_provision_count( 'subscriptions', $not_found_id ) && 0 === sub01b_provision_count( 'entitlements', $not_found_id ), 'A known recovered Stripe 404 must be terminal for its one durable claim, without a retry DELETE, duplicate audit or entitlement.' );

$transient_id = '99999999-1111-4111-8111-111111111111';
$transient_customer = 'cus_transientunlinked';
Faluss_Subscriptions_Repository::record_customer( $transient_id, 'stripe', $transient_customer, 'test' );
$transient_subscription = sub01b_provision_subscription( $transient_id, $transient_customer, 'sub_transientunlinked' );
$transient_cancellations = $sub01b_provision_adapter->cancelled;
$sub01b_provision_adapter->customer = array( 'id' => $transient_customer, 'invoice_settings' => array( 'default_payment_method' => 'pm_transientunlinked' ) );
$sub01b_provision_adapter->payment_method = array( 'id' => 'pm_transientunlinked', 'customer' => $transient_customer, 'card' => array( 'fingerprint' => 'FpSharedTrialCard' ) );
$transient = Faluss_Subscriptions_Billing::apply_stripe_subscription( $transient_subscription, 'customer.subscription.updated' );
sub01b_provision_assert( is_wp_error( $transient ) && 'payment_fingerprint_already_consumed' === $transient->get_error_code() && $transient_cancellations === $sub01b_provision_adapter->cancelled && 0 === count( sub01b_provision_audits( $transient_id, 'stripe_subscription_canceled_for_trial_ineligibility' ) ) && 0 === sub01b_provision_count( 'trials', $transient_id ), 'An absent or transient local Checkout relation must fail closed without prematurely cancelling a remote Stripe subscription.' );

$canceled_id = '12121212-1111-4111-8111-111111111111';
$canceled_customer = 'cus_trialcanceled';
$canceled_session = 'cs_trialcanceled';
$canceled_subscription = 'sub_trialcanceled';
Faluss_Subscriptions_Repository::record_customer( $canceled_id, 'stripe', $canceled_customer, 'test' );
$canceled_checkout = Faluss_Subscriptions_Repository::create_checkout( array( 'faluss_id' => $canceled_id, 'billing_interval' => 'monthly', 'provider_customer_reference' => $canceled_customer, 'opaque_state' => str_repeat( 'f', 64 ), 'idempotency_key' => str_repeat( 'a', 64 ) ) );
sub01b_provision_assert( is_array( $canceled_checkout ) && is_array( Faluss_Subscriptions_Repository::mark_checkout_provider_session( $canceled_checkout['id'], $canceled_session, gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) ) ) && is_array( Faluss_Subscriptions_Trials::activate_verified_trial( $canceled_id, $canceled_subscription, 'FpCanceledTrial1', gmdate( 'Y-m-d H:i:s' ) ) ), 'The canceled-subscription contract requires one previously verified local trial and Checkout.' );
$sub01b_provision_adapter->subscription = sub01b_provision_subscription( $canceled_id, $canceled_customer, $canceled_subscription, '', 'canceled', $canceled_checkout['checkout_uuid'] );
$sub01b_provision_adapter->customer = array( 'id' => $canceled_customer, 'invoice_settings' => array() );
$sub01b_provision_adapter->payment_method = array();
$sub01b_provision_adapter->checkout_session = array( 'id' => $canceled_session, 'status' => 'complete', 'customer' => $canceled_customer, 'subscription' => $canceled_subscription );
$canceled_cancellations = $sub01b_provision_adapter->cancelled;
$canceled_reconciled = Faluss_Subscriptions_Billing::reconcile_checkout( $canceled_session );
$canceled_decision = Faluss_Subscriptions_Resolver::resolve_for_faluss_id( $canceled_id );
sub01b_provision_assert( is_array( $canceled_reconciled ) && 'free' === $canceled_decision['level'] && false === $canceled_decision['entitlements']['faluss.pro'] && $canceled_cancellations === $sub01b_provision_adapter->cancelled && 0 === sub01b_provision_count( 'entitlements', $canceled_id ), 'A Stripe subscription now confirmed canceled must remain free after reconciliation and never receive an artificial entitlement or another remote cancellation.' );
sub01b_provision_assert( false === strpos( json_encode( $wpdb->rows['audit'] ), 'FpTrial' ) && false === strpos( json_encode( $wpdb->rows['audit'] ), 'pm_trial' ), 'Trial audits must contain only safe reason codes, never a payment fingerprint or PaymentMethod reference.' );

echo "SUB-01B provisioning contract: OK\n";
