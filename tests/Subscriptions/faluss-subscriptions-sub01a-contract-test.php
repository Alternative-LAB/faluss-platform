<?php

define( 'ABSPATH', __DIR__ . '/' );

function sub01a_assert( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); } }

$root = dirname( __DIR__, 2 );
$plugin = $root . '/src/Subscriptions/Legacy';
$bootstrap = file_get_contents( $root . '/src/Subscriptions/SubscriptionsModule.php' );
$schema = file_get_contents( $plugin . '/includes/class-faluss-subscriptions-schema.php' );
$catalogue = file_get_contents( $plugin . '/includes/class-faluss-subscriptions-catalog.php' );
$trials = file_get_contents( $plugin . '/includes/class-faluss-subscriptions-trials.php' );
$entitlements = file_get_contents( $plugin . '/includes/class-faluss-subscriptions-entitlements.php' );
$resolver_source = file_get_contents( $plugin . '/includes/class-faluss-subscriptions-resolver.php' );
$repository = file_get_contents( $plugin . '/includes/class-faluss-subscriptions-repository.php' );
$audit = file_get_contents( $plugin . '/includes/class-faluss-subscriptions-audit.php' );
$admin = file_get_contents( $plugin . '/includes/class-faluss-subscriptions-admin.php' );
$notices = file_get_contents( $plugin . '/includes/class-faluss-subscriptions-admin-notices.php' );
$documentation = file_get_contents( $root . '/docs/modules/SUBSCRIPTIONS.md' );
$persistence_contract = file_get_contents( __DIR__ . '/faluss-subscriptions-sub01a-persistence-contract-test.php' );
$admin_post_contract = file_get_contents( __DIR__ . '/faluss-subscriptions-sub01a-admin-post-contract-test.php' );
$returns_contract = file_get_contents( __DIR__ . '/faluss-subscriptions-sub01b-returns-contract-test.php' );

sub01a_assert( false !== strpos( $bootstrap, "public const VERSION = '0.2.7'" ) && false !== strpos( $bootstrap, "return 'subscriptions'" ), 'The platform module must preserve Faluss Subscriptions 0.2.7 under its stable module identifier.' );
sub01a_assert( false !== strpos( $schema, 'RENAME TABLE' ) && false !== strpos( $schema, 'temporary_tables' ) && false !== strpos( $schema, 'current_schema_ready' ) && false !== strpos( $schema, 'GET_LOCK' ), 'Installation must be atomic, verified, locked and replayable.' );
foreach ( array( 'faluss_subscriptions', 'faluss_subscription_trials', 'faluss_entitlements', 'faluss_subscription_events', 'faluss_subscription_audit', 'ENGINE=InnoDB' ) as $needle ) { sub01a_assert( false !== strpos( $schema, $needle ), 'Missing dedicated subscription schema invariant: ' . $needle ); }
sub01a_assert( false !== strpos( $schema, 'trial_faluss_unique' ) && false !== strpos( $schema, 'trial_payment_fingerprint_unique' ) && false !== strpos( $schema, 'trial_override_reference_unique' ), 'Trial identity, derived payment fingerprint and administrative override must be uniquely constrained.' );

$wpdb = (object) array( 'prefix' => 'wp_' );
require_once $plugin . '/includes/class-faluss-subscriptions-schema.php';
$plan = Faluss_Subscriptions_Schema::get_install_plan( '0123456789abcdef' );
sub01a_assert( is_array( $plan ) && 8 === count( $plan['final_tables'] ) && 8 === count( $plan['temporary_tables'] ), 'The v2 initial migration must plan the five original and three additive billing tables.' );
foreach ( $plan['temporary_tables'] as $temporary ) { sub01a_assert( strlen( $temporary ) <= 64, 'Migration temporary table names must stay within MySQL limits.' ); }

require_once $plugin . '/includes/class-faluss-subscriptions-catalog.php';
require_once $plugin . '/includes/class-faluss-subscriptions-resolver.php';

$plans = Faluss_Subscriptions_Catalog::plans();
sub01a_assert( isset( $plans['free'], $plans['pro'] ) && 'Faluss Gratuit' === $plans['free']['public_name'] && 'Faluss Max' === $plans['pro']['public_name'] && 'pro' === $plans['pro']['key'], 'Catalogue must expose Faluss Max while preserving the canonical pro key.' );
sub01a_assert( 999 === $plans['pro']['periods']['monthly']['amount_cents'] && 9900 === $plans['pro']['periods']['annual']['amount_cents'] && 'EUR' === $plans['pro']['currency'], 'Pro must retain EUR 999/9900 integer-cent prices.' );
sub01a_assert( 15 === $plans['pro']['trial_days'] && true === $plans['pro']['card_required'] && true === $plans['pro']['auto_renew'] && true === $plans['pro']['commercially_active'], 'SUB-01B keeps the 15-day card-required trial testable only through protected administration.' );
sub01a_assert( false !== strpos( $trials, 'trial_payment_proof_required' ) && false !== strpos( $trials, 'verification_reference' ) && false !== strpos( $trials, 'payment_fingerprint_hash' ), 'Trial activation must refuse absent server payment proof and persist only a derived fingerprint.' );
sub01a_assert( false !== strpos( $trials, 'START TRANSACTION' ) && false !== strpos( $trials, 'trial_locks' ) && false !== strpos( $trials, 'trial_already_used' ), 'Trial activation must serialize and reject double consumption.' );

$now = '2026-09-08 12:00:00';
$free = Faluss_Subscriptions_Resolver::resolve_records( array(), array(), array(), $now );
sub01a_assert( 'free' === $free['level'] && false === $free['entitlements']['faluss.pro'], 'Absent subscription or entitlement must resolve to Gratuit.' );
$trial = Faluss_Subscriptions_Resolver::resolve_records( array(), array( array( 'trial_state' => 'trialing', 'expires_at' => '2026-09-23 12:00:00', 'trial_uuid' => 'trial-a' ) ), array(), $now );
sub01a_assert( 'pro' === $trial['level'] && 'trialing' === $trial['state'], 'A valid trial must resolve to Pro.' );
$trial_expired = Faluss_Subscriptions_Resolver::resolve_records( array(), array( array( 'trial_state' => 'trialing', 'expires_at' => $now, 'trial_uuid' => 'trial-b' ) ), array(), $now );
sub01a_assert( 'free' === $trial_expired['level'] && 'trial_expired' === $trial_expired['reason'], 'An exact trial expiration must resolve to Gratuit without cron.' );
$canceled_provider_trial = Faluss_Subscriptions_Resolver::resolve_records( array( array( 'provider' => 'stripe', 'provider_subscription_reference' => 'sub-canceled', 'normalized_state' => 'expired', 'provider_status' => 'canceled', 'subscription_uuid' => 'sub-canceled-local' ) ), array( array( 'trial_state' => 'trialing', 'expires_at' => '2026-09-23 12:00:00', 'verification_reference' => 'sub-canceled', 'trial_uuid' => 'trial-canceled' ) ), array(), $now );
sub01a_assert( 'free' === $canceled_provider_trial['level'] && false === $canceled_provider_trial['entitlements']['faluss.pro'], 'A Stripe subscription confirmed canceled must suppress its matching trial and never resolve Faluss Max.' );
$active = Faluss_Subscriptions_Resolver::resolve_records( array( array( 'normalized_state' => 'active', 'period_ends_at' => '2026-10-08 12:00:00', 'subscription_uuid' => 'sub-a' ) ), array(), array(), $now );
sub01a_assert( 'pro' === $active['level'] && 'active' === $active['state'], 'An active subscription must resolve to Pro.' );
$canceling = Faluss_Subscriptions_Resolver::resolve_records( array( array( 'normalized_state' => 'canceling', 'period_ends_at' => '2026-10-08 12:00:00', 'subscription_uuid' => 'sub-b' ) ), array(), array(), $now );
sub01a_assert( 'pro' === $canceling['level'] && 'canceling' === $canceling['state'], 'Cancellation at period end must preserve Pro until the term.' );
$grace_record = array( 'normalized_state' => 'past_due', 'period_ends_at' => '2026-09-08 12:00:00', 'grace_ends_at' => '2026-10-01 12:00:00', 'subscription_uuid' => 'sub-c' );
$grace = Faluss_Subscriptions_Resolver::resolve_records( array( $grace_record ), array(), array(), $now );
$after_grace = Faluss_Subscriptions_Resolver::resolve_records( array( $grace_record ), array(), array(), '2026-09-15 12:00:00' );
sub01a_assert( 'pro' === $grace['level'] && 'past_due' === $grace['state'] && 'free' === $after_grace['level'], 'Past due must preserve Pro for no more than the central seven-day grace period.' );
$revoked = Faluss_Subscriptions_Resolver::resolve_records( array( array( 'normalized_state' => 'active', 'period_ends_at' => '2026-10-08 12:00:00', 'subscription_uuid' => 'sub-d' ) ), array(), array( array( 'entitlement_key' => 'faluss.pro', 'entitlement_value' => 'revoked', 'source' => 'compliance_override', 'status' => 'active', 'starts_at' => '2026-09-01 00:00:00', 'expires_at' => null ) ), $now );
sub01a_assert( 'free' === $revoked['level'] && 'revoked' === $revoked['state'] && 'compliance_override' === $revoked['reason'], 'Compliance revocation must win over positive sources.' );
$admin_pro = Faluss_Subscriptions_Resolver::resolve_records( array(), array(), array( array( 'entitlement_key' => 'faluss.pro', 'entitlement_value' => 'pro', 'source' => 'admin_grant', 'priority' => 300, 'status' => 'active', 'starts_at' => '2026-09-01 00:00:00', 'expires_at' => '2026-10-01 00:00:00', 'entitlement_uuid' => 'grant-a' ) ), $now );
$admin_expired = Faluss_Subscriptions_Resolver::resolve_records( array(), array(), array( array( 'entitlement_key' => 'faluss.pro', 'entitlement_value' => 'pro', 'source' => 'admin_grant', 'priority' => 300, 'status' => 'active', 'starts_at' => '2026-09-01 00:00:00', 'expires_at' => $now, 'entitlement_uuid' => 'grant-b' ) ), $now );
sub01a_assert( 'pro' === $admin_pro['level'] && 'comped' === $admin_pro['state'] && 'free' === $admin_expired['level'], 'Temporary administrative Pro must apply and expire deterministically.' );

sub01a_assert( false !== strpos( $schema, 'provider_event_unique' ) && false !== strpos( $repository, "'idempotent' => true" ) && false !== strpos( $repository, 'payload_hash' ), 'Future provider events must be idempotent and retain only a payload hash.' );
sub01a_assert( false !== strpos( $audit, 'clean_state' ) && false !== strpos( $audit, 'payment|payload|fingerprint' ) && false !== strpos( $entitlements, 'admin_grant_succeeded' ) && false !== strpos( $entitlements, 'admin_pro_revoked' ), 'Sensitive mutations must be audited with sanitized states.' );
foreach ( array( "CAPABILITY = 'manage_faluss_subscriptions'", 'current_user_can', 'check_admin_referer', "'POST'", 'wp_safe_redirect' ) as $needle ) { sub01a_assert( false !== strpos( $admin, $needle ), 'Administration must retain capability/nonce/POST controls: ' . $needle ); }

sub01a_assert( false !== strpos( $schema, 'normalise_type' ) && false !== strpos( $schema, 'MySQL 8 omits legacy integer display widths' ) && false !== strpos( $schema, 'inspect_tables' ), 'The schema must accept only MySQL 8 integer-width presentation differences and expose all five table inspections.' );
sub01a_assert( false !== strpos( $admin, 'ensure_capability' ) && false !== strpos( $admin, "add_action( 'admin_init'" ), 'The administrator capability must be repaired on upgrade without widening another role.' );
sub01a_assert( false !== strpos( $admin, 'Faluss_Subscriptions_Admin_Notices::consume' ) && false !== strpos( $admin, 'Faluss_Subscriptions_Admin_Notices::add' ) && false !== strpos( $notices, 'delete_transient' ), 'PRG notices must be per-administrator and consumed exactly once.' );
sub01a_assert( false === strpos( $admin, 'fs_status' ) && false === strpos( $admin, 'last_error' ), 'Administrative redirects and notices must not expose generic URL status or SQL diagnostics.' );
sub01a_assert( false !== strpos( $admin, 'Faluss Max a été attribué jusqu’au %s.' ) && false !== strpos( $admin, "array( 'success', 'warning', 'error' )" ) && false !== strpos( $admin, "notice-' . esc_attr( \$type )" ), 'Grant, warning and failure notices must be explicit and readable.' );
sub01a_assert( false !== strpos( $entitlements, 'START TRANSACTION' ) && false !== strpos( $entitlements, 'ROLLBACK' ) && false !== strpos( $entitlements, 'grant_by_id' ) && false !== strpos( $entitlements, 'saved_grant_matches' ) && false !== strpos( $entitlements, 'admin_grant_resolution_failed' ), 'Administrative grants must be transactional, independently resolved, audited and reread before success.' );
sub01a_assert( false !== strpos( $entitlements, 'wp_cache_delete' ) && false !== strpos( $entitlements, 'invalidate_decision' ), 'Changed grants must invalidate the central decision cache key if one exists.' );
sub01a_assert( false !== strpos( $entitlements, 'wp_timezone' ) && false !== strpos( $entitlements, "Y-m-d\\\\TH:i" ), 'A datetime-local expiration must be parsed in the WordPress site timezone then saved as UTC.' );
sub01a_assert( false !== strpos( $trials, 'trial_override_audit_failed' ) && false !== strpos( $trials, 'START TRANSACTION' ) && false !== strpos( $trials, 'FOR UPDATE' ), 'Trial eligibility overrides must not report success before transaction, reread and audit.' );
sub01a_assert( false !== strpos( $admin, "'run_migration'" ) && false !== strpos( $admin, 'migration_verified' ) && false !== strpos( $admin, 'Tables vérifiées' ), 'Migration reruns must report a specific result and expose the structural inspection.' );
sub01a_assert( false !== strpos( $persistence_contract, 'Faluss_Subscriptions_Entitlements::grant_temporary_pro' ) && false !== strpos( $persistence_contract, 'Faluss_Subscriptions_Resolver::resolve_for_faluss_id' ) && false !== strpos( $persistence_contract, 'MySQL 8-style schema' ), 'The repair requires a functional persistence contract, not only source-string assertions.' );
sub01a_assert( false !== strpos( $admin, 'POST_ACTION' ) && false !== strpos( $admin, 'admin_post_url' ) && false !== strpos( $admin, 'mutation_form_open' ) && false !== strpos( $admin, 'data-faluss-subscriptions-mutation' ) && false !== strpos( $admin_post_contract, 'sub01a_dispatch_form' ) && false !== strpos( $admin_post_contract, 'admin_grant_succeeded' ) && false !== strpos( $admin_post_contract, 'revoke_grant' ), 'The browser-shaped contract must execute the bootstrap handler from autonomous admin-post forms through grant and revoke.' );
sub01a_assert( false !== strpos( $returns_contract, 'checkout_return_completed' ) && false !== strpos( $returns_contract, 'checkout_return_cancelled' ) && false !== strpos( $returns_contract, 'checkout_return_session_invalid' ), 'The browser-shaped Checkout return contract must cover safe PRG outcomes.' );
foreach ( array( 'admin_grant_succeeded', 'admin_grant_failed', 'admin_grant_forbidden', 'admin_grant_invalid_nonce', 'admin_grant_invalid_subject', 'admin_grant_invalid_expiration', 'admin_grant_persistence_failed', 'admin_grant_resolution_failed' ) as $outcome ) { sub01a_assert( false !== strpos( $admin . $entitlements, $outcome ), 'Administrative grant outcome must remain traceable: ' . $outcome ); }

$all_source = ''; foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin ) ) as $file ) { if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) { $all_source .= file_get_contents( $file->getPathname() ); } }
sub01a_assert( false === strpos( $all_source, 'wp_ajax_' ) && false === strpos( $all_source, 'add_shortcode' ) && false !== strpos( $all_source, "'faluss-subscriptions/v1'" ), 'SUB-01B may expose only its signed Stripe webhook; no browser trial activation endpoint exists.' );
sub01a_assert( 0 === preg_match( '/(?:sk|pk)_(?:live|test)_[A-Za-z0-9]{10,}/', $all_source ), 'SUB-01B must not hard-code a Stripe secret.' );
sub01a_assert( false !== strpos( $documentation, 'SUB-01B' ) && false !== strpos( $documentation, 'Token Engine' ) && false !== strpos( $documentation, 'FL-21' ), 'Documentation must preserve boundaries and future lots.' );

echo "SUB-01A subscriptions contract: OK\n";
