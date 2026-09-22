<?php

$fed01b7_assertions = 0;

function fed01b7_assert( $condition, $message ) {
    global $fed01b7_assertions;
    $fed01b7_assertions++;
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
        exit( 1 );
    }
}

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

class WP_Error {
    private $code;
    public function __construct( $code ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}

class WP_REST_Response {
    private $data;
    private $status;
    private $headers = array();
    public function __construct( $data, $status = 200 ) { $this->data = $data; $this->status = $status; }
    public function get_data() { return $this->data; }
    public function get_status() { return $this->status; }
    public function get_headers() { return $this->headers; }
    public function header( $name, $value ) { $this->headers[ $name ] = $value; }
}

function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }

final class Faluss_Federation_Crypto {
    public static function format_utc_timestamp( $timestamp ) { return gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ); }
    public static function response_canonical( $response, $status, $raw ) { return 'canonical-response'; }
    public static function sign( $canonical ) { return str_repeat( 's', 86 ); }
}

final class Faluss_Federation_Schema {
    public static $audits = array();
    public static function audit( $data ) { self::$audits[] = $data; return true; }
}

function fed01b7_authenticated_response( $result, $request, $identity ) {
    Faluss_Federation_Schema::$audits = array();
    $method = new ReflectionMethod( 'Faluss_Federation_Server', 'authenticated_response' );
    $method->setAccessible( true );
    return $method->invoke( null, $result, $request, str_repeat( 'a', 64 ), $identity, microtime( true ) );
}

function fed01b7_response_body( $response ) {
    fed01b7_assert( $response instanceof WP_REST_Response, 'Production authenticated_response() must return a REST response.' );
    $data = $response->get_data();
    fed01b7_assert( is_array( $data ) && isset( $data['_faluss_federation_raw_json'] ), 'Authenticated response must expose its pre-serialized JSON marker.' );
    $decoded = json_decode( $data['_faluss_federation_raw_json'], true );
    fed01b7_assert( is_array( $decoded ), 'Authenticated response JSON must decode to an object.' );
    return $decoded;
}

function fed01b7_assert_audit( $status ) {
    fed01b7_assert( 1 === count( Faluss_Federation_Schema::$audits ), 'Exactly one receiver audit must be emitted.' );
    fed01b7_assert( $status === Faluss_Federation_Schema::$audits[0]['result_code'], 'Receiver audit must preserve status ' . $status . '.' );
}

function fed01b7_failure( $status ) {
    return array(
        'status' => $status,
        'payload_contract' => null,
        'payload' => array(),
        'error' => array( 'code' => $status, 'message' => 'Request could not be completed.' ),
    );
}

$source_root = getenv( 'FALUSS_FEDERATION_SOURCE_ROOT' );
$source_root = is_string( $source_root ) && '' !== $source_root ? rtrim( $source_root, '/\\' ) : dirname( __DIR__, 3 );
$providers_path = $source_root . '/src/Federation/Legacy/includes/class-faluss-federation-providers.php';
$server_path = $source_root . '/src/Federation/Legacy/includes/class-faluss-federation-server.php';
fed01b7_assert( is_file( $providers_path ) && is_file( $server_path ), 'Production Providers and Server classes must exist.' );
require_once $providers_path;
require_once $server_path;

$request = array(
    'request_id' => '11111111-1111-4111-8111-111111111111',
    'operation' => 'diagnostic.read',
    'sender' => array( 'node_id' => 'hub-node', 'app_key' => 'hub-app', 'key_id' => 'hub-key-0001' ),
    'recipient' => array( 'node_id' => 'me-node', 'app_key' => 'me-app' ),
    'parameters' => array(),
);
$identity = array( 'node_id' => 'me-node', 'app_key' => 'me-app', 'key_id' => 'me-key-0001' );
$diagnostic = Faluss_Federation_Providers::dispatch( $request, $identity );
fed01b7_assert( is_array( $diagnostic ) && array_key_exists( 'error', $diagnostic ) && null === $diagnostic['error'], 'Production diagnostic provider must return its contractual null error.' );
$diagnostic_response = fed01b7_authenticated_response( $diagnostic, $request, $identity );
$diagnostic_body = fed01b7_response_body( $diagnostic_response );

$expect_base_blocker = in_array( '--expect-base-blocker', $argv, true );
if ( $expect_base_blocker ) {
    fed01b7_assert( 409 === $diagnostic_response->get_status() && 'incompatible' === $diagnostic_body['status'], 'Base 4caba7a must turn the nullable diagnostic success into incompatible.' );
    fed01b7_assert_audit( 'incompatible' );
    $method = new ReflectionMethod( 'Faluss_Federation_Server', 'authenticated_response' );
    $lines = file( $server_path );
    $source = implode( '', array_slice( $lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );
    fed01b7_assert( false !== strpos( $source, "isset( \$result['status'], \$result['payload_contract'], \$result['payload'], \$result['error'] )" ), 'Base blocker must be the four-key isset() check.' );
    echo 'FED-01B.7 base 4caba7a blocker reproduced (' . $fed01b7_assertions . ' assertions)' . PHP_EOL;
    exit( 0 );
}

fed01b7_assert( 'success' === $diagnostic_body['status'] && null === $diagnostic_body['error'], 'Diagnostic success with a contractual null error must remain success.' );
fed01b7_assert( 200 === $diagnostic_response->get_status(), 'Diagnostic success HTTP status must remain 200.' );
fed01b7_assert_audit( 'success' );

$empty = array( 'status' => 'empty', 'payload_contract' => null, 'payload' => array(), 'error' => null );
$empty_response = fed01b7_authenticated_response( $empty, $request, $identity );
$empty_body = fed01b7_response_body( $empty_response );
fed01b7_assert( 'empty' === $empty_body['status'] && null === $empty_body['payload_contract'] && null === $empty_body['error'], 'Empty result must preserve both contractual null values.' );
fed01b7_assert( 200 === $empty_response->get_status(), 'Empty HTTP status must remain 200.' );
fed01b7_assert_audit( 'empty' );

$failure_http = array( 'not_available' => 404, 'not_authorized' => 403, 'incompatible' => 409, 'temporarily_unavailable' => 503, 'invalid_request' => 400, 'replay_rejected' => 409 );
foreach ( $failure_http as $status => $http_status ) {
    $response = fed01b7_authenticated_response( fed01b7_failure( $status ), $request, $identity );
    $body = fed01b7_response_body( $response );
    fed01b7_assert( $status === $body['status'] && null === $body['payload_contract'], 'Failure must preserve status and null payload contract: ' . $status );
    fed01b7_assert( $http_status === $response->get_status(), 'Failure must preserve HTTP mapping: ' . $status );
    fed01b7_assert_audit( $status );
}

foreach ( array( 'status', 'payload_contract', 'payload', 'error' ) as $missing_key ) {
    $missing = $empty;
    unset( $missing[ $missing_key ] );
    $body = fed01b7_response_body( fed01b7_authenticated_response( $missing, $request, $identity ) );
    fed01b7_assert( 'incompatible' === $body['status'], 'Missing provider key must be incompatible: ' . $missing_key );
    fed01b7_assert_audit( 'incompatible' );
}

$extra = $empty;
$extra['unexpected'] = true;
$extra_body = fed01b7_response_body( fed01b7_authenticated_response( $extra, $request, $identity ) );
fed01b7_assert( 'incompatible' === $extra_body['status'], 'An additional provider key must be incompatible.' );
fed01b7_assert_audit( 'incompatible' );

$invalid = $empty;
$invalid['payload'] = array( 'unexpected' => true );
$invalid_body = fed01b7_response_body( fed01b7_authenticated_response( $invalid, $request, $identity ) );
fed01b7_assert( 'incompatible' === $invalid_body['status'], 'An invalid provider value combination must be incompatible.' );
fed01b7_assert_audit( 'incompatible' );

$method = new ReflectionMethod( 'Faluss_Federation_Server', 'authenticated_response' );
$lines = file( $server_path );
$source = implode( '', array_slice( $lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );
fed01b7_assert( false === strpos( $source, "isset( \$result['status'], \$result['payload_contract'], \$result['payload'], \$result['error'] )" ), 'authenticated_response() must not use isset() for nullable provider keys.' );
fed01b7_assert( false !== strpos( $source, 'self::has_keys( $result, $keys )' ) && false !== strpos( $source, 'self::only_keys( $result, $keys )' ), 'authenticated_response() must require the exact provider key set.' );

echo 'FED-01B.7 nullable provider result regression: OK (' . $fed01b7_assertions . ' assertions; production Providers + Server authenticated_response)' . PHP_EOL;
