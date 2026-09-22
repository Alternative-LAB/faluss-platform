<?php

$fed01c_assertions = 0;

function fed01c_assert( $condition, $message ) {
    global $fed01c_assertions;
    $fed01c_assertions++;
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
function absint( $value ) { return abs( (int) $value ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return is_scalar( $value ) ? trim( (string) $value ) : ''; }
function sanitize_key( $value ) { return is_string( $value ) ? preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ) : ''; }
function esc_html__( $value ) { return $value; }
function __( $value ) { return $value; }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return (string) $value; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }
function current_user_can( $capability ) { return 'manage_options' === $capability; }
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $action ) . '">'; }

final class Faluss_Federation_Crypto {
    public static function is_node( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-z][a-z0-9-]{1,63}$/D', $value ); }
    public static function is_key_id( $value ) { return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{7,127}$/D', $value ); }
    public static function is_canonical_origin( $value ) { return is_string( $value ) && 0 === strpos( $value, 'https://' ); }
    public static function base64url_decode( $value, $expected_length ) { return 'valid-public-key' === $value && 32 === $expected_length ? str_repeat( 'k', 32 ) : new WP_Error( 'invalid_key' ); }
    public static function format_utc_timestamp( $timestamp ) { return gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ); }
    public static function response_canonical( $response, $status, $raw ) { return 'canonical-response'; }
    public static function sign( $canonical ) { return str_repeat( 's', 86 ); }
}

final class Faluss_Federation_Schema {
    public static $audits = array();
    public static function is_ready() { return true; }
    public static function peers_table() { return 'wp_faluss_federation_peers'; }
    public static function quote_identifier( $identifier ) { return '`' . $identifier . '`'; }
    public static function audit( $data ) { self::$audits[] = $data; return true; }
}

final class FED01C_WPDB {
    public $last_error = '';
    public $rows = array();
    public $queries = array();
    public $updates = array();
    public $insert_count = 0;
    public $rollback_count = 0;
    public $commit_count = 0;
    public $start_count = 0;
    private $scenario;
    private $snapshot = null;

    public function __construct( $rows, $scenario = 'success' ) {
        $this->rows = array_values( $rows );
        $this->scenario = $scenario;
    }

    public function prepare( $query ) {
        $arguments = func_get_args();
        array_shift( $arguments );
        foreach ( $arguments as $argument ) {
            if ( false !== strpos( $query, '%d' ) ) {
                $query = preg_replace( '/%d/', (string) (int) $argument, $query, 1 );
            } else {
                $replacement = "'" . str_replace( "'", "''", (string) $argument ) . "'";
                $query = preg_replace( '/%s/', $replacement, $query, 1 );
            }
        }
        return $query;
    }

    public function query( $query ) {
        $this->queries[] = $query;
        $this->last_error = '';
        if ( 'START TRANSACTION' === $query ) {
            $this->start_count++;
            if ( 'start_fail' === $this->scenario ) { $this->last_error = 'start failed'; return false; }
            $this->snapshot = $this->rows;
            return 1;
        }
        if ( 'ROLLBACK' === $query ) {
            $this->rollback_count++;
            if ( is_array( $this->snapshot ) ) { $this->rows = $this->snapshot; }
            $this->snapshot = null;
            return 1;
        }
        if ( 'COMMIT' === $query ) {
            $this->commit_count++;
            if ( 'commit_fail' === $this->scenario ) { $this->last_error = 'commit failed'; return false; }
            if ( 'commit_last_error' === $this->scenario ) { $this->last_error = 'commit ambiguous'; return 1; }
            $this->snapshot = null;
            return 1;
        }
        return 1;
    }

    public function get_results( $query, $format = ARRAY_A ) {
        $this->last_error = '';
        if ( false !== strpos( $query, 'FOR UPDATE' ) ) {
            if ( 'read_error' === $this->scenario ) { $this->last_error = 'read failed'; return null; }
            if ( 'read_last_error' === $this->scenario ) { $this->last_error = 'read ambiguous'; return $this->rows; }
            if ( 'absent' === $this->scenario ) { return array(); }
            if ( 'ambiguous' === $this->scenario ) {
                $duplicate = $this->rows[0];
                $duplicate['id'] = (int) $duplicate['id'] + 1;
                return array( $this->rows[0], $duplicate );
            }
            if ( 1 === preg_match( '/WHERE id = ([0-9]+)/', $query, $match ) ) {
                return array_values( array_filter( $this->rows, static function ( $row ) use ( $match ) { return (int) $row['id'] === (int) $match[1]; } ) );
            }
        }
        return $this->rows;
    }

    public function get_row( $query, $format = ARRAY_A ) {
        $this->last_error = '';
        return empty( $this->rows ) ? null : $this->rows[0];
    }

    public function update( $table, $data, $where, $formats, $where_formats ) {
        $this->last_error = '';
        $this->updates[] = array( 'table' => $table, 'data' => $data, 'where' => $where, 'formats' => $formats, 'where_formats' => $where_formats );
        if ( 'update_false' === $this->scenario ) { $this->last_error = 'update failed'; return false; }
        if ( 'update_zero' === $this->scenario ) { return 0; }
        foreach ( $this->rows as &$row ) {
            if ( (int) $row['id'] === (int) $where['id'] ) { $row = array_merge( $row, $data ); }
        }
        unset( $row );
        if ( 'update_last_error' === $this->scenario ) { $this->last_error = 'update ambiguous'; }
        return 1;
    }

    public function insert() { $this->insert_count++; return 1; }
}

function fed01c_row( $state = 'active', $expired = false ) {
    return array(
        'id' => 7,
        'peer_node_id' => 'hub-node',
        'peer_app_key' => 'faluss-hub',
        'canonical_origin' => 'https://faluss.com',
        'key_id' => 'hub-key-0001',
        'public_key' => 'valid-public-key',
        'key_state' => $state,
        'valid_from' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
        'valid_until' => gmdate( 'Y-m-d H:i:s', $expired ? time() - 1 : time() + 3600 ),
        'operations_json' => '["diagnostic.read"]',
        'owner_apps_json' => '[]',
        'capabilities_json' => '[]',
        'audiences_json' => '[]',
        'created_at' => '2026-09-13 00:00:00',
        'updated_at' => '2026-09-13 00:00:00',
        'revoked_at' => null,
    );
}

function fed01c_policy_input() {
    return array(
        'operations' => array( 'manifest.read', 'diagnostic.read' ),
        'owner_apps' => array( 'faluss-me' ),
        'capabilities' => array(),
        'audiences' => array(),
    );
}

function fed01c_revision() {
    $summaries = Faluss_Federation_Policy::peer_summaries();
    fed01c_assert( 1 === count( $summaries ) && isset( $summaries[0]['policy_revision'] ), 'Peer summary must expose one server-derived policy revision.' );
    return $summaries[0]['policy_revision'];
}

function fed01c_error_code( $value ) { return is_wp_error( $value ) ? $value->get_error_code() : null; }

function fed01c_private( $class, $method, $arguments = array() ) {
    $reflection = new ReflectionMethod( $class, $method );
    $reflection->setAccessible( true );
    return $reflection->invokeArgs( null, $arguments );
}

function fed01c_method_source( $class, $method, $path ) {
    $reflection = new ReflectionMethod( $class, $method );
    $lines = file( $path );
    return implode( '', array_slice( $lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1 ) );
}

function fed01c_response_body( $response ) {
    fed01c_assert( $response instanceof WP_REST_Response, 'Signed provider result must return a REST response.' );
    $data = $response->get_data();
    $body = is_array( $data ) && isset( $data['_faluss_federation_raw_json'] ) ? json_decode( $data['_faluss_federation_raw_json'], true ) : null;
    fed01c_assert( is_array( $body ), 'Signed provider result must contain decodable pre-serialized JSON.' );
    return $body;
}

$source_root = getenv( 'FALUSS_FEDERATION_SOURCE_ROOT' );
$source_root = is_string( $source_root ) && '' !== $source_root ? rtrim( $source_root, '/\\' ) : dirname( __DIR__, 3 );
$includes = $source_root . '/src/Federation/Legacy/includes/';
$policy_path = $includes . 'class-faluss-federation-policy.php';
$admin_path = $includes . 'class-faluss-federation-admin.php';
$server_path = $includes . 'class-faluss-federation-server.php';
$bootstrap_path = $source_root . '/src/Federation/Legacy/faluss-federation.php';
foreach ( array( $policy_path, $admin_path, $server_path, $bootstrap_path ) as $path ) { fed01c_assert( is_file( $path ), 'Required production artifact must exist: ' . $path ); }
require_once $policy_path;
require_once $includes . 'class-faluss-federation-providers.php';
require_once $server_path;
require_once $admin_path;

$expect_base_blocker = in_array( '--expect-base-blocker', $argv, true );
if ( $expect_base_blocker ) {
    fed01c_assert( ! method_exists( 'Faluss_Federation_Policy', 'update_peer_policy' ), 'Base 2e6fa43 must lack the safe peer-policy mutation primitive.' );
    fed01c_assert( false === strpos( file_get_contents( $admin_path ), 'METTRE A JOUR LA POLITIQUE FEDERATION' ), 'Base 2e6fa43 must lack the dedicated confirmed administration action.' );
    echo 'FED-01C base 2e6fa43 gap reproduced (' . $fed01c_assertions . ' assertions)' . PHP_EOL;
    exit( 0 );
}

fed01c_assert( method_exists( 'Faluss_Federation_Policy', 'update_peer_policy' ), 'Production Policy must expose update_peer_policy().' );

global $wpdb;
$wpdb = new FED01C_WPDB( array( fed01c_row() ) );
Faluss_Federation_Schema::$audits = array();
$original_row = $wpdb->rows[0];
$original_identity = array_intersect_key( $original_row, array_flip( array( 'id', 'peer_node_id', 'peer_app_key', 'canonical_origin', 'key_id', 'public_key', 'valid_from', 'valid_until', 'key_state', 'created_at', 'revoked_at' ) ) );
$original_revision = fed01c_revision();
$result = Faluss_Federation_Policy::update_peer_policy( 7, $original_revision, fed01c_policy_input() );
fed01c_assert( 'updated' === $result, 'Policy update must report a confirmed modification; got ' . ( is_wp_error( $result ) ? $result->get_error_code() : var_export( $result, true ) ) . '.' );
fed01c_assert( 1 === count( $wpdb->rows ) && 7 === (int) $wpdb->rows[0]['id'], 'Policy update must preserve row count and internal identifier.' );
$updated_identity = array_intersect_key( $wpdb->rows[0], $original_identity );
fed01c_assert( $original_identity === $updated_identity, 'Peer identity, origin, key, key_id, period and state must remain byte-for-byte unchanged.' );
fed01c_assert( '["diagnostic.read","manifest.read"]' === $wpdb->rows[0]['operations_json'] && '["faluss-me"]' === $wpdb->rows[0]['owner_apps_json'], 'Operations and owner applications must be deterministically sorted and stored.' );
fed01c_assert( '[]' === $wpdb->rows[0]['capabilities_json'] && '[]' === $wpdb->rows[0]['audiences_json'], 'Empty capabilities and audiences must remain exact empty lists.' );
fed01c_assert( 1 === count( $wpdb->updates ), 'A changed policy must perform exactly one UPDATE.' );
$updated_columns = array_keys( $wpdb->updates[0]['data'] );
sort( $updated_columns, SORT_STRING );
fed01c_assert( array( 'audiences_json', 'capabilities_json', 'operations_json', 'owner_apps_json', 'updated_at' ) === $updated_columns, 'UPDATE must target only four policy columns and updated_at.' );
fed01c_assert( array( 'id' => 7 ) === $wpdb->updates[0]['where'] && array( '%d' ) === $wpdb->updates[0]['where_formats'], 'UPDATE must target the same server-selected internal identifier.' );
fed01c_assert( 0 === $wpdb->insert_count && 'active' === $wpdb->rows[0]['key_state'], 'Policy update must not insert, revoke or rotate a peer.' );
fed01c_assert( array( array( 'result_code' => 'peer_policy_updated', 'opaque_code' => 'admin' ) ) === Faluss_Federation_Schema::$audits, 'Confirmed change must emit only the minimal peer_policy_updated audit.' );

$new_revision = fed01c_revision();
fed01c_assert( $new_revision !== $original_revision && 1 === preg_match( '/^[a-f0-9]{64}$/D', $new_revision ), 'Policy change must produce a new opaque SHA-256 revision.' );
$before_retry_updates = count( $wpdb->updates );
$before_retry_audits = count( Faluss_Federation_Schema::$audits );
$retry = Faluss_Federation_Policy::update_peer_policy( 7, $new_revision, fed01c_policy_input() );
fed01c_assert( 'unchanged' === $retry, 'Identical policy retry with the current revision must report unchanged.' );
fed01c_assert( $before_retry_updates === count( $wpdb->updates ) && $before_retry_audits === count( Faluss_Federation_Schema::$audits ), 'Idempotent retry must not rewrite or audit the same policy.' );

$before_stale_row = $wpdb->rows[0];
$before_stale_updates = count( $wpdb->updates );
$before_stale_rollbacks = $wpdb->rollback_count;
$stale_input = fed01c_policy_input();
$stale_input['operations'] = array( 'diagnostic.read' );
$stale = Faluss_Federation_Policy::update_peer_policy( 7, $original_revision, $stale_input );
fed01c_assert( 'faluss_federation_stale_peer_policy' === fed01c_error_code( $stale ), 'Obsolete policy revision must fail with stale_peer_policy.' );
fed01c_assert( $before_stale_row === $wpdb->rows[0] && $before_stale_updates === count( $wpdb->updates ), 'Stale revision must perform no write.' );
fed01c_assert( $before_stale_rollbacks + 1 === $wpdb->rollback_count, 'Stale revision must roll back its locked transaction.' );

$invalid_inputs = array();
$extra = fed01c_policy_input(); $extra['key_id'] = 'forbidden'; $invalid_inputs['extra field'] = $extra;
$wildcard = fed01c_policy_input(); $wildcard['owner_apps'] = array( '*' ); $invalid_inputs['wildcard'] = $wildcard;
$unknown = fed01c_policy_input(); $unknown['operations'] = array( 'diagnostic.read', 'unknown.read' ); $invalid_inputs['unknown operation'] = $unknown;
$nonscalar = fed01c_policy_input(); $nonscalar['owner_apps'] = array( array( 'faluss-me' ) ); $invalid_inputs['non-scalar'] = $nonscalar;
$duplicate = fed01c_policy_input(); $duplicate['operations'] = array( 'diagnostic.read', 'diagnostic.read' ); $invalid_inputs['duplicate'] = $duplicate;
$empty_value = fed01c_policy_input(); $empty_value['owner_apps'] = array( '' ); $invalid_inputs['empty value'] = $empty_value;
$json = fed01c_policy_input(); $json['owner_apps'] = '["faluss-me"]'; $invalid_inputs['browser JSON'] = $json;
foreach ( $invalid_inputs as $label => $invalid_input ) {
    $invalid_db = new FED01C_WPDB( array( fed01c_row() ) );
    $wpdb = $invalid_db;
    $invalid_result = Faluss_Federation_Policy::update_peer_policy( 7, str_repeat( 'a', 64 ), $invalid_input );
    fed01c_assert( 'faluss_federation_invalid_peer_policy' === fed01c_error_code( $invalid_result ), 'Invalid policy input must be refused before transaction: ' . $label );
    fed01c_assert( 0 === $invalid_db->start_count && 0 === count( $invalid_db->updates ), 'Invalid input must perform no database mutation: ' . $label );
}

$baseline_db = new FED01C_WPDB( array( fed01c_row() ) );
$wpdb = $baseline_db;
$baseline_revision = fed01c_revision();
foreach ( array( 'absent', 'revoked', 'expired' ) as $case ) {
    $row = 'revoked' === $case ? fed01c_row( 'revoked' ) : fed01c_row( 'active', 'expired' === $case );
    $case_db = new FED01C_WPDB( array( $row ), 'absent' === $case ? 'absent' : 'success' );
    $wpdb = $case_db;
    $case_result = Faluss_Federation_Policy::update_peer_policy( 7, $baseline_revision, fed01c_policy_input() );
    fed01c_assert( 'faluss_federation_peer_policy_refused' === fed01c_error_code( $case_result ), 'Absent, revoked or expired peer must be refused: ' . $case );
    fed01c_assert( 0 === count( $case_db->updates ) && 1 === $case_db->rollback_count, 'Ineligible peer must not be written and must roll back: ' . $case );
}

$rotating_db = new FED01C_WPDB( array( fed01c_row( 'rotating' ) ) );
$wpdb = $rotating_db;
$rotating_revision = fed01c_revision();
fed01c_assert( 'updated' === Faluss_Federation_Policy::update_peer_policy( 7, $rotating_revision, fed01c_policy_input() ), 'A usable rotating peer policy must be editable.' );
fed01c_assert( 'rotating' === $rotating_db->rows[0]['key_state'], 'Editing a rotating peer must not change its state.' );

foreach ( array( 'start_fail', 'read_error', 'read_last_error', 'ambiguous', 'update_false', 'update_zero', 'update_last_error', 'commit_fail', 'commit_last_error' ) as $scenario ) {
    $fault_db = new FED01C_WPDB( array( fed01c_row() ), $scenario );
    $wpdb = $fault_db;
    Faluss_Federation_Schema::$audits = array();
    $fault_result = Faluss_Federation_Policy::update_peer_policy( 7, $baseline_revision, fed01c_policy_input() );
    fed01c_assert( 'faluss_federation_fail_closed' === fed01c_error_code( $fault_result ), 'Transaction fault must fail closed: ' . $scenario );
    fed01c_assert( 1 === $fault_db->rollback_count, 'Transaction fault must attempt exactly one rollback: ' . $scenario );
    fed01c_assert( fed01c_row() === $fault_db->rows[0] && array() === Faluss_Federation_Schema::$audits, 'Fault must preserve the original row and emit no audit: ' . $scenario );
}

$wpdb = new FED01C_WPDB( array( $updated_identity + array(
    'operations_json' => '["diagnostic.read","manifest.read"]',
    'owner_apps_json' => '["faluss-me"]',
    'capabilities_json' => '[]',
    'audiences_json' => '[]',
    'updated_at' => gmdate( 'Y-m-d H:i:s' ),
) ) );
$peer = Faluss_Federation_Policy::find_peer( 'hub-node', 'faluss-hub', 'hub-key-0001' );
fed01c_assert( is_array( $peer ), 'Updated peer must remain usable.' );
$identity = array( 'node_id' => 'me-node', 'app_key' => 'faluss-me', 'key_id' => 'me-key-0001' );
$diagnostic_request = array( 'operation' => 'diagnostic.read', 'sender' => array( 'node_id' => 'hub-node', 'app_key' => 'faluss-hub', 'key_id' => 'hub-key-0001' ), 'recipient' => array( 'node_id' => 'me-node', 'app_key' => 'faluss-me' ), 'subject_context' => null, 'parameters' => array() );
$manifest_request = array( 'request_id' => '11111111-1111-4111-8111-111111111111', 'operation' => 'manifest.read', 'sender' => array( 'node_id' => 'hub-node', 'app_key' => 'faluss-hub', 'key_id' => 'hub-key-0001' ), 'recipient' => array( 'node_id' => 'me-node', 'app_key' => 'faluss-me' ), 'subject_context' => null, 'parameters' => array( 'app_key' => 'faluss-me' ) );
fed01c_assert( true === Faluss_Federation_Policy::allow_incoming( $diagnostic_request, $peer, $identity ), 'diagnostic.read must remain authorized after policy update.' );
fed01c_assert( true === Faluss_Federation_Policy::allow_incoming( $manifest_request, $peer, $identity ), 'manifest.read must become authorized by the updated local policy.' );
$provider_result = Faluss_Federation_Providers::dispatch( $manifest_request, $identity );
fed01c_assert( 'not_available' === $provider_result['status'], 'Missing CAP-01B provider must remain not_available.' );
Faluss_Federation_Schema::$audits = array();
$response = fed01c_private( 'Faluss_Federation_Server', 'authenticated_response', array( $provider_result, $manifest_request, str_repeat( 'b', 64 ), $identity, microtime( true ) ) );
$response_body = fed01c_response_body( $response );
fed01c_assert( 404 === $response->get_status() && 'not_available' === $response_body['status'], 'Missing provider must remain a signed closed 404 response.' );
fed01c_assert( 86 === strlen( $response->get_headers()['X-Faluss-Federation-Signature'] ?? '' ), 'Missing-provider response must remain signed.' );

$policy_source = fed01c_method_source( 'Faluss_Federation_Policy', 'update_peer_policy_transaction', $policy_path );
fed01c_assert( false === strpos( $policy_source, '$wpdb->insert' ) && false === strpos( $policy_source, 'create_peer(' ) && false === strpos( $policy_source, 'revoke_peer(' ), 'Policy update primitive must never create, replace or revoke a peer.' );
$bootstrap = file_get_contents( $bootstrap_path );
fed01c_assert( false !== strpos( $bootstrap, 'Version: 0.3.0' ) && false !== strpos( $bootstrap, "FALUSS_FEDERATION_SCHEMA_VERSION', '1'" ), 'Federation must be 0.3.0 with schema 1.' );
fed01c_assert( false === strpos( $bootstrap, 'update_peer_policy' ) && false !== strpos( $bootstrap, "register_activation_hook( __FILE__, array( 'Faluss_Federation_Schema', 'activate' ) )" ), 'Activation and update bootstrap must never mutate peer policy.' );

$wpdb = new FED01C_WPDB( array( $updated_identity + array(
    'operations_json' => '["diagnostic.read","manifest.read"]',
    'owner_apps_json' => '["faluss-me"]',
    'capabilities_json' => '[]',
    'audiences_json' => '[]',
    'updated_at' => gmdate( 'Y-m-d H:i:s' ),
) ) );
ob_start();
fed01c_private( 'Faluss_Federation_Admin', 'render_peer_list' );
$html = ob_get_clean();
$action_position = strpos( $html, 'value="update_peer_policy"' );
$form_start = false === $action_position ? false : strrpos( substr( $html, 0, $action_position ), '<form' );
$form_end = false === $action_position ? false : strpos( $html, '</form>', $action_position );
$policy_form = false !== $form_start && false !== $form_end ? substr( $html, $form_start, $form_end - $form_start + 7 ) : '';
fed01c_assert( '' !== $policy_form && false !== strpos( $policy_form, 'method="post"' ) && false !== strpos( $policy_form, 'faluss_federation_update_policy' ), 'Active peer must expose a distinct POST form with its dedicated nonce.' );
fed01c_assert( false !== strpos( $policy_form, 'name="peer_id" value="7"' ) && false !== strpos( $policy_form, 'name="policy_revision"' ), 'Policy form must carry only server-derived target and revision metadata.' );
fed01c_assert( false !== strpos( $policy_form, 'METTRE A JOUR LA POLITIQUE FEDERATION' ), 'Policy form must display the exact confirmation phrase.' );
fed01c_assert( false !== strpos( $policy_form, 'value="diagnostic.read" checked') && false !== strpos( $policy_form, 'value="manifest.read" checked') && false !== strpos( $policy_form, 'name="owner_apps" value="faluss-me"' ), 'Policy form must display the four current policy lists.' );
foreach ( array( 'peer_node_id', 'peer_app_key', 'canonical_origin', 'key_id', 'public_key', 'valid_from', 'valid_until', 'key_state' ) as $forbidden_name ) {
    fed01c_assert( false === strpos( $policy_form, 'name="' . $forbidden_name . '"' ), 'Policy form must not submit immutable peer field ' . $forbidden_name . '.' );
}

$_POST = array(
    'action' => 'faluss_federation_manage',
    '_wpnonce' => 'nonce',
    '_wp_http_referer' => '/wp-admin/tools.php?page=faluss-federation',
    'faluss_federation_action' => 'update_peer_policy',
    'peer_id' => '7',
    'policy_revision' => $new_revision,
    'operations' => array( 'diagnostic.read', 'manifest.read' ),
    'owner_apps' => 'faluss-me',
    'capabilities' => '',
    'confirmation' => 'METTRE A JOUR LA POLITIQUE FEDERATION',
);
fed01c_assert( true === fed01c_private( 'Faluss_Federation_Admin', 'update_policy_post_is_exact' ), 'Exact policy POST field set must be accepted.' );
$admin_input = fed01c_private( 'Faluss_Federation_Admin', 'policy_input_from_post' );
fed01c_assert( array( 'operations' => array( 'diagnostic.read', 'manifest.read' ), 'owner_apps' => array( 'faluss-me' ), 'capabilities' => array(), 'audiences' => array() ) === $admin_input, 'Administration must parse only the four policy lists without JSON.' );
$_POST['peer_id'] = array( '7' );
fed01c_assert( false === fed01c_private( 'Faluss_Federation_Admin', 'update_policy_post_is_exact' ), 'Non-scalar target metadata must be refused.' );
$_POST['peer_id'] = '7';
$_POST['key_id'] = 'forbidden';
fed01c_assert( false === fed01c_private( 'Faluss_Federation_Admin', 'update_policy_post_is_exact' ), 'Additional browser field must be refused.' );

$handle_source = fed01c_method_source( 'Faluss_Federation_Admin', 'handle_post', $admin_path );
$capability_position = strpos( $handle_source, "current_user_can( 'manage_options' )" );
$nonce_position = strpos( $handle_source, "check_admin_referer( 'faluss_federation_update_policy' )" );
$mutation_position = strpos( $handle_source, 'Faluss_Federation_Policy::update_peer_policy' );
fed01c_assert( false !== $capability_position && false !== $nonce_position && false !== $mutation_position && $capability_position < $nonce_position && $nonce_position < $mutation_position, 'Capability and dedicated nonce must be verified before the policy transaction.' );

echo 'FED-01C peer policy mutation: OK (' . $fed01c_assertions . ' assertions; production Policy/Admin/Providers/Server + controlled wpdb)' . PHP_EOL;
