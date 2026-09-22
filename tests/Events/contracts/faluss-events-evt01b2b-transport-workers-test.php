<?php

/** EVT-01B.2B closed transport, leases and workers regression. */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

final class WP_Error {
    private $code;
    public function __construct( $code ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_generate_uuid4() { $GLOBALS['evt01b2b_uuid'] = ( $GLOBALS['evt01b2b_uuid'] ?? 0 ) + 1; return sprintf( '90000000-0000-4000-8000-%012d', $GLOBALS['evt01b2b_uuid'] ); }
function absint( $value ) { return abs( (int) $value ); }
function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['evt01b2b_actions'][ $hook ][ $priority ][] = $callback; }
function add_filter( $hook, $callback ) { $GLOBALS['evt01b2b_filters'][ $hook ][] = $callback; }
function wp_next_scheduled( $hook ) { return $GLOBALS['evt01b2b_cron'][ $hook ] ?? false; }
function wp_schedule_event( $time, $schedule, $hook ) { $GLOBALS['evt01b2b_cron'][ $hook ] = array( $time, $schedule ); return true; }
function wp_clear_scheduled_hook( $hook ) { $GLOBALS['evt01b2b_cleared'][] = $hook; unset( $GLOBALS['evt01b2b_cron'][ $hook ] ); return 1; }

$evt01b2b_assertions = 0;
function evt01b2b_assert( $condition, $message ) { global $evt01b2b_assertions; $evt01b2b_assertions++; if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); } }
function evt01b2b_private( $class, $method, $arguments = array() ) { $r = new ReflectionMethod( $class, $method ); $r->setAccessible( true ); return $r->invokeArgs( null, $arguments ); }

final class Faluss_Federation_Crypto {
    public static function is_node( $v ) { return is_string( $v ) && '*' !== $v && 1 === preg_match( '/^[a-z][a-z0-9-]{1,63}$/D', $v ); }
    public static function is_key_id( $v ) { return is_string( $v ) && strlen( $v ) >= 8; }
    public static function is_semver( $v ) { return is_string( $v ) && strlen( $v ) <= 32 && 1 === preg_match( '/^[1-9][0-9]*\.[0-9]+\.[0-9]+$/D', $v ); }
    public static function is_canonical_origin( $v ) { return is_string( $v ) && 0 === strpos( $v, 'https://' ); }
    public static function base64url_decode( $v, $n ) { unset( $v ); return str_repeat( 'x', $n ); }
    public static function local_identity() { return array( 'node_id' => 'consumer-node', 'app_key' => 'consumer-app', 'key_id' => 'consumer-key-01' ); }
}

final class Faluss_Federation_Schema {
    public static function is_ready() { return true; }
    public static function peers_table() { return 'wp_faluss_federation_peers'; }
    public static function quote_identifier( $v ) { return '`' . $v . '`'; }
}

final class Faluss_Events_Schema {
    public static function is_ready() { return true; }
    public static function events_table() { return 'wp_faluss_events_events'; }
    public static function outbox_table() { return 'wp_faluss_events_outbox'; }
    public static function inbox_table() { return 'wp_faluss_events_inbox'; }
    public static function consumer_deliveries_table() { return 'wp_faluss_events_consumer_deliveries'; }
    public static function tombstones_table() { return 'wp_faluss_events_tombstones'; }
    public static function quote_identifier( $v ) { return '`' . $v . '`'; }
}

final class EVT01B2B_WPDB {
    public $prefix = 'wp_';
    public $last_error = '';
    public $rows = array( 'wp_faluss_events_outbox' => array(), 'wp_faluss_events_consumer_deliveries' => array(), 'wp_faluss_events_inbox' => array() );
    public $in_transaction = false;
    public $network_transactions = array();
    public $lock_available = true;
    public $fail_next_consumer_finish = false;
    public $fail_next_commit = false;
    private $snapshot;

    public function prepare( $query, ...$args ) { $i = 0; return preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$i, $args ) { $v = $args[ $i++ ]; return '%d' === $m[0] ? (string) (int) $v : "'" . str_replace( "'", "''", (string) $v ) . "'"; }, $query ); }
    public function query( $query ) {
        if ( 'START TRANSACTION' === $query ) { $this->in_transaction = true; $this->snapshot = unserialize( serialize( $this->rows ) ); return 1; }
        if ( 'COMMIT' === $query ) { if ( $this->fail_next_commit ) { $this->fail_next_commit = false; return false; } $this->in_transaction = false; $this->snapshot = null; return 1; }
        if ( 'ROLLBACK' === $query ) { if ( is_array( $this->snapshot ) ) { $this->rows = $this->snapshot; } $this->in_transaction = false; $this->snapshot = null; return 1; }
        return 1;
    }
    public function get_results( $query, $output = null ) {
        unset( $output ); $table = false !== strpos( $query, 'consumer_deliveries' ) ? 'wp_faluss_events_consumer_deliveries' : 'wp_faluss_events_outbox'; $now = time(); $out = array();
        foreach ( $this->rows[ $table ] as $row ) {
            $status = $row['status']; $due = 'pending' === $status || ( 'wp_faluss_events_outbox' === $table && 'retry' === $status && strtotime( $row['next_attempt_at'] ) <= $now ) || ( 'wp_faluss_events_consumer_deliveries' === $table && 'retry' === $status && strtotime( $row['lease_expires_at'] ) <= $now ) || ( 'leased' === $status && ! empty( $row['lease_expires_at'] ) && strtotime( $row['lease_expires_at'] ) <= $now );
            if ( $due ) { $out[] = $row; }
        }
        usort( $out, function ( $a, $b ) { return $a['id'] <=> $b['id']; } ); return array_slice( $out, 0, 50 );
    }
    public function get_row( $query, $output = null ) { unset( $output ); if ( preg_match( "/WHERE id = ([0-9]+)/", $query, $m ) ) { foreach ( $this->rows['wp_faluss_events_outbox'] as $row ) { if ( (int) $row['id'] === (int) $m[1] ) { return array( 'status' => $row['status'], 'lease_token' => $row['lease_token'] ); } } } return null; }
    public function get_var( $query ) {
        if ( 0 === strpos( $query, 'SELECT GET_LOCK(' ) ) { return $this->lock_available ? 1 : 0; }
        if ( 0 === strpos( $query, 'SELECT RELEASE_LOCK(' ) ) { return 1; }
        if ( false !== strpos( $query, 'consumer_deliveries' ) && preg_match( "/event_id = '([^']+)' AND destination = '([^']+)' AND consumer_key = '([^']+)'/", $query, $m ) ) { foreach ( $this->rows['wp_faluss_events_consumer_deliveries'] as $row ) { if ( $row['event_id'] === $m[1] && $row['destination'] === $m[2] && $row['consumer_key'] === $m[3] ) { return $row['delivery_uuid']; } } }
        return null;
    }
    public function update( $table, $data, $where, $formats = null, $where_formats = null ) {
        unset( $formats, $where_formats );
        if ( $this->fail_next_consumer_finish && 'wp_faluss_events_consumer_deliveries' === $table && isset( $where['lease_token'] ) ) { $this->fail_next_consumer_finish = false; return false; }
        foreach ( $this->rows[ $table ] as &$row ) { $match = true; foreach ( $where as $k => $v ) { if ( (string) ( $row[ $k ] ?? '' ) !== (string) $v ) { $match = false; break; } } if ( $match ) { foreach ( $data as $k => $v ) { $row[ $k ] = $v; } unset( $row ); return 1; } } unset( $row ); return 0;
    }
    public function insert( $table, $data, $formats = null ) { unset( $formats ); $data['id'] = count( $this->rows[ $table ] ) + 1; $this->rows[ $table ][] = $data; return 1; }
}

final class Faluss_Events_Engine {
    public static $event;
    public static $inbound_result;
    public static $mode = 'federation';
    public static $callback;
    public static function accept_inbound_event( $event, $sender, $recipient ) { $GLOBALS['evt01b2b_inbound'][] = array( $event, $sender, $recipient ); return self::$inbound_result; }
    public static function route_registry_ready() { return true; }
    public static function consumer_registry_ready() { return true; }
    public static function event_record_by_id( $id ) { return $id === ( self::$event['event_id'] ?? null ) ? array( 'event' => self::$event, 'event_sha256' => hash( 'sha256', Faluss_Events_Canonicalizer::canonicalize_event( self::$event ) ), 'retention_until' => '2031-01-01 00:00:00' ) : new WP_Error( 'faluss_events_unavailable' ); }
    public static function delivery_route( $event, $row ) { unset( $event ); return array( 'mode' => self::$mode, 'destination' => $row['destination'], 'target_node_id' => $row['target_node_id'], 'target_app_key' => $row['target_app_key'] ); }
    public static function consumers_for_local_route( $event, $destination, $node, $app ) { unset( $event, $node, $app ); return array( array( 'consumer_key' => 'future-consumer.analytics', 'destination' => $destination ) ); }
    public static function consumer_descriptor( $event, $row, $node, $app ) { unset( $event, $row, $node, $app ); return array( 'callback' => self::$callback ); }
}

final class Faluss_Federation_Client {
    public static $calls = array();
    public static $response;
    public static function event_publish( $node, $app, $event ) { global $wpdb; self::$calls[] = array( $node, $app, $event ); $wpdb->network_transactions[] = $wpdb->in_transaction; return self::$response; }
}

$root = dirname( __DIR__, 3 );
require_once $root . '/src/Events/Legacy/includes/class-faluss-events-catalog-validator.php';
require_once $root . '/src/Events/Legacy/includes/class-faluss-events-envelope-validator.php';
require_once $root . '/src/Events/Legacy/includes/class-faluss-events-canonicalizer.php';
require_once $root . '/tests/Events/fixtures/Federation/includes/class-faluss-federation-providers.php';
require_once $root . '/tests/Events/fixtures/Federation/includes/class-faluss-federation-policy.php';
require_once $root . '/tests/Events/fixtures/Federation/includes/class-faluss-federation-server.php';
require_once $root . '/src/Events/Legacy/includes/class-faluss-events.php';
require_once $root . '/src/Events/Legacy/includes/class-faluss-events-retention.php';
require_once $root . '/src/Events/Legacy/includes/class-faluss-events-workers.php';

$event = array(
    'contract_version' => '1.0.0', 'event_id' => '70000000-0000-4000-8000-000000000001', 'event_type' => 'future-app.fact', 'event_version' => '1.0.0',
    'source' => array( 'node_id' => 'future-node', 'app_key' => 'future-app', 'owner' => 'future-app', 'capability_key' => 'future-app.events', 'catalog_version' => '1.0.0' ),
    'source_event_reference' => 'future-ref-0001', 'occurred_at' => '2030-01-01T00:00:00Z', 'produced_at' => '2030-01-01T00:00:01Z', 'subject_context' => null,
    'actor_context' => array( 'actor_type' => 'system', 'actor_faluss_id' => null, 'anonymous_reference' => null, 'anonymous_scope' => null ), 'object_context' => null,
    'destinations' => array( 'analytics.events' ), 'payload_contract' => array( 'document_type' => 'future-app.fact-payload', 'contract_version' => '1.0.0' ), 'payload' => array( 'count' => 1 ),
);
Faluss_Events_Engine::$event = $event;
$hash = hash( 'sha256', Faluss_Events_Canonicalizer::canonicalize_event( $event ) );
$request = array( 'protocol_version' => '1', 'message_type' => 'request', 'request_id' => '70000000-0000-4000-8000-000000000002', 'operation' => 'event.publish', 'sender' => array( 'node_id' => 'future-node', 'app_key' => 'future-app', 'key_id' => 'future-key-01' ), 'recipient' => array( 'node_id' => 'consumer-node', 'app_key' => 'consumer-app' ), 'issued_at' => '2030-01-01T00:00:00Z', 'expires_at' => '2030-01-01T00:05:00Z', 'nonce' => str_repeat( 'A', 43 ), 'subject_context' => null, 'parameters' => array( 'event' => $event ) );

evt01b2b_assert( in_array( 'event.publish', Faluss_Federation_Policy::operations(), true ) && true === Faluss_Events::register_federation_integration(), 'Events must register the one closed publish adapter.' );
$request_schema = json_decode( file_get_contents( $root . '/tests/Events/fixtures/Federation/contracts/faluss-federation-request.schema.json' ), true ); $acceptance_schema = json_decode( file_get_contents( $root . '/contracts/faluss-event-acceptance.schema.json' ), true );
$publish_branch = $request_schema['allOf'][4]['then']['properties'] ?? array();
evt01b2b_assert( in_array( 'event.publish', $request_schema['properties']['operation']['enum'] ?? array(), true ) && array_key_exists( 'const', $publish_branch['subject_context'] ?? array() ) && null === $publish_branch['subject_context']['const'] && array( 'event' ) === ( $publish_branch['parameters']['required'] ?? null ) && false === ( $publish_branch['parameters']['additionalProperties'] ?? null ) && 'faluss-event-envelope.schema.json' === ( $publish_branch['parameters']['properties']['event']['$ref'] ?? null ), 'Federation JSON contract must close publish to one EVT envelope and null subject.' );
evt01b2b_assert( false === ( $acceptance_schema['additionalProperties'] ?? null ) && array( 'event_id', 'event_sha256', 'disposition' ) === ( $acceptance_schema['required'] ?? null ) && array( 'accepted', 'existing' ) === ( $acceptance_schema['properties']['disposition']['enum'] ?? null ), 'Acceptance JSON contract must expose exactly ID, digest and two dispositions.' );
evt01b2b_assert( Faluss_Events::validate_publish_request( $request ), 'Exactly one valid event parameter must pass the production adapter.' );
$missing_validator_state = Faluss_Events::payload_validator_state( $event ); $invalid_selector = $event; $invalid_selector['payload_contract']['extra'] = true;
evt01b2b_assert( 'unavailable' === $missing_validator_state && 'invalid' === Faluss_Events::payload_validator_state( $invalid_selector ), 'Missing payload registries and invalid selectors must remain distinguishable for closed error mapping.' );
$extra = $request; $extra['parameters']['extra'] = true; evt01b2b_assert( ! Faluss_Events::validate_publish_request( $extra ), 'Additional publish parameters must fail.' );
$wild = $request; $wild['parameters']['event']['source']['node_id'] = '*'; evt01b2b_assert( ! Faluss_Events::validate_publish_request( $wild ), 'Wildcard event sources must fail.' );

$peer = array( 'peer_node_id' => 'future-node', 'peer_app_key' => 'future-app', 'canonical_origin' => 'https://future.test', 'key_id' => 'future-key-01', 'public_key' => str_repeat( 'A', 43 ), 'key_state' => 'active', 'valid_from' => gmdate( 'Y-m-d H:i:s', time() - 60 ), 'valid_until' => gmdate( 'Y-m-d H:i:s', time() + 3600 ), 'operations' => array( 'event.publish' ), 'owner_apps' => array(), 'capabilities' => array( 'future-app.events' ), 'audiences' => array() );
$identity = Faluss_Federation_Crypto::local_identity();
evt01b2b_assert( true === Faluss_Federation_Policy::allow_incoming( $request, $peer, $identity ), 'Exact sender, recipient, operation and capability must authorize.' );
$no_operation = $peer; $no_operation['operations'] = array( 'diagnostic.read' ); evt01b2b_assert( is_wp_error( Faluss_Federation_Policy::allow_incoming( $request, $no_operation, $identity ) ), 'An existing policy without publish must refuse before dispatch.' );
$no_capability = $peer; $no_capability['capabilities'] = array(); evt01b2b_assert( is_wp_error( Faluss_Federation_Policy::allow_incoming( $request, $no_capability, $identity ) ), 'Missing exact capability must refuse.' );
$wild_capability = $peer; $wild_capability['capabilities'] = array( '*' ); evt01b2b_assert( is_wp_error( Faluss_Federation_Policy::allow_incoming( $request, $wild_capability, $identity ) ), 'Wildcard policy cannot authorize.' );

Faluss_Events_Engine::$inbound_result = array( 'event_id' => $event['event_id'], 'event_sha256' => $hash, 'existing' => false );
$accepted = Faluss_Federation_Providers::dispatch( $request, $identity );
evt01b2b_assert( 'success' === $accepted['status'] && 'accepted' === $accepted['payload']['disposition'] && 1 === count( $GLOBALS['evt01b2b_inbound'] ), 'Receiver must invoke only inbound acceptance and return accepted after it succeeds.' );
evt01b2b_assert( Faluss_Events::validate_publish_response( $accepted['payload'], $accepted['payload_contract'], $request ), 'Acknowledgement must bind exact event ID and canonical digest.' );
$forged = $accepted['payload']; $forged['event_id'] = '70000000-0000-4000-8000-000000000099'; evt01b2b_assert( ! Faluss_Events::validate_publish_response( $forged, $accepted['payload_contract'], $request ), 'Acknowledgement for another event must fail.' );
$forged = $accepted['payload']; $forged['event_sha256'] = str_repeat( '0', 64 ); evt01b2b_assert( ! Faluss_Events::validate_publish_response( $forged, $accepted['payload_contract'], $request ), 'Forged acknowledgement digest must fail.' );
Faluss_Events_Engine::$inbound_result['existing'] = true; $existing = Faluss_Federation_Providers::dispatch( $request, $identity ); evt01b2b_assert( 'existing' === $existing['payload']['disposition'], 'A lost-response retry must return existing as a successful acknowledgement.' );
foreach ( array( 'faluss_events_conflict' => 'incompatible', 'faluss_events_registry_unavailable' => 'not_available', 'faluss_events_unavailable' => 'temporarily_unavailable' ) as $code => $status ) { Faluss_Events_Engine::$inbound_result = new WP_Error( $code ); evt01b2b_assert( $status === Faluss_Federation_Providers::dispatch( $request, $identity )['status'], 'Receiver error mapping must remain closed: ' . $code ); }

$wpdb = new EVT01B2B_WPDB(); $GLOBALS['wpdb'] = $wpdb;
$base_outbox = array( 'id' => 1, 'delivery_uuid' => '80000000-0000-4000-8000-000000000001', 'event_id' => $event['event_id'], 'destination' => 'analytics.events', 'target_node_id' => 'consumer-node', 'target_app_key' => 'consumer-app', 'status' => 'pending', 'attempt_count' => 0, 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ), 'lease_token' => null, 'lease_expires_at' => null, 'last_result_code' => null, 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'delivered_at' => null );
$wpdb->rows['wp_faluss_events_outbox'][] = $base_outbox;
Faluss_Federation_Client::$response = array( 'status' => 'success', 'payload' => array( 'event_id' => $event['event_id'], 'event_sha256' => $hash, 'disposition' => 'accepted' ) );
Faluss_Events_Workers::run_outbox();
evt01b2b_assert( 'delivered' === $wpdb->rows['wp_faluss_events_outbox'][0]['status'] && 1 === $wpdb->rows['wp_faluss_events_outbox'][0]['attempt_count'] && array( false ) === $wpdb->network_transactions, 'Outbox lease must commit before the sole Federation facade call.' );

$wpdb->rows['wp_faluss_events_outbox'][0] = $base_outbox; Faluss_Federation_Client::$calls = array();
$first = evt01b2b_private( 'Faluss_Events_Workers', 'claim_outbox' ); $second = evt01b2b_private( 'Faluss_Events_Workers', 'claim_outbox' );
evt01b2b_assert( 1 === count( $first ) && 0 === count( $second ), 'Two claimers cannot receive the same unexpired attempt.' );
$old = $first[0]; $wpdb->rows['wp_faluss_events_outbox'][0]['lease_expires_at'] = gmdate( 'Y-m-d H:i:s', time() - 1 ); $reclaimed = evt01b2b_private( 'Faluss_Events_Workers', 'claim_outbox' );
evt01b2b_assert( 1 === count( $reclaimed ) && 2 === $reclaimed[0]['attempt_count'] && $old['lease_token'] !== $reclaimed[0]['lease_token'], 'Expired lease must be reclaimed with a new token and attempt.' );
evt01b2b_private( 'Faluss_Events_Workers', 'finish_outbox', array( $old, true, 'accepted' ) ); evt01b2b_assert( 'leased' === $wpdb->rows['wp_faluss_events_outbox'][0]['status'], 'An old worker cannot finalize a reclaimed lease.' );

$delays = array(); for ( $attempt = 1; $attempt <= 7; $attempt++ ) { $delays[] = evt01b2b_private( 'Faluss_Events_Workers', 'retry_delay', array( $attempt ) ); }
evt01b2b_assert( array( 60, 300, 900, 3600, 10800, 21600, 43200 ) === $delays, 'Seven retry deadlines must be exact and deterministic.' );
$dead = $reclaimed[0]; $dead['attempt_count'] = 8; $dead['lease_token'] = $wpdb->rows['wp_faluss_events_outbox'][0]['lease_token']; $wpdb->rows['wp_faluss_events_outbox'][0]['attempt_count'] = 8; evt01b2b_private( 'Faluss_Events_Workers', 'finish_outbox', array( $dead, false, 'temporary' ) ); evt01b2b_assert( 'dead_letter' === $wpdb->rows['wp_faluss_events_outbox'][0]['status'], 'Eighth unconfirmed outbox attempt must dead-letter.' );
$expired_eighth = $base_outbox; $expired_eighth['status'] = 'leased'; $expired_eighth['attempt_count'] = 8; $expired_eighth['lease_token'] = 'expired-eight'; $expired_eighth['lease_expires_at'] = gmdate( 'Y-m-d H:i:s', time() - 1 ); $wpdb->rows['wp_faluss_events_outbox'][0] = $expired_eighth;
$exhausted_claim = evt01b2b_private( 'Faluss_Events_Workers', 'claim_outbox' ); evt01b2b_assert( array() === $exhausted_claim && 'dead_letter' === $wpdb->rows['wp_faluss_events_outbox'][0]['status'] && 'temporary' === $wpdb->rows['wp_faluss_events_outbox'][0]['last_result_code'], 'An expired eighth outbox lease must terminate without a ninth execution.' );

$wpdb->rows['wp_faluss_events_outbox'][0] = $base_outbox; Faluss_Events_Engine::$mode = 'federation'; Faluss_Federation_Client::$response = array( 'status' => 'not_authorized', 'payload' => array() ); Faluss_Events_Workers::run_outbox();
evt01b2b_assert( 'dead_letter' === $wpdb->rows['wp_faluss_events_outbox'][0]['status'] && 'not_authorized' === $wpdb->rows['wp_faluss_events_outbox'][0]['last_result_code'], 'Remote authorization failure must be classified and terminate without an infinite loop.' );
$wpdb->rows['wp_faluss_events_outbox'][0] = $base_outbox; Faluss_Federation_Client::$response = array( 'status' => 'not_available', 'payload' => array() ); Faluss_Events_Workers::run_outbox();
evt01b2b_assert( 'retry' === $wpdb->rows['wp_faluss_events_outbox'][0]['status'] && 'not_available' === $wpdb->rows['wp_faluss_events_outbox'][0]['last_result_code'], 'Remote unavailable response must use a bounded retry and allowlisted result code.' );

Faluss_Events_Engine::$mode = 'local'; Faluss_Federation_Client::$calls = array(); $wpdb->rows['wp_faluss_events_outbox'][0] = $base_outbox; $wpdb->rows['wp_faluss_events_consumer_deliveries'] = array();
Faluss_Events_Workers::run_outbox();
evt01b2b_assert( 'delivered' === $wpdb->rows['wp_faluss_events_outbox'][0]['status'] && 1 === count( $wpdb->rows['wp_faluss_events_consumer_deliveries'] ) && array() === Faluss_Federation_Client::$calls && array() === $wpdb->rows['wp_faluss_events_inbox'], 'Local route must atomically create missing deliveries and finalize without HTTP or inbox.' );
$before_local = count( $wpdb->rows['wp_faluss_events_consumer_deliveries'] ); $wpdb->rows['wp_faluss_events_outbox'][0] = $base_outbox; Faluss_Events_Workers::run_outbox(); evt01b2b_assert( $before_local === count( $wpdb->rows['wp_faluss_events_consumer_deliveries'] ), 'Local retry must create no duplicate delivery.' );
$consumer_row = $wpdb->rows['wp_faluss_events_consumer_deliveries'][0];
$wpdb->rows['wp_faluss_events_outbox'][0] = $base_outbox; $wpdb->rows['wp_faluss_events_consumer_deliveries'] = array(); $claimed_local = evt01b2b_private( 'Faluss_Events_Workers', 'claim_outbox' ); $wpdb->fail_next_commit = true;
$local_committed = evt01b2b_private( 'Faluss_Events_Workers', 'complete_local_route', array( $claimed_local[0], $event, array( 'mode' => 'local', 'target_node_id' => 'consumer-node', 'target_app_key' => 'consumer-app' ) ) );
evt01b2b_assert( false === $local_committed && 'leased' === $wpdb->rows['wp_faluss_events_outbox'][0]['status'] && array() === $wpdb->rows['wp_faluss_events_consumer_deliveries'], 'Unconfirmed local commit must roll back both delivery creation and outbox finalization.' );

$contexts = array(); $callback_transactions = array(); Faluss_Events_Engine::$callback = function ( $context ) use ( &$contexts, &$callback_transactions ) { global $wpdb; $contexts[] = $context; $callback_transactions[] = $wpdb->in_transaction; return true; };
$consumer_row['status'] = 'pending'; $consumer_row['attempt_count'] = 0; $consumer_row['lease_token'] = null; $consumer_row['lease_expires_at'] = null; $wpdb->rows['wp_faluss_events_consumer_deliveries'][0] = $consumer_row;
Faluss_Events_Workers::run_consumers(); evt01b2b_assert( 'processed' === $wpdb->rows['wp_faluss_events_consumer_deliveries'][0]['status'] && 1 === count( $contexts ) && array( false ) === $callback_transactions && array( 'event', 'delivery_uuid', 'destination', 'consumer_key', 'attempt', 'idempotency_key' ) === array_keys( $contexts[0] ), 'Consumer callback must run once outside a transaction with only the closed context.' );

$contexts = array(); $callback_transactions = array(); $wpdb->rows['wp_faluss_events_consumer_deliveries'][0] = $consumer_row; $wpdb->fail_next_consumer_finish = true; Faluss_Events_Workers::run_consumers();
evt01b2b_assert( 1 === count( $contexts ) && 'leased' === $wpdb->rows['wp_faluss_events_consumer_deliveries'][0]['status'], 'Uncertain database confirmation must leave the leased attempt recoverable without an immediate second callback.' );
$before_expiry_contexts = count( $contexts ); Faluss_Events_Workers::run_consumers(); evt01b2b_assert( $before_expiry_contexts === count( $contexts ), 'An unexpired lease must never execute its callback twice in one attempt.' );
$wpdb->rows['wp_faluss_events_consumer_deliveries'][0]['lease_expires_at'] = gmdate( 'Y-m-d H:i:s', time() - 1 ); Faluss_Events_Workers::run_consumers();
evt01b2b_assert( 2 === count( $contexts ) && 1 === $contexts[0]['attempt'] && 2 === $contexts[1]['attempt'] && $contexts[0]['idempotency_key'] === $contexts[1]['idempotency_key'], 'Recovered callback must use a new attempt number and the same stable idempotency key.' );

Faluss_Events_Engine::$callback = function () { return new WP_Error( 'faluss_events_retryable' ); }; $wpdb->rows['wp_faluss_events_consumer_deliveries'][0] = $consumer_row; Faluss_Events_Workers::run_consumers();
evt01b2b_assert( 'retry' === $wpdb->rows['wp_faluss_events_consumer_deliveries'][0]['status'] && 'retryable' === $wpdb->rows['wp_faluss_events_consumer_deliveries'][0]['last_result_code'] && strtotime( $wpdb->rows['wp_faluss_events_consumer_deliveries'][0]['lease_expires_at'] ) > time(), 'Retryable consumer error must use lease_expires_at as the retry due time.' );
Faluss_Events_Engine::$callback = function () { return new WP_Error( 'faluss_events_permanent' ); }; $wpdb->rows['wp_faluss_events_consumer_deliveries'][0] = $consumer_row; Faluss_Events_Workers::run_consumers();
evt01b2b_assert( 'dead_letter' === $wpdb->rows['wp_faluss_events_consumer_deliveries'][0]['status'] && null === $wpdb->rows['wp_faluss_events_consumer_deliveries'][0]['lease_expires_at'], 'Permanent consumer result must terminate with no due time.' );
$consumer_dead = $consumer_row; $consumer_dead['status'] = 'leased'; $consumer_dead['attempt_count'] = 8; $consumer_dead['lease_token'] = 'lease-eight'; $wpdb->rows['wp_faluss_events_consumer_deliveries'][0] = $consumer_dead; evt01b2b_private( 'Faluss_Events_Workers', 'finish_consumer', array( $consumer_dead, 'retryable' ) );
evt01b2b_assert( 'dead_letter' === $wpdb->rows['wp_faluss_events_consumer_deliveries'][0]['status'], 'Eighth consumer failure must dead-letter.' );
$consumer_dead['lease_expires_at'] = gmdate( 'Y-m-d H:i:s', time() - 1 ); $wpdb->rows['wp_faluss_events_consumer_deliveries'][0] = $consumer_dead;
$exhausted_claim = evt01b2b_private( 'Faluss_Events_Workers', 'claim_consumers' ); evt01b2b_assert( array() === $exhausted_claim && 'dead_letter' === $wpdb->rows['wp_faluss_events_consumer_deliveries'][0]['status'] && 'invalid_result' === $wpdb->rows['wp_faluss_events_consumer_deliveries'][0]['last_result_code'], 'An expired eighth consumer lease must terminate without a ninth callback.' );

$GLOBALS['evt01b2b_cron'] = array(); $GLOBALS['evt01b2b_cleared'] = array(); Faluss_Events_Workers::boot(); Faluss_Events_Workers::schedule(); Faluss_Events_Workers::schedule();
evt01b2b_assert( 2 === count( $GLOBALS['evt01b2b_cron'] ) && isset( $GLOBALS['evt01b2b_cron'][ Faluss_Events_Workers::OUTBOX_HOOK ], $GLOBALS['evt01b2b_cron'][ Faluss_Events_Workers::CONSUMER_HOOK ] ), 'Exactly two unique one-minute cron events must be scheduled idempotently.' );
Faluss_Events_Workers::deactivate(); sort( $GLOBALS['evt01b2b_cleared'] ); $expected_hooks = array( Faluss_Events_Workers::CONSUMER_HOOK, Faluss_Events_Workers::OUTBOX_HOOK ); sort( $expected_hooks ); evt01b2b_assert( $expected_hooks === $GLOBALS['evt01b2b_cleared'], 'Deactivation must clear only both Events hooks.' );

evt01b2b_assert( 600 === evt01b2b_private( 'Faluss_Federation_Server', 'rate_limit', array( 'event.publish' ) ) && 30 === evt01b2b_private( 'Faluss_Federation_Server', 'rate_limit', array( 'diagnostic.read' ) ) && 60 === evt01b2b_private( 'Faluss_Federation_Server', 'rate_limit', array( 'manifest.read' ) ), 'Publish must have explicit 600/minute limit while prior limits stay unchanged.' );
$workers_source = file_get_contents( $root . '/src/Events/Legacy/includes/class-faluss-events-workers.php' ); $server_source = file_get_contents( $root . '/tests/Events/fixtures/Federation/includes/class-faluss-federation-server.php' ); $federation_schema_source = file_get_contents( $root . '/tests/Events/fixtures/Federation/includes/class-faluss-federation-schema.php' );
evt01b2b_assert( false === strpos( $workers_source, 'register_rest_route' ) && false === strpos( $workers_source, 'wp_remote_' ) && false === strpos( $workers_source, 'Faluss_Analytics' ), 'Workers must expose no browser route, alternate HTTP client or business engine.' );
preg_match( '/\$audit = \'event\.publish\'.*?\? array\((.*?)\)\s*: array/s', $server_source, $audit_match );
evt01b2b_assert( isset( $audit_match[1] ) && false === strpos( $audit_match[1], "'request_id' =>" ) && false === strpos( $audit_match[1], "'event_id' =>" ), 'Publish audit branch must omit request ID and event identifiers.' );
evt01b2b_assert( false !== strpos( $federation_schema_source, "\$is_event_publish = 'event.publish' === \$operation" ) && false !== strpos( $federation_schema_source, "\$request_id = ! \$is_event_publish" ) && false !== strpos( $federation_schema_source, "\$opaque = \$is_event_publish ? ''" ), 'Federation audit storage must discard publish request IDs and store no opaque diagnostic detail.' );
$events_schema_source = file_get_contents( $root . '/src/Events/Legacy/includes/class-faluss-events-schema.php' );
evt01b2b_assert( false !== strpos( $events_schema_source, "const VERSION = '2'" ) && false !== strpos( $events_schema_source, 'faluss_events_tombstones' ), 'Events schema 2 must add only the retention receipt table; legacy DDL equality is covered by EVT-01B.2C.' );
$bootstrap_events = file_get_contents( $root . '/src/Events/Legacy/faluss-events.php' ); $bootstrap_fed = file_get_contents( $root . '/tests/Events/fixtures/Federation/faluss-federation.php' );
evt01b2b_assert( false !== strpos( $bootstrap_events, 'Version: 0.3.1' ) && false !== strpos( $bootstrap_events, "FALUSS_EVENTS_SCHEMA_VERSION', '2'" ) && false !== strpos( $bootstrap_fed, 'Version: 0.3.0' ) && false !== strpos( $bootstrap_fed, "FALUSS_FEDERATION_SCHEMA_VERSION', '1'" ), 'Events must be 0.3.1/schema 2 while Federation stays 0.3.0/schema 1.' );
$events_runtime = $bootstrap_events . file_get_contents( $root . '/src/Events/Legacy/includes/class-faluss-events.php' ) . $workers_source;
evt01b2b_assert( false === strpos( $events_runtime, 'Faluss_Events_Engine::register_delivery_route(' ) && false === strpos( $events_runtime, 'Faluss_Events_Engine::register_consumer(' ) && false === strpos( $events_runtime, 'Faluss_Events::register_catalog_provider(' ) && false === strpos( $events_runtime, 'update_peer_policy(' ), 'Loading must register no business route, consumer, catalog/provider or policy mutation.' );
$before_rows = array_map( 'count', $wpdb->rows ); $duplicate_adapter = Faluss_Federation_Providers::register_event_publish_adapter( function () { return true; }, function () { return true; }, function () { return true; } ); Faluss_Events_Workers::run_outbox(); Faluss_Events_Workers::run_consumers();
$duplicate_dispatch = Faluss_Federation_Providers::dispatch( $request, $identity );
evt01b2b_assert( is_wp_error( $duplicate_adapter ) && 'not_available' === Faluss_Federation_Providers::operation_availability()['event.publish'] && 'not_available' === ( $duplicate_dispatch['status'] ?? null ) && ! Faluss_Federation_Providers::validate_event_publish_request( $request ) && $before_rows === array_map( 'count', $wpdb->rows ), 'Duplicate adapter must poison publish unavailable without creating a row.' );

echo 'EVT-01B.2B transport and workers: OK (' . $evt01b2b_assertions . ' assertions; production adapters/workers with controlled wpdb)' . PHP_EOL;
