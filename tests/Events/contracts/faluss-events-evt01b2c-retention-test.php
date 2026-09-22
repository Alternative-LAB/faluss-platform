<?php

/** EVT-01B.2C effective retention, tombstones and schema migration regression. */
ob_start();
require __DIR__ . '/faluss-events-evt01b2a-engine-test.php';
$evt01b2c_prior_output = ob_get_clean();

$root = dirname( __DIR__, 3 );
require_once $root . '/src/Events/Legacy/includes/class-faluss-events-retention.php';

if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['evt01b2c_actions'][ $hook ][ $priority ][] = $callback; }
    function add_filter( $hook, $callback ) { $GLOBALS['evt01b2c_filters'][ $hook ][] = $callback; }
    function wp_next_scheduled( $hook ) { return $GLOBALS['evt01b2c_cron'][ $hook ] ?? false; }
    function wp_schedule_event( $time, $schedule, $hook ) { $GLOBALS['evt01b2c_cron'][ $hook ] = array( $time, $schedule ); return true; }
    function wp_clear_scheduled_hook( $hook ) { $GLOBALS['evt01b2c_cleared'][] = $hook; unset( $GLOBALS['evt01b2c_cron'][ $hook ] ); return 1; }
}
if ( ! class_exists( 'Faluss_Federation_Client' ) ) {
    final class Faluss_Federation_Client {
        public static $calls = 0;
        public static function event_publish( $node, $app, $event ) { unset( $node, $app, $event ); self::$calls++; return new WP_Error( 'unexpected_network' ); }
    }
}

$evt01b2c_assertions = 0;
function evt01b2c_assert( $condition, $message ) {
    global $evt01b2c_assertions;
    $evt01b2c_assertions++;
    if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); }
}
function evt01b2c_private( $class, $method, $arguments = array() ) { $reflection = new ReflectionMethod( $class, $method ); $reflection->setAccessible( true ); return $reflection->invokeArgs( null, $arguments ); }
function evt01b2c_rows_for_event( $db, $table, $event_id ) { return array_values( array_filter( $db->rows[ $table ] ?? array(), function ( $row ) use ( $event_id ) { return ( $row['event_id'] ?? null ) === $event_id; } ) ); }
function evt01b2c_seed_expired( $db, $event_id, $identity_hash, $event_hash ) {
    $now = gmdate( 'Y-m-d H:i:s', time() - 60 );
    $db->rows['wp_faluss_events_events'][] = array( 'id' => 900, 'event_id' => $event_id, 'source_identity_sha256' => $identity_hash, 'event_sha256' => $event_hash, 'retention_until' => $now );
    $db->rows['wp_faluss_events_outbox'][] = array( 'id' => 901, 'event_id' => $event_id );
    $db->rows['wp_faluss_events_inbox'][] = array( 'id' => 902, 'event_id' => $event_id );
    $db->rows['wp_faluss_events_consumer_deliveries'][] = array( 'id' => 903, 'event_id' => $event_id );
}

/* The schema-2 fresh path creates six empty verified tables and no receipt. */
$GLOBALS['evt01b2a_options'] = array();
$fresh = new EVT01B2A_WPDB();
$wpdb = $fresh;
evt01b2c_assert( true === Faluss_Events_Schema::install() && '2' === get_option( Faluss_Events_Schema::OPTION ) && 6 === count( $fresh->ddl ), 'Fresh installation must create and verify exactly six schema-2 tables.' );
evt01b2c_assert( array() === array_filter( array_map( 'count', $fresh->rows ) ), 'Fresh installation must seed no catalog, event, operation or tombstone.' );

/* The schema-1 migration verifies and preserves the historical five tables byte-for-byte. */
$legacy = new EVT01B2A_WPDB();
$wpdb = $legacy;
$GLOBALS['evt01b2a_options'] = array();
evt01b2c_assert( true === Faluss_Events_Schema::install(), 'Controlled setup for the migration harness must succeed.' );
unset( $legacy->ddl['wp_faluss_events_tombstones'], $legacy->rows['wp_faluss_events_tombstones'] );
$GLOBALS['evt01b2a_options'][ Faluss_Events_Schema::OPTION ] = '1';
$legacy->rows['wp_faluss_events_catalogs'][] = array( 'id' => 1, 'sentinel' => 'unchanged' );
$legacy_ddl = $legacy->ddl;
$legacy_rows = $legacy->rows;
$before_queries = count( $legacy->queries );
evt01b2c_assert( true === Faluss_Events_Schema::maybe_upgrade() && '2' === get_option( Faluss_Events_Schema::OPTION ), 'Exact schema 1 must migrate additively to schema 2.' );
$migration_queries = array_slice( $legacy->queries, $before_queries );
evt01b2c_assert( 1 === count( array_filter( $migration_queries, function ( $query ) { return 0 === strpos( $query, 'CREATE TABLE ' ); } ) ) && 1 === count( array_filter( $migration_queries, function ( $query ) { return 0 === strpos( $query, 'RENAME TABLE ' ); } ) ), 'Migration 1 to 2 must create and promote only the tombstone table.' );
foreach ( $legacy_ddl as $table => $ddl ) { evt01b2c_assert( $ddl === $legacy->ddl[ $table ] && $legacy_rows[ $table ] === $legacy->rows[ $table ], 'Migration must preserve historical DDL and rows exactly: ' . $table ); }
evt01b2c_assert( array() === $legacy->rows['wp_faluss_events_tombstones'], 'Migration must create no tombstone.' );

/* The historical DDL is protected by the module characterization hash. */

/* Restore the production-class harness produced by EVT-01B.2A. */
$GLOBALS['evt01b2a_options'] = array();
$wpdb = new EVT01B2A_WPDB();
evt01b2c_assert( true === Faluss_Events_Schema::install(), 'Retention harness schema must install.' );
$catalog = evt01b2a_catalog();
evt01b2c_private( 'Faluss_Events_Engine', 'persist_validated_catalog', array( $catalog ) );
evt01b2a_reset_registry();
$callback_calls = 0;
$route = array( 'source_node_id' => 'future-node', 'source_app_key' => 'future-app', 'source_capability_key' => 'future-app.events', 'catalog_version' => '1.0.0', 'destination' => 'analytics.events', 'mode' => 'federation', 'target_node_id' => 'consumer-node', 'target_app_key' => 'consumer-app' );
$consumer = array( 'consumer_key' => 'future-consumer.analytics', 'destination' => 'analytics.events', 'target_node_id' => 'consumer-node', 'target_app_key' => 'consumer-app', 'sources' => array( array( 'node_id' => 'future-node', 'app_key' => 'future-app', 'capability_key' => 'future-app.events', 'catalog_version' => '1.0.0' ) ), 'callback' => function () use ( &$callback_calls ) { $callback_calls++; return true; } );
Faluss_Events_Engine::register_delivery_route( $route );
Faluss_Events_Engine::register_consumer( $consumer );
$expired_event = evt01b2a_event( '61000000-0000-4000-8000-000000000001', 'event-ref-retention' );
$live_event = evt01b2a_event( '62000000-0000-4000-8000-000000000001', 'event-ref-live' );
evt01b2c_assert( false === Faluss_Events_Engine::accept_local_event( $expired_event )['existing'] && false === Faluss_Events_Engine::accept_local_event( $live_event )['existing'], 'Controlled expired and live events must use the real acceptance path.' );
foreach ( $wpdb->rows['wp_faluss_events_events'] as &$row ) { if ( $row['event_id'] === $expired_event['event_id'] ) { $row['retention_until'] = gmdate( 'Y-m-d H:i:s', time() - 60 ); } } unset( $row );
$expired_row = evt01b2c_rows_for_event( $wpdb, 'wp_faluss_events_outbox', $expired_event['event_id'] )[0];
$expired_row['status'] = 'leased'; $expired_row['attempt_count'] = 1; $expired_row['lease_token'] = 'retention-lease';
foreach ( $wpdb->rows['wp_faluss_events_outbox'] as &$row ) { if ( $row['event_id'] === $expired_event['event_id'] ) { $row = $expired_row; } } unset( $row );
$wpdb->rows['wp_faluss_events_consumer_deliveries'][] = array( 'id' => 800, 'delivery_uuid' => '63000000-0000-4000-8000-000000000001', 'event_id' => $expired_event['event_id'], 'destination' => 'analytics.events', 'consumer_key' => 'future-consumer.analytics', 'status' => 'leased', 'attempt_count' => 1, 'lease_token' => 'consumer-retention-lease', 'lease_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 300 ), 'last_result_code' => null, 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'processed_at' => null );
$wpdb->rows['wp_faluss_events_inbox'][] = array( 'id' => 801, 'event_id' => $expired_event['event_id'] );
evt01b2c_private( 'Faluss_Events_Workers', 'process_outbox', array( $expired_row ) );
$expired_consumer = evt01b2c_rows_for_event( $wpdb, 'wp_faluss_events_consumer_deliveries', $expired_event['event_id'] )[0];
evt01b2c_private( 'Faluss_Events_Workers', 'process_consumer', array( $expired_consumer ) );
evt01b2c_assert( 0 === Faluss_Federation_Client::$calls && 0 === $callback_calls, 'Workers must reread expiry and execute neither network nor callback for an expired event.' );
evt01b2c_assert( 'expired' === evt01b2c_rows_for_event( $wpdb, 'wp_faluss_events_outbox', $expired_event['event_id'] )[0]['last_result_code'] && 'expired' === evt01b2c_rows_for_event( $wpdb, 'wp_faluss_events_consumer_deliveries', $expired_event['event_id'] )[0]['last_result_code'], 'Expired attempts must use only the closed technical result.' );

/* Simulate a concurrent purge by holding the deterministic maintenance lock. */
$retention_lock = 'faluss_evt_retention_' . substr( hash( 'sha256', $wpdb->prefix ), 0, 40 );
evt01b2c_assert( 1 === $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $retention_lock, 0 ) ) && false === Faluss_Events_Retention::run(), 'A second purge process must fail closed while the retention lock is held.' );
evt01b2c_assert( 1 === count( evt01b2c_rows_for_event( $wpdb, 'wp_faluss_events_events', $expired_event['event_id'] ) ), 'Concurrent lock refusal must not partially purge.' );
$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $retention_lock ) );

$catalog_count = count( $wpdb->rows['wp_faluss_events_catalogs'] );
evt01b2c_assert( true === Faluss_Events_Retention::run(), 'Bounded retention run must succeed for an expired event.' );
foreach ( array( 'wp_faluss_events_events', 'wp_faluss_events_outbox', 'wp_faluss_events_inbox', 'wp_faluss_events_consumer_deliveries' ) as $table ) { evt01b2c_assert( array() === evt01b2c_rows_for_event( $wpdb, $table, $expired_event['event_id'] ), 'Purge must remove event children in full: ' . $table ); }
evt01b2c_assert( 1 === count( evt01b2c_rows_for_event( $wpdb, 'wp_faluss_events_events', $live_event['event_id'] ) ) && $catalog_count === count( $wpdb->rows['wp_faluss_events_catalogs'] ), 'Non-expired events and accepted catalogs must be preserved.' );
$tombstones = evt01b2c_rows_for_event( $wpdb, 'wp_faluss_events_tombstones', $expired_event['event_id'] );
evt01b2c_assert( 1 === count( $tombstones ) && array( 'id', 'tombstone_uuid', 'event_id', 'source_identity_sha256', 'event_sha256', 'purged_at', 'expires_at', 'created_at' ) === array_keys( $tombstones[0] ), 'Purge must create exactly one minimal tombstone.' );
evt01b2c_assert( Faluss_Events_Retention::TOMBSTONE_SECONDS === strtotime( $tombstones[0]['expires_at'] . ' UTC' ) - strtotime( $tombstones[0]['purged_at'] . ' UTC' ), 'Tombstone retention must be exactly thirty days.' );
evt01b2c_assert( true === Faluss_Events_Retention::run() && 1 === count( evt01b2c_rows_for_event( $wpdb, 'wp_faluss_events_tombstones', $expired_event['event_id'] ) ), 'A non-expired receipt must survive an idempotent second purge run.' );

$before_children = count( $wpdb->rows['wp_faluss_events_outbox'] );
$retry = Faluss_Events_Engine::accept_local_event( $expired_event );
evt01b2c_assert( is_array( $retry ) && true === $retry['existing'] && $before_children === count( $wpdb->rows['wp_faluss_events_outbox'] ), 'An identical acceptance after purge must return existing without recreating child rows.' );
$divergent_hash = $expired_event; $divergent_hash['payload']['count'] = 2;
$divergent_id = $expired_event; $divergent_id['event_id'] = '61000000-0000-4000-8000-000000000002';
evt01b2c_assert( 'faluss_events_conflict' === evt01b2a_error_code( Faluss_Events_Engine::accept_local_event( $divergent_hash ) ) && 'faluss_events_conflict' === evt01b2a_error_code( Faluss_Events_Engine::accept_local_event( $divergent_id ) ), 'Divergent event hash or source identity mapping must conflict against the tombstone.' );
$runtime_db = $wpdb;
$runtime_options = $GLOBALS['evt01b2a_options'];

/* Every write boundary and an uncertain commit must restore the full pre-purge state. */
$fault_db = new EVT01B2A_WPDB(); $wpdb = $fault_db; $GLOBALS['evt01b2a_options'] = array(); Faluss_Events_Schema::install();
$fault_event_id = '64000000-0000-4000-8000-000000000001';
evt01b2c_seed_expired( $fault_db, $fault_event_id, str_repeat( 'a', 64 ), str_repeat( 'b', 64 ) );
foreach ( array( 'fail_start', 'fail_event_read', 'fail_tombstone_read' ) as $read_fault ) {
    $before = serialize( $fault_db->rows ); $fault_db->{$read_fault} = true;
    $result = evt01b2c_private( 'Faluss_Events_Retention', 'purge_event_transaction', array( $fault_event_id, gmdate( 'Y-m-d H:i:s' ) ) );
    evt01b2c_assert( false === $result && $before === serialize( $fault_db->rows ), 'Injected transaction/read failure must preserve every row: ' . $read_fault );
}
foreach ( array( 'insert:wp_faluss_events_tombstones', 'delete:wp_faluss_events_outbox', 'delete:wp_faluss_events_inbox', 'delete:wp_faluss_events_consumer_deliveries', 'delete:wp_faluss_events_events', 'commit' ) as $fault ) {
    $before = serialize( $fault_db->rows );
    if ( 0 === strpos( $fault, 'insert:' ) ) { $fault_db->fail_insert_table = substr( $fault, 7 ); }
    elseif ( 0 === strpos( $fault, 'delete:' ) ) { $fault_db->fail_delete_table = substr( $fault, 7 ); }
    else { $fault_db->fail_commit = true; }
    $result = evt01b2c_private( 'Faluss_Events_Retention', 'purge_event_transaction', array( $fault_event_id, gmdate( 'Y-m-d H:i:s' ) ) );
    evt01b2c_assert( false === $result && $before === serialize( $fault_db->rows ), 'Injected purge failure must roll back every row: ' . $fault );
}
$fault_db->rows['wp_faluss_events_tombstones'][] = array( 'id' => 950, 'tombstone_uuid' => '65000000-0000-4000-8000-000000000001', 'event_id' => $fault_event_id, 'source_identity_sha256' => str_repeat( 'a', 64 ), 'event_sha256' => str_repeat( 'b', 64 ), 'purged_at' => gmdate( 'Y-m-d H:i:s' ), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ), 'created_at' => gmdate( 'Y-m-d H:i:s' ) );
$fault_db->rows['wp_faluss_events_tombstones'][] = array( 'id' => 951, 'tombstone_uuid' => '65000000-0000-4000-8000-000000000002', 'event_id' => '65000000-0000-4000-8000-000000000003', 'source_identity_sha256' => str_repeat( 'a', 64 ), 'event_sha256' => str_repeat( 'b', 64 ), 'purged_at' => gmdate( 'Y-m-d H:i:s' ), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ), 'created_at' => gmdate( 'Y-m-d H:i:s' ) );
$before_ambiguous = serialize( $fault_db->rows );
evt01b2c_assert( false === evt01b2c_private( 'Faluss_Events_Retention', 'purge_event_transaction', array( $fault_event_id, gmdate( 'Y-m-d H:i:s' ) ) ) && $before_ambiguous === serialize( $fault_db->rows ), 'Ambiguous tombstones must fail closed without partial deletion.' );

/* Expired receipts are deleted separately; once deleted they no longer prove existence. */
$wpdb = $runtime_db; $GLOBALS['evt01b2a_options'] = $runtime_options;
foreach ( $wpdb->rows['wp_faluss_events_tombstones'] as &$row ) { if ( $row['event_id'] === $expired_event['event_id'] ) { $row['expires_at'] = gmdate( 'Y-m-d H:i:s', time() - 1 ); } } unset( $row );
evt01b2c_assert( true === Faluss_Events_Retention::run() && array() === evt01b2c_rows_for_event( $wpdb, 'wp_faluss_events_tombstones', $expired_event['event_id'] ), 'An expired tombstone must be deleted by the separate bounded cleanup.' );
$after_receipt_expiry = Faluss_Events_Engine::accept_local_event( $expired_event );
evt01b2c_assert( is_array( $after_receipt_expiry ) && false === $after_receipt_expiry['existing'] && 1 === count( evt01b2c_rows_for_event( $wpdb, 'wp_faluss_events_events', $expired_event['event_id'] ) ), 'A deleted expired tombstone must no longer act as an idempotence receipt.' );

/* Cron topology and immutable forbidden scopes are independently observable. */
$GLOBALS['evt01b2c_cron'] = array(); $GLOBALS['evt01b2c_cleared'] = array();
Faluss_Events_Workers::boot(); Faluss_Events_Retention::boot(); Faluss_Events_Workers::schedule(); Faluss_Events_Retention::schedule(); Faluss_Events_Workers::schedule(); Faluss_Events_Retention::schedule();
evt01b2c_assert( array( Faluss_Events_Workers::CONSUMER_HOOK, Faluss_Events_Workers::OUTBOX_HOOK, Faluss_Events_Retention::HOOK ) === ( function () { $hooks = array_keys( $GLOBALS['evt01b2c_cron'] ); sort( $hooks, SORT_STRING ); return $hooks; } )(), 'Exactly three unique Cron hooks must be scheduled idempotently.' );
Faluss_Events_Workers::deactivate(); Faluss_Events_Retention::deactivate(); sort( $GLOBALS['evt01b2c_cleared'], SORT_STRING );
evt01b2c_assert( array( Faluss_Events_Workers::CONSUMER_HOOK, Faluss_Events_Workers::OUTBOX_HOOK, Faluss_Events_Retention::HOOK ) === $GLOBALS['evt01b2c_cleared'], 'Deactivation must clear all three and only those three hooks.' );

$retention_source = file_get_contents( $root . '/src/Events/Legacy/includes/class-faluss-events-retention.php' );
$bootstrap = file_get_contents( $root . '/src/Events/Legacy/faluss-events.php' );
foreach ( array( 'register_rest_route', 'wp_remote_', 'Faluss_Analytics', 'Faluss_Tracking', 'setcookie', 'wp_ajax_', 'admin_post_' ) as $forbidden ) { evt01b2c_assert( false === stripos( $retention_source, $forbidden ), 'Retention must not activate browser, tracking, Analytics or network behavior: ' . $forbidden ); }
evt01b2c_assert( false !== strpos( $bootstrap, 'Version: 0.3.1' ) && false !== strpos( $bootstrap, "FALUSS_EVENTS_SCHEMA_VERSION', '2'" ), 'Faluss Events version and schema must be 0.3.1 and 2.' );

echo 'EVT-01B.2C effective retention: OK (' . $evt01b2c_assertions . ' assertions; production schema/engine/workers/retention with transactional wpdb harness)' . PHP_EOL;
