<?php

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

final class WP_Error {
    private $code;
    public function __construct( $code ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_parse_url( $value ) { return parse_url( $value ); }
function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['an01b3_hooks'][ $hook ][ $priority ][] = $callback; }
function did_action( $hook ) { return (int) ( $GLOBALS['an01b3_actions'][ $hook ] ?? 0 ); }
function wp_remote_get() { $GLOBALS['an01b3_network']++; return new WP_Error( 'network_forbidden' ); }
function wp_remote_post() { $GLOBALS['an01b3_network']++; return new WP_Error( 'network_forbidden' ); }
function wp_generate_uuid4() { $GLOBALS['an01b3_uuids']++; return '00000000-0000-4000-8000-000000000000'; }

final class Faluss_Events_Schema {
    public static $ready = true;
    public static function is_ready() { return self::$ready; }
}
final class Faluss_Analytics_Schema {
    public static $ready = true;
    public static function is_ready() { return self::$ready; }
}
final class Faluss_Federation_Crypto {
    public static $identity = array( 'node_id' => 'hub-node', 'app_key' => 'faluss-hub', 'origin' => 'https://faluss.com' );
    public static $transport_ready = true;
    public static function local_identity() { return self::$identity; }
    public static function transport_ready() { return self::$transport_ready; }
    public static function is_key_id( $value ) { return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{7,127}$/D', $value ); }
}

final class AN01B3_WPDB_Spy {
    public $last_error = '';
    public $queries = 0;
    public function __call( $name, $arguments ) { $this->queries++; return false; }
}

$GLOBALS['an01b3_network'] = 0;
$GLOBALS['an01b3_uuids'] = 0;
$GLOBALS['wpdb'] = new AN01B3_WPDB_Spy();
$an01b3_assertions = 0;

function an01b3_assert( $condition, $message ) {
    global $an01b3_assertions;
    $an01b3_assertions++;
    if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); }
}
function an01b3_get( $class, $property ) {
    $reflection = new ReflectionProperty( $class, $property );
    $reflection->setAccessible( true );
    return $reflection->getValue();
}
function an01b3_set( $class, $property, $value ) {
    $reflection = new ReflectionProperty( $class, $property );
    $reflection->setAccessible( true );
    $reflection->setValue( null, $value );
}
function an01b3_reset() {
    foreach ( array(
        'catalog_providers' => array(), 'payload_validators' => array(), 'provider_conflict' => false,
        'payload_validator_conflict' => false, 'federation_validator_registered' => false,
        'federation_provider_keys' => array(), 'federation_publish_registered' => false,
    ) as $property => $value ) { an01b3_set( 'Faluss_Events', $property, $value ); }
    foreach ( array( 'routes' => array(), 'consumers' => array(), 'route_conflict' => false, 'consumer_conflict' => false ) as $property => $value ) {
        an01b3_set( 'Faluss_Events_Engine', $property, $value );
    }
    foreach ( array( 'validators_registered' => false, 'consumer_registered' => false, 'runtime_conflict' => false ) as $property => $value ) {
        an01b3_set( 'Faluss_Analytics', $property, $value );
    }
    foreach ( array( 'Faluss_Portal_Events_Catalog', 'Faluss_Link_Events_Catalog' ) as $class ) {
        an01b3_set( $class, 'registered', false ); an01b3_set( $class, 'conflict', false );
    }
    foreach ( array( 'route_registered' => false, 'runtime_conflict' => false ) as $property => $value ) {
        an01b3_set( 'Faluss_Portal_Events_Runtime', $property, $value );
    }
    foreach ( array( 'validators_registered' => false, 'route_registered' => false, 'runtime_conflict' => false ) as $property => $value ) {
        an01b3_set( 'Faluss_Link_Events_Runtime', $property, $value );
    }
    Faluss_Events_Schema::$ready = true;
    Faluss_Analytics_Schema::$ready = true;
    Faluss_Federation_Crypto::$transport_ready = true;
}
function an01b3_event( $event_type, $document_type ) {
    return array(
        'event_type' => $event_type,
        'source' => array(
            'node_id' => 'me-node',
            'app_key' => 'faluss-me',
            'owner' => 'faluss-me',
            'capability_key' => 'faluss-me.events',
            'catalog_version' => '1.0.0',
        ),
        'payload_contract' => array( 'document_type' => $document_type, 'contract_version' => '1.0.0' ),
        'payload' => array(),
    );
}
$root = dirname( __DIR__, 3 );
$paths = array(
    $root . '/src/AppsRegistry/ManifestValidator.php',
    $root . '/src/AppsRegistry/ReadModelValidator.php',
    $root . '/src/AppsRegistry/AppsRegistryService.php',
    $root . '/src/AppsRegistry/LegacyAppsRegistryFacades.php',
    $root . '/src/Events/Legacy/includes/class-faluss-events-catalog-validator.php',
    $root . '/src/Events/Legacy/includes/class-faluss-events.php',
    $root . '/src/Events/Legacy/includes/class-faluss-events-engine.php',
    $root . '/src/Portal/LegacyPortalManifest.php',
    $root . '/src/Portal/LegacyPortalEventsCatalog.php',
    $root . '/src/Link/LegacyLinkManifest.php',
    $root . '/src/Link/LegacyLinkEventsCatalog.php',
    $root . '/src/Analytics/Legacy/includes/class-faluss-analytics-event-validator.php',
    $root . '/src/Analytics/Legacy/includes/class-faluss-analytics-consumer.php',
    $root . '/src/Analytics/Legacy/includes/class-faluss-analytics.php',
    $root . '/src/Portal/PortalAnalyticsAdapter.php',
    $root . '/src/Portal/LegacyPortalEventsRuntime.php',
    $root . '/src/Link/LegacyLinkEventsRuntime.php',
);
foreach ( $paths as $path ) { an01b3_assert( is_file( $path ), 'Required production artifact missing: ' . $path ); require_once $path; }

$portal_module = file_get_contents( $root . '/src/Portal/PortalModule.php' );
$link_module = file_get_contents( $root . '/src/Link/LinkModule.php' );
an01b3_assert( false !== strpos( $portal_module, "VERSION = '0.1.24'" ) && false !== strpos( $link_module, "VERSION = '0.4.1'" ), 'Portal and Link compatibility versions must remain exact.' );
an01b3_assert( false !== strpos( $portal_module, 'Faluss_Portal_Events_Runtime::boot()' ) && false !== strpos( $link_module, 'Faluss_Link_Events_Runtime::boot()' ), 'Both owner runtimes must boot from their Platform module.' );

$hub_route = array(
    'source_node_id' => 'hub-node', 'source_app_key' => 'faluss-hub', 'source_capability_key' => 'faluss-hub.events',
    'catalog_version' => '1.0.0', 'destination' => 'analytics.events', 'mode' => 'local',
    'target_node_id' => 'hub-node', 'target_app_key' => 'faluss-hub',
);
$me_route = array(
    'source_node_id' => 'me-node', 'source_app_key' => 'faluss-me', 'source_capability_key' => 'faluss-me.events',
    'catalog_version' => '1.0.0', 'destination' => 'analytics.events', 'mode' => 'federation',
    'target_node_id' => 'hub-node', 'target_app_key' => 'faluss-hub',
);
an01b3_assert( $hub_route === Faluss_Portal_Events_Runtime::route_descriptor(), 'Hub local route descriptor must be exact.' );
an01b3_assert( $me_route === Faluss_Link_Events_Runtime::route_descriptor(), 'Me Federation route descriptor must be exact and hard-code the Hub target.' );

/* Hub: no route before exact identity, Events schema and Analytics consumer readiness. */
an01b3_reset();
Faluss_Federation_Crypto::$identity = array( 'node_id' => 'hub-node', 'app_key' => 'faluss-hub', 'origin' => 'https://wrong.example' );
an01b3_assert( false === Faluss_Portal_Events_Runtime::register_runtime() && array() === an01b3_get( 'Faluss_Events_Engine', 'routes' ), 'Hub route must refuse a divergent local identity.' );
an01b3_reset();
Faluss_Federation_Crypto::$identity = array( 'node_id' => 'hub-node', 'app_key' => 'faluss-hub', 'origin' => 'https://faluss.com' );
Faluss_Events_Schema::$ready = false;
an01b3_assert( false === Faluss_Portal_Events_Runtime::register_runtime() && array() === an01b3_get( 'Faluss_Events_Engine', 'routes' ), 'Hub route must wait for Events schema readiness.' );
an01b3_reset();
Faluss_Federation_Crypto::$identity = array( 'node_id' => 'hub-node', 'app_key' => 'faluss-hub', 'origin' => 'https://faluss.com' );
Faluss_Analytics_Schema::$ready = false;
an01b3_assert( false === Faluss_Portal_Events_Runtime::register_runtime() && array() === an01b3_get( 'Faluss_Events_Engine', 'routes' ), 'Hub route must wait for Analytics schema and consumer readiness.' );
an01b3_reset();
Faluss_Federation_Crypto::$identity = array( 'node_id' => 'hub-node', 'app_key' => 'faluss-hub', 'origin' => 'https://faluss.com' );
an01b3_assert( true === Faluss_Events_Engine::register_consumer( Faluss_Analytics::consumer_descriptor() ), 'Consumer collision precondition must register the exact tuple.' );
an01b3_assert( false === Faluss_Portal_Events_Runtime::register_runtime() && array() === an01b3_get( 'Faluss_Events_Engine', 'routes' ) && true === Faluss_Portal_Events_Runtime::runtime_state()['conflict'], 'Hub route must remain absent when the Analytics consumer registry is unavailable.' );

an01b3_reset();
Faluss_Federation_Crypto::$identity = array( 'node_id' => 'hub-node', 'app_key' => 'faluss-hub', 'origin' => 'https://faluss.com' );
an01b3_assert( true === Faluss_Portal_Events_Runtime::register_runtime(), 'Exact Hub runtime must register.' );
$routes = an01b3_get( 'Faluss_Events_Engine', 'routes' );
an01b3_assert( 1 === count( $routes ) && $hub_route === reset( $routes ), 'Hub runtime must register exactly its local Analytics route.' );
an01b3_assert( true === Faluss_Portal_Events_Runtime::register_runtime() && 1 === count( an01b3_get( 'Faluss_Events_Engine', 'routes' ) ), 'Repeated Hub hooks must be idempotent.' );
$analytics_state = Faluss_Analytics::runtime_state();
an01b3_assert( true === $analytics_state['local_hub'] && true === $analytics_state['schema_ready'] && true === $analytics_state['consumer_registered'] && false === $analytics_state['conflict'], 'Hub route must be gated by the exact ready Analytics consumer.' );
an01b3_assert( Faluss_Portal_Events_Catalog::catalog() === Faluss_Events::resolve_local_catalog( 'hub-node', 'faluss-hub', 'faluss-hub.events', '1.0.0' ), 'Hub route must use the existing resolved and validated owner catalog.' );

an01b3_reset();
Faluss_Federation_Crypto::$identity = array( 'node_id' => 'hub-node', 'app_key' => 'faluss-hub', 'origin' => 'https://faluss.com' );
an01b3_assert( true === Faluss_Events_Engine::register_delivery_route( $hub_route ), 'Hub collision precondition must register the exact tuple.' );
an01b3_assert( false === Faluss_Portal_Events_Runtime::register_runtime() && true === Faluss_Portal_Events_Runtime::runtime_state()['conflict'], 'A foreign Hub route collision must fail closed.' );

/* Me: validators register locally before transport, then only the route resumes. */
an01b3_reset();
Faluss_Federation_Crypto::$identity = array( 'node_id' => 'me-node', 'app_key' => 'faluss-me', 'origin' => 'https://faluss.me' );
Faluss_Federation_Crypto::$transport_ready = false;
an01b3_assert( false === Faluss_Link_Events_Runtime::register_runtime(), 'Me route must wait while Federation transport is unavailable.' );
$link_state = Faluss_Link_Events_Runtime::runtime_state();
an01b3_assert( true === $link_state['validators_registered'] && false === $link_state['route_registered'] && false === $link_state['conflict'], 'Me validators must remain ready while only the route waits.' );
$validators = an01b3_get( 'Faluss_Events', 'payload_validators' );
an01b3_assert( 3 === count( $validators ), 'Me must register exactly three local producer payload validators.' );
Faluss_Federation_Crypto::$transport_ready = true;
an01b3_assert( true === Faluss_Link_Events_Runtime::register_runtime(), 'Me route must resume after Federation becomes ready.' );
$routes = an01b3_get( 'Faluss_Events_Engine', 'routes' );
an01b3_assert( 1 === count( $routes ) && $me_route === reset( $routes ), 'Me runtime must register exactly its Federation Analytics route.' );
an01b3_assert( true === Faluss_Link_Events_Runtime::register_runtime() && 3 === count( an01b3_get( 'Faluss_Events', 'payload_validators' ) ) && 1 === count( an01b3_get( 'Faluss_Events_Engine', 'routes' ) ), 'Repeated Me hooks must not duplicate validators or route.' );
an01b3_assert( Faluss_Link_Events_Catalog::catalog() === Faluss_Events::resolve_local_catalog( 'me-node', 'faluss-me', 'faluss-me.events', '1.0.0' ), 'Me route must use the existing resolved and validated owner catalog.' );

$mapping = array(
    'faluss-me.card.viewed' => 'faluss-me.card-viewed',
    'faluss-me.link.clicked' => 'faluss-me.link-clicked',
    'faluss-me.collection.opened' => 'faluss-me.collection-opened',
);
foreach ( $mapping as $event_type => $document_type ) {
    $event = an01b3_event( $event_type, $document_type );
    an01b3_assert( 'registered' === Faluss_Events::payload_validator_state( $event ), 'Production Events registry must expose the validator for ' . $document_type . '.' );
    an01b3_assert( true === Faluss_Link_Events_Runtime::validate_payload( array(), $event['payload_contract'], $event ), 'Exact producer payload must pass for ' . $event_type . '.' );
    $permuted = $event;
    $permuted['source'] = array_reverse( $event['source'], true );
    $permuted['payload_contract'] = array_reverse( $event['payload_contract'], true );
    $contract = array_reverse( $event['payload_contract'], true );
    an01b3_assert( true === Faluss_Link_Events_Runtime::validate_payload( array(), $contract, $permuted ), 'Key order must not affect producer validation for ' . $event_type . '.' );
}

$valid = an01b3_event( 'faluss-me.link.clicked', 'faluss-me.link-clicked' );
$invalid_cases = array();
$case = $valid; $case['event_type'] = 'faluss-hub.app.opened'; $invalid_cases['Hub event'] = $case;
$case = $valid; $case['event_type'] = 'faluss-me.link-clicked'; $invalid_cases['event alias'] = $case;
$case = $valid; $case['source']['node_id'] = 'hub-node'; $invalid_cases['source node'] = $case;
$case = $valid; $case['source']['owner'] = 'other-app'; $invalid_cases['source owner'] = $case;
$case = $valid; $case['source']['extra'] = true; $invalid_cases['extra source key'] = $case;
$case = $valid; $case['payload_contract']['document_type'] = 'faluss-me.card-viewed'; $invalid_cases['type contract mismatch'] = $case;
$case = $valid; $case['payload_contract']['contract_version'] = '2.0.0'; $invalid_cases['contract version'] = $case;
$case = $valid; $case['payload_contract']['extra'] = true; $invalid_cases['extra contract key'] = $case;
foreach ( $invalid_cases as $label => $invalid ) {
    an01b3_assert( false === Faluss_Link_Events_Runtime::validate_payload( array(), $invalid['payload_contract'], $invalid ), 'Producer validator must refuse divergent ' . $label . '.' );
}
an01b3_assert( false === Faluss_Link_Events_Runtime::validate_payload( array( 'enriched' => true ), $valid['payload_contract'], $valid ), 'Producer validator must refuse enriched payloads.' );
an01b3_assert( false === Faluss_Link_Events_Runtime::validate_payload( 'not-an-array', $valid['payload_contract'], $valid ), 'Producer validator must refuse non-array payloads.' );
$missing_contract = $valid['payload_contract']; unset( $missing_contract['contract_version'] );
an01b3_assert( false === Faluss_Link_Events_Runtime::validate_payload( array(), $missing_contract, $valid ), 'Producer validator must refuse incomplete contracts.' );

an01b3_reset();
Faluss_Federation_Crypto::$identity = array( 'node_id' => 'me-node', 'app_key' => 'faluss-me', 'origin' => 'https://wrong.example' );
an01b3_assert( false === Faluss_Link_Events_Runtime::register_runtime() && array() === an01b3_get( 'Faluss_Events', 'payload_validators' ) && array() === an01b3_get( 'Faluss_Events_Engine', 'routes' ), 'Wrong Me identity must register neither validators nor route.' );

an01b3_reset();
Faluss_Federation_Crypto::$identity = array( 'node_id' => 'me-node', 'app_key' => 'faluss-me', 'origin' => 'https://faluss.me' );
an01b3_assert( true === Faluss_Events::register_payload_validator( 'faluss-me.card-viewed', '1.0.0', function () { return true; } ), 'Validator collision precondition must register the first tuple.' );
an01b3_assert( false === Faluss_Link_Events_Runtime::register_runtime() && true === Faluss_Link_Events_Runtime::runtime_state()['conflict'] && array() === an01b3_get( 'Faluss_Events_Engine', 'routes' ), 'Foreign or partial validator registration must fail closed before the route.' );

an01b3_reset();
Faluss_Federation_Crypto::$identity = array( 'node_id' => 'me-node', 'app_key' => 'faluss-me', 'origin' => 'https://faluss.me' );
an01b3_assert( true === Faluss_Events_Engine::register_delivery_route( $me_route ), 'Me collision precondition must register the exact route tuple.' );
an01b3_assert( false === Faluss_Link_Events_Runtime::register_runtime() && true === Faluss_Link_Events_Runtime::runtime_state()['conflict'], 'A foreign Me route collision must fail closed.' );

$runtime_files = array(
    $root . '/src/Portal/LegacyPortalEventsRuntime.php',
    $root . '/src/Link/LegacyLinkEventsRuntime.php',
    $root . '/src/Portal/PortalModule.php',
    $root . '/src/Link/LinkModule.php',
);
$runtime_source = '';
foreach ( $runtime_files as $file ) { $runtime_source .= file_get_contents( $file ); }
foreach ( array( 'accept_registered_local_catalog', 'refresh_remote_catalog', 'accept_local_event', 'accept_inbound_event', 'event_publish', 'event_catalog_read', 'update_peer_policy' ) as $forbidden ) {
    an01b3_assert( false === strpos( $runtime_source, $forbidden ), 'AN-01B.3 runtime must not call forbidden mechanism: ' . $forbidden );
}
an01b3_assert( false === strpos( file_get_contents( $runtime_files[0] ), 'register_payload_validator' ), 'Portal must register no payload validator.' );
an01b3_assert( false === strpos( file_get_contents( $runtime_files[1] ), 'Faluss_Analytics' ), 'Link producer runtime must not import, copy or call Analytics.' );
an01b3_assert( 0 === $GLOBALS['an01b3_network'] && 0 === $GLOBALS['wpdb']->queries && 0 === $GLOBALS['an01b3_uuids'], 'Registration must perform no network, SQL or UUID generation.' );

echo 'AN-01B.3 closed Platform producer/runtime contracts: ' . $an01b3_assertions . '/' . $an01b3_assertions . " assertions passed; no real WordPress/MariaDB/Federation recipe.\n";
