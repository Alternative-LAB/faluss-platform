<?php

/** AN-01B.1 production Analytics engine regression with a transactional wpdb harness. */
ob_start();
require __DIR__ . '/faluss-analytics-an01a-contract-test.php';
$an01b1_an01a_output = ob_get_clean();

if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! class_exists( 'WP_Error' ) ) {
    final class WP_Error {
        private $code;
        public function __construct( $code ) { $this->code = $code; }
        public function get_error_code() { return $this->code; }
    }
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $value ) { return $value instanceof WP_Error; } }
if ( ! function_exists( 'wp_generate_uuid4' ) ) { function wp_generate_uuid4() { $GLOBALS['an01b1_uuid'] = ( $GLOBALS['an01b1_uuid'] ?? 0 ) + 1; return sprintf( '90000000-0000-4000-8000-%012x', $GLOBALS['an01b1_uuid'] ); } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['an01b1_options'] ) ? $GLOBALS['an01b1_options'][ $name ] : $default; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $name, $value, $autoload = null ) { unset( $autoload ); $GLOBALS['an01b1_options'][ $name ] = $value; return true; } }
if ( ! function_exists( 'wp_die' ) ) { function wp_die( $message ) { throw new RuntimeException( $message ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $message, $domain ) { unset( $domain ); return $message; } }
if ( ! function_exists( 'add_action' ) ) { function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['an01b1_actions'][ $hook ][ $priority ][] = $callback; } }
if ( ! function_exists( 'wp_next_scheduled' ) ) { function wp_next_scheduled( $hook ) { return $GLOBALS['an01b1_cron'][ $hook ] ?? false; } }
if ( ! function_exists( 'wp_schedule_event' ) ) { function wp_schedule_event( $time, $schedule, $hook ) { $GLOBALS['an01b1_cron'][ $hook ] = array( $time, $schedule ); return true; } }
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) { function wp_clear_scheduled_hook( $hook ) { $GLOBALS['an01b1_cleared'][] = $hook; unset( $GLOBALS['an01b1_cron'][ $hook ] ); return 1; } }

$an01b1_assertions = 0;
function an01b1_assert( $condition, $message ) {
    global $an01b1_assertions;
    $an01b1_assertions++;
    if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); }
}
function an01b1_error( $value ) { return is_wp_error( $value ) ? $value->get_error_code() : null; }
function an01b1_private( $class, $method, $arguments = array() ) { $method = new ReflectionMethod( $class, $method ); $method->setAccessible( true ); return $method->invokeArgs( null, $arguments ); }
function an01b1_reset_static( $class, $values ) { foreach ( $values as $name => $value ) { $property = new ReflectionProperty( $class, $name ); $property->setAccessible( true ); $property->setValue( null, $value ); } }

final class AN01B1_WPDB {
    public $prefix = 'wp_';
    public $last_error = '';
    public $insert_id = 0;
    public $ddl = array();
    public $rows = array();
    public $queries = array();
    public $held_locks = array();
    public $fail_create_at = null;
    public $create_attempts = 0;
    public $fail_stage = null;
    public $uncertain_commit = false;
    public $release_failure = false;
    private $snapshot = null;

    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
    public function esc_like( $value ) { return addcslashes( $value, '_%\\' ); }
    public function prepare( $query, ...$arguments ) {
        $index = 0;
        return preg_replace_callback( '/%[sd]/', function ( $match ) use ( $arguments, &$index ) {
            $value = $arguments[ $index++ ];
            return '%d' === $match[0] ? (string) (int) $value : "'" . str_replace( "'", "''", (string) $value ) . "'";
        }, $query );
    }

    public function get_var( $query ) {
        $this->begin( $query );
        if ( 0 === strpos( $query, 'SELECT GET_LOCK(' ) ) {
            if ( ! preg_match( "/SELECT GET_LOCK\('([^']+)'/", $query, $matches ) || isset( $this->held_locks[ $matches[1] ] ) ) { return 0; }
            $this->held_locks[ $matches[1] ] = true; return 1;
        }
        if ( 0 === strpos( $query, 'SELECT RELEASE_LOCK(' ) ) {
            if ( $this->release_failure ) { $this->release_failure = false; return 0; }
            if ( ! preg_match( "/SELECT RELEASE_LOCK\('([^']+)'/", $query, $matches ) || ! isset( $this->held_locks[ $matches[1] ] ) ) { return 0; }
            unset( $this->held_locks[ $matches[1] ] ); return 1;
        }
        if ( preg_match( "/^SHOW TABLES LIKE '((?:''|[^'])+)'$/", $query, $matches ) ) {
            $table = preg_replace( '/\\\\([_%\\\\])/', '$1', str_replace( "''", "'", $matches[1] ) );
            return array_key_exists( $table, $this->ddl ) ? $table : null;
        }
        if ( preg_match( '/^SELECT COUNT\(\*\) FROM `([^`]+)`$/', $query, $matches ) ) { return count( $this->rows[ $matches[1] ] ?? array() ); }
        if ( preg_match( '/^SELECT metric_value FROM `([^`]+)` WHERE /', $query, $matches ) ) {
            foreach ( $this->rows[ $matches[1] ] ?? array() as $row ) { if ( $this->matches( $query, $row, array_keys( array_intersect_key( $row, array_flip( array( 'subject_identity_sha256', 'aggregate_date', 'metric_key', 'object_type', 'object_reference' ) ) ) ) ) ) { return $row['metric_value']; } }
            return null;
        }
        return null;
    }

    public function query( $query ) {
        $this->begin( $query );
        if ( in_array( $query, array( 'START TRANSACTION', 'START TRANSACTION WITH CONSISTENT SNAPSHOT' ), true ) ) {
            if ( 'start' === $this->fail_stage ) { return $this->fail( 'start' ); }
            $this->snapshot = serialize( array( $this->rows, $this->insert_id ) ); return 1;
        }
        if ( 'ROLLBACK' === $query ) {
            if ( null !== $this->snapshot ) { list( $this->rows, $this->insert_id ) = unserialize( $this->snapshot ); }
            $this->snapshot = null; return 1;
        }
        if ( 'COMMIT' === $query ) {
            if ( $this->uncertain_commit ) { $this->uncertain_commit = false; $this->snapshot = null; return $this->fail( 'uncertain commit' ); }
            if ( 'commit' === $this->fail_stage ) { return $this->fail( 'commit' ); }
            $this->snapshot = null; return 1;
        }
        if ( preg_match( '/^CREATE TABLE `([^`]+)` /', $query, $matches ) ) {
            $this->create_attempts++;
            if ( $this->fail_create_at === $this->create_attempts ) { return $this->fail( 'create' ); }
            if ( isset( $this->ddl[ $matches[1] ] ) ) { return $this->fail( 'exists' ); }
            $this->ddl[ $matches[1] ] = $query; $this->rows[ $matches[1] ] = array(); return 1;
        }
        if ( 0 === strpos( $query, 'RENAME TABLE ' ) ) {
            preg_match_all( '/`([^`]+)` TO `([^`]+)`/', $query, $pairs, PREG_SET_ORDER );
            if ( 3 !== count( $pairs ) ) { return $this->fail( 'rename' ); }
            foreach ( $pairs as $pair ) { if ( ! isset( $this->ddl[ $pair[1] ] ) || isset( $this->ddl[ $pair[2] ] ) ) { return $this->fail( 'rename collision' ); } }
            foreach ( $pairs as $pair ) { $this->ddl[ $pair[2] ] = $this->ddl[ $pair[1] ]; $this->rows[ $pair[2] ] = $this->rows[ $pair[1] ]; unset( $this->ddl[ $pair[1] ], $this->rows[ $pair[1] ] ); } return 1;
        }
        if ( preg_match( '/^DROP TABLE `([^`]+)`$/', $query, $matches ) ) { unset( $this->ddl[ $matches[1] ], $this->rows[ $matches[1] ] ); return 1; }
        if ( preg_match( '/^INSERT INTO `([^`]+)` \(([^)]+)\) VALUES \((.+)\) ON DUPLICATE KEY UPDATE /', $query, $matches ) ) {
            $stage = false !== strpos( $matches[1], 'daily_objects' ) ? 'object' : 'metric';
            if ( $stage === $this->fail_stage ) { return $this->fail( $stage ); }
            $fields = explode( ',', $matches[2] ); $values = $this->sql_values( $matches[3] );
            if ( count( $fields ) !== count( $values ) ) { return $this->fail( 'values' ); }
            $data = array_combine( $fields, $values );
            $keys = 'object' === $stage ? array( 'subject_identity_sha256', 'aggregate_date', 'object_type', 'object_reference' ) : array( 'subject_identity_sha256', 'aggregate_date', 'metric_key' );
            foreach ( $this->rows[ $matches[1] ] as &$row ) {
                if ( $this->same_fields( $row, $data, $keys ) ) { $row['metric_value'] = (int) $row['metric_value'] + 1; $row['updated_at'] = $data['updated_at']; unset( $row ); return 2; }
            }
            unset( $row ); $this->insert_id++; $data['metric_value'] = (int) $data['metric_value']; $this->rows[ $matches[1] ][] = array( 'id' => $this->insert_id ) + $data; return 1;
        }
        return 1;
    }

    public function get_row( $query, $output = null ) {
        unset( $output ); $this->begin( $query );
        if ( preg_match( "/^SHOW TABLE STATUS LIKE '((?:''|[^'])+)'$/", $query, $matches ) ) {
            $table = preg_replace( '/\\\\([_%\\\\])/', '$1', str_replace( "''", "'", $matches[1] ) );
            return isset( $this->ddl[ $table ] ) ? array( 'Name' => $table, 'Engine' => 'InnoDB', 'Collation' => 'utf8mb4_unicode_ci' ) : null;
        }
        if ( preg_match( "/^SELECT event_id,event_sha256,retention_until,envelope_json FROM `([^`]+)` WHERE event_id = '([^']+)' LIMIT 1$/", $query, $matches ) ) {
            foreach ( $this->rows[ $matches[1] ] ?? array() as $row ) {
                if ( $matches[2] === ( $row['event_id'] ?? null ) ) { return $row; }
            }
        }
        return null;
    }

    public function get_results( $query, $output = null ) {
        unset( $output ); $this->begin( $query );
        if ( 'read' === $this->fail_stage && 0 === strpos( $query, 'SELECT ' ) ) { $this->fail_stage = null; $this->last_error = 'injected read'; return null; }
        if ( preg_match( '/^SHOW FULL COLUMNS FROM `([^`]+)`$/', $query, $matches ) ) { return $this->ddl_columns( $this->ddl[ $matches[1] ] ?? '' ); }
        if ( preg_match( '/^SHOW INDEX FROM `([^`]+)`$/', $query, $matches ) ) { return $this->ddl_indexes( $this->ddl[ $matches[1] ] ?? '' ); }
        if ( preg_match( '/^SELECT event_id,idempotency_sha256,event_sha256,subject_identity_sha256 FROM `([^`]+)` WHERE /', $query, $matches ) ) {
            $found = array(); foreach ( $this->rows[ $matches[1] ] ?? array() as $row ) { if ( $this->matches_any( $query, $row, array( 'event_id', 'idempotency_sha256' ) ) ) { $found[] = $row; } } return $found;
        }
        if ( preg_match( '/^SELECT aggregate_date,metric_key,metric_value FROM `([^`]+)` WHERE /', $query, $matches ) ) {
            $found = $this->period_rows( $matches[1], $query ); usort( $found, function ( $a, $b ) { return array( $a['aggregate_date'], $a['metric_key'] ) <=> array( $b['aggregate_date'], $b['metric_key'] ); } ); return $found;
        }
        if ( preg_match( '/^SELECT object_type,object_reference,SUM\(metric_value\) AS metric_value FROM `([^`]+)` WHERE /', $query, $matches ) ) {
            $grouped = array(); foreach ( $this->period_rows( $matches[1], $query ) as $row ) { $key = $row['object_type'] . "\x1F" . $row['object_reference']; if ( ! isset( $grouped[ $key ] ) ) { $grouped[ $key ] = array( 'object_type' => $row['object_type'], 'object_reference' => $row['object_reference'], 'metric_value' => 0 ); } $grouped[ $key ]['metric_value'] += (int) $row['metric_value']; }
            $found = array_values( $grouped ); usort( $found, function ( $a, $b ) { return array( -(int) $a['metric_value'], $a['object_type'], $a['object_reference'] ) <=> array( -(int) $b['metric_value'], $b['object_type'], $b['object_reference'] ); } ); return array_slice( $found, 0, 200 );
        }
        if ( preg_match( '/^SELECT id FROM `([^`]+)` WHERE (expires_at|aggregate_date) (?:<=|<) \'([^\']+)\' ORDER BY id ASC LIMIT 50 FOR UPDATE$/', $query, $matches ) ) {
            $found = array(); foreach ( $this->rows[ $matches[1] ] ?? array() as $row ) { if ( ( $row[ $matches[2] ] ?? '' ) <= $matches[3] ) { $found[] = array( 'id' => $row['id'] ); } } usort( $found, function ( $a, $b ) { return $a['id'] <=> $b['id']; } ); return array_slice( $found, 0, 50 );
        }
        return array();
    }

    public function insert( $table, $data, $formats = null ) {
        unset( $formats ); $this->last_error = '';
        if ( 'receipt' === $this->fail_stage ) { return $this->fail( 'receipt' ); }
        foreach ( $this->rows[ $table ] ?? array() as $row ) { if ( $row['receipt_uuid'] === $data['receipt_uuid'] || $row['event_id'] === $data['event_id'] || $row['idempotency_sha256'] === $data['idempotency_sha256'] ) { return $this->fail( 'duplicate' ); } }
        $this->insert_id++; $this->rows[ $table ][] = array( 'id' => $this->insert_id ) + $data; return 1;
    }

    public function delete( $table, $where, $formats = null ) {
        unset( $formats ); $this->last_error = '';
        $stage = false !== strpos( $table, 'receipts' ) ? 'delete_receipt' : ( false !== strpos( $table, 'daily_metrics' ) ? 'delete_metric' : 'delete_object' );
        if ( $stage === $this->fail_stage ) { return $this->fail( $stage ); }
        $kept = array(); $deleted = 0; foreach ( $this->rows[ $table ] ?? array() as $row ) { if ( $this->same_fields( $row, $where, array_keys( $where ) ) ) { $deleted++; } else { $kept[] = $row; } } $this->rows[ $table ] = $kept; return $deleted;
    }

    private function begin( $query ) { $this->last_error = ''; $this->queries[] = $query; }
    private function fail( $message ) { $this->last_error = 'injected ' . $message; $this->fail_stage = null; return false; }
    private function value( $query, $field ) { return preg_match( "/(?:^|[ (])" . preg_quote( $field, '/' ) . " = '((?:''|[^'])*)'/", $query, $matches ) ? str_replace( "''", "'", $matches[1] ) : null; }
    private function matches( $query, $row, $fields ) { foreach ( $fields as $field ) { $value = $this->value( $query, $field ); if ( null === $value || (string) ( $row[ $field ] ?? '' ) !== $value ) { return false; } } return true; }
    private function matches_any( $query, $row, $fields ) { foreach ( $fields as $field ) { $value = $this->value( $query, $field ); if ( null !== $value && (string) ( $row[ $field ] ?? '' ) === $value ) { return true; } } return false; }
    private function same_fields( $left, $right, $fields ) { foreach ( $fields as $field ) { if ( (string) ( $left[ $field ] ?? '' ) !== (string) ( $right[ $field ] ?? '' ) ) { return false; } } return true; }
    private function period_rows( $table, $query ) { preg_match( "/subject_identity_sha256 = '([^']+)' AND aggregate_date >= '([^']+)' AND aggregate_date <= '([^']+)'/", $query, $matches ); return array_values( array_filter( $this->rows[ $table ] ?? array(), function ( $row ) use ( $matches ) { return isset( $matches[1]) && $row['subject_identity_sha256'] === $matches[1] && $row['aggregate_date'] >= $matches[2] && $row['aggregate_date'] <= $matches[3]; } ) ); }
    private function sql_values( $list ) { preg_match_all( "/'(?:''|[^'])*'|[0-9]+/", $list, $matches ); return array_map( function ( $value ) { return "'" === substr( $value, 0, 1 ) ? str_replace( "''", "'", substr( $value, 1, -1 ) ) : (int) $value; }, $matches[0] ); }
    private function ddl_parts( $ddl ) { if ( ! preg_match( '/^[^(]+\((.*)\) ENGINE=InnoDB /', $ddl, $matches ) ) { return array(); } $parts = array(); $part = ''; $depth = 0; foreach ( str_split( $matches[1] ) as $character ) { if ( '(' === $character ) { $depth++; } elseif ( ')' === $character ) { $depth--; } if ( ',' === $character && 0 === $depth ) { $parts[] = $part; $part = ''; } else { $part .= $character; } } if ( '' !== $part ) { $parts[] = $part; } return $parts; }
    private function ddl_columns( $ddl ) { $columns = array(); foreach ( $this->ddl_parts( $ddl ) as $part ) { if ( preg_match( '/^`([^`]+)` (.+?) (NOT NULL|NULL)(?: AUTO_INCREMENT)?$/', $part, $matches ) ) { $textual = 1 === preg_match( '/^(?:char|varchar)(?:\(|$)/', strtolower( $matches[2] ) ); $columns[] = array( 'Field' => $matches[1], 'Type' => $matches[2], 'Null' => 'NOT NULL' === $matches[3] ? 'NO' : 'YES', 'Default' => null, 'Extra' => false !== strpos( $part, 'AUTO_INCREMENT' ) ? 'auto_increment' : '', 'Collation' => $textual ? 'utf8mb4_unicode_ci' : null ); } } return $columns; }
    private function ddl_indexes( $ddl ) { $indexes = array(); foreach ( $this->ddl_parts( $ddl ) as $part ) { $name = null; $non_unique = 1; if ( preg_match( '/^PRIMARY KEY \((.+)\)$/', $part, $matches ) ) { $name = 'PRIMARY'; $non_unique = 0; } elseif ( preg_match( '/^UNIQUE KEY `([^`]+)` \((.+)\)$/', $part, $matches ) ) { $name = $matches[1]; $non_unique = 0; } elseif ( preg_match( '/^KEY `([^`]+)` \((.+)\)$/', $part, $matches ) ) { $name = $matches[1]; } if ( null === $name ) { continue; } preg_match_all( '/`([^`]+)`/', $matches[ count( $matches ) - 1 ], $columns ); foreach ( $columns[1] as $index => $column ) { $indexes[] = array( 'Key_name' => $name, 'Seq_in_index' => $index + 1, 'Column_name' => $column, 'Non_unique' => $non_unique, 'Sub_part' => null, 'Index_type' => 'BTREE' ); } } return $indexes; }
}

final class Faluss_Federation_Crypto {
    public static $identity = array( 'node_id' => 'hub-node', 'app_key' => 'faluss-hub', 'origin' => 'https://faluss.com' );
    public static function local_identity() { return self::$identity; }
}

final class Faluss_Events_Schema {
    public static function is_ready() { return true; }
    public static function events_table() { return 'wp_faluss_events'; }
    public static function quote_identifier( $identifier ) { return '`' . str_replace( '`', '``', (string) $identifier ) . '`'; }
}

$root = dirname( __DIR__, 3 );
require_once $root . '/src/Events/Legacy/includes/class-faluss-events-canonicalizer.php';
require_once $root . '/src/Events/Legacy/includes/class-faluss-events.php';
require_once $root . '/src/Events/Legacy/includes/class-faluss-events-engine.php';
foreach ( array( 'schema', 'event-validator', 'consumer', 'read-model', 'retention', '' ) as $part ) {
    $path = '' === $part ? $root . '/src/Analytics/Legacy/includes/class-faluss-analytics.php' : $root . '/src/Analytics/Legacy/includes/class-faluss-analytics-' . $part . '.php';
    require_once $path;
}

function an01b1_reference_times() {
    static $times = null;
    if ( null === $times ) {
        $date = gmdate( 'Y-m-d' );
        $occurred = strtotime( $date . ' 12:00:00 UTC' );
        $times = array( 'occurred_at' => gmdate( 'Y-m-d\TH:i:s\Z', $occurred ), 'produced_at' => gmdate( 'Y-m-d\TH:i:s\Z', $occurred + 1800 ) );
    }
    return $times;
}
function an01b1_event( $type, $event_id, $subject ) {
    $definition = Faluss_Analytics_Event_Validator::definition( $type );
    $anonymous = 'anonymous' === $definition['actor_type'];
    $times = an01b1_reference_times();
    $event = array(
        'contract_version' => '1.0.0', 'event_id' => $event_id, 'event_type' => $type, 'event_version' => '1.0.0',
        'source' => array( 'node_id' => $definition['node_id'], 'app_key' => $definition['app_key'], 'owner' => $definition['app_key'], 'capability_key' => $definition['capability_key'], 'catalog_version' => '1.0.0' ),
        'source_event_reference' => 'an01_event_' . hash( 'sha256', $type . $event_id ), 'occurred_at' => $times['occurred_at'], 'produced_at' => $times['produced_at'],
        'subject_context' => array( 'subject_type' => 'faluss_member', 'subject_faluss_id' => $subject ),
        'actor_context' => $anonymous ? array( 'actor_type' => 'anonymous', 'actor_faluss_id' => null, 'anonymous_reference' => null, 'anonymous_scope' => null ) : array( 'actor_type' => 'member', 'actor_faluss_id' => $subject, 'anonymous_reference' => null, 'anonymous_scope' => null ),
        'object_context' => null, 'destinations' => array( 'analytics.events' ),
        'payload_contract' => array( 'document_type' => $definition['document_type'], 'contract_version' => '1.0.0' ),
        'payload' => 'faluss-hub.portal.viewed' === $type ? array( 'surface_key' => 'apps' ) : array(),
    );
    if ( null !== $definition['object_type'] ) { $event['object_context'] = array( 'object_type' => $definition['object_type'], 'object_reference' => 'an01_' . $definition['object_type'] . '_' . hash( 'sha256', $type . ':object' ) ); }
    return $event;
}
function an01b1_context( $event, $delivery = 1 ) {
    $destination = 'analytics.events'; $consumer = 'faluss-analytics.aggregate-v1'; $event_id = $event['event_id'];
    return array( 'event' => $event, 'delivery_uuid' => sprintf( 'a0000000-0000-4000-8000-%012x', $delivery ), 'destination' => $destination, 'consumer_key' => $consumer, 'attempt' => 1, 'idempotency_key' => 'faluss-event-consumer:' . hash( 'sha256', strlen( $event_id ) . ':' . $event_id . ';' . strlen( $destination ) . ':' . $destination . ';' . strlen( $consumer ) . ':' . $consumer ) );
}
function an01b1_install_db() { global $wpdb; $GLOBALS['an01b1_options'] = array(); $wpdb = new AN01B1_WPDB(); an01b1_assert( true === Faluss_Analytics_Schema::install(), 'Controlled Analytics schema installation must succeed.' ); return $wpdb; }

/* Strict fresh installation, option timing, no seed and divergent-schema refusal. */
$failed = new AN01B1_WPDB(); $failed->fail_create_at = 2; $wpdb = $failed; $GLOBALS['an01b1_options'] = array();
an01b1_assert( false === Faluss_Analytics_Schema::install() && ! array_key_exists( Faluss_Analytics_Schema::OPTION, $GLOBALS['an01b1_options'] ), 'Schema option must not be written after an incomplete installation.' );
an01b1_assert( array() === array_filter( array_keys( $failed->ddl ), function ( $table ) { return false !== strpos( $table, 'faluss_analytics_' ); } ), 'Failed atomic installation must remove temporary Analytics tables.' );
$wpdb = an01b1_install_db();
an01b1_assert( '1' === get_option( Faluss_Analytics_Schema::OPTION ) && 3 === count( $wpdb->ddl ) && array() === array_filter( array_map( 'count', $wpdb->rows ) ), 'Fresh schema must expose exactly three empty tables and no seed.' );
$wpdb->ddl['wp_faluss_analytics_receipts'] = str_replace( '`event_sha256` char(64)', '`event_sha256` char(63)', $wpdb->ddl['wp_faluss_analytics_receipts'] );
an01b1_assert( false === Faluss_Analytics_Schema::is_ready() && false === Faluss_Analytics_Schema::install(), 'A divergent existing structure must be refused without repair.' );
$wpdb = an01b1_install_db();

/* Hub-only, idempotent registration through the actual Events registries. */
an01b1_reset_static( 'Faluss_Events', array( 'payload_validators' => array(), 'payload_validator_conflict' => false ) );
an01b1_reset_static( 'Faluss_Events_Engine', array( 'consumers' => array(), 'consumer_conflict' => false ) );
an01b1_reset_static( 'Faluss_Analytics', array( 'validators_registered' => false, 'consumer_registered' => false, 'runtime_conflict' => false ) );
Faluss_Federation_Crypto::$identity = array( 'node_id' => 'me-node', 'app_key' => 'faluss-me', 'origin' => 'https://faluss.me' );
an01b1_assert( false === Faluss_Analytics::register_runtime(), 'Analytics runtime must remain closed outside the exact Hub identity.' );
$events_validators = new ReflectionProperty( 'Faluss_Events', 'payload_validators' ); $events_validators->setAccessible( true );
$events_consumers = new ReflectionProperty( 'Faluss_Events_Engine', 'consumers' ); $events_consumers->setAccessible( true );
an01b1_assert( array() === $events_validators->getValue() && array() === $events_consumers->getValue(), 'Wrong-node loading must register neither validator nor consumer.' );
Faluss_Federation_Crypto::$identity = array( 'node_id' => 'hub-node', 'app_key' => 'faluss-hub', 'origin' => 'https://other.example' );
an01b1_assert( false === Faluss_Analytics::register_runtime(), 'The exact Hub node and app must still reject a divergent canonical origin.' );
Faluss_Federation_Crypto::$identity = array( 'node_id' => 'hub-node', 'app_key' => 'faluss-hub', 'origin' => 'https://faluss.com' );
Faluss_Events::register_payload_validator( 'faluss-hub.portal-viewed', '1.0.0', function () { return true; } );
an01b1_assert( false === Faluss_Analytics::register_runtime() && true === Faluss_Analytics::runtime_state()['conflict'], 'A real payload-validator collision must close the Analytics runtime.' );
an01b1_reset_static( 'Faluss_Events', array( 'payload_validators' => array(), 'payload_validator_conflict' => false ) );
an01b1_reset_static( 'Faluss_Events_Engine', array( 'consumers' => array(), 'consumer_conflict' => false ) );
an01b1_reset_static( 'Faluss_Analytics', array( 'validators_registered' => false, 'consumer_registered' => false, 'runtime_conflict' => false ) );
an01b1_assert( true === Faluss_Analytics::register_runtime() && true === Faluss_Analytics::register_runtime(), 'Exact Hub registration must be successful and idempotent.' );
an01b1_assert( 6 === count( $events_validators->getValue() ) && 1 === count( $events_consumers->getValue() ), 'Exactly six payload validators and one consumer must be registered.' );
$descriptor = Faluss_Analytics::consumer_descriptor();
an01b1_assert( array( 'consumer_key', 'destination', 'target_node_id', 'target_app_key', 'sources', 'callback' ) === array_keys( $descriptor ) && 'faluss-analytics.aggregate-v1' === $descriptor['consumer_key'] && 'analytics.events' === $descriptor['destination'] && 'hub-node' === $descriptor['target_node_id'] && 'faluss-hub' === $descriptor['target_app_key'], 'Consumer descriptor root must be exact.' );
an01b1_assert( array( array( 'node_id' => 'hub-node', 'app_key' => 'faluss-hub', 'capability_key' => 'faluss-hub.events', 'catalog_version' => '1.0.0' ), array( 'node_id' => 'me-node', 'app_key' => 'faluss-me', 'capability_key' => 'faluss-me.events', 'catalog_version' => '1.0.0' ) ) === $descriptor['sources'] && false === strpos( serialize( $descriptor['sources'] ), '*' ), 'Consumer must expose only the two exact sources without wildcard.' );

/* Six exact mappings and closed event semantics. */
$subject = '11111111-1111-4111-8111-111111111111';
$types = array( 'faluss-hub.portal.viewed', 'faluss-hub.app.opened', 'faluss-hub.daily-reward.claimed', 'faluss-me.card.viewed', 'faluss-me.link.clicked', 'faluss-me.collection.opened' );
$events = array();
foreach ( $types as $index => $type ) {
    $events[ $type ] = an01b1_event( $type, sprintf( 'b0000000-0000-4000-8000-%012x', $index + 1 ), $subject );
    an01b1_assert( Faluss_Analytics_Event_Validator::validate_event( $events[ $type ] ), 'Production Analytics validator must accept exact event: ' . $type );
}
$times = an01b1_reference_times();
an01b1_assert( '12:00:00' === substr( $times['occurred_at'], 11, 8 ) && 1800 === strtotime( $times['produced_at'] ) - strtotime( $times['occurred_at'] ), 'All test events must use one UTC-date-stable reference after 01:00 with a valid deterministic delay.' );
$invalid = $events['faluss-hub.portal.viewed']; $invalid['actor_context']['actor_faluss_id'] = '22222222-2222-4222-8222-222222222222';
an01b1_assert( ! Faluss_Analytics_Event_Validator::validate_event( $invalid ), 'Hub actor must equal its member subject.' );
$invalid = $events['faluss-me.card.viewed']; $invalid['actor_context']['anonymous_reference'] = 'visitor_reference';
an01b1_assert( ! Faluss_Analytics_Event_Validator::validate_event( $invalid ), 'Me anonymous actor must carry no reference or scope.' );
$invalid = $events['faluss-me.link.clicked']; $invalid['object_context']['object_reference'] = 'an01:link:' . str_repeat( 'a', 64 );
an01b1_assert( ! Faluss_Analytics_Event_Validator::validate_event( $invalid ), 'Legacy or non-opaque object forms must be rejected.' );
$invalid = $events['faluss-hub.portal.viewed']; $invalid['produced_at'] = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $invalid['occurred_at'] ) + 3601 );
an01b1_assert( ! Faluss_Analytics_Event_Validator::validate_event( $invalid ), 'Analytics consumer must preserve the one-hour delivery bound of the six AN-01 definitions.' );
$invalid = $events['faluss-hub.portal.viewed']; $invalid['payload']['extra'] = true;
$before_invalid = serialize( $wpdb->rows );
an01b1_assert( 'faluss_events_permanent' === an01b1_error( Faluss_Analytics_Consumer::consume( an01b1_context( $invalid ) ) ) && $before_invalid === serialize( $wpdb->rows ), 'Invalid events must be permanently rejected before any write.' );

/* Actual Events canonical storage round-trip for all three anonymous Me events. */
$wpdb = an01b1_install_db();
$wpdb->rows['wp_faluss_events'] = array();
$me_types = array( 'faluss-me.card.viewed', 'faluss-me.link.clicked', 'faluss-me.collection.opened' );
$roundtrip_results = array();
foreach ( $me_types as $index => $type ) {
    $canonical = Faluss_Events_Canonicalizer::canonicalize_event( $events[ $type ] );
    $decoded = is_string( $canonical ) ? json_decode( $canonical, true ) : null;
    an01b1_assert( is_string( $canonical ) && is_array( $decoded ) && array( 'actor_faluss_id', 'actor_type', 'anonymous_reference', 'anonymous_scope' ) === array_keys( $decoded['actor_context'] ), 'Events canonical round-trip must reproduce its sorted anonymous actor order: ' . $type );
    $wpdb->rows['wp_faluss_events'][] = array( 'event_id' => $decoded['event_id'], 'event_sha256' => hash( 'sha256', $canonical ), 'retention_until' => '2099-12-31 23:59:59', 'envelope_json' => $canonical );
    $record = Faluss_Events_Engine::event_record_by_id( $decoded['event_id'] );
    an01b1_assert( is_array( $record ) && $decoded === $record['event'] && Faluss_Analytics_Event_Validator::validate_event( $record['event'] ), 'Production event_record_by_id round-trip must remain valid for Analytics: ' . $type );
    $context = an01b1_context( $record['event'], 70 + $index );
    $roundtrip_results[] = Faluss_Analytics_Consumer::consume( $context );
    $before_permutation = serialize( $wpdb->rows );
    $permuted = $record['event'];
    $permuted['actor_context'] = array( 'anonymous_scope' => null, 'actor_type' => 'anonymous', 'anonymous_reference' => null, 'actor_faluss_id' => null );
    an01b1_assert( Faluss_Analytics_Event_Validator::validate_event( $permuted ) && true === Faluss_Analytics_Consumer::consume( an01b1_context( $permuted, 80 + $index ) ) && $before_permutation === serialize( $wpdb->rows ), 'Anonymous actor key permutation must preserve validation and exact-once aggregation: ' . $type );
}
an01b1_assert( array( true, true, true ) === $roundtrip_results && 3 === count( $wpdb->rows['wp_faluss_analytics_receipts'] ) && 3 === count( $wpdb->rows['wp_faluss_analytics_daily_metrics'] ) && 2 === count( $wpdb->rows['wp_faluss_analytics_daily_objects'] ), 'Three valid canonical Me round-trips must aggregate exactly once without a permanent result.' );
$anonymous_base = json_decode( Faluss_Events_Canonicalizer::canonicalize_event( $events['faluss-me.card.viewed'] ), true );
$anonymous_divergences = array(
    'actor_type' => array( 'actor_type' => 'system' ),
    'actor_faluss_id' => array( 'actor_faluss_id' => $subject ),
    'anonymous_reference' => array( 'anonymous_reference' => 'an01_event_' . str_repeat( 'a', 64 ), 'anonymous_scope' => 'request' ),
    'anonymous_scope' => array( 'anonymous_scope' => 'request' ),
);
foreach ( $anonymous_divergences as $field => $changes ) {
    $invalid = $anonymous_base;
    foreach ( $changes as $key => $value ) { $invalid['actor_context'][ $key ] = $value; }
    an01b1_assert( ! Faluss_Analytics_Event_Validator::validate_event( $invalid ), 'Divergent anonymous value must remain refused: ' . $field );
}
$invalid = $anonymous_base; unset( $invalid['actor_context']['anonymous_scope'] );
an01b1_assert( ! Faluss_Analytics_Event_Validator::validate_event( $invalid ), 'A missing anonymous actor key must remain refused.' );
$invalid = $anonymous_base; $invalid['actor_context']['extra'] = null;
an01b1_assert( ! Faluss_Analytics_Event_Validator::validate_event( $invalid ), 'An additional anonymous actor key must remain refused.' );
$wpdb = an01b1_install_db();

/* Idempotent aggregation, divergent retry, contention and no lost increment. */
$first = $events['faluss-me.link.clicked']; $first_context = an01b1_context( $first, 10 );
an01b1_assert( true === Faluss_Analytics_Consumer::consume( $first_context ), 'First exact consumer delivery must aggregate.' );
$accepted_metric = array_values( array_filter( $wpdb->rows['wp_faluss_analytics_daily_metrics'], function ( $row ) { return 'me.link.clicks' === $row['metric_key']; } ) );
an01b1_assert( 1 === count( $accepted_metric ) && 1 === $accepted_metric[0]['metric_value'], 'One accepted synthetic link event must produce exactly the expected me.link.clicks metric.' );
$first_receipt = $wpdb->rows['wp_faluss_analytics_receipts'][0];
an01b1_assert( Faluss_Analytics_Consumer::RECEIPT_SECONDS === strtotime( $first_receipt['expires_at'] . ' UTC' ) - strtotime( $first_receipt['processed_at'] . ' UTC' ), 'Analytics receipt retention must be exactly thirty days from acquired processing.' );
$after_first = serialize( $wpdb->rows );
an01b1_assert( true === Faluss_Analytics_Consumer::consume( $first_context ) && $after_first === serialize( $wpdb->rows ), 'Exact retry must return true without a second increment.' );
$divergent = $first; $divergent['produced_at'] = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $first['produced_at'] ) + 1 );
an01b1_assert( 'faluss_events_permanent' === an01b1_error( Faluss_Analytics_Consumer::consume( an01b1_context( $divergent, 11 ) ) ), 'Same event identity with a divergent canonical hash must fail permanently.' );
$event_lock = 'faluss_an_event_' . substr( hash( 'sha256', $first['event_id'] . "\x1F" . hash( 'sha256', $first_context['idempotency_key'] ) ), 0, 40 );
$wpdb->held_locks[ $event_lock ] = true;
an01b1_assert( 'faluss_events_retryable' === an01b1_error( Faluss_Analytics_Consumer::consume( $first_context ) ), 'Concurrent processing of the same event must fail retryably while its deterministic lock is held.' );
unset( $wpdb->held_locks[ $event_lock ] );
$second_link = an01b1_event( 'faluss-me.link.clicked', 'b1000000-0000-4000-8000-000000000001', $subject );
an01b1_assert( true === Faluss_Analytics_Consumer::consume( an01b1_context( $second_link, 12 ) ), 'A distinct event for the same metric must aggregate without losing the prior increment.' );
$link_metric = array_values( array_filter( $wpdb->rows['wp_faluss_analytics_daily_metrics'], function ( $row ) { return 'me.link.clicks' === $row['metric_key']; } ) );
an01b1_assert( 1 === count( $link_metric ) && 2 === $link_metric[0]['metric_value'], 'Atomic upsert must retain both distinct metric increments.' );

/* Roll back every consumer write boundary, then recover an uncertain commit by receipt. */
foreach ( array( 'metric', 'object', 'receipt', 'commit' ) as $stage ) {
    $fault_db = an01b1_install_db(); $fault_db->fail_stage = $stage;
    $fault_event = an01b1_event( 'faluss-me.link.clicked', 'b2000000-0000-4000-8000-' . substr( hash( 'sha256', $stage ), 0, 12 ), $subject );
    an01b1_assert( 'faluss_events_retryable' === an01b1_error( Faluss_Analytics_Consumer::consume( an01b1_context( $fault_event, 20 ) ) ) && array() === array_filter( array_map( 'count', $fault_db->rows ) ), 'Injected consumer failure must roll back every write: ' . $stage );
}
$wpdb = an01b1_install_db(); $uncertain = an01b1_event( 'faluss-hub.app.opened', 'b3000000-0000-4000-8000-000000000001', $subject ); $uncertain_context = an01b1_context( $uncertain, 30 ); $wpdb->uncertain_commit = true;
an01b1_assert( 'faluss_events_retryable' === an01b1_error( Faluss_Analytics_Consumer::consume( $uncertain_context ) ), 'An uncertain commit must ask Events for a retry.' );
an01b1_assert( true === Faluss_Analytics_Consumer::consume( $uncertain_context ) && 1 === count( $wpdb->rows['wp_faluss_analytics_receipts'] ) && 1 === $wpdb->rows['wp_faluss_analytics_daily_metrics'][0]['metric_value'], 'Retry after uncertain commit must resolve by receipt without a second effect.' );

/* Produce all six real aggregation shapes and validate the private summary contract. */
$wpdb = an01b1_install_db();
foreach ( $events as $index => $event ) { an01b1_assert( true === Faluss_Analytics_Consumer::consume( an01b1_context( $event, 40 + array_search( $index, $types, true ) ) ), 'Each AN-01 event must reach its exact aggregate mapping: ' . $index ); }
$today = gmdate( 'Y-m-d' );
$summary = Faluss_Analytics::summary( $subject, $today . 'T00:00:00Z', $today . 'T23:59:59Z' );
$summary_schema = json_decode( file_get_contents( $root . '/contracts/faluss-analytics-summary.schema.json' ), true );
an01b1_assert( 'ready' === $summary['status'] && an01a_summary_is_valid( $summary, $summary_schema ), 'Production read model must emit a ready analytics.summary conforming to the AN-01 schema semantics.' );
an01b1_assert( 6 === count( $summary['totals'] ) && 3 === count( $summary['breakdowns']['by_object_reference'] ) && array( 'status' => 'not_supported', 'total' => null, 'daily_series' => array() ) === $summary['unique_visitors'], 'Summary must expose six metrics, three opaque objects and no unique visitor inference.' );
$subject_hash = Faluss_Analytics_Consumer::subject_hash( $subject );
for ( $index = 0; $index < 205; $index++ ) { $wpdb->rows['wp_faluss_analytics_daily_objects'][] = array( 'id' => 6000 + $index, 'subject_identity_sha256' => $subject_hash, 'aggregate_date' => $today, 'object_type' => 'app', 'object_reference' => 'an01_app_' . hash( 'sha256', 'bounded-' . $index ), 'metric_value' => 1, 'created_at' => $today . ' 00:00:00', 'updated_at' => $today . ' 00:00:00' ); }
$bounded = Faluss_Analytics::summary( $subject, $today . 'T00:00:00Z', $today . 'T23:59:59Z' );
an01b1_assert( 'ready' === $bounded['status'] && 200 === count( $bounded['breakdowns']['by_object_reference'] ) && an01a_summary_is_valid( $bounded, $summary_schema ), 'Object breakdown must be deterministically bounded to 200 entries.' );
$empty = Faluss_Analytics::summary( '33333333-3333-4333-8333-333333333333', $today . 'T00:00:00Z', $today . 'T23:59:59Z' );
an01b1_assert( 'empty' === $empty['status'] && an01a_summary_is_valid( $empty, $summary_schema ) && array() === $empty['totals'], 'Empty subjects must not receive invented zero metrics.' );
$too_long = Faluss_Analytics::summary( $subject, '2020-01-01T00:00:00Z', '2023-01-01T00:00:00Z' );
an01b1_assert( 'not_supported' === $too_long['status'] && an01a_summary_is_valid( $too_long, $summary_schema ), 'Periods over 800 days must be refused with a closed contract status.' );
$requested_790 = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->sub( new DateInterval( 'P790D' ) )->format( 'Y-m-d\TH:i:s\Z' );
$clamped = Faluss_Analytics::summary( $subject, $requested_790, gmdate( 'Y-m-d\TH:i:s\Z' ) );
an01b1_assert( 'ready' === $clamped['status'] && $clamped['produced_period']['from'] > $clamped['requested_period']['from'] && an01a_summary_is_valid( $clamped, $summary_schema ), 'A supported request must never read before the retained 25-month window.' );
Faluss_Federation_Crypto::$identity = array( 'node_id' => 'me-node', 'app_key' => 'faluss-me', 'origin' => 'https://faluss.me' );
$unavailable = Faluss_Analytics::summary( $subject, $today . 'T00:00:00Z', $today . 'T23:59:59Z' );
an01b1_assert( 'unavailable' === $unavailable['status'] && an01a_summary_is_valid( $unavailable, $summary_schema ), 'Private summary must remain unavailable outside its exact Hub identity.' );
Faluss_Federation_Crypto::$identity = array( 'node_id' => 'hub-node', 'app_key' => 'faluss-hub', 'origin' => 'https://faluss.com' );
foreach ( array( 'faluss_id', 'event_id', 'event_hash', 'visitor_identity', 'url', 'ip_address', 'cookie', 'session', 'pf', 'score', 'billing' ) as $forbidden ) { an01b1_assert( false === strpos( wp_json_encode( $summary ), '"' . $forbidden . '"' ), 'Summary must omit forbidden data: ' . $forbidden ); }

/* Member deletion removes only aggregates; retained receipts block late recreation. */
$receipt_count = count( $wpdb->rows['wp_faluss_analytics_receipts'] );
foreach ( array( 'delete_metric', 'delete_object' ) as $stage ) { $delete_fault = clone $wpdb; $delete_fault->fail_stage = $stage; $before_delete = serialize( $delete_fault->rows ); $wpdb = $delete_fault; an01b1_assert( 'faluss_events_retryable' === an01b1_error( Faluss_Analytics::delete_subject( $subject ) ) && $before_delete === serialize( $delete_fault->rows ), 'Subject deletion must roll back on failure: ' . $stage ); }
$wpdb = $delete_fault = clone $delete_fault; $delete_fault->fail_stage = null;
an01b1_assert( true === Faluss_Analytics::delete_subject( $subject ) && array() === $wpdb->rows['wp_faluss_analytics_daily_metrics'] && array() === $wpdb->rows['wp_faluss_analytics_daily_objects'] && $receipt_count === count( $wpdb->rows['wp_faluss_analytics_receipts'] ), 'Subject deletion must remove both aggregate tables and retain receipts.' );
$late_retry = reset( $events );
an01b1_assert( true === Faluss_Analytics_Consumer::consume( an01b1_context( $late_retry, 99 ) ) && array() === $wpdb->rows['wp_faluss_analytics_daily_metrics'], 'A retained receipt must prevent a late retry from recreating deleted aggregates.' );

/* Retention, rollback and one Hub-only Cron hook. */
$old = gmdate( 'Y-m-d', strtotime( '-26 months' ) );
$wpdb->rows['wp_faluss_analytics_daily_metrics'][] = array( 'id' => 8001, 'subject_identity_sha256' => str_repeat( 'a', 64 ), 'aggregate_date' => $old, 'metric_key' => 'hub.portal.raw_views', 'metric_value' => 1, 'created_at' => $old . ' 00:00:00', 'updated_at' => $old . ' 00:00:00' );
$wpdb->rows['wp_faluss_analytics_daily_objects'][] = array( 'id' => 8002, 'subject_identity_sha256' => str_repeat( 'a', 64 ), 'aggregate_date' => $old, 'object_type' => 'app', 'object_reference' => 'an01_app_' . str_repeat( 'a', 64 ), 'metric_value' => 1, 'created_at' => $old . ' 00:00:00', 'updated_at' => $old . ' 00:00:00' );
$before_retention_receipts = count( $wpdb->rows['wp_faluss_analytics_receipts'] );
$wpdb->rows['wp_faluss_analytics_receipts'][0]['expires_at'] = gmdate( 'Y-m-d H:i:s', time() - 1 );
an01b1_assert( true === Faluss_Analytics_Retention::run() && $before_retention_receipts - 1 === count( $wpdb->rows['wp_faluss_analytics_receipts'] ) && 0 === count( array_filter( $wpdb->rows['wp_faluss_analytics_receipts'], function ( $row ) { return $row['expires_at'] <= gmdate( 'Y-m-d H:i:s' ); } ) ) && array() === array_filter( $wpdb->rows['wp_faluss_analytics_daily_metrics'], function ( $row ) { return $row['aggregate_date'] < gmdate( 'Y-m-d', strtotime( '-25 months' ) ); } ), 'Retention must delete only expired receipts and aggregates beyond 25 months in bounded UTC batches.' );
$rollback_db = $wpdb; $rollback_db->rows['wp_faluss_analytics_daily_metrics'][] = array( 'id' => 9001, 'subject_identity_sha256' => str_repeat( 'b', 64 ), 'aggregate_date' => $old, 'metric_key' => 'hub.portal.raw_views', 'metric_value' => 1, 'created_at' => $old . ' 00:00:00', 'updated_at' => $old . ' 00:00:00' ); $before_retention = serialize( $rollback_db->rows ); $rollback_db->fail_stage = 'delete_metric';
an01b1_assert( false === Faluss_Analytics_Retention::run() && $before_retention === serialize( $rollback_db->rows ), 'Retention deletion failure must roll back its complete batch.' );
$GLOBALS['an01b1_cron'] = array(); $GLOBALS['an01b1_cleared'] = array(); Faluss_Analytics_Retention::schedule(); Faluss_Analytics_Retention::schedule();
an01b1_assert( array( Faluss_Analytics_Retention::HOOK ) === array_keys( $GLOBALS['an01b1_cron'] ), 'Analytics retention hook must be scheduled exactly once.' );
Faluss_Analytics_Retention::deactivate();
an01b1_assert( array( Faluss_Analytics_Retention::HOOK ) === $GLOBALS['an01b1_cleared'] && array() === $GLOBALS['an01b1_cron'], 'Deactivation must clear only the Analytics retention hook.' );

/* Static scope proof: no parallel producer, transport, UI or browser behavior. */
$plugin_source = '';
foreach ( glob( $root . '/src/Analytics/Legacy/*.php' ) as $path ) { $plugin_source .= file_get_contents( $path ); }
foreach ( glob( $root . '/src/Analytics/Legacy/includes/*.php' ) as $path ) { $plugin_source .= file_get_contents( $path ); }
foreach ( array( 'register_rest_route', 'wp_remote_', 'register_delivery_route', 'register_catalog_provider', 'setcookie', 'wp_ajax_', 'admin_post_', 'add_shortcode', 'Faluss_Federation_Providers', 'event.publish' ) as $forbidden ) { an01b1_assert( false === stripos( $plugin_source, $forbidden ), 'Analytics runtime must not activate transport, producer, UI or browser behavior: ' . $forbidden ); }
$bootstrap = file_get_contents( $root . '/src/Analytics/Legacy/faluss-analytics.php' );
an01b1_assert( false !== strpos( $bootstrap, 'Version: 0.1.1' ) && false !== strpos( $bootstrap, "FALUSS_ANALYTICS_VERSION', '0.1.1'" ) && false !== strpos( $bootstrap, "FALUSS_ANALYTICS_SCHEMA_VERSION', '1'" ), 'Analytics patch version must be 0.1.1 while schema remains 1.' );

fwrite( STDOUT, 'AN-01B.1.1 Analytics engine: OK (' . $an01b1_assertions . ' assertions; synthetic event-to-metric mappings plus schema/validator/consumer/read-model/retention)' . PHP_EOL );
