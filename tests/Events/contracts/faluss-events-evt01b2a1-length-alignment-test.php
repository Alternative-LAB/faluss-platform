<?php

/** EVT-01B.2A.1 storage-length alignment regression. */

define( 'ABSPATH', __DIR__ . '/' );

final class WP_Error {
    private $code;
    public function __construct( $code = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}

function is_wp_error( $value ) { return $value instanceof WP_Error; }

$GLOBALS['evt01b2a1_uuid_calls'] = 0;
function wp_generate_uuid4() {
    $GLOBALS['evt01b2a1_uuid_calls']++;
    return '10000000-0000-4000-8000-000000000001';
}

final class EVT01B2A1_WPDB_Spy {
    public $queries = 0;
    public $writes = 0;
    public $locks = 0;
    public $transactions = 0;

    public function query( $query ) {
        $this->queries++;
        if ( preg_match( '/\b(?:INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|RENAME)\b/i', $query ) ) { $this->writes++; }
        if ( false !== stripos( $query, 'GET_LOCK' ) ) { $this->locks++; }
        if ( preg_match( '/\b(?:START\s+TRANSACTION|COMMIT|ROLLBACK)\b/i', $query ) ) { $this->transactions++; }
        return false;
    }

    public function __call( $name, $arguments ) {
        $this->queries++;
        if ( in_array( $name, array( 'insert', 'update', 'delete', 'replace' ), true ) ) { $this->writes++; }
        return false;
    }
}

function evt01b2a1_assert( $condition, $message ) {
    global $evt01b2a1_assertions;
    $evt01b2a1_assertions++;
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
        exit( 1 );
    }
}

function evt01b2a1_private( $class, $method, $arguments ) {
    $reflection = new ReflectionMethod( $class, $method );
    $reflection->setAccessible( true );
    return $reflection->invokeArgs( null, $arguments );
}

$evt01b2a1_assertions = 0;
$root = dirname( __DIR__, 3 );
$GLOBALS['wpdb'] = new EVT01B2A1_WPDB_Spy();

require_once $root . '/src/Events/Legacy/includes/class-faluss-events-catalog-validator.php';
require_once $root . '/src/Events/Legacy/includes/class-faluss-events-envelope-validator.php';
require_once $root . '/src/Events/Legacy/includes/class-faluss-events.php';
require_once $root . '/src/Events/Legacy/includes/class-faluss-events-engine.php';

$namespaced_512 = str_repeat( 'a', 57 ) . '.' . implode( '.', array_fill( 0, 7, str_repeat( 'b', 64 ) ) );
$namespaced_513 = str_repeat( 'a', 58 ) . '.' . implode( '.', array_fill( 0, 7, str_repeat( 'b', 64 ) ) );
$owner_512 = str_repeat( 'a', 57 );
$owner_513 = str_repeat( 'a', 58 );
$consumer_128 = str_repeat( 'c', 63 ) . '.' . str_repeat( 'd', 64 );
$consumer_129 = str_repeat( 'c', 64 ) . '.' . str_repeat( 'd', 64 );
$semver_32 = str_repeat( '9', 28 ) . '.1.1';
$semver_33 = str_repeat( '9', 29 ) . '.1.1';

evt01b2a1_assert( 512 === strlen( $namespaced_512 ) && 513 === strlen( $namespaced_513 ), 'Namespaced boundary fixtures must be exact.' );
evt01b2a1_assert( 128 === strlen( $consumer_128 ) && 129 === strlen( $consumer_129 ), 'Consumer boundary fixtures must be exact.' );
evt01b2a1_assert( 32 === strlen( $semver_32 ) && 33 === strlen( $semver_33 ), 'Semantic-version boundary fixtures must be exact.' );

foreach ( array( 'faluss-event-envelope.schema.json', 'faluss-event-source-catalog.schema.json' ) as $filename ) {
    $schema = json_decode( file_get_contents( $root . '/contracts/' . $filename ), true );
    evt01b2a1_assert( is_array( $schema ) && JSON_ERROR_NONE === json_last_error(), 'EVT schema must parse: ' . $filename );
    evt01b2a1_assert( 512 === ( $schema['$defs']['namespacedKey']['maxLength'] ?? null ) && 512 === ( $schema['$defs']['documentType']['maxLength'] ?? null ) && 32 === ( $schema['$defs']['semanticVersion']['maxLength'] ?? null ), 'EVT schema limits must match storage: ' . $filename );
}

foreach ( array( 'Faluss_Events_Catalog_Validator', 'Faluss_Events_Envelope_Validator' ) as $validator ) {
    evt01b2a1_assert( true === evt01b2a1_private( $validator, 'is_namespaced_key', array( $namespaced_512 ) ), $validator . ' must accept 512 characters.' );
    evt01b2a1_assert( false === evt01b2a1_private( $validator, 'is_namespaced_key', array( $namespaced_513 ) ), $validator . ' must reject 513 characters.' );
    evt01b2a1_assert( true === evt01b2a1_private( $validator, 'is_semver', array( $semver_32 ) ), $validator . ' must accept a 32-character semantic version.' );
    evt01b2a1_assert( false === evt01b2a1_private( $validator, 'is_semver', array( $semver_33 ) ), $validator . ' must reject a 33-character semantic version.' );
}

evt01b2a1_assert( true === evt01b2a1_private( 'Faluss_Events', 'is_capability_key', array( $namespaced_512, $owner_512 ) ) && false === evt01b2a1_private( 'Faluss_Events', 'is_capability_key', array( $namespaced_513, $owner_513 ) ), 'Events provider registry must enforce the 512-character capability boundary.' );
evt01b2a1_assert( true === evt01b2a1_private( 'Faluss_Events', 'is_document_type', array( $namespaced_512 ) ) && false === evt01b2a1_private( 'Faluss_Events', 'is_document_type', array( $namespaced_513 ) ), 'Events payload registry must enforce the 512-character document-type boundary.' );
evt01b2a1_assert( true === evt01b2a1_private( 'Faluss_Events', 'is_semver', array( $semver_32 ) ) && false === evt01b2a1_private( 'Faluss_Events', 'is_semver', array( $semver_33 ) ), 'Events registries must enforce the 32-character semantic-version boundary.' );
evt01b2a1_assert( true === evt01b2a1_private( 'Faluss_Events_Engine', 'is_capability', array( $namespaced_512, $owner_512 ) ) && false === evt01b2a1_private( 'Faluss_Events_Engine', 'is_capability', array( $namespaced_513, $owner_513 ) ), 'Persistent engine must enforce the 512-character capability boundary.' );
evt01b2a1_assert( true === evt01b2a1_private( 'Faluss_Events_Engine', 'is_consumer_key', array( $consumer_128 ) ) && false === evt01b2a1_private( 'Faluss_Events_Engine', 'is_consumer_key', array( $consumer_129 ) ), 'Persistent engine must enforce the 128-character consumer boundary.' );
evt01b2a1_assert( true === evt01b2a1_private( 'Faluss_Events_Engine', 'is_semver', array( $semver_32 ) ) && false === evt01b2a1_private( 'Faluss_Events_Engine', 'is_semver', array( $semver_33 ) ), 'Persistent engine must enforce the 32-character semantic-version boundary.' );

$route = array(
    'source_node_id' => 'source-node', 'source_app_key' => $owner_512, 'source_capability_key' => $namespaced_512,
    'catalog_version' => $semver_32, 'destination' => 'analytics.events', 'mode' => 'local',
    'target_node_id' => 'target-node', 'target_app_key' => 'target-app',
);
$consumer = array(
    'consumer_key' => $consumer_128,
    'destination' => 'analytics.events',
    'target_node_id' => 'target-node',
    'target_app_key' => 'target-app',
    'sources' => array( array( 'node_id' => 'source-node', 'app_key' => $owner_512, 'capability_key' => $namespaced_512, 'catalog_version' => $semver_32 ) ),
    'callback' => function () { return true; },
);
evt01b2a1_assert( true === Faluss_Events_Engine::register_delivery_route( $route ) && true === Faluss_Events_Engine::register_consumer( $consumer ), 'Exact storage boundaries must register through production methods.' );
$too_long_route = $route; $too_long_route['source_app_key'] = $owner_513; $too_long_route['source_capability_key'] = $namespaced_513;
$too_long_version = $route; $too_long_version['catalog_version'] = $semver_33;
$too_long_consumer = $consumer; $too_long_consumer['consumer_key'] = $consumer_129;
evt01b2a1_assert( is_wp_error( Faluss_Events_Engine::register_delivery_route( $too_long_route ) ) && is_wp_error( Faluss_Events_Engine::register_delivery_route( $too_long_version ) ) && is_wp_error( Faluss_Events_Engine::register_consumer( $too_long_consumer ) ), 'Oversized route and consumer descriptors must fail through production methods.' );
evt01b2a1_assert( 0 === $GLOBALS['wpdb']->queries && 0 === $GLOBALS['wpdb']->writes && 0 === $GLOBALS['wpdb']->locks && 0 === $GLOBALS['wpdb']->transactions && 0 === $GLOBALS['evt01b2a1_uuid_calls'], 'Boundary refusal must precede every query, write, lock, transaction and UUID.' );

$bootstrap = file_get_contents( $root . '/src/Events/Legacy/faluss-events.php' );
$runtime = $bootstrap;
foreach ( glob( $root . '/src/Events/Legacy/includes/*.php' ) as $file ) { $runtime .= file_get_contents( $file ); }
evt01b2a1_assert( false !== strpos( $bootstrap, 'Version: 0.3.1' ) && false !== strpos( $bootstrap, "FALUSS_EVENTS_VERSION', '0.3.1'" ) && false !== strpos( $bootstrap, "FALUSS_EVENTS_SCHEMA_VERSION', '2'" ), 'Faluss Events must be 0.3.1 with schema 2.' );
foreach ( array( 'register_rest_route', 'Faluss_Events::register_catalog_provider(', 'Faluss_Events_Engine::register_delivery_route(', 'Faluss_Events_Engine::register_consumer(' ) as $forbidden ) {
    evt01b2a1_assert( false === strpos( $runtime, $forbidden ), 'No business provider, real event, browser route or concrete consumer may be introduced: ' . $forbidden );
}

echo 'EVT-01B.2A.1 length alignment: OK (' . $evt01b2a1_assertions . ' assertions; production boundary predicates)' . PHP_EOL;
