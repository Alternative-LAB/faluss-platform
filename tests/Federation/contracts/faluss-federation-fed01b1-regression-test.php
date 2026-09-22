<?php

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $code;
        public function __construct( $code ) { $this->code = $code; }
        public function get_error_code() { return $this->code; }
    }
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $value ) { return $value instanceof WP_Error; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $value ) { return parse_url( $value ); } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); } }
if ( ! function_exists( 'wp_generate_uuid4' ) ) { function wp_generate_uuid4() { return '11111111-1111-4111-8111-111111111111'; } }
$fed01b1_get_option_calls = 0;
if ( ! function_exists( 'get_option' ) ) { function get_option( $name, $default = false ) { global $fed01b1_get_option_calls; $fed01b1_get_option_calls++; return $default; } }

$fed01b1_http_capture = null;
if ( ! function_exists( 'wp_remote_post' ) ) {
    function wp_remote_post( $url, $args ) {
        global $fed01b1_http_capture;
        $fed01b1_http_capture = array( 'url' => $url, 'args' => $args );
        return array( 'mock' => true );
    }
}

$fed01b1_assertions = 0;
function fed01b1_assert( $condition, $message ) {
    global $fed01b1_assertions;
    $fed01b1_assertions++;
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
        exit( 1 );
    }
}

function fed01b1_private( $class, $method, $arguments ) {
    $reflection = new ReflectionMethod( $class, $method );
    $reflection->setAccessible( true );
    return $reflection->invokeArgs( null, $arguments );
}

function fed01b1_error_code( $result ) {
    return is_wp_error( $result ) ? $result->get_error_code() : null;
}

$root = getenv( 'FALUSS_FEDERATION_SOURCE_ROOT' );
$root = is_string( $root ) && '' !== $root ? rtrim( $root, '/\\' ) : dirname( __DIR__, 3 );
$includes = $root . '/src/Federation/Legacy/includes/';
require_once $includes . 'class-faluss-federation-crypto.php';

fed01b1_assert( method_exists( 'Faluss_Federation_Crypto', 'format_utc_timestamp' ), 'FED-01B.1 blocker: production UTC formatter is missing.' );
fed01b1_assert( method_exists( 'Faluss_Federation_Crypto', 'parse_utc_timestamp' ), 'FED-01B.1 blocker: production strict UTC parser is missing.' );

require_once $includes . 'class-faluss-federation-schema.php';
require_once $includes . 'class-faluss-federation-policy.php';
if ( ! class_exists( 'Faluss_Federation_Providers' ) ) {
    final class Faluss_Federation_Providers {
        public static $dispatches = 0;
        public static function dispatch( $message, $identity ) { self::$dispatches++; return array( 'status' => 'empty', 'payload_contract' => null, 'payload' => array(), 'error' => null ); }
        public static function validate_received_payload() { return true; }
    }
}
require_once $includes . 'class-faluss-federation-client.php';
require_once $includes . 'class-faluss-federation-server.php';

foreach ( array(
    array( 'Faluss_Federation_Schema', 'consume_transaction', 'atomic consumption core' ),
    array( 'Faluss_Federation_Policy', 'create_peer_transaction', 'atomic peer creation core' ),
    array( 'Faluss_Federation_Policy', 'revoke_peer_transaction', 'atomic peer revocation core' ),
    array( 'Faluss_Federation_Client', 'build_request', 'production request builder' ),
    array( 'Faluss_Federation_Client', 'send_raw', 'production HTTP boundary' ),
    array( 'Faluss_Federation_Server', 'build_response_envelope', 'production response builder' ),
    array( 'Faluss_Federation_Server', 'dispatch_after_consumption', 'production dispatch gate' ),
) as $method ) {
    fed01b1_assert( method_exists( $method[0], $method[1] ), 'FED-01B.1 blocker: missing ' . $method[2] . '.' );
}

/* Exact timestamp grammar, production builders and cross-side validation. */
$now = time();
$identity = array( 'node_id' => 'hub-node', 'app_key' => 'hub-app', 'key_id' => 'hub-key-0001' );
$peer = array( 'peer_node_id' => 'me-node', 'peer_app_key' => 'me-app', 'canonical_origin' => 'https://peer.example' );
$nonce = Faluss_Federation_Crypto::base64url_encode( str_repeat( 'n', 32 ) );
$request = fed01b1_private( 'Faluss_Federation_Client', 'build_request', array( $identity, $peer, 'diagnostic.read', array(), null, $nonce, $now ) );
fed01b1_assert( is_array( $request ), 'Production client must build a request.' );
fed01b1_assert( gmdate( 'Y-m-d\TH:i:s\Z', $now ) === $request['issued_at'] && gmdate( 'Y-m-d\TH:i:s\Z', $now + 300 ) === $request['expires_at'], 'Client dates must use the exact shared UTC grammar.' );
fed01b1_assert( true === fed01b1_private( 'Faluss_Federation_Server', 'valid_request', array( $request ) ), 'Server must accept a request produced by the client.' );
fed01b1_assert( true === fed01b1_private( 'Faluss_Federation_Server', 'request_is_fresh', array( $request ) ), 'Server must accept the client freshness window.' );

$empty_result = array( 'status' => 'empty', 'payload_contract' => null, 'payload' => array(), 'error' => null );
$response = fed01b1_private( 'Faluss_Federation_Server', 'build_response_envelope', array( $empty_result, $request, str_repeat( 'a', 64 ), $identity, $now ) );
fed01b1_assert( is_array( $response ), 'Production server must build a response.' );
fed01b1_assert( gmdate( 'Y-m-d\TH:i:s\Z', $now ) === $response['generated_at'] && gmdate( 'Y-m-d\TH:i:s\Z', $now + 300 ) === $response['expires_at'], 'Server dates must use the exact shared UTC grammar.' );
fed01b1_assert( true === fed01b1_private( 'Faluss_Federation_Client', 'valid_response_shape', array( $response ) ), 'Client must accept a response produced by the server.' );
fed01b1_assert( true === fed01b1_private( 'Faluss_Federation_Client', 'response_is_fresh', array( $response, $request ) ), 'Client must accept the server freshness window.' );

foreach ( array(
    '2026-09-13T10:00:00+00:00',
    '2026-02-30T10:00:00Z',
    '2026-09-13T10:00Z',
    '2026-09-13T10:00:00.1Z',
    '2026-9-13T10:00:00Z',
    'tomorrow',
) as $invalid ) {
    fed01b1_assert( is_wp_error( Faluss_Federation_Crypto::parse_utc_timestamp( $invalid ) ), 'Strict parser must reject: ' . $invalid );
    $invalid_request = $request;
    $invalid_request['issued_at'] = $invalid;
    fed01b1_assert( false === fed01b1_private( 'Faluss_Federation_Server', 'valid_request', array( $invalid_request ) ), 'Server must reject a noncanonical request date: ' . $invalid );
    $invalid_response = $response;
    $invalid_response['generated_at'] = $invalid;
    fed01b1_assert( false === fed01b1_private( 'Faluss_Federation_Client', 'valid_response_shape', array( $invalid_response ) ), 'Client must reject a noncanonical response date: ' . $invalid );
}
fed01b1_assert( false !== strtotime( 'tomorrow' ) && is_wp_error( Faluss_Federation_Crypto::parse_utc_timestamp( 'tomorrow' ) ), 'Strict parser must reject text that strtotime would normalize.' );

$too_long = $request;
$too_long['expires_at'] = Faluss_Federation_Crypto::format_utc_timestamp( $now + 301 );
fed01b1_assert( false === fed01b1_private( 'Faluss_Federation_Server', 'request_is_fresh', array( $too_long ) ), 'Request lifetime must remain at most 300 seconds.' );
$future = $request;
$future['issued_at'] = Faluss_Federation_Crypto::format_utc_timestamp( $now + 61 );
$future['expires_at'] = Faluss_Federation_Crypto::format_utc_timestamp( $now + 361 );
fed01b1_assert( false === fed01b1_private( 'Faluss_Federation_Server', 'request_is_fresh', array( $future ) ), 'Request clock skew must remain at most 60 seconds.' );

/* Actual WordPress HTTP boundary arguments. */
fed01b1_private( 'Faluss_Federation_Client', 'send_raw', array( 'https://peer.example', $identity, '{}', str_repeat( 's', 86 ) ) );
fed01b1_assert( 'https://peer.example/wp-json/faluss-federation/v1/exchange' === $fed01b1_http_capture['url'], 'HTTP boundary must use the exact Federation endpoint.' );
$http_args = $fed01b1_http_capture['args'];
fed01b1_assert( 3 === $http_args['timeout'] && 0 === $http_args['redirection'] && true === $http_args['sslverify'] && 65536 === $http_args['limit_response_size'], 'HTTP boundary must pass the four required supported values.' );
fed01b1_assert( ! array_key_exists( 'connect_timeout', $http_args ), 'HTTP boundary must not pass unsupported connect_timeout.' );

/** Minimal wpdb fault injector used only around production private transaction cores. */
class FED01B1_WPDB {
    public $prefix = 'wp_';
    public $last_error = '';
    public $scenario;
    public $queries = array();
    public $insert_count = 0;
    public $update_count = 0;
    public $audit_inserts = 0;
    private $binding_reads = 0;
    private $nonce_reads = 0;
    private $collision = '';

    public function __construct( $scenario ) { $this->scenario = $scenario; }
    public function prepare( $query ) { return $query; }
    private function fail( $message ) { $this->last_error = $message; return false; }
    private function okay() { $this->last_error = ''; }

    public function query( $query ) {
        $this->queries[] = $query;
        $this->okay();
        if ( 'START TRANSACTION' === $query && 'start_fail' === $this->scenario ) { return $this->fail( 'start failed' ); }
        if ( 'COMMIT' === $query && in_array( $this->scenario, array( 'commit_fail', 'admin_create_commit_fail', 'admin_revoke_commit_fail' ), true ) ) { return $this->fail( 'commit failed' ); }
        return 1;
    }

    public function get_var( $query ) {
        $this->okay();
        if ( false !== strpos( $query, 'GET_LOCK' ) || false !== strpos( $query, 'RELEASE_LOCK' ) ) {
            return '1';
        }
        if ( false !== strpos( $query, 'request_bindings' ) ) {
            $this->binding_reads++;
            if ( 'binding_read_fail' === $this->scenario && 1 === $this->binding_reads ) { return $this->fail( 'binding read failed' ); }
            return 'existing_binding' === $this->scenario || ( 'binding' === $this->collision && $this->binding_reads > 1 ) ? str_repeat( 'a', 64 ) : null;
        }
        if ( false !== strpos( $query, 'SELECT COUNT(*)' ) ) {
            if ( 'rate_read_fail' === $this->scenario ) { return $this->fail( 'rate read failed' ); }
            return 'rate_limited' === $this->scenario ? '30' : '0';
        }
        if ( false !== strpos( $query, 'faluss_federation_nonces' ) ) {
            $this->nonce_reads++;
            if ( 'nonce_read_fail' === $this->scenario && 1 === $this->nonce_reads ) { return $this->fail( 'nonce read failed' ); }
            return 'existing_nonce' === $this->scenario || ( 'nonce' === $this->collision && $this->nonce_reads > 1 ) ? '1' : null;
        }
        if ( false !== strpos( $query, 'SELECT key_state FROM' ) ) {
            if ( 'admin_revoke_read_fail' === $this->scenario ) { return $this->fail( 'peer read failed' ); }
            return 'active';
        }
        return null;
    }

    public function get_results( $query ) {
        $this->okay();
        if ( 'admin_create_read_fail' === $this->scenario ) { $this->fail( 'peer list failed' ); return null; }
        return array();
    }

    public function insert( $table ) {
        $this->okay();
        if ( false !== strpos( $table, 'faluss_federation_audit' ) ) { $this->audit_inserts++; return 1; }
        $this->insert_count++;
        if ( 1 === $this->insert_count && in_array( $this->scenario, array( 'insert1_fail', 'insert1_collision' ), true ) ) {
            if ( 'insert1_collision' === $this->scenario ) { $this->collision = 'binding'; }
            return $this->fail( 'binding insert failed' );
        }
        if ( 2 === $this->insert_count && in_array( $this->scenario, array( 'insert2_fail', 'insert2_collision' ), true ) ) {
            if ( 'insert2_collision' === $this->scenario ) { $this->collision = 'nonce'; }
            return $this->fail( 'nonce insert failed' );
        }
        return 1;
    }

    public function update() {
        $this->okay();
        $this->update_count++;
        return 1;
    }
}

function fed01b1_request() {
    return array(
        'sender' => array( 'node_id' => 'hub-node', 'key_id' => 'hub-key-0001' ),
        'request_id' => '11111111-1111-4111-8111-111111111111',
        'nonce' => Faluss_Federation_Crypto::base64url_encode( str_repeat( 'n', 32 ) ),
        'operation' => 'diagnostic.read',
    );
}

function fed01b1_consume( $scenario, $limit = 30 ) {
    global $wpdb;
    $wpdb = new FED01B1_WPDB( $scenario );
    $result = fed01b1_private( 'Faluss_Federation_Schema', 'consume_transaction', array( fed01b1_request(), str_repeat( 'b', 64 ), $limit ) );
    return array( $result, $wpdb );
}

/* Every uncertain database state fails closed; known duplicates alone are replay. */
foreach ( array( 'start_fail', 'binding_read_fail', 'nonce_read_fail', 'rate_read_fail', 'insert1_fail', 'insert2_fail', 'commit_fail' ) as $scenario ) {
    list( $result, $db ) = fed01b1_consume( $scenario );
    fed01b1_assert( 'faluss_federation_fail_closed' === fed01b1_error_code( $result ), 'Database fault must fail closed: ' . $scenario );
    Faluss_Federation_Providers::$dispatches = 0;
    fed01b1_private( 'Faluss_Federation_Server', 'dispatch_after_consumption', array( $result, fed01b1_request(), array() ) );
    fed01b1_assert( 0 === Faluss_Federation_Providers::$dispatches, 'Database fault must never dispatch: ' . $scenario );
    fed01b1_assert( 0 === count( array_filter( $db->queries, function ( $query ) { return 'COMMIT' === $query; } ) ) || 'commit_fail' === $scenario, 'No pre-commit fault may report a commit: ' . $scenario );
    if ( 'start_fail' === $scenario ) {
        fed01b1_assert( 0 === $db->insert_count, 'START TRANSACTION failure must perform no insert.' );
    }
    if ( in_array( $scenario, array( 'insert1_fail', 'insert2_fail' ), true ) ) {
        fed01b1_assert( in_array( 'ROLLBACK', $db->queries, true ), 'Insert failure must attempt rollback: ' . $scenario );
    }
}
foreach ( array( 'existing_binding', 'existing_nonce', 'insert1_collision', 'insert2_collision' ) as $scenario ) {
    list( $result, $db ) = fed01b1_consume( $scenario );
    fed01b1_assert( 'faluss_federation_replay_rejected' === fed01b1_error_code( $result ), 'Confirmed duplicate must map to replay: ' . $scenario );
    fed01b1_assert( in_array( 'ROLLBACK', $db->queries, true ), 'Confirmed replay must roll back before returning: ' . $scenario );
    Faluss_Federation_Providers::$dispatches = 0;
    fed01b1_private( 'Faluss_Federation_Server', 'dispatch_after_consumption', array( $result, fed01b1_request(), array() ) );
    fed01b1_assert( 0 === Faluss_Federation_Providers::$dispatches, 'Confirmed replay must never dispatch: ' . $scenario );
}

list( $limited, $limited_db ) = fed01b1_consume( 'rate_limited' );
fed01b1_assert( 'faluss_federation_rate_limited' === fed01b1_error_code( $limited ), 'Rate-limited request must return the dedicated error.' );
fed01b1_assert( 2 === $limited_db->insert_count && in_array( 'COMMIT', $limited_db->queries, true ), 'Rate-limited request must consume binding and nonce before a confirmed commit.' );
Faluss_Federation_Providers::$dispatches = 0;
fed01b1_private( 'Faluss_Federation_Server', 'dispatch_after_consumption', array( $limited, fed01b1_request(), array() ) );
fed01b1_assert( 0 === Faluss_Federation_Providers::$dispatches, 'Rate-limited request must not dispatch.' );
list( $accepted, $accepted_db ) = fed01b1_consume( 'success' );
fed01b1_assert( true === $accepted && 2 === $accepted_db->insert_count && in_array( 'COMMIT', $accepted_db->queries, true ), 'Provider eligibility exists only after both inserts and a confirmed commit.' );
Faluss_Federation_Providers::$dispatches = 0;
fed01b1_private( 'Faluss_Federation_Server', 'dispatch_after_consumption', array( $accepted, fed01b1_request(), array() ) );
fed01b1_assert( 1 === Faluss_Federation_Providers::$dispatches, 'Confirmed commit must permit exactly one dispatch.' );
Faluss_Federation_Providers::$dispatches = 0;
$uncertain_gate = fed01b1_private( 'Faluss_Federation_Server', 'dispatch_after_consumption', array( false, fed01b1_request(), array() ) );
fed01b1_assert( 'faluss_federation_fail_closed' === fed01b1_error_code( $uncertain_gate ) && 0 === Faluss_Federation_Providers::$dispatches, 'Any non-true consumption result must fail closed without dispatch.' );

fed01b1_assert( 'replay_rejected' === fed01b1_private( 'Faluss_Federation_Server', 'consume_failure_status', array( new WP_Error( 'faluss_federation_replay_rejected' ) ) ), 'Server must map only confirmed replay to replay_rejected.' );
foreach ( array( 'faluss_federation_rate_limited', 'faluss_federation_fail_closed', 'faluss_federation_invalid_request' ) as $code ) {
    fed01b1_assert( 'temporarily_unavailable' === fed01b1_private( 'Faluss_Federation_Server', 'consume_failure_status', array( new WP_Error( $code ) ) ), 'Server must hide database or uncertain state as temporary unavailability: ' . $code );
}

/* Admin mutations: commit faults never become success and never reach audit. */
$peer_input = array(
    'peer_node_id' => 'me-node', 'peer_app_key' => 'me-app', 'canonical_origin' => 'https://peer.example',
    'key_id' => 'peer-key-0001', 'public_key' => str_repeat( 'p', 43 ), 'key_state' => 'active',
    'valid_from' => '2026-01-01 00:00:00', 'valid_until' => '2027-01-01 00:00:00',
    'operations' => array( 'diagnostic.read' ), 'owner_apps' => array(), 'capabilities' => array(), 'audiences' => array(),
);
global $wpdb;
$fed01b1_get_option_calls = 0;
$wpdb = new FED01B1_WPDB( 'admin_create_commit_fail' );
$created = fed01b1_private( 'Faluss_Federation_Policy', 'create_peer_transaction', array( $peer_input ) );
fed01b1_assert( 'faluss_federation_fail_closed' === fed01b1_error_code( $created ) && 0 === $wpdb->audit_inserts && 0 === $fed01b1_get_option_calls, 'Peer creation commit fault must fail closed before any audit attempt.' );
$fed01b1_get_option_calls = 0;
$wpdb = new FED01B1_WPDB( 'admin_revoke_commit_fail' );
$revoked = fed01b1_private( 'Faluss_Federation_Policy', 'revoke_peer_transaction', array( 7 ) );
fed01b1_assert( 'faluss_federation_fail_closed' === fed01b1_error_code( $revoked ) && 0 === $wpdb->audit_inserts && 0 === $fed01b1_get_option_calls, 'Peer revocation commit fault must fail closed before any audit attempt.' );

$wpdb = new FED01B1_WPDB( 'admin_create_read_fail' );
fed01b1_assert( 'faluss_federation_fail_closed' === fed01b1_error_code( fed01b1_private( 'Faluss_Federation_Policy', 'create_peer_transaction', array( $peer_input ) ) ), 'Peer creation read error must differ from an empty peer list.' );
$wpdb = new FED01B1_WPDB( 'admin_revoke_read_fail' );
fed01b1_assert( 'faluss_federation_fail_closed' === fed01b1_error_code( fed01b1_private( 'Faluss_Federation_Policy', 'revoke_peer_transaction', array( 7 ) ) ), 'Peer revocation read error must differ from an unknown peer.' );
$wpdb = new FED01B1_WPDB( 'start_fail' );
fed01b1_assert( 'faluss_federation_fail_closed' === fed01b1_error_code( fed01b1_private( 'Faluss_Federation_Policy', 'create_peer_transaction', array( $peer_input ) ) ), 'Peer creation must check START TRANSACTION.' );
$wpdb = new FED01B1_WPDB( 'start_fail' );
fed01b1_assert( 'faluss_federation_fail_closed' === fed01b1_error_code( fed01b1_private( 'Faluss_Federation_Policy', 'revoke_peer_transaction', array( 7 ) ) ), 'Peer revocation must check START TRANSACTION.' );

echo 'FED-01B.1 Federation regression: OK (' . $fed01b1_assertions . ' assertions; production classes + injected wpdb/http boundaries)' . PHP_EOL;
