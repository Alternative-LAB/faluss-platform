<?php

define( 'ABSPATH', __DIR__ . '/' );

function sub01b_assert( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); } }
function __( $value ) { return $value; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function wp_parse_url( $value ) { return parse_url( $value ); }
function home_url( $path = '' ) { return 'https://faluss.example' . $path; }
function add_query_arg( $args, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }
function wp_unslash( $value ) { return $value; }

final class WP_Error { private $code; public function __construct( $code ) { $this->code = $code; } public function get_error_code() { return $this->code; } }

$root = dirname( __DIR__, 2 );
$plugin = $root . '/src/Subscriptions/Legacy';
$config = file_get_contents( $plugin . '/includes/class-faluss-subscriptions-stripe-config.php' );
$sdk = file_get_contents( $plugin . '/includes/class-faluss-subscriptions-stripe-sdk.php' );
$billing = file_get_contents( $plugin . '/includes/class-faluss-subscriptions-billing.php' );
$webhooks = file_get_contents( $plugin . '/includes/class-faluss-subscriptions-webhooks.php' );
$notifications = file_get_contents( $plugin . '/includes/class-faluss-subscriptions-notifications.php' );
$returns = file_get_contents( $plugin . '/includes/class-faluss-subscriptions-returns.php' );
$schema = file_get_contents( $plugin . '/includes/class-faluss-subscriptions-schema.php' );
$repository = file_get_contents( $plugin . '/includes/class-faluss-subscriptions-repository.php' );
$admin = file_get_contents( $plugin . '/includes/class-faluss-subscriptions-admin.php' );
$bootstrap = file_get_contents( $root . '/src/Subscriptions/SubscriptionsModule.php' );
$composer = json_decode( file_get_contents( $root . '/composer.lock' ), true );

require_once $plugin . '/includes/class-faluss-subscriptions-catalog.php';
require_once $plugin . '/includes/class-faluss-subscriptions-stripe-config.php';
require_once $plugin . '/includes/class-faluss-subscriptions-stripe-sdk.php';
require_once $plugin . '/includes/class-faluss-subscriptions-billing.php';
require_once $root . '/vendor/autoload.php';

final class Faluss_Subscriptions_Tax_Location_Test_Sessions {
    public function create( $parameters, $options ) {
        throw \Stripe\Exception\InvalidRequestException::factory( 'Sensitive provider message that must not cross the adapter boundary.', 400, null, null, null, 'customer_tax_location_invalid' );
    }
}

final class Faluss_Subscriptions_Tax_Location_Test_Client {
    public $checkout;
    public function __construct() {
        $this->checkout = (object) array( 'sessions' => new Faluss_Subscriptions_Tax_Location_Test_Sessions() );
    }
}

final class Faluss_Subscriptions_Transport_Test_Sessions {
    public function create( $parameters, $options ) {
        throw new Exception( 'Sensitive transport failure that must not cross the adapter boundary.' );
    }
}

final class Faluss_Subscriptions_Transport_Test_Client {
    public $checkout;
    public function __construct() {
        $this->checkout = (object) array( 'sessions' => new Faluss_Subscriptions_Transport_Test_Sessions() );
    }
}

final class Faluss_Subscriptions_Not_Found_Test_Sessions {
    public function create( $parameters, $options ) {
        throw \Stripe\Exception\InvalidRequestException::factory( 'Sensitive missing-resource message that must not cross the adapter boundary.', 404, null, null, null, 'resource_missing' );
    }
}

final class Faluss_Subscriptions_Not_Found_Test_Client {
    public $checkout;
    public function __construct() {
        $this->checkout = (object) array( 'sessions' => new Faluss_Subscriptions_Not_Found_Test_Sessions() );
    }
}

sub01b_assert( 'test' === Faluss_Subscriptions_Stripe_Config::mode(), 'Stripe mode must default to test without a server constant.' );
sub01b_assert( '2025-03-31.basil' === Faluss_Subscriptions_Stripe_Config::API_VERSION && false !== strpos( $config, 'FALUSS_STRIPE_LIVE_ENABLED' ), 'The Stripe API version must be pinned and live must remain opt-in.' );
foreach ( array( 'FALUSS_STRIPE_TEST_SECRET_KEY', 'FALUSS_STRIPE_TEST_WEBHOOK_SECRET', "'_PRICE_PRO_'", "'_PORTAL_CONFIGURATION_ID'" ) as $constant ) { sub01b_assert( false !== strpos( $config, $constant ), 'Required server-only Stripe configuration is missing: ' . $constant ); }
sub01b_assert( isset( $composer['packages'][0]['name'], $composer['packages'][0]['version'] ) && 'stripe/stripe-php' === $composer['packages'][0]['name'] && 'v21.3.0' === $composer['packages'][0]['version'], 'The distributed lock file must pin the official Stripe SDK v21.3.0.' );
sub01b_assert( false !== strpos( $sdk, 'stripe_sdk_collision' ) && false !== strpos( $sdk, 'vendor/autoload.php' ) && false !== strpos( $sdk, 'Webhook::constructEvent' ) && false !== strpos( $sdk, 'retrieve_event' ), 'The bundled SDK must fail closed on a WordPress collision, verify signatures and fetch canonical retry events.' );
$tax_location_error = ( new Faluss_Subscriptions_Stripe_Adapter( new Faluss_Subscriptions_Tax_Location_Test_Client() ) )->create_checkout_session( array(), 'stripe-tax-test-idempotency-key' );
$transport_error = ( new Faluss_Subscriptions_Stripe_Adapter( new Faluss_Subscriptions_Transport_Test_Client() ) )->create_checkout_session( array(), 'stripe-transport-test-idempotency-key' );
$not_found_error = ( new Faluss_Subscriptions_Stripe_Adapter( new Faluss_Subscriptions_Not_Found_Test_Client() ) )->create_checkout_session( array(), 'stripe-not-found-test-idempotency-key' );
sub01b_assert( is_wp_error( $tax_location_error ) && 'stripe_customer_tax_location_invalid' === $tax_location_error->get_error_code() && is_wp_error( $transport_error ) && 'stripe_transport_failed' === $transport_error->get_error_code() && is_wp_error( $not_found_error ) && 'stripe_subscription_not_found' === $not_found_error->get_error_code(), 'Known Stripe InvalidRequestException codes must map safely without replacing unrelated transport failures.' );
foreach ( array( "'unit_amount'", "'tax_behavior'", "'inclusive'", "'interval_count'", "'FALUSS_STRIPE_" ) as $needle ) { sub01b_assert( false !== strpos( $billing . $config, $needle ), 'Server-side Price validation is missing: ' . $needle ); }
foreach ( array( "'mode' => 'subscription'", "'payment_method_types' => array( 'card' )", "'payment_method_collection' => 'always'", "'billing_address_collection' => 'required'", "'customer_update' => array( 'address' => 'auto' )", "'trial_period_days' => Faluss_Subscriptions_Catalog::TRIAL_DAYS", "'automatic_tax' => array( 'enabled' => true )", "'idempotency_key'" ) as $needle ) { sub01b_assert( false !== strpos( $billing . $sdk, $needle ), 'Checkout invariant is missing: ' . $needle ); }
sub01b_assert( false !== strpos( $sdk, 'customer_tax_location_invalid' ) && false !== strpos( $sdk, 'stripe_customer_tax_location_invalid' ) && false !== strpos( $sdk, 'stripe_transport_failed' ), 'Stripe Tax address failures must have a dedicated safe classification and retain the transport fallback.' );
sub01b_assert( false !== strpos( $billing, 'checkout_subscription_exists' ) && false !== strpos( $billing, 'checkout_trial_already_used' ) && false !== strpos( $billing, 'GET_LOCK' ), 'Checkout must serialize a subject and refuse duplicate subscription or trial.' );
sub01b_assert( false !== strpos( $webhooks, 'file_get_contents( \'php://input\' )' ) && false !== strpos( $webhooks, 'stripe-signature' ) && false !== strpos( $webhooks, 'permission_callback' ) && false !== strpos( $webhooks, 'no-store, private' ), 'The webhook must use raw body, Stripe signature, public route permission and no-cache responses.' );
foreach ( array( 'checkout.session.completed', 'customer.subscription.updated', 'invoice.paid', 'invoice.payment_failed', 'charge.refunded', 'charge.dispute.created' ) as $event_type ) { sub01b_assert( false !== strpos( $webhooks, $event_type ), 'Required Stripe event is not handled: ' . $event_type ); }
sub01b_assert( false !== strpos( $webhooks, 'retrieve_subscription' ) && false !== strpos( $webhooks, 'retrieve_event' ) && false !== strpos( $webhooks, 'customer.updated' ) && false !== strpos( $webhooks, 'record_event' ) && false !== strpos( $repository, 'payload_hash' ), 'A webhook or retry must record only an idempotence hash then re-fetch its current Stripe subject.' );
sub01b_assert( false !== strpos( $billing, 'trial_payment_proof' ) && false !== strpos( $billing, 'retrieve_customer' ) && false !== strpos( $billing, 'payment_proof_missing' ) && false !== strpos( $billing, 'payment_fingerprint_missing' ) && false !== strpos( $billing, 'trial_already_consumed' ), 'Trial provisioning must re-read a Customer payment proof and retain only safe refusal codes.' );
sub01b_assert( false !== strpos( $billing, "'payment_fingerprint_already_consumed'" ) && false !== strpos( $billing, 'stripe_subscription_canceled_for_trial_ineligibility' ) && false !== strpos( $billing, 'claim_checkout_trial_ineligibility' ) && false !== strpos( $billing, 'trial_ineligible_pending' ) && false !== strpos( $repository, 'link_checkout_subscription' ) && false !== strpos( $repository, 'claim_checkout_trial_ineligibility' ) && false !== strpos( $repository, 'finalize_checkout_trial_ineligibility' ) && false !== strpos( $repository, "'session_status' => 'completed'" ) && false !== strpos( $sdk, 'stripe_subscription_not_found' ), 'Only a demonstrated cross-identity payment-fingerprint collision with its linked Checkout may atomically claim one remote cancellation and safely deduplicate each replay.' );
sub01b_assert( false !== strpos( $trials = file_get_contents( $plugin . '/includes/class-faluss-subscriptions-trials.php' ), 'verification_reference' ) && false !== strpos( $trials, 'return $existing;' ) && false !== strpos( $resolver = file_get_contents( $plugin . '/includes/class-faluss-subscriptions-resolver.php' ), 'trial_canceled_by_provider' ) && false !== strpos( $resolver, "'canceled' === ( \$subscription['provider_status'] ?? '' )" ), 'The same Faluss ID and Stripe subscription must reuse its verified trial, while a later canceled provider state suppresses that trial.' );
sub01b_assert( false !== strpos( $webhooks, 'open_checkout_for_customer_reference' ) && false !== strpos( $webhooks, "'complete' === ( \$session['status'] ?? '' )" ) && false !== strpos( $repository, 'open_checkout_for_customer_reference' ), 'A later signed Customer or payment event must recover only through its matching completed local Checkout, then re-read Stripe.' );
sub01b_assert( false !== strpos( $billing, 'reconcile_checkout' ) && false !== strpos( $billing, 'checkout_reconciliation_incomplete' ) && false !== strpos( $billing, 'stripe_checkout_reconciled' ), 'Test-only reconciliation must re-read a completed local Checkout and never grant administratively.' );
sub01b_assert( false !== strpos( $billing, 'validated_subscription_price( $adapter' ) && false !== strpos( $billing, 'self::validated_price( $adapter, $period )' ) && false !== strpos( $billing, 'grace_started_at' ), 'Webhook application must revalidate the configured Price and preserve the first grace anchor.' );
sub01b_assert( false !== strpos( $schema, "const VERSION = '2'" ) && false !== strpos( $schema, 'migrate_v1_to_v2' ) && false !== strpos( $schema, 'faluss_billing_customers' ) && false !== strpos( $schema, 'faluss_billing_checkout_sessions' ) && false !== strpos( $schema, 'faluss_subscription_notifications' ), 'The migration must add replayable billing tables without replacing SUB-01A tables.' );
sub01b_assert( false !== strpos( $notifications, 'trial_reminder_j7' ) && false !== strpos( $notifications, 'trial_reminder_j3' ) && false !== strpos( $notifications, 'trial_reminder_j1' ) && false !== strpos( $notifications, 'wp_mail' ) && false !== strpos( $notifications, 'faluss_subscriptions_daily_lock' ), 'Transactional reminders require J-7/J-3/J-1, wp_mail idempotence and a reconciliation lock.' );
sub01b_assert( false !== strpos( $returns, 'checkout_for_state' ) && false !== strpos( $returns, 'matches_provider_session' ) && false !== strpos( $returns, 'wp_safe_redirect' ) && false !== strpos( $returns, 'checkout_return_completed' ) && false === strpos( $returns, '<!doctype html>' ), 'Checkout returns must validate state/session then PRG without rendering an autonomous page.' );
sub01b_assert( false !== strpos( $admin, "SANDBOX_TAB = 'sandbox-test'" ) && false !== strpos( $admin, 'Faluss Max' ) && false !== strpos( $notifications, 'Faluss Max' ) && false !== strpos( $billing, "Faluss_Subscriptions_Catalog::PRO" ), 'Visible labels must use Faluss Max while the technical pro contract remains intact.' );
sub01b_assert( false !== strpos( $admin, 'sandbox_checkout' ) && false !== strpos( $admin, 'Configuration Stripe' ) && false !== strpos( $admin, 'resync_subscription' ) && false !== strpos( $admin, 'sandbox_test_only' ), 'Protected administration must expose configuration, sandbox and resynchronization only.' );
sub01b_assert( false !== strpos( $bootstrap, 'Faluss_Subscriptions_Webhooks::boot' ) && false !== strpos( $bootstrap, 'Faluss_Subscriptions_Notifications::boot' ), 'Bootstrap must mount webhook and daily operational services.' );

sub01b_assert( 'trialing' === Faluss_Subscriptions_Billing::normalise_state( array( 'status' => 'trialing' ) ), 'A trialing Stripe subscription must normalize to trialing only after billing verification.' );
sub01b_assert( 'canceling' === Faluss_Subscriptions_Billing::normalise_state( array( 'status' => 'active', 'cancel_at_period_end' => true ) ), 'An active Stripe subscription cancelled at period end must normalize to canceling.' );
sub01b_assert( 'past_due' === Faluss_Subscriptions_Billing::normalise_state( array( 'status' => 'past_due' ) ) && 'suspended' === Faluss_Subscriptions_Billing::normalise_state( array( 'status' => 'unpaid' ) ) && 'expired' === Faluss_Subscriptions_Billing::normalise_state( array( 'status' => 'incomplete' ) ), 'Past due, unpaid and incomplete must normalize to the required distinct central outcomes.' );

echo "SUB-01B Stripe contract: OK\n";
