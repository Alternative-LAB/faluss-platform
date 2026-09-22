<?php

$fed01b6_assertions = 0;

function fed01b6_assert( $condition, $message ) {
    global $fed01b6_assertions;
    $fed01b6_assertions++;
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
        exit( 1 );
    }
}

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

class WP_Error {
    private $code;
    public function __construct( $code ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}

function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }

final class Faluss_Federation_Schema {
    public static function is_ready() { return true; }
    public static function peers_table() { return 'wp_faluss_federation_peers'; }
    public static function quote_identifier( $identifier ) { return '`' . $identifier . '`'; }
}

final class Faluss_Federation_Crypto {
    public static $decode_calls = 0;

    public static function is_node( $value ) { return is_string( $value ) && '' !== $value; }
    public static function is_key_id( $value ) { return is_string( $value ) && strlen( $value ) >= 8; }
    public static function is_canonical_origin( $value ) { return is_string( $value ) && 0 === strpos( $value, 'https://' ); }
    public static function base64url_decode( $value, $expected_length ) {
        self::$decode_calls++;
        return 'valid-public-key' === $value && 32 === $expected_length ? str_repeat( 'k', 32 ) : new WP_Error( 'invalid_key' );
    }
}

final class FED01B6_WPDB {
    public $last_error = '';
    public $prepare_calls = 0;
    public $get_results_calls = 0;
    public $query_template = '';
    public $prepare_arguments = array();
    public $prepared_query = '';
    public $syntax_error = false;
    private $rows;
    private $scenario;

    public function __construct( $rows, $scenario = 'success' ) {
        $this->rows = $rows;
        $this->scenario = $scenario;
    }

    public function prepare( $query ) {
        $arguments = func_get_args();
        array_shift( $arguments );
        $this->prepare_calls++;
        $this->query_template = $query;
        $this->prepare_arguments = $arguments;
        $argument_index = 0;
        $this->prepared_query = preg_replace_callback(
            '/%s/',
            static function () use ( &$argument_index, $arguments ) {
                if ( ! array_key_exists( $argument_index, $arguments ) ) {
                    return '%s';
                }
                $value = "'" . str_replace( "'", "''", (string) $arguments[ $argument_index ] ) . "'";
                $argument_index++;
                return $value;
            },
            $query
        );
        return $this->prepared_query;
    }

    public function get_results( $query, $format ) {
        $this->get_results_calls++;
        $this->prepared_query = $query;
        if ( false !== strpos( $query, '\"active\"' ) || false !== strpos( $query, '\"rotating\"' ) ) {
            $this->syntax_error = true;
            $this->last_error = 'Synthetic MariaDB syntax error near escaped state literal.';
            return false;
        }
        if ( 'sql_error' === $this->scenario ) {
            $this->last_error = 'Synthetic SQL failure.';
            return false;
        }
        if ( 'last_error' === $this->scenario ) {
            $this->last_error = 'Synthetic stale database error.';
            return $this->rows;
        }
        $this->last_error = '';
        $active = $this->prepare_arguments[2] ?? null;
        $rotating = $this->prepare_arguments[3] ?? null;
        $rows = $this->rows;
        usort(
            $rows,
            static function ( $left, $right ) use ( $active, $rotating ) {
                $priority = static function ( $state ) use ( $active, $rotating ) {
                    return $state === $active ? 0 : ( $state === $rotating ? 1 : 2 );
                };
                $comparison = $priority( $left['key_state'] ) <=> $priority( $right['key_state'] );
                return 0 !== $comparison ? $comparison : ( (int) $right['id'] <=> (int) $left['id'] );
            }
        );
        return $rows;
    }
}

function fed01b6_peer_row( $state, $id, $usable = true ) {
    return array(
        'id' => $id,
        'peer_node_id' => 'me-node',
        'peer_app_key' => 'me-app',
        'canonical_origin' => 'https://peer.example',
        'key_id' => 'peer-key-' . str_pad( (string) $id, 4, '0', STR_PAD_LEFT ),
        'public_key' => 'valid-public-key',
        'key_state' => $state,
        'valid_from' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
        'valid_until' => gmdate( 'Y-m-d H:i:s', $usable ? time() + 3600 : time() - 1 ),
        'operations_json' => '["diagnostic.read"]',
        'owner_apps_json' => '[]',
        'capabilities_json' => '[]',
        'audiences_json' => '["private"]',
    );
}

$source_root = getenv( 'FALUSS_FEDERATION_SOURCE_ROOT' );
$source_root = is_string( $source_root ) && '' !== $source_root ? rtrim( $source_root, '/\\' ) : dirname( __DIR__, 3 );
$policy_path = $source_root . '/src/Federation/Legacy/includes/class-faluss-federation-policy.php';
fed01b6_assert( is_file( $policy_path ), 'Production Policy class must exist.' );
require_once $policy_path;

$expect_base_blocker = in_array( '--expect-base-blocker', $argv, true );
$wpdb = new FED01B6_WPDB( array( fed01b6_peer_row( 'rotating', 20 ), fed01b6_peer_row( 'active', 10 ) ) );
$selected = Faluss_Federation_Policy::find_outbound_peer( 'me-node', 'me-app' );

if ( $expect_base_blocker ) {
    fed01b6_assert( $wpdb->syntax_error, 'Base 379f569 must reproduce the escaped-state MariaDB syntax blocker.' );
    fed01b6_assert( is_wp_error( $selected ), 'Base 379f569 must stop before selecting an outbound peer.' );
    fed01b6_assert( false !== strpos( $wpdb->prepared_query, '\"active\"' ) && false !== strpos( $wpdb->prepared_query, '\"rotating\"' ), 'Base query must expose both malformed escaped literals.' );
    echo 'FED-01B.6 base 379f569 blocker reproduced (' . $fed01b6_assertions . ' assertions)' . PHP_EOL;
    exit( 0 );
}

fed01b6_assert( ! $wpdb->syntax_error, 'Escaped state literals must never reach the SQL query.' );
fed01b6_assert( ! is_wp_error( $selected ) && 'active' === $selected['key_state'], 'An active peer must be selected before a rotating peer.' );
fed01b6_assert( 1 === $wpdb->prepare_calls && 1 === $wpdb->get_results_calls, 'Production selection must prepare and execute exactly one query.' );
fed01b6_assert( 4 === substr_count( $wpdb->query_template, '%s' ), 'Outbound selection must use four string placeholders.' );
fed01b6_assert( array( 'me-node', 'me-app', 'active', 'rotating' ) === $wpdb->prepare_arguments, 'Prepared arguments must be node, app, active and rotating in exact order.' );
fed01b6_assert( false === strpos( $wpdb->query_template, '\"active\"' ) && false === strpos( $wpdb->query_template, '\"rotating\"' ), 'Query template must contain no escaped state literal.' );
fed01b6_assert( 0 === preg_match( '/"[^"\r\n]*"/', $wpdb->query_template ), 'Query template must contain no double-quoted SQL literal.' );
fed01b6_assert( false !== strpos( $wpdb->prepared_query, "WHEN 'active' THEN 0" ) && false !== strpos( $wpdb->prepared_query, "WHEN 'rotating' THEN 1" ), 'Prepared SQL must retain active then rotating priority.' );

Faluss_Federation_Crypto::$decode_calls = 0;
$wpdb = new FED01B6_WPDB( array( fed01b6_peer_row( 'rotating', 20 ), fed01b6_peer_row( 'active', 10, false ) ) );
$fallback = Faluss_Federation_Policy::find_outbound_peer( 'me-node', 'me-app' );
fed01b6_assert( ! is_wp_error( $fallback ) && 'rotating' === $fallback['key_state'], 'A rotating peer must be the fallback when the active peer is unusable.' );
fed01b6_assert( 2 === Faluss_Federation_Crypto::$decode_calls, 'Fallback must validate the unusable active row before the rotating row.' );

Faluss_Federation_Crypto::$decode_calls = 0;
$wpdb = new FED01B6_WPDB( array( fed01b6_peer_row( 'active', 10 ) ), 'sql_error' );
$sql_error = Faluss_Federation_Policy::find_outbound_peer( 'me-node', 'me-app' );
fed01b6_assert( is_wp_error( $sql_error ) && 'faluss_federation_unknown_peer' === $sql_error->get_error_code(), 'A SQL read failure must return WP_Error.' );
fed01b6_assert( 0 === Faluss_Federation_Crypto::$decode_calls, 'A SQL read failure must stop before peer normalization.' );

Faluss_Federation_Crypto::$decode_calls = 0;
$wpdb = new FED01B6_WPDB( array( fed01b6_peer_row( 'active', 10 ) ), 'last_error' );
$last_error = Faluss_Federation_Policy::find_outbound_peer( 'me-node', 'me-app' );
fed01b6_assert( is_wp_error( $last_error ) && 'faluss_federation_unknown_peer' === $last_error->get_error_code(), 'A non-empty wpdb last_error must return WP_Error.' );
fed01b6_assert( 0 === Faluss_Federation_Crypto::$decode_calls, 'A non-empty wpdb last_error must stop before peer normalization.' );

echo 'FED-01B.6 outbound peer SQL regression: OK (' . $fed01b6_assertions . ' assertions; production Policy + controlled wpdb)' . PHP_EOL;
