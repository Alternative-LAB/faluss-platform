<?php

$fed01b5_assertions = 0;

function fed01b5_assert( $condition, $message ) {
    global $fed01b5_assertions;
    $fed01b5_assertions++;
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
        exit( 1 );
    }
}

$fed01b5_mode = isset( $argv[1] ) ? $argv[1] : 'enabled';
if ( 'enabled' === $fed01b5_mode ) {
    foreach ( array( 'disabled-false', 'disabled-integer' ) as $child_mode ) {
        $output = array();
        $status = 0;
        exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $child_mode ), $output, $status );
        fed01b5_assert( 0 === $status, 'Trace-disabled child must pass: ' . $child_mode );
    }
    define( 'FALUSS_FEDERATION_DIAGNOSTIC_TRACE', true );
} else {
    define( 'FALUSS_FEDERATION_DIAGNOSTIC_TRACE', 'disabled-integer' === $fed01b5_mode ? 1 : false );
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
function wp_generate_uuid4() { return '11111111-1111-4111-8111-111111111111'; }
function is_ssl() { return true; }
function add_action( $hook, $callback ) {}
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}

$GLOBALS['fed01b5_json_mode'] = 'normal';
function wp_json_encode( $value, $flags = 0 ) {
    return 'failure' === $GLOBALS['fed01b5_json_mode'] ? false : json_encode( $value, $flags );
}

$GLOBALS['fed01b5_http_mode'] = 'error';
$GLOBALS['fed01b5_http_response'] = array();
function wp_remote_post( $url, $args ) {
    return 'error' === $GLOBALS['fed01b5_http_mode'] ? new WP_Error( 'sensitive-network-detail' ) : $GLOBALS['fed01b5_http_response'];
}
function wp_remote_retrieve_response_code( $response ) { return is_array( $response ) ? ( $response['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $response ) { return is_array( $response ) ? ( $response['body'] ?? '' ) : ''; }
function wp_remote_retrieve_headers( $response ) { return is_array( $response ) ? ( $response['headers'] ?? array() ) : array(); }

final class Faluss_Federation_Crypto {
    const PATH = '/wp-json/faluss-federation/v1/exchange';
    public static $transport_ready = true;
    public static $identity_error = false;
    public static $canonical_error = false;
    public static $sign_error = false;
    public static $verify = true;
    public static function transport_ready() { return self::$transport_ready; }
    public static function local_identity() { return self::$identity_error ? new WP_Error( 'identity-secret' ) : array( 'node_id' => 'local-node', 'app_key' => 'local-app', 'key_id' => 'local-key-0001' ); }
    public static function is_canonical_origin( $origin ) { return is_string( $origin ) && 0 === strpos( $origin, 'https://' ); }
    public static function base64url_encode( $value ) { return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); }
    public static function format_utc_timestamp( $timestamp ) { return gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ); }
    public static function request_canonical( $request, $raw ) { return self::$canonical_error ? new WP_Error( 'canonical-secret' ) : 'canonical-request'; }
    public static function response_canonical( $response, $status, $raw ) { return self::$canonical_error ? new WP_Error( 'canonical-secret' ) : 'canonical-response'; }
    public static function sign( $canonical ) { return self::$sign_error ? new WP_Error( 'sign-secret' ) : str_repeat( 's', 86 ); }
    public static function verify( $canonical, $signature, $public_key ) { return self::$verify; }
    public static function is_uuid( $value ) { return is_string( $value ) && 36 === strlen( $value ); }
    public static function is_node( $value ) { return is_string( $value ) && '' !== $value; }
    public static function is_key_id( $value ) { return is_string( $value ) && strlen( $value ) >= 8; }
    public static function is_sha256( $value ) { return is_string( $value ) && 64 === strlen( $value ); }
    public static function is_signature( $value ) { return is_string( $value ) && 86 === strlen( $value ); }
    public static function is_utc_timestamp( $value ) { return is_string( $value ) && false !== strtotime( $value ); }
    public static function parse_utc_timestamp( $value ) { $parsed = strtotime( $value ); return false === $parsed ? new WP_Error( 'date-secret' ) : $parsed; }
}

final class Faluss_Federation_Policy {
    public static $peer_error = false;
    public static $peer_operations = array( 'diagnostic.read' );
    public static function operations() { return array( 'diagnostic.read', 'manifest.read', 'read_model.read' ); }
    public static function audiences() { return array( 'private', 'members', 'public' ); }
    public static function find_outbound_peer( $node, $app ) { return self::$peer_error ? new WP_Error( 'peer-secret' ) : array( 'peer_node_id' => 'remote-node', 'peer_app_key' => 'remote-app', 'canonical_origin' => 'https://sensitive.example', 'operations' => self::$peer_operations ); }
    public static function find_peer( $node, $app, $key ) { return self::$peer_error ? new WP_Error( 'peer-secret' ) : array( 'public_key' => 'sensitive-public-key' ); }
}

final class Faluss_Federation_Providers {
    public static $payload_valid = true;
    public static function validate_received_payload() { return self::$payload_valid; }
}

function fed01b5_private( $class, $method, $arguments = array() ) {
    $reflection = new ReflectionMethod( $class, $method );
    $reflection->setAccessible( true );
    return $reflection->invokeArgs( null, $arguments );
}

function fed01b5_method_source( $class, $method, $path ) {
    $reflection = new ReflectionMethod( $class, $method );
    $lines = file( $path );
    return implode( '', array_slice( $lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1 ) );
}

function fed01b5_log_lines() {
    global $fed01b5_log_path;
    clearstatcache( true, $fed01b5_log_path );
    $lines = is_file( $fed01b5_log_path ) ? file( $fed01b5_log_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) : array();
    return array_map( static function ( $line ) { return preg_replace( '/^\[[^]]+\]\s+/', '', $line ); }, $lines );
}

function fed01b5_clear_log() {
    global $fed01b5_log_path;
    file_put_contents( $fed01b5_log_path, '' );
}

$fed01b5_log_path = tempnam( sys_get_temp_dir(), 'fed01b5-' );
fed01b5_assert( is_string( $fed01b5_log_path ), 'Temporary trace log must be available.' );
ini_set( 'log_errors', '1' );
ini_set( 'error_log', $fed01b5_log_path );

$root = dirname( __DIR__, 3 );
$server_path = $root . '/src/Federation/Legacy/includes/class-faluss-federation-server.php';
$client_path = $root . '/src/Federation/Legacy/includes/class-faluss-federation-client.php';
require_once $server_path;
require_once $client_path;

fed01b5_assert( method_exists( 'Faluss_Federation_Server', 'trace' ), 'Base 61b9d2c blocker: server trace is missing.' );
fed01b5_assert( method_exists( 'Faluss_Federation_Client', 'trace' ), 'Base 61b9d2c blocker: client trace is missing.' );

if ( 'enabled' !== $fed01b5_mode ) {
    fed01b5_private( 'Faluss_Federation_Server', 'trace', array( 'server_headers' ) );
    fed01b5_private( 'Faluss_Federation_Client', 'trace', array( 'client_http_4xx' ) );
    fed01b5_assert( array() === fed01b5_log_lines(), 'Trace must be absent unless the activation constant is strictly true.' );
    unlink( $fed01b5_log_path );
    echo 'FED-01B.5 trace disabled: OK' . PHP_EOL;
    exit( 0 );
}

$server_stages = array( 'server_request_type', 'server_method', 'server_ssl', 'server_query', 'server_content_type', 'server_content_encoding', 'server_body', 'server_headers', 'server_body_hash', 'server_json', 'server_shape', 'server_identity', 'server_key_binding', 'server_peer', 'server_canonical', 'server_signature', 'server_freshness' );
$client_stages = array( 'client_transport_unavailable', 'client_operation', 'client_identity', 'client_peer', 'client_peer_operation', 'client_origin', 'client_nonce', 'client_envelope', 'client_serialization', 'client_canonical', 'client_signature', 'client_http_error', 'client_http_0', 'client_http_2xx', 'client_http_3xx', 'client_http_4xx', 'client_http_5xx', 'client_http_other', 'client_response_http_status', 'client_response_body', 'client_response_headers', 'client_response_hash', 'client_response_json', 'client_response_shape', 'client_response_binding', 'client_response_freshness', 'client_response_status', 'client_response_key', 'client_response_canonical', 'client_response_signature', 'client_response_payload' );

$server_reflection = new ReflectionClass( 'Faluss_Federation_Server' );
$client_reflection = new ReflectionClass( 'Faluss_Federation_Client' );
fed01b5_assert( $server_stages === $server_reflection->getConstant( 'TRACE_STAGES' ), 'Server trace allowlist must be exact.' );
fed01b5_assert( $client_stages === $client_reflection->getConstant( 'TRACE_STAGES' ), 'Client trace allowlist must be exact.' );

foreach ( $server_stages as $stage ) {
    $response = fed01b5_private( 'Faluss_Federation_Server', 'pre_auth_reject', array( $stage ) );
    fed01b5_assert( $response instanceof WP_REST_Response, 'Server rejection must remain a REST response for ' . $stage );
    fed01b5_assert( 400 === $response->get_status(), 'Server rejection status must stay 400 for ' . $stage );
    fed01b5_assert( '{"code":"invalid_request","message":"Request rejected."}' === wp_json_encode( $response->get_data() ), 'Server rejection bytes must stay identical for ' . $stage );
}
foreach ( $client_stages as $stage ) {
    fed01b5_private( 'Faluss_Federation_Client', 'trace', array( $stage ) );
}
fed01b5_private( 'Faluss_Federation_Server', 'trace', array( 'server_headers request_id=sensitive-request-id' ) );
fed01b5_private( 'Faluss_Federation_Client', 'trace', array( 'client_http_4xx https://sensitive.example' ) );

$lines = fed01b5_log_lines();
fed01b5_assert( count( $server_stages ) + count( $client_stages ) === count( $lines ), 'Only allowlisted stages may be logged.' );
foreach ( $lines as $line ) {
    fed01b5_assert( 1 === preg_match( '/^\[Faluss Federation trace\] side=(server|client) stage=[a-z0-9_]+$/D', $line ), 'Every trace line must use the fixed format.' );
}
$log = implode( "\n", $lines );
foreach ( array( 'sensitive.example', 'sensitive-request-id', 'sensitive-public-key', 'sensitive-network-detail', 'local-node', 'remote-node', 'local-key-0001', 'signature:', 'nonce:', 'body:', 'payload:' ) as $forbidden ) {
    fed01b5_assert( false === strpos( $log, $forbidden ), 'Trace must not expose ' . $forbidden );
}

$handle_source = fed01b5_method_source( 'Faluss_Federation_Server', 'handle', $server_path );
$position = -1;
foreach ( $server_stages as $stage ) {
    $needle = "pre_auth_reject( '" . $stage . "' )";
    $next = strpos( $handle_source, $needle );
    fed01b5_assert( false !== $next && $next > $position, 'Server rejection stage must appear once and in guard order: ' . $stage );
    fed01b5_assert( 1 === substr_count( $handle_source, $needle ), 'Server rejection stage must be distinct: ' . $stage );
    $position = $next;
}

$call_source = fed01b5_method_source( 'Faluss_Federation_Client', 'call', $client_path );
foreach ( array( 'client_transport_unavailable', 'client_operation', 'client_identity', 'client_peer', 'client_peer_operation', 'client_origin', 'client_nonce', 'client_envelope', 'client_serialization', 'client_canonical', 'client_signature', 'client_http_error' ) as $stage ) {
    fed01b5_assert( false !== strpos( $call_source, "'" . $stage . "'" ), 'Client call() must distinguish ' . $stage );
}
$validate_source = fed01b5_method_source( 'Faluss_Federation_Client', 'validate_response', $client_path );
foreach ( array( 'client_response_http_status', 'client_response_body', 'client_response_headers', 'client_response_hash', 'client_response_json', 'client_response_shape', 'client_response_binding', 'client_response_freshness', 'client_response_status', 'client_response_key', 'client_response_canonical', 'client_response_signature', 'client_response_payload' ) as $stage ) {
    fed01b5_assert( false !== strpos( $validate_source, "'" . $stage . "'" ), 'Client response validation must distinguish ' . $stage );
}

fed01b5_clear_log();
Faluss_Federation_Crypto::$transport_ready = false;
$preflight = Faluss_Federation_Client::diagnostic_read( 'remote-node', 'remote-app' );
fed01b5_assert( is_wp_error( $preflight ) && 'faluss_federation_fail_closed' === $preflight->get_error_code(), 'Preflight refusal must keep its 0.1.4 result.' );
fed01b5_assert( array( '[Faluss Federation trace] side=client stage=client_transport_unavailable' ) === fed01b5_log_lines(), 'Preflight refusal must have its fixed stage.' );

fed01b5_clear_log();
Faluss_Federation_Crypto::$transport_ready = true;
$GLOBALS['fed01b5_http_mode'] = 'error';
$transport = Faluss_Federation_Client::diagnostic_read( 'remote-node', 'remote-app' );
fed01b5_assert( is_wp_error( $transport ) && 'faluss_federation_transport_failed' === $transport->get_error_code(), 'HTTP WP_Error must keep its 0.1.4 result.' );
fed01b5_assert( array( '[Faluss Federation trace] side=client stage=client_http_error' ) === fed01b5_log_lines(), 'HTTP WP_Error must have its fixed stage without details.' );

fed01b5_clear_log();
$invalid_http = array( 'code' => 200, 'body' => '{}', 'headers' => array() );
$request = array( 'request_id' => '11111111-1111-4111-8111-111111111111', 'issued_at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'sender' => array( 'node_id' => 'local-node', 'app_key' => 'local-app' ) );
$peer = array( 'peer_node_id' => 'remote-node', 'peer_app_key' => 'remote-app' );
$validation = fed01b5_private( 'Faluss_Federation_Client', 'validate_response', array( $invalid_http, $request, 'request-body-marker', $peer ) );
fed01b5_assert( is_wp_error( $validation ) && 'faluss_federation_invalid_response' === $validation->get_error_code(), 'Response-header refusal must keep its 0.1.4 result.' );
fed01b5_assert( array( '[Faluss Federation trace] side=client stage=client_response_headers' ) === fed01b5_log_lines(), 'Response validation must have a distinct fixed stage.' );

$now = time();
$request['issued_at'] = gmdate( 'Y-m-d\TH:i:s\Z', $now );
$response = array( 'protocol_version' => '1', 'message_type' => 'response', 'request_id' => $request['request_id'], 'request_body_sha256' => hash( 'sha256', 'request-body-marker' ), 'responder' => array( 'node_id' => 'remote-node', 'app_key' => 'remote-app', 'key_id' => 'remote-key-0001' ), 'recipient' => array( 'node_id' => 'local-node', 'app_key' => 'local-app' ), 'generated_at' => gmdate( 'Y-m-d\TH:i:s\Z', $now ), 'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', $now + 300 ), 'status' => 'empty', 'payload_contract' => null, 'payload' => array(), 'error' => null );
$raw_response = wp_json_encode( $response );
$valid_http = array( 'code' => 200, 'body' => $raw_response, 'headers' => array( 'Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Faluss-Federation-Key-Id' => 'remote-key-0001', 'X-Faluss-Federation-Content-SHA256' => hash( 'sha256', $raw_response ), 'X-Faluss-Federation-Signature' => str_repeat( 's', 86 ) ) );
fed01b5_clear_log();
$accepted = fed01b5_private( 'Faluss_Federation_Client', 'validate_response', array( $valid_http, $request, 'request-body-marker', $peer ) );
fed01b5_assert( $response === $accepted, 'Accepted 0.1.4 response behavior must remain unchanged.' );
fed01b5_assert( array() === fed01b5_log_lines(), 'Accepted response validation must emit no failure stage.' );

fed01b5_clear_log();
foreach ( array( 0 => 'client_http_0', 200 => 'client_http_2xx', 302 => 'client_http_3xx', 400 => 'client_http_4xx', 503 => 'client_http_5xx', 700 => 'client_http_other' ) as $status => $stage ) {
    fed01b5_private( 'Faluss_Federation_Client', 'trace_http_status', array( $status ) );
}
fed01b5_assert( array(
    '[Faluss Federation trace] side=client stage=client_http_0',
    '[Faluss Federation trace] side=client stage=client_http_2xx',
    '[Faluss Federation trace] side=client stage=client_http_3xx',
    '[Faluss Federation trace] side=client stage=client_http_4xx',
    '[Faluss Federation trace] side=client stage=client_http_5xx',
    '[Faluss Federation trace] side=client stage=client_http_other',
) === fed01b5_log_lines(), 'HTTP status categories must remain fixed and distinct.' );

unlink( $fed01b5_log_path );
echo 'FED-01B.5 diagnostic trace: OK (' . $fed01b5_assertions . ' assertions)' . PHP_EOL;
