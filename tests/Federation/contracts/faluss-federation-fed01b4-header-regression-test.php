<?php

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $code;
        public function __construct( $code ) { $this->code = $code; }
        public function get_error_code() { return $this->code; }
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $value ) { return $value instanceof WP_Error; }
}

/** Faithful header surface for the relevant WP_REST_Request public API. */
class WP_REST_Request {
    private $headers = array();

    public static function canonicalize_header_name( $key ) {
        return str_replace( '-', '_', strtolower( $key ) );
    }

    public function set_header( $key, $value ) {
        $this->headers[ self::canonicalize_header_name( $key ) ] = (array) $value;
    }

    public function add_header( $key, $value ) {
        $key = self::canonicalize_header_name( $key );
        if ( ! isset( $this->headers[ $key ] ) ) {
            $this->headers[ $key ] = array();
        }
        $this->headers[ $key ][] = $value;
    }

    public function remove_header( $key ) {
        unset( $this->headers[ self::canonicalize_header_name( $key ) ] );
    }

    public function get_headers() {
        return $this->headers;
    }

    public function get_header_as_array( $key ) {
        $key = self::canonicalize_header_name( $key );
        return isset( $this->headers[ $key ] ) ? $this->headers[ $key ] : null;
    }
}

$fed01b4_assertions = 0;

function fed01b4_assert( $condition, $message ) {
    global $fed01b4_assertions;
    $fed01b4_assertions++;
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
        exit( 1 );
    }
}

function fed01b4_extract( $request ) {
    static $method = null;
    if ( null === $method ) {
        $method = new ReflectionMethod( 'Faluss_Federation_Server', 'request_headers' );
        $method->setAccessible( true );
    }
    return $method->invoke( null, $request );
}

function fed01b4_rejected( $request, $message ) {
    $result = fed01b4_extract( $request );
    fed01b4_assert( is_wp_error( $result ), $message );
    fed01b4_assert( 'faluss_federation_invalid_headers' === $result->get_error_code(), $message . ' must stay generic.' );
}

function fed01b4_request( $name_case = 'canonical' ) {
    global $fed01b4_values;
    $request = new WP_REST_Request();
    $names = 'mixed' === $name_case
        ? array( 'x-FaLuSs-FeDeRaTiOn-KeY-iD', 'X-fAlUsS-fEdErAtIoN-cOnTeNt-ShA256', 'x-FALUSS-federation-SIGNATURE' )
        : array( 'X-Faluss-Federation-Key-Id', 'X-Faluss-Federation-Content-SHA256', 'X-Faluss-Federation-Signature' );
    foreach ( array_values( $fed01b4_values ) as $index => $value ) {
        $request->set_header( $names[ $index ], $value );
    }
    return $request;
}

$root = dirname( __DIR__, 3 );
$includes = $root . '/src/Federation/Legacy/includes/';
require_once $includes . 'class-faluss-federation-crypto.php';
require_once $includes . 'class-faluss-federation-server.php';

$fed01b4_values = array(
    'X-Faluss-Federation-Key-Id' => 'hub-key-0001',
    'X-Faluss-Federation-Content-SHA256' => str_repeat( 'a', 64 ),
    'X-Faluss-Federation-Signature' => Faluss_Federation_Crypto::base64url_encode( str_repeat( 's', 64 ) ),
);

$request = fed01b4_request();
$stored = $request->get_headers();
foreach ( array( 'x_faluss_federation_key_id', 'x_faluss_federation_content_sha256', 'x_faluss_federation_signature' ) as $name ) {
    fed01b4_assert( isset( $stored[ $name ] ), 'WordPress-compatible storage must use the underscore key ' . $name );
}
fed01b4_assert( ! isset( $stored['x-faluss-federation-key-id'] ), 'Base 36781a3 raw dashed-key lookup must miss the canonicalized WordPress storage.' );

$extracted = fed01b4_extract( $request );
fed01b4_assert( is_array( $extracted ), 'Production request_headers() must extract valid canonicalized headers.' );
fed01b4_assert( $fed01b4_values === $extracted, 'Production extraction must preserve the three exact header values.' );

$mixed_case = fed01b4_extract( fed01b4_request( 'mixed' ) );
fed01b4_assert( $fed01b4_values === $mixed_case, 'WordPress API canonicalization must keep header names case-insensitive.' );

foreach ( array_keys( $fed01b4_values ) as $name ) {
    $missing = fed01b4_request();
    $missing->remove_header( $name );
    fed01b4_rejected( $missing, 'Missing ' . $name . ' must be rejected.' );

    $empty = fed01b4_request();
    $empty->set_header( $name, '' );
    fed01b4_rejected( $empty, 'Empty ' . $name . ' must be rejected.' );

    $duplicate = fed01b4_request();
    $duplicate->add_header( $name, $fed01b4_values[ $name ] );
    fed01b4_rejected( $duplicate, 'Duplicated ' . $name . ' must be rejected.' );

    $two_values = fed01b4_request();
    $two_values->set_header( $name, array( $fed01b4_values[ $name ], 'second-value' ) );
    fed01b4_rejected( $two_values, 'Two-valued ' . $name . ' must be rejected.' );

    $merged = fed01b4_request();
    $merged->set_header( $name, $fed01b4_values[ $name ] . ',' . $fed01b4_values[ $name ] );
    fed01b4_rejected( $merged, 'Comma-merged ' . $name . ' must be rejected.' );

    $ambiguous = fed01b4_request();
    $ambiguous->set_header( $name, array( array( $fed01b4_values[ $name ] ) ) );
    fed01b4_rejected( $ambiguous, 'Ambiguous array ' . $name . ' must be rejected.' );
}

$invalid_values = array(
    'X-Faluss-Federation-Key-Id' => 'bad',
    'X-Faluss-Federation-Content-SHA256' => str_repeat( 'z', 64 ),
    'X-Faluss-Federation-Signature' => str_repeat( '!', 86 ),
);
foreach ( $invalid_values as $name => $invalid_value ) {
    $invalid = fed01b4_request();
    $invalid->set_header( $name, $invalid_value );
    fed01b4_rejected( $invalid, 'Invalid syntax for ' . $name . ' must be rejected.' );
}

$server_source = file_get_contents( $includes . 'class-faluss-federation-server.php' );
fed01b4_assert( is_string( $server_source ), 'Production Server source must be readable.' );
$method = new ReflectionMethod( 'Faluss_Federation_Server', 'request_headers' );
$lines = file( $includes . 'class-faluss-federation-server.php' );
$method_source = implode( '', array_slice( $lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );
fed01b4_assert( false !== strpos( $method_source, 'get_header_as_array(' ), 'Production request_headers() must use the public WordPress header API.' );
fed01b4_assert( false === strpos( $method_source, 'get_headers(' ), 'Production request_headers() must not inspect raw get_headers() keys.' );
foreach ( array_keys( $fed01b4_values ) as $name ) {
    fed01b4_assert( false !== strpos( $method_source, "'" . $name . "'" ), 'Production request_headers() must request canonical public name ' . $name );
}

echo 'FED-01B.4 canonical REST headers: OK (' . $fed01b4_assertions . ' assertions)' . PHP_EOL;
