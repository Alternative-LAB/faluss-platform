<?php

$fed01b3_assertions = 0;

function fed01b3_assert( $condition, $message ) {
    global $fed01b3_assertions;
    $fed01b3_assertions++;
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
        exit( 1 );
    }
}

fed01b3_assert( ! extension_loaded( 'sodium' ), 'This sodium_compat regression process must run without the native Sodium extension.' );

if ( ! class_exists( 'SodiumException' ) ) {
    class SodiumException extends Exception {}
}

foreach ( array(
    'SODIUM_CRYPTO_SIGN_SEEDBYTES' => 32,
    'SODIUM_CRYPTO_SIGN_KEYPAIRBYTES' => 96,
    'SODIUM_CRYPTO_SIGN_SECRETKEYBYTES' => 64,
    'SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES' => 32,
    'SODIUM_CRYPTO_SIGN_BYTES' => 64,
) as $constant => $value ) {
    if ( ! defined( $constant ) ) {
        define( $constant, $value );
    }
}

$GLOBALS['fed01b3_sodium_calls'] = array();
$GLOBALS['fed01b3_wipe_mode'] = 'sodium_exception';
$GLOBALS['fed01b3_routes'] = 0;
$GLOBALS['fed01b3_network_calls'] = 0;
$GLOBALS['fed01b3_database_calls'] = 0;
$GLOBALS['fed01b3_activation_hooks'] = 0;

function fed01b3_sodium_call( $name ) {
    $GLOBALS['fed01b3_sodium_calls'][] = $name;
}

if ( ! function_exists( 'sodium_crypto_sign_seed_keypair' ) ) {
    function sodium_crypto_sign_seed_keypair( $seed ) {
        fed01b3_sodium_call( __FUNCTION__ );
        return 'pair:' . $seed;
    }
}
if ( ! function_exists( 'sodium_crypto_sign_secretkey' ) ) {
    function sodium_crypto_sign_secretkey( $pair ) {
        fed01b3_sodium_call( __FUNCTION__ );
        return 'secret:' . $pair;
    }
}
if ( ! function_exists( 'sodium_crypto_sign_publickey' ) ) {
    function sodium_crypto_sign_publickey( $pair ) {
        fed01b3_sodium_call( __FUNCTION__ );
        return 'public:' . $pair;
    }
}
if ( ! function_exists( 'sodium_crypto_sign_detached' ) ) {
    function sodium_crypto_sign_detached( $message, $secret ) {
        fed01b3_sodium_call( __FUNCTION__ );
        return 'signature:' . $message . ':' . $secret;
    }
}
if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
    function sodium_crypto_sign_verify_detached( $signature, $message, $public ) {
        fed01b3_sodium_call( __FUNCTION__ );
        return 'faluss-federation-ed25519-self-test-v1' === $message;
    }
}
if ( ! function_exists( 'sodium_memzero' ) ) {
    function sodium_memzero( &$value ) {
        fed01b3_sodium_call( __FUNCTION__ );
        if ( 'sodium_exception' === $GLOBALS['fed01b3_wipe_mode'] ) {
            throw new SodiumException( 'sodium_compat cannot securely wipe memory.' );
        }
        if ( 'error' === $GLOBALS['fed01b3_wipe_mode'] ) {
            throw new Error( 'Synthetic cleanup Error.' );
        }
        $value = null;
    }
}

class WP_Error {
    public $code;
    public function __construct( $code = '' ) { $this->code = $code; }
}

class Fed01b3_Wpdb {
    public $prefix = 'wp_';
    public $last_error = '';
    public function __call( $name, $arguments ) {
        $GLOBALS['fed01b3_database_calls']++;
        return null;
    }
}

function is_wp_error( $value ) { return $value instanceof WP_Error; }
function plugin_dir_path( $file ) { return dirname( $file ) . DIRECTORY_SEPARATOR; }
function register_activation_hook( $file, $callback ) { $GLOBALS['fed01b3_activation_hooks']++; }
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}
function is_admin() { return true; }
function current_user_can( $capability ) { return true; }
function get_option( $name, $default = false ) { return $default; }
function __( $text, $domain = null ) { return $text; }
function esc_html__( $text, $domain = null ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_url( $url ) { return (string) $url; }
function admin_url( $path = '' ) { return 'https://example.invalid/wp-admin/' . ltrim( $path, '/' ); }
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="test">'; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function register_rest_route( $namespace, $route, $args ) { $GLOBALS['fed01b3_routes']++; }
function wp_remote_post( $url, $args = array() ) { $GLOBALS['fed01b3_network_calls']++; return new WP_Error( 'unexpected_network' ); }

set_error_handler(
    static function ( $severity, $message, $file, $line ) {
        throw new ErrorException( $message, 0, $severity, $file, $line );
    }
);

define( 'ABSPATH', dirname( __DIR__, 3 ) . DIRECTORY_SEPARATOR );
$GLOBALS['wpdb'] = new Fed01b3_Wpdb();

require dirname( __DIR__, 3 ) . '/src/Federation/Legacy/faluss-federation.php';
Faluss_Federation::load();

fed01b3_assert( '0.3.0' === FALUSS_FEDERATION_VERSION, 'Federation version must be 0.3.0.' );
fed01b3_assert( '1' === FALUSS_FEDERATION_SCHEMA_VERSION, 'Schema version must stay at 1.' );
fed01b3_assert( 1 === $GLOBALS['fed01b3_activation_hooks'], 'Plugin activation must remain registerable without native Sodium.' );

$crypto_source = file_get_contents( dirname( __DIR__, 3 ) . '/src/Federation/Legacy/includes/class-faluss-federation-crypto.php' );
fed01b3_assert( is_string( $crypto_source ), 'Crypto source must be readable.' );
foreach ( array( "extension_loaded( 'sodium' )", 'SODIUM_CRYPTO_SIGN_SEEDBYTES', 'SODIUM_CRYPTO_SIGN_KEYPAIRBYTES', 'SODIUM_CRYPTO_SIGN_SECRETKEYBYTES', 'SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES', 'SODIUM_CRYPTO_SIGN_BYTES', 'catch ( Throwable $throwable )' ) as $needle ) {
    fed01b3_assert( false !== strpos( $crypto_source, $needle ), 'Crypto source must contain ' . $needle );
}
fed01b3_assert( false === strpos( $crypto_source, 'catch ( Exception $exception )' ), 'Crypto boundaries must not leave Exception-only catches.' );
fed01b3_assert( false !== strpos( $crypto_source, 'return $cleaned && $result;' ), 'Auto-test success must be decided after cleanup.' );
fed01b3_assert( 2 === substr_count( $crypto_source, 'return $cleaned ? $result' ), 'Identity and signing success must be decided after cleanup.' );

foreach ( array( 'sodium_crypto_sign_seed_keypair', 'sodium_crypto_sign_secretkey', 'sodium_crypto_sign_publickey', 'sodium_crypto_sign_detached', 'sodium_crypto_sign_verify_detached', 'sodium_memzero' ) as $function ) {
    fed01b3_assert( function_exists( $function ), 'sodium_compat primitive must be present in the simulation: ' . $function );
}
fed01b3_assert( false === Faluss_Federation_Crypto::sodium_available(), 'sodium_compat functions must never satisfy the native Sodium gate.' );
fed01b3_assert( false === Faluss_Federation_Crypto::auto_test(), 'Public auto-test must fail closed before polyfill primitives run.' );
fed01b3_assert( is_wp_error( Faluss_Federation_Crypto::local_identity() ), 'Local identity must be a WP_Error without native Sodium.' );
fed01b3_assert( false === Faluss_Federation_Crypto::transport_ready(), 'Transport must not be ready without native Sodium.' );
fed01b3_assert( is_wp_error( Faluss_Federation_Crypto::sign( 'fed01b3' ) ), 'Signing must fail closed without native Sodium.' );
fed01b3_assert( array() === $GLOBALS['fed01b3_sodium_calls'], 'Public fail-closed paths must not invoke sodium_compat primitives.' );

$requirements = new ReflectionMethod( 'Faluss_Federation_Crypto', 'sodium_requirements_met' );
$requirements->setAccessible( true );
$all_constants = array_fill( 0, 5, true );
$all_functions = array_fill( 0, 6, true );
fed01b3_assert( true === $requirements->invoke( null, true, $all_constants, $all_functions ), 'Complete announced native environment must pass the internal decision.' );
fed01b3_assert( false === $requirements->invoke( null, false, $all_constants, $all_functions ), 'Polyfill-only environment must fail the internal decision.' );
$missing_constant = $all_constants;
$missing_constant[0] = false;
fed01b3_assert( false === $requirements->invoke( null, true, $missing_constant, $all_functions ), 'Announced native environment with a missing constant must be unavailable.' );
$missing_function = $all_functions;
$missing_function[0] = false;
fed01b3_assert( false === $requirements->invoke( null, true, $all_constants, $missing_function ), 'Announced native environment with a missing primitive must be unavailable.' );

$raw_auto_test = new ReflectionMethod( 'Faluss_Federation_Crypto', 'run_auto_test' );
$raw_auto_test->setAccessible( true );
fed01b3_assert( false === $raw_auto_test->invoke( null ), 'Synthetic sodium_compat cleanup exception must make the primitive self-test fail closed.' );
$GLOBALS['fed01b3_wipe_mode'] = 'error';
fed01b3_assert( false === $raw_auto_test->invoke( null ), 'A cleanup Error must make the primitive self-test fail closed without escaping.' );

$wipe = new ReflectionMethod( 'Faluss_Federation_Crypto', 'wipe' );
$wipe->setAccessible( true );
$buffer = 'sensitive-test-buffer';
$arguments = array( &$buffer );
fed01b3_assert( false === $wipe->invokeArgs( null, $arguments ), 'Cleanup Error must be reported as a failure.' );
fed01b3_assert( null === $buffer, 'Sensitive buffer variable must be inaccessible after failed cleanup.' );

ob_start();
Faluss_Federation_Admin::render();
$admin_output = ob_get_clean();
fed01b3_assert( is_string( $admin_output ) && '' !== $admin_output, 'Actual administrator render must complete.' );
foreach ( array( '<code>absent</code>', '<code>échoué</code>', '<code>fail_closed</code>', 'Transport indisponible : Sodium absent ou invalide', '</form>', '</div>' ) as $needle ) {
    fed01b3_assert( false !== strpos( $admin_output, $needle ), 'Administrator output must contain ' . $needle );
}

Faluss_Federation_Server::register_route();
fed01b3_assert( 0 === $GLOBALS['fed01b3_routes'], 'Receiver must register no operational route.' );
fed01b3_assert( 0 === $GLOBALS['fed01b3_network_calls'], 'Fail-closed rendering must issue no network request.' );
fed01b3_assert( 0 === $GLOBALS['fed01b3_database_calls'], 'Fail-closed rendering must execute no pairing mutation.' );

restore_error_handler();
echo 'FED-01B.3 sodium_compat regression: OK (' . $fed01b3_assertions . ' assertions; native signatures not executed)' . PHP_EOL;
