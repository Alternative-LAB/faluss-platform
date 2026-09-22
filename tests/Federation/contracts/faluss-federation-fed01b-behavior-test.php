<?php

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $code;
        public function __construct( $code ) { $this->code = $code; }
        public function get_error_code() { return $this->code; }
    }
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $value ) { return $value instanceof WP_Error; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $value ) { return parse_url( $value ); } }

$fed01b_behavior_assertions = 0;
function fed01b_behavior_assert( $condition, $message ) {
    global $fed01b_behavior_assertions;
    $fed01b_behavior_assertions++;
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
        exit( 1 );
    }
}

$root = getenv( 'FALUSS_FEDERATION_SOURCE_ROOT' );
$root = is_string( $root ) && '' !== $root ? rtrim( $root, '/\\' ) : dirname( __DIR__, 3 );
$crypto_path = $root . '/src/Federation/Legacy/includes/class-faluss-federation-crypto.php';
fed01b_behavior_assert( is_file( $crypto_path ), 'Production Crypto class must exist.' );
require_once $crypto_path;

$server = file_get_contents( $root . '/src/Federation/Legacy/includes/class-faluss-federation-server.php' );
$client = file_get_contents( $root . '/src/Federation/Legacy/includes/class-faluss-federation-client.php' );
$providers = file_get_contents( $root . '/src/Federation/Legacy/includes/class-faluss-federation-providers.php' );

$request_raw = '{"protocol_version":"1","message_type":"request"}';
$request = array(
    'protocol_version' => '1',
    'sender' => array( 'node_id' => 'hub-node', 'key_id' => 'key-0001' ),
    'recipient' => array( 'node_id' => 'me-node' ),
    'issued_at' => '2026-09-13T10:00:00Z',
    'expires_at' => '2026-09-13T10:05:00Z',
    'nonce' => Faluss_Federation_Crypto::base64url_encode( str_repeat( 'n', 32 ) ),
);
$request_canonical = Faluss_Federation_Crypto::request_canonical( $request, $request_raw );
fed01b_behavior_assert( is_string( $request_canonical ) && 0 === strpos( $request_canonical, "1\nPOST\n/wp-json" ), 'Production request canonicalization must bind the exact POST path and raw-body hash.' );
fed01b_behavior_assert( is_wp_error( Faluss_Federation_Crypto::canonical_join( array( '1', "POST\r\nGET" ) ) ), 'Production canonicalization must reject CR/LF injection.' );
fed01b_behavior_assert( is_wp_error( Faluss_Federation_Crypto::canonical_join( array( "\xEF\xBB\xBF1", 'POST' ) ) ), 'Production canonicalization must reject an UTF-8 BOM.' );

$timestamp = Faluss_Federation_Crypto::format_utc_timestamp( 1789293600 );
fed01b_behavior_assert( '2026-09-13T10:00:00Z' === $timestamp, 'Production timestamp formatting must emit the exact UTC wire grammar.' );
fed01b_behavior_assert( 1789293600 === Faluss_Federation_Crypto::parse_utc_timestamp( $timestamp ), 'Production timestamp parsing must round-trip exactly.' );
foreach ( array( '2026-09-13T10:00:00+00:00', '2026-02-30T10:00:00Z', '2026-09-13T10:00Z', '2026-09-13T10:00:00.000Z' ) as $invalid ) {
    fed01b_behavior_assert( is_wp_error( Faluss_Federation_Crypto::parse_utc_timestamp( $invalid ) ), 'Production timestamp parser must reject noncanonical or impossible values: ' . $invalid );
}

fed01b_behavior_assert( false !== strpos( $server, "'request_body_sha256' => " . '$request_body_hash' ), 'Signed response must bind the original raw request hash.' );
fed01b_behavior_assert( false !== strpos( $server, 'JSON_THROW_ON_ERROR' ) && false !== strpos( $server, 'MAX_BODY = 65536' ), 'Receiver must decode only after the raw-size limit.' );
fed01b_behavior_assert( false !== strpos( $server, 'pre_auth_reject' ) && false !== strpos( $server, "'Request rejected.'" ), 'Pre-authentication failures must remain generic.' );
fed01b_behavior_assert( false !== strpos( $client, "'timeout' => 3" ) && false !== strpos( $client, "'redirection' => 0" ) && false !== strpos( $client, "'sslverify' => true" ) && false !== strpos( $client, "'limit_response_size' => self::MAX_RESPONSE" ) && false === strpos( $client, 'connect_timeout' ), 'Outbound call must use the supported bounded WordPress HTTP arguments.' );
fed01b_behavior_assert( false === strpos( $client, 'public static function call' ) && false !== strpos( $client, 'private static function call' ), 'Network primitive must remain private behind closed read facades.' );
fed01b_behavior_assert( false !== strpos( $providers, "failure( 'not_available' )" ) && false !== strpos( $providers, 'valid_diagnostic' ), 'Only diagnostic is available without a specialized registered provider.' );
fed01b_behavior_assert( false !== strpos( $server, "'not_available' => 404" ) && false !== strpos( $server, "'replay_rejected' => 409" ), 'Authenticated status-to-HTTP mapping must remain closed.' );

if ( Faluss_Federation_Crypto::sodium_available() ) {
    $seed = random_bytes( SODIUM_CRYPTO_SIGN_SEEDBYTES );
    $pair = sodium_crypto_sign_seed_keypair( $seed );
    $secret = sodium_crypto_sign_secretkey( $pair );
    $public = sodium_crypto_sign_publickey( $pair );
    $signature = sodium_crypto_sign_detached( $request_canonical, $secret );
    fed01b_behavior_assert( sodium_crypto_sign_verify_detached( $signature, $request_canonical, $public ), 'Ed25519 must validate a production-canonical request.' );
    fed01b_behavior_assert( ! sodium_crypto_sign_verify_detached( $signature, $request_canonical . 'x', $public ), 'Ed25519 must reject an altered production-canonical request.' );
    if ( function_exists( 'sodium_memzero' ) ) { sodium_memzero( $secret ); sodium_memzero( $pair ); sodium_memzero( $seed ); }
    $sodium_state = 'executed';
} else {
    $sodium_state = 'not-executed-sodium-unavailable';
}

echo 'FED-01B Federation behavior: OK (' . $fed01b_behavior_assertions . ' assertions; sodium=' . $sodium_state . ')' . PHP_EOL;
