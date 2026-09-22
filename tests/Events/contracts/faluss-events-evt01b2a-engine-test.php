<?php

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

final class WP_Error {
    private $code;
    public function __construct( $code ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_generate_uuid4() { $GLOBALS['evt01b2a_uuid'] = ( $GLOBALS['evt01b2a_uuid'] ?? 0 ) + 1; return sprintf( '00000000-0000-4000-8000-%012x', $GLOBALS['evt01b2a_uuid'] ); }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['evt01b2a_options'] ) ? $GLOBALS['evt01b2a_options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { unset( $autoload ); $GLOBALS['evt01b2a_options'][ $name ] = $value; return true; }
function wp_die( $message ) { throw new RuntimeException( $message ); }
function esc_html__( $message, $domain ) { unset( $domain ); return $message; }

$evt01b2a_assertions = 0;
function evt01b2a_assert( $condition, $message ) {
    global $evt01b2a_assertions;
    $evt01b2a_assertions++;
    if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); }
}
function evt01b2a_error_code( $value ) { return is_wp_error( $value ) ? $value->get_error_code() : null; }
function evt01b2a_private( $class, $method, $arguments = array() ) { $reflection = new ReflectionMethod( $class, $method ); $reflection->setAccessible( true ); return $reflection->invokeArgs( null, $arguments ); }
function evt01b2a_reset_registry() { foreach ( array( 'routes' => array(), 'consumers' => array(), 'route_conflict' => false, 'consumer_conflict' => false ) as $property => $value ) { $reflection = new ReflectionProperty( 'Faluss_Events_Engine', $property ); $reflection->setAccessible( true ); $reflection->setValue( null, $value ); } }

final class EVT01B2A_WPDB {
    public $prefix = 'wp_';
    public $last_error = '';
    public $insert_id = 0;
    public $ddl = array();
    public $rows = array();
    public $queries = array();
    public $insert_count = 0;
    public $fail_insert_table = null;
    public $fail_delete_table = null;
    public $fail_start = false;
    public $fail_event_read = false;
    public $fail_tombstone_read = false;
    public $fail_commit = false;
    public $fail_create_at = null;
    public $create_attempts = 0;
    public $lock_acquisitions = 0;
    public $lock_available = true;
    public $held_locks = array();
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
        $this->begin_query( $query );
        if ( 0 === strpos( $query, 'SELECT GET_LOCK(' ) ) {
            $this->lock_acquisitions++;
            if ( ! $this->lock_available || ! preg_match( "/SELECT GET_LOCK\('([^']+)'/", $query, $matches ) || isset( $this->held_locks[ $matches[1] ] ) ) { return 0; }
            $this->held_locks[ $matches[1] ] = true;
            return 1;
        }
        if ( 0 === strpos( $query, 'SELECT RELEASE_LOCK(' ) ) {
            if ( ! preg_match( "/SELECT RELEASE_LOCK\('([^']+)'/", $query, $matches ) || ! isset( $this->held_locks[ $matches[1] ] ) ) { return 0; }
            unset( $this->held_locks[ $matches[1] ] );
            return 1;
        }
        if ( preg_match( "/^SHOW TABLES LIKE '((?:''|[^'])+)'$/", $query, $matches ) ) {
            $table = preg_replace( '/\\\\([_%\\\\])/', '$1', str_replace( "''", "'", $matches[1] ) );
            return array_key_exists( $table, $this->ddl ) ? $table : null;
        }
        if ( preg_match( '/^SELECT COUNT\(\*\) FROM `([^`]+)`$/', $query, $matches ) ) {
            return count( $this->rows[ $matches[1] ] ?? array() );
        }
        return null;
    }

    public function query( $query ) {
        $this->begin_query( $query );
        if ( 'START TRANSACTION' === $query ) { if ( $this->fail_start ) { $this->fail_start = false; $this->last_error = 'injected start failure'; return false; } $this->snapshot = serialize( $this->rows ); return 1; }
        if ( 'ROLLBACK' === $query ) { if ( null !== $this->snapshot ) { $this->rows = unserialize( $this->snapshot ); } $this->snapshot = null; return 1; }
        if ( 'COMMIT' === $query ) {
            if ( $this->fail_commit ) { $this->fail_commit = false; $this->last_error = 'injected commit failure'; return false; }
            $this->snapshot = null; return 1;
        }
        if ( preg_match( '/^CREATE TABLE `([^`]+)` /', $query, $matches ) ) {
            $this->create_attempts++;
            if ( $this->fail_create_at === $this->create_attempts ) { $this->last_error = 'injected create failure'; return false; }
            if ( isset( $this->ddl[ $matches[1] ] ) ) { $this->last_error = 'table exists'; return false; }
            $this->ddl[ $matches[1] ] = $query; $this->rows[ $matches[1] ] = array(); return 1;
        }
        if ( 0 === strpos( $query, 'RENAME TABLE ' ) ) {
            preg_match_all( '/`([^`]+)` TO `([^`]+)`/', $query, $pairs, PREG_SET_ORDER );
            if ( ! in_array( count( $pairs ), array( 1, 6 ), true ) ) { $this->last_error = 'invalid rename'; return false; }
            foreach ( $pairs as $pair ) { if ( ! isset( $this->ddl[ $pair[1] ] ) || isset( $this->ddl[ $pair[2] ] ) ) { $this->last_error = 'rename collision'; return false; } }
            foreach ( $pairs as $pair ) { $this->ddl[ $pair[2] ] = $this->ddl[ $pair[1] ]; $this->rows[ $pair[2] ] = $this->rows[ $pair[1] ]; unset( $this->ddl[ $pair[1] ], $this->rows[ $pair[1] ] ); }
            return 1;
        }
        if ( preg_match( '/^DROP TABLE `([^`]+)`$/', $query, $matches ) ) { unset( $this->ddl[ $matches[1] ], $this->rows[ $matches[1] ] ); return 1; }
        return 1;
    }

    public function get_row( $query, $output = null ) {
        unset( $output );
        $this->begin_query( $query );
        if ( preg_match( "/^SHOW TABLE STATUS LIKE '((?:''|[^'])+)'$/", $query, $matches ) ) {
            $table = preg_replace( '/\\\\([_%\\\\])/', '$1', str_replace( "''", "'", $matches[1] ) );
            return isset( $this->ddl[ $table ] ) ? array( 'Name' => $table, 'Engine' => 'InnoDB', 'Collation' => 'utf8mb4_unicode_ci' ) : null;
        }
        if ( false !== strpos( $query, 'FROM `' . $this->prefix . 'faluss_events_catalogs`' ) ) {
            foreach ( $this->rows[ $this->prefix . 'faluss_events_catalogs' ] ?? array() as $row ) {
                if ( $this->matches( $query, $row, array( 'source_node_id', 'source_app_key', 'capability_key', 'catalog_version' ) ) ) { return $row; }
            }
            return null;
        }
        if ( false !== strpos( $query, 'FROM `' . $this->prefix . 'faluss_events_events`' ) && false !== strpos( $query, 'WHERE event_id =' ) ) {
            if ( $this->fail_event_read ) { $this->fail_event_read = false; $this->last_error = 'injected event read failure'; return null; }
            foreach ( $this->rows[ $this->prefix . 'faluss_events_events' ] ?? array() as $row ) { if ( $this->matches( $query, $row, array( 'event_id' ) ) ) { return $row; } }
            return null;
        }
        if ( false !== strpos( $query, 'FROM `' . $this->prefix . 'faluss_events_inbox`' ) ) {
            foreach ( $this->rows[ $this->prefix . 'faluss_events_inbox' ] ?? array() as $row ) { if ( $this->matches( $query, $row, array( 'event_id' ) ) ) { return $row; } }
            return null;
        }
        return null;
    }

    public function get_results( $query, $output = null ) {
        unset( $output );
        $this->begin_query( $query );
        if ( preg_match( '/^SHOW FULL COLUMNS FROM `([^`]+)`$/', $query, $matches ) ) { return $this->ddl_columns( $this->ddl[ $matches[1] ] ?? '' ); }
        if ( preg_match( '/^SHOW INDEX FROM `([^`]+)`$/', $query, $matches ) ) { return $this->ddl_indexes( $this->ddl[ $matches[1] ] ?? '' ); }
        if ( false !== strpos( $query, 'SELECT event_id FROM `' . $this->prefix . 'faluss_events_events`' ) && preg_match( "/retention_until <= '([^']+)'/", $query, $matches ) ) {
            $found = array(); foreach ( $this->rows[ $this->prefix . 'faluss_events_events' ] ?? array() as $row ) { if ( ( $row['retention_until'] ?? '' ) <= $matches[1] ) { $found[] = array( 'event_id' => $row['event_id'] ); } }
            usort( $found, function ( $a, $b ) { return strcmp( $a['event_id'], $b['event_id'] ); } ); return array_slice( $found, 0, 50 );
        }
        if ( false !== strpos( $query, 'FROM `' . $this->prefix . 'faluss_events_tombstones`' ) && false !== strpos( $query, 'source_identity_sha256 =' ) ) {
            if ( $this->fail_tombstone_read ) { $this->fail_tombstone_read = false; $this->last_error = 'injected tombstone read failure'; return null; }
            $found = array(); foreach ( $this->rows[ $this->prefix . 'faluss_events_tombstones' ] ?? array() as $row ) { if ( $this->matches_any( $query, $row, array( 'source_identity_sha256', 'event_id' ) ) ) { $found[] = $row; } } return $found;
        }
        if ( false !== strpos( $query, 'SELECT id FROM `' . $this->prefix . 'faluss_events_tombstones`' ) && preg_match( "/expires_at <= '([^']+)'/", $query, $matches ) ) {
            $found = array(); foreach ( $this->rows[ $this->prefix . 'faluss_events_tombstones' ] ?? array() as $row ) { if ( ( $row['expires_at'] ?? '' ) <= $matches[1] ) { $found[] = array( 'id' => $row['id'] ); } } usort( $found, function ( $a, $b ) { return $a['id'] <=> $b['id']; } ); return array_slice( $found, 0, 50 );
        }
        if ( false !== strpos( $query, 'SELECT * FROM `' . $this->prefix . 'faluss_events_events`' ) ) {
            $found = array();
            foreach ( $this->rows[ $this->prefix . 'faluss_events_events' ] ?? array() as $row ) {
                if ( $this->matches_any( $query, $row, array( 'source_identity_sha256', 'event_id' ) ) ) { $found[] = $row; }
            }
            return $found;
        }
        if ( false !== strpos( $query, 'FROM `' . $this->prefix . 'faluss_events_outbox`' ) ) {
            return array_values( array_filter( $this->rows[ $this->prefix . 'faluss_events_outbox' ] ?? array(), function ( $row ) use ( $query ) { return $this->matches( $query, $row, array( 'event_id' ) ); } ) );
        }
        if ( false !== strpos( $query, 'FROM `' . $this->prefix . 'faluss_events_consumer_deliveries`' ) ) {
            return array_values( array_filter( $this->rows[ $this->prefix . 'faluss_events_consumer_deliveries' ] ?? array(), function ( $row ) use ( $query ) { return $this->matches( $query, $row, array( 'event_id' ) ); } ) );
        }
        return array();
    }

    public function insert( $table, $data, $formats = null ) {
        unset( $formats );
        $this->last_error = '';
        $this->insert_count++;
        if ( $this->fail_insert_table === $table ) { $this->fail_insert_table = null; $this->last_error = 'injected insert failure'; return false; }
        if ( ! isset( $this->rows[ $table ] ) ) { $this->last_error = 'missing table'; return false; }
        foreach ( $this->unique_sets( $table ) as $fields ) {
            foreach ( $this->rows[ $table ] as $row ) {
                $same = true; foreach ( $fields as $field ) { if ( ( $row[ $field ] ?? null ) !== ( $data[ $field ] ?? null ) ) { $same = false; break; } }
                if ( $same ) { $this->last_error = 'duplicate'; return false; }
            }
        }
        $this->insert_id++;
        $data = array( 'id' => $this->insert_id ) + $data;
        $this->rows[ $table ][] = $data;
        return 1;
    }

    public function update( $table, $data, $where, $formats = null, $where_formats = null ) {
        unset( $formats, $where_formats );
        $this->last_error = '';
        if ( ! isset( $this->rows[ $table ] ) ) { return 0; }
        foreach ( $this->rows[ $table ] as &$row ) {
            $match = true; foreach ( $where as $field => $value ) { if ( (string) ( $row[ $field ] ?? '' ) !== (string) $value ) { $match = false; break; } }
            if ( $match ) { foreach ( $data as $field => $value ) { $row[ $field ] = $value; } unset( $row ); return 1; }
        }
        unset( $row );
        return 0;
    }

    public function delete( $table, $where, $formats = null ) {
        unset( $formats );
        $this->last_error = '';
        if ( $this->fail_delete_table === $table ) { $this->fail_delete_table = null; $this->last_error = 'injected delete failure'; return false; }
        $kept = array(); $deleted = 0;
        foreach ( $this->rows[ $table ] ?? array() as $row ) {
            $match = true; foreach ( $where as $field => $value ) { if ( (string) ( $row[ $field ] ?? '' ) !== (string) $value ) { $match = false; break; } }
            if ( $match ) { $deleted++; } else { $kept[] = $row; }
        }
        $this->rows[ $table ] = $kept;
        return $deleted;
    }

    public function columns_for( $table ) { return $this->ddl_columns( $this->ddl[ $table ] ?? '' ); }
    public function indexes_for( $table ) { return $this->ddl_indexes( $this->ddl[ $table ] ?? '' ); }

    private function begin_query( $query ) { $this->last_error = ''; $this->queries[] = $query; }
    private function value( $query, $field ) { return preg_match( "/(?:^|[ (])" . preg_quote( $field, '/' ) . " = '((?:''|[^'])*)'/", $query, $matches ) ? str_replace( "''", "'", $matches[1] ) : null; }
    private function matches( $query, $row, $fields ) { foreach ( $fields as $field ) { $expected = $this->value( $query, $field ); if ( null === $expected || (string) ( $row[ $field ] ?? '' ) !== $expected ) { return false; } } return true; }
    private function matches_any( $query, $row, $fields ) { foreach ( $fields as $field ) { $expected = $this->value( $query, $field ); if ( null !== $expected && (string) ( $row[ $field ] ?? '' ) === $expected ) { return true; } } return false; }

    private function unique_sets( $table ) {
        if ( false !== strpos( $table, 'faluss_events_catalogs' ) ) { return array( array( 'catalog_uuid' ), array( 'source_node_id', 'source_app_key', 'capability_key', 'catalog_version' ) ); }
        if ( false !== strpos( $table, 'faluss_events_events' ) ) { return array( array( 'event_id' ), array( 'source_identity_sha256' ) ); }
        if ( false !== strpos( $table, 'faluss_events_outbox' ) ) { return array( array( 'delivery_uuid' ), array( 'event_id', 'destination', 'target_node_id', 'target_app_key' ) ); }
        if ( false !== strpos( $table, 'faluss_events_inbox' ) ) { return array( array( 'receipt_uuid' ), array( 'sender_node_id', 'sender_app_key', 'event_id' ) ); }
        if ( false !== strpos( $table, 'faluss_events_tombstones' ) ) { return array( array( 'tombstone_uuid' ), array( 'event_id' ), array( 'source_identity_sha256' ) ); }
        return array( array( 'delivery_uuid' ), array( 'event_id', 'destination', 'consumer_key' ) );
    }

    private function ddl_parts( $ddl ) {
        if ( ! preg_match( '/^[^(]+\((.*)\) ENGINE=InnoDB /', $ddl, $matches ) ) { return array(); }
        $parts = array(); $part = ''; $depth = 0;
        foreach ( str_split( $matches[1] ) as $character ) {
            if ( '(' === $character ) { $depth++; } elseif ( ')' === $character ) { $depth--; }
            if ( ',' === $character && 0 === $depth ) { $parts[] = $part; $part = ''; } else { $part .= $character; }
        }
        if ( '' !== $part ) { $parts[] = $part; }
        return $parts;
    }

    private function ddl_columns( $ddl ) {
        $columns = array();
        foreach ( $this->ddl_parts( $ddl ) as $part ) {
            if ( preg_match( '/^`([^`]+)` (.+?) (NOT NULL|NULL)(?: AUTO_INCREMENT)?$/', $part, $matches ) ) {
                $textual = 1 === preg_match( '/^(?:char|varchar|longtext)(?:\(|$)/', strtolower( $matches[2] ) );
                $columns[] = array( 'Field' => $matches[1], 'Type' => $matches[2], 'Null' => 'NOT NULL' === $matches[3] ? 'NO' : 'YES', 'Default' => null, 'Extra' => false !== strpos( $part, 'AUTO_INCREMENT' ) ? 'auto_increment' : '', 'Collation' => $textual ? 'utf8mb4_unicode_ci' : null );
            }
        }
        return $columns;
    }

    private function ddl_indexes( $ddl ) {
        $indexes = array();
        foreach ( $this->ddl_parts( $ddl ) as $part ) {
            $name = null; $non_unique = 1; $columns = array();
            if ( preg_match( '/^PRIMARY KEY \((.+)\)$/', $part, $matches ) ) { $name = 'PRIMARY'; $non_unique = 0; }
            elseif ( preg_match( '/^UNIQUE KEY `([^`]+)` \((.+)\)$/', $part, $matches ) ) { $name = $matches[1]; $non_unique = 0; }
            elseif ( preg_match( '/^KEY `([^`]+)` \((.+)\)$/', $part, $matches ) ) { $name = $matches[1]; }
            if ( null === $name ) { continue; }
            preg_match_all( '/`([^`]+)`/', $matches[ count( $matches ) - 1 ], $column_matches );
            foreach ( $column_matches[1] as $index => $column ) { $indexes[] = array( 'Key_name' => $name, 'Seq_in_index' => $index + 1, 'Column_name' => $column, 'Non_unique' => $non_unique, 'Sub_part' => null, 'Index_type' => 'BTREE' ); }
        }
        return $indexes;
    }
}

$root = dirname( __DIR__, 3 );
require_once $root . '/src/Events/Legacy/includes/class-faluss-events-catalog-validator.php';
require_once $root . '/src/Events/Legacy/includes/class-faluss-events-envelope-validator.php';
require_once $root . '/src/Events/Legacy/includes/class-faluss-events-canonicalizer.php';
require_once $root . '/src/Events/Legacy/includes/class-faluss-events.php';
require_once $root . '/src/Events/Legacy/includes/class-faluss-events-schema.php';
require_once $root . '/src/Events/Legacy/includes/class-faluss-events-engine.php';
require_once $root . '/src/Events/Legacy/includes/class-faluss-events-workers.php';

function evt01b2a_definition( $version = '1.0.0', $destinations = array( 'analytics.events' ) ) {
    return array( 'event_type' => 'future-app.fact.recorded', 'event_version' => '1.0.0', 'payload_contract' => array( 'document_type' => 'future-app.fact-payload', 'contract_version' => '1.0.0' ), 'subject_policy' => 'forbidden', 'allowed_actor_types' => array( 'system' ), 'object_policy' => array( 'presence' => 'forbidden', 'allowed_types' => array() ), 'allowed_destinations' => $destinations, 'max_delivery_delay_seconds' => 3600, 'data_classification' => 'operational', 'max_retention_seconds' => 86400, 'member_result_visibility' => 'never', 'lifecycle' => array( 'deprecated' => false, 'sunset_at' => null, 'replacement_event_type' => null ) );
}
function evt01b2a_catalog( $version = '1.0.0', $destinations = array( 'analytics.events' ) ) {
    return array( 'contract_version' => '1.0.0', 'document_type' => 'faluss.event-source-catalog', 'catalog_version' => $version, 'node_id' => 'future-node', 'app_key' => 'future-app', 'owner' => 'future-app', 'owner_engine' => 'future-engine', 'capability_key' => 'future-app.events', 'capability_interface' => 'event_source', 'event_types' => array( evt01b2a_definition( $version, $destinations ) ), 'compatibility' => array( 'minimum_runtime_version' => '1.0.0', 'compatible_with' => array( '1.0.0' ), 'deprecated' => false, 'sunset_at' => null, 'replacement_catalog_version' => null ) );
}
function evt01b2a_event( $event_id, $reference, $catalog_version = '1.0.0', $destinations = array( 'analytics.events' ) ) {
    return array( 'contract_version' => '1.0.0', 'event_id' => $event_id, 'event_type' => 'future-app.fact.recorded', 'event_version' => '1.0.0', 'source' => array( 'node_id' => 'future-node', 'app_key' => 'future-app', 'owner' => 'future-app', 'capability_key' => 'future-app.events', 'catalog_version' => $catalog_version ), 'source_event_reference' => $reference, 'occurred_at' => '2026-09-13T10:00:00Z', 'produced_at' => '2026-09-13T10:00:01Z', 'subject_context' => null, 'actor_context' => array( 'actor_type' => 'system', 'actor_faluss_id' => null, 'anonymous_reference' => null, 'anonymous_scope' => null ), 'object_context' => null, 'destinations' => $destinations, 'payload_contract' => array( 'document_type' => 'future-app.fact-payload', 'contract_version' => '1.0.0' ), 'payload' => array( 'count' => 1, 'detail' => "Été\nligne" ) );
}
function evt01b2a_row_count( $db, $suffix ) { return count( $db->rows[ $db->prefix . $suffix ] ?? array() ); }
function evt01b2a_index_map( $rows ) {
    $map = array();
    foreach ( $rows as $row ) { $map[ $row['Key_name'] ]['non_unique'] = (int) $row['Non_unique']; $map[ $row['Key_name'] ]['columns'][ (int) $row['Seq_in_index'] ] = $row['Column_name']; }
    foreach ( $map as &$index ) { ksort( $index['columns'], SORT_NUMERIC ); $index['columns'] = array_values( $index['columns'] ); } unset( $index );
    ksort( $map, SORT_STRING );
    return $map;
}

/* Fresh schema-2 installation through an active plugin and activation share the same empty controlled path. */
$GLOBALS['evt01b2a_options'] = array(); $GLOBALS['evt01b2a_uuid'] = 0; $wpdb = new EVT01B2A_WPDB();
evt01b2a_assert( true === Faluss_Events_Schema::maybe_upgrade(), 'An empty installation must install schema 2.' );
evt01b2a_assert( '2' === get_option( 'faluss_events_schema_version' ), 'Schema option is declared only after full promotion.' );
$expected_tables = array( 'faluss_events_catalogs', 'faluss_events_events', 'faluss_events_outbox', 'faluss_events_inbox', 'faluss_events_consumer_deliveries', 'faluss_events_tombstones' );
foreach ( $expected_tables as $suffix ) { evt01b2a_assert( isset( $wpdb->ddl[ 'wp_' . $suffix ] ), 'Exact table missing: ' . $suffix ); evt01b2a_assert( 0 === evt01b2a_row_count( $wpdb, $suffix ), 'Installation must create no business row: ' . $suffix ); }
$expected_columns = array(
    'faluss_events_catalogs' => array( 'id', 'catalog_uuid', 'source_node_id', 'source_app_key', 'capability_key', 'catalog_version', 'catalog_sha256', 'catalog_json', 'accepted_at' ),
    'faluss_events_events' => array( 'id', 'event_id', 'source_identity_sha256', 'event_sha256', 'source_node_id', 'source_app_key', 'source_owner', 'source_capability_key', 'catalog_version', 'event_type', 'event_version', 'source_event_reference', 'direction', 'occurred_at', 'produced_at', 'accepted_at', 'retention_until', 'envelope_json' ),
    'faluss_events_outbox' => array( 'id', 'delivery_uuid', 'event_id', 'destination', 'target_node_id', 'target_app_key', 'status', 'attempt_count', 'next_attempt_at', 'lease_token', 'lease_expires_at', 'last_result_code', 'created_at', 'delivered_at' ),
    'faluss_events_inbox' => array( 'id', 'receipt_uuid', 'sender_node_id', 'sender_app_key', 'event_id', 'event_sha256', 'received_at' ),
    'faluss_events_consumer_deliveries' => array( 'id', 'delivery_uuid', 'event_id', 'destination', 'consumer_key', 'status', 'attempt_count', 'lease_token', 'lease_expires_at', 'last_result_code', 'created_at', 'processed_at' ),
    'faluss_events_tombstones' => array( 'id', 'tombstone_uuid', 'event_id', 'source_identity_sha256', 'event_sha256', 'purged_at', 'expires_at', 'created_at' ),
);
foreach ( $expected_columns as $suffix => $expected ) { evt01b2a_assert( $expected === array_column( $wpdb->columns_for( 'wp_' . $suffix ), 'Field' ), 'Exact ordered columns differ for ' . $suffix ); }
$expected_indexes = array(
    'faluss_events_catalogs' => array( 'PRIMARY' => array( 0, array( 'id' ) ), 'catalog_uuid_unique' => array( 0, array( 'catalog_uuid' ) ), 'catalog_tuple_unique' => array( 0, array( 'source_node_id', 'source_app_key', 'capability_key', 'catalog_version' ) ) ),
    'faluss_events_events' => array( 'PRIMARY' => array( 0, array( 'id' ) ), 'event_id_unique' => array( 0, array( 'event_id' ) ), 'event_retention' => array( 1, array( 'retention_until' ) ), 'source_identity_unique' => array( 0, array( 'source_identity_sha256' ) ) ),
    'faluss_events_outbox' => array( 'PRIMARY' => array( 0, array( 'id' ) ), 'outbox_delivery_unique' => array( 0, array( 'delivery_uuid' ) ), 'outbox_due' => array( 1, array( 'status', 'next_attempt_at' ) ), 'outbox_route_unique' => array( 0, array( 'event_id', 'destination', 'target_node_id', 'target_app_key' ) ) ),
    'faluss_events_inbox' => array( 'PRIMARY' => array( 0, array( 'id' ) ), 'inbox_receipt_unique' => array( 0, array( 'receipt_uuid' ) ), 'inbox_received' => array( 1, array( 'received_at' ) ), 'inbox_sender_event_unique' => array( 0, array( 'sender_node_id', 'sender_app_key', 'event_id' ) ) ),
    'faluss_events_consumer_deliveries' => array( 'PRIMARY' => array( 0, array( 'id' ) ), 'consumer_delivery_unique' => array( 0, array( 'delivery_uuid' ) ), 'consumer_event_unique' => array( 0, array( 'event_id', 'destination', 'consumer_key' ) ), 'consumer_pending' => array( 1, array( 'status', 'created_at' ) ) ),
    'faluss_events_tombstones' => array( 'PRIMARY' => array( 0, array( 'id' ) ), 'tombstone_uuid_unique' => array( 0, array( 'tombstone_uuid' ) ), 'tombstone_event_unique' => array( 0, array( 'event_id' ) ), 'tombstone_source_identity_unique' => array( 0, array( 'source_identity_sha256' ) ), 'tombstone_expiry' => array( 1, array( 'expires_at' ) ) ),
);
foreach ( $expected_indexes as $suffix => $expected ) {
    $actual = evt01b2a_index_map( $wpdb->indexes_for( 'wp_' . $suffix ) );
    $normalized = array(); foreach ( $expected as $name => $definition ) { $normalized[ $name ] = array( 'non_unique' => $definition[0], 'columns' => $definition[1] ); } ksort( $normalized, SORT_STRING );
    evt01b2a_assert( $normalized === $actual && false !== strpos( $wpdb->ddl[ 'wp_' . $suffix ], 'ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' ), 'Exact InnoDB indexes or WordPress collation differ for ' . $suffix );
}
evt01b2a_assert( 6 === count( array_filter( $wpdb->queries, function ( $query ) { return 0 === strpos( $query, 'CREATE TABLE ' ); } ) ) && 1 === count( array_filter( $wpdb->queries, function ( $query ) { return 0 === strpos( $query, 'RENAME TABLE ' ); } ) ), 'Six verified temporary InnoDB tables must be promoted by one atomic rename.' );
$query_count = count( $wpdb->queries );
evt01b2a_assert( true === Faluss_Events_Schema::install() && $query_count < count( $wpdb->queries ) && 6 === count( $wpdb->ddl ), 'Second installation check is idempotent and creates no extra table.' );
$query_count = count( $wpdb->queries );
evt01b2a_assert( true === Faluss_Events_Schema::maybe_upgrade() && $query_count === count( $wpdb->queries ), 'Normal loading with declared schema 2 must perform no schema or data query.' );
$query_count = count( $wpdb->queries ); $row_counts = array_map( 'count', $wpdb->rows ); Faluss_Events_Workers::run_outbox(); Faluss_Events_Workers::run_consumers();
evt01b2a_assert( $query_count === count( $wpdb->queries ) && $row_counts === array_map( 'count', $wpdb->rows ), 'Empty route and consumer registries must cause both workers to create no row and issue no query.' );

$GLOBALS['evt01b2a_options'] = array(); $partial = new EVT01B2A_WPDB(); $partial->ddl['wp_faluss_events_catalogs'] = 'CREATE TABLE `wp_faluss_events_catalogs` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; $partial->rows['wp_faluss_events_catalogs'] = array(); $wpdb = $partial;
evt01b2a_assert( false === Faluss_Events_Schema::install() && 0 === count( array_filter( $partial->queries, function ( $query ) { return 0 === strpos( $query, 'CREATE TABLE ' ); } ) ), 'Partial or divergent schema must be refused without repair.' );
$GLOBALS['evt01b2a_options'] = array(); $failed_install = new EVT01B2A_WPDB(); $failed_install->fail_create_at = 3; $wpdb = $failed_install;
evt01b2a_assert( false === Faluss_Events_Schema::install() && array() === $failed_install->ddl && null === get_option( 'faluss_events_schema_version', null ), 'Failed initial creation must remove only its temporary tables and never declare schema 2.' );

/* Canonicalizer: recursive object order, stable UTF-8 and significant arrays. */
$object_a = array( 'z' => array( 'b' => 2, 'a' => 1 ), 'a' => "Été/\n\t" );
$object_b = array( 'a' => "Été/\n\t", 'z' => array( 'a' => 1, 'b' => 2 ) );
$canonical_a = Faluss_Events_Canonicalizer::canonicalize( $object_a ); $canonical_b = Faluss_Events_Canonicalizer::canonicalize( $object_b );
evt01b2a_assert( is_string( $canonical_a ) && $canonical_a === $canonical_b && hash( 'sha256', $canonical_a ) === hash( 'sha256', $canonical_b ), 'Recursive object key order must not affect canonical bytes or hash.' );
evt01b2a_assert( false !== strpos( $canonical_a, 'Été/' ) && false !== strpos( $canonical_a, '\\n\\t' ) && false === strpos( $canonical_a, '\\/'), 'Unicode and controls must serialize deterministically without artificial slash escaping.' );
evt01b2a_assert( false !== strpos( Faluss_Events_Canonicalizer::canonicalize( "ligne\u{2028}suivante" ), "\u{2028}" ), 'Unicode line separators must remain unescaped canonical UTF-8.' );
$array_a = Faluss_Events_Canonicalizer::canonicalize( array( 1, 2 ) ); $array_b = Faluss_Events_Canonicalizer::canonicalize( array( 2, 1 ) );
evt01b2a_assert( is_string( $array_a ) && is_string( $array_b ) && hash( 'sha256', $array_a ) !== hash( 'sha256', $array_b ), 'Array order must remain significant.' );
evt01b2a_assert( '9007199254740991' === Faluss_Events_Canonicalizer::canonicalize( 9007199254740991 ), 'Maximum JCS-safe integer must be accepted.' );
evt01b2a_assert( is_wp_error( Faluss_Events_Canonicalizer::canonicalize( 1.5 ) ) && is_wp_error( Faluss_Events_Canonicalizer::canonicalize( "\xC3\x28" ) ), 'Floats and invalid UTF-8 must fail before persistence.' );

/* Install an independent fresh harness for persistent engine behavior. */
$GLOBALS['evt01b2a_options'] = array(); $wpdb = new EVT01B2A_WPDB();
evt01b2a_assert( true === Faluss_Events_Schema::install() && Faluss_Events_Schema::is_ready(), 'Fresh installation must reach verified ready state.' );
$catalog = evt01b2a_catalog();
$catalog_first = evt01b2a_private( 'Faluss_Events_Engine', 'persist_validated_catalog', array( $catalog ) );
$catalog_retry = evt01b2a_private( 'Faluss_Events_Engine', 'persist_validated_catalog', array( array_reverse( $catalog, true ) ) );
evt01b2a_assert( false === ( $catalog_first['existing'] ?? true ) && true === ( $catalog_retry['existing'] ?? false ) && ( $catalog_first['catalog_uuid'] ?? null ) === ( $catalog_retry['catalog_uuid'] ?? null ) && 1 === evt01b2a_row_count( $wpdb, 'faluss_events_catalogs' ), 'Catalog acceptance must be canonical and idempotent.' );
$catalog_conflict = $catalog; $catalog_conflict['owner_engine'] = 'other-engine';
evt01b2a_assert( 'faluss_events_conflict' === evt01b2a_error_code( evt01b2a_private( 'Faluss_Events_Engine', 'persist_validated_catalog', array( $catalog_conflict ) ) ), 'Same catalog tuple with another hash must fail closed as conflict.' );
$before_concurrent = evt01b2a_row_count( $wpdb, 'faluss_events_catalogs' );
evt01b2a_private( 'Faluss_Events_Engine', 'persist_validated_catalog', array( $catalog ) ); evt01b2a_private( 'Faluss_Events_Engine', 'persist_validated_catalog', array( $catalog ) );
evt01b2a_assert( $before_concurrent === evt01b2a_row_count( $wpdb, 'faluss_events_catalogs' ) && $wpdb->lock_acquisitions >= 4, 'Two shared-lock simulations must retain one catalog occurrence.' );

$before_missing_validator = $wpdb->insert_count;
$missing_validator = Faluss_Events_Engine::accept_inbound_event( evt01b2a_event( '90000000-0000-4000-8000-000000000001', 'event-ref-0099' ), array( 'node_id' => 'future-node', 'app_key' => 'future-app' ), array( 'node_id' => 'consumer-node', 'app_key' => 'consumer-app' ) );
evt01b2a_assert( 'faluss_events_registry_unavailable' === evt01b2a_error_code( $missing_validator ) && $before_missing_validator === $wpdb->insert_count, 'Missing payload validator registry must map to not_available before any event write.' );

Faluss_Events::register_payload_validator( 'future-app.fact-payload', '1.0.0', function ( $payload ) { return is_array( $payload ) && isset( $payload['count'], $payload['detail'] ); } );
evt01b2a_reset_registry();
$route = array( 'source_node_id' => 'future-node', 'source_app_key' => 'future-app', 'source_capability_key' => 'future-app.events', 'catalog_version' => '1.0.0', 'destination' => 'analytics.events', 'mode' => 'local', 'target_node_id' => 'consumer-node', 'target_app_key' => 'consumer-app' );
$callback_calls = 0;
$consumer = array( 'consumer_key' => 'future-consumer.analytics', 'destination' => 'analytics.events', 'target_node_id' => 'consumer-node', 'target_app_key' => 'consumer-app', 'sources' => array( array( 'node_id' => 'future-node', 'app_key' => 'future-app', 'capability_key' => 'future-app.events', 'catalog_version' => '1.0.0' ) ), 'callback' => function () use ( &$callback_calls ) { $callback_calls++; return true; } );
evt01b2a_assert( true === Faluss_Events_Engine::register_delivery_route( $route ) && true === Faluss_Events_Engine::register_consumer( $consumer ), 'Exact trusted route and consumer must register.' );
evt01b2a_assert( is_wp_error( Faluss_Events_Engine::register_delivery_route( $route ) ), 'Duplicate route must poison its registry closed.' );
evt01b2a_assert( is_wp_error( Faluss_Events_Engine::register_consumer( $consumer ) ), 'Duplicate consumer must poison its registry closed.' );
$wildcard_route = $route; $wildcard_route['source_node_id'] = '*'; $wildcard_consumer = $consumer; $wildcard_consumer['sources'][0]['node_id'] = '*';
evt01b2a_assert( is_wp_error( Faluss_Events_Engine::register_delivery_route( $wildcard_route ) ) && is_wp_error( Faluss_Events_Engine::register_consumer( $wildcard_consumer ) ), 'Route and consumer registries must reject every wildcard.' );
evt01b2a_reset_registry(); Faluss_Events_Engine::register_delivery_route( $route ); Faluss_Events_Engine::register_consumer( $consumer );

$event = evt01b2a_event( '10000000-0000-4000-8000-000000000001', 'event-ref-0001' );
$event_canonical = Faluss_Events_Canonicalizer::canonicalize_event( $event );
$accepted_catalog_debug = evt01b2a_private( 'Faluss_Events_Engine', 'accepted_catalog_for_event', array( $event ) );
evt01b2a_assert( is_array( $accepted_catalog_debug ), 'Persisted catalog must remain readable and hash-valid before event acceptance; result=' . ( evt01b2a_error_code( $accepted_catalog_debug ) ?? 'non-error' ) );
evt01b2a_assert( true === Faluss_Events::validate_event( $event, $accepted_catalog_debug ), 'The real envelope and payload validator must accept the catalog-bound event independently of JSON object key order.' );
$identity_hash = hash( 'sha256', evt01b2a_private( 'Faluss_Events_Engine', 'source_identity_canonical', array( $event ) ) );
$identity_locks_a = evt01b2a_private( 'Faluss_Events_Engine', 'event_lock_names', array( $identity_hash, $event['event_id'] ) );
$identity_locks_b = evt01b2a_private( 'Faluss_Events_Engine', 'event_lock_names', array( $identity_hash, '10000000-0000-4000-8000-000000000099' ) );
$event_locks_b = evt01b2a_private( 'Faluss_Events_Engine', 'event_lock_names', array( str_repeat( 'f', 64 ), $event['event_id'] ) );
evt01b2a_assert( 2 === count( $identity_locks_a ) && 1 === count( array_intersect( $identity_locks_a, $identity_locks_b ) ) && 1 === count( array_intersect( $identity_locks_a, $event_locks_b ) ) && 64 >= max( array_map( 'strlen', $identity_locks_a ) ), 'Concurrent variants must serialize on separate identity and event_id named locks.' );
foreach ( array(
    function ( $value ) { $value['event_id'] = '10000000-0000-4000-8000-000000000002'; return $value; },
    function ( $value ) { $value['event_version'] = '1.0.1'; return $value; },
    function ( $value ) { $value['source_event_reference'] = 'event-ref-9999'; return $value; },
    function ( $value ) { $value['source']['node_id'] = 'other-node'; return $value; },
    function ( $value ) { $value['destinations'] = array( 'quests.events' ); return $value; },
    function ( $value ) { $value['payload']['count'] = 2; return $value; }
) as $mutation ) {
    $mutated = Faluss_Events_Canonicalizer::canonicalize_event( $mutation( $event ) );
    evt01b2a_assert( is_string( $mutated ) && hash( 'sha256', $mutated ) !== hash( 'sha256', $event_canonical ), 'Every identity, source, destination or payload mutation must alter the event hash.' );
}

$local = Faluss_Events_Engine::accept_local_event( $event );
evt01b2a_assert( is_array( $local ) && false === $local['existing'] && 1 === evt01b2a_row_count( $wpdb, 'faluss_events_events' ) && 1 === evt01b2a_row_count( $wpdb, 'faluss_events_outbox' ), 'Local event and every outbox row must commit atomically.' );
$local_retry = Faluss_Events_Engine::accept_local_event( $event );
evt01b2a_assert( is_array( $local_retry ) && true === $local_retry['existing'] && 1 === evt01b2a_row_count( $wpdb, 'faluss_events_events' ) && 1 === evt01b2a_row_count( $wpdb, 'faluss_events_outbox' ), 'Identical local retry must create no second row.' );
$other_id = $event; $other_id['event_id'] = '10000000-0000-4000-8000-000000000003';
evt01b2a_assert( 'faluss_events_conflict' === evt01b2a_error_code( Faluss_Events_Engine::accept_local_event( $other_id ) ), 'Same business identity with another event_id must conflict.' );
$other_content = $event; $other_content['payload']['count'] = 2;
evt01b2a_assert( 'faluss_events_conflict' === evt01b2a_error_code( Faluss_Events_Engine::accept_local_event( $other_content ) ), 'Same event_id with another content hash must conflict.' );

$inbound = evt01b2a_event( '20000000-0000-4000-8000-000000000001', 'event-ref-0002' );
$sender = array( 'node_id' => 'future-node', 'app_key' => 'future-app' );
$recipient = array( 'node_id' => 'consumer-node', 'app_key' => 'consumer-app' );
$inbound_result = Faluss_Events_Engine::accept_inbound_event( $inbound, $sender, $recipient );
evt01b2a_assert( is_array( $inbound_result ) && false === $inbound_result['existing'] && 2 === evt01b2a_row_count( $wpdb, 'faluss_events_events' ) && 1 === evt01b2a_row_count( $wpdb, 'faluss_events_inbox' ) && 1 === evt01b2a_row_count( $wpdb, 'faluss_events_consumer_deliveries' ), 'Inbound event, inbox and consumer delivery must commit atomically.' );
$inbound_retry = Faluss_Events_Engine::accept_inbound_event( $inbound, $sender, $recipient );
evt01b2a_assert( is_array( $inbound_retry ) && true === $inbound_retry['existing'] && 1 === evt01b2a_row_count( $wpdb, 'faluss_events_inbox' ) && 1 === evt01b2a_row_count( $wpdb, 'faluss_events_consumer_deliveries' ), 'Inbound retry must be idempotent across inbox and consumer deliveries.' );
evt01b2a_assert( 0 === $callback_calls, 'EVT-01B.2A must never execute a registered consumer callback.' );
$before = $wpdb->insert_count; $wrong_sender = array( 'node_id' => 'other-node', 'app_key' => 'future-app' );
evt01b2a_assert( is_wp_error( Faluss_Events_Engine::accept_inbound_event( evt01b2a_event( '20000000-0000-4000-8000-000000000002', 'event-ref-0003' ), $wrong_sender, $recipient ) ) && $before === $wpdb->insert_count, 'Inbound source different from authenticated sender must fail before writing.' );

$missing_catalog = evt01b2a_event( '30000000-0000-4000-8000-000000000001', 'event-ref-0004', '1.0.9' ); $before = $wpdb->insert_count;
evt01b2a_assert( is_wp_error( Faluss_Events_Engine::accept_local_event( $missing_catalog ) ) && $before === $wpdb->insert_count, 'Missing accepted catalog must fail before writing or network fallback.' );
$catalog_row =& $wpdb->rows['wp_faluss_events_catalogs'][0]; $saved_hash = $catalog_row['catalog_sha256']; $catalog_row['catalog_sha256'] = str_repeat( '0', 64 ); $before = $wpdb->insert_count;
evt01b2a_assert( is_wp_error( Faluss_Events_Engine::accept_local_event( evt01b2a_event( '30000000-0000-4000-8000-000000000002', 'event-ref-0005' ) ) ) && $before === $wpdb->insert_count, 'Divergent persisted catalog hash must fail before writing.' ); $catalog_row['catalog_sha256'] = $saved_hash; unset( $catalog_row );

$multi_catalog = evt01b2a_catalog( '1.0.1', array( 'analytics.events', 'quests.events' ) ); evt01b2a_private( 'Faluss_Events_Engine', 'persist_validated_catalog', array( $multi_catalog ) );
$unrouted = evt01b2a_event( '30000000-0000-4000-8000-000000000003', 'event-ref-0006', '1.0.1', array( 'quests.events' ) ); $before = $wpdb->insert_count;
evt01b2a_assert( is_wp_error( Faluss_Events_Engine::accept_local_event( $unrouted ) ) && $before === $wpdb->insert_count, 'Destination without an exact route must fail before writing.' );
evt01b2a_assert( is_wp_error( Faluss_Events_Engine::accept_inbound_event( $unrouted, $sender, $recipient ) ) && $before === $wpdb->insert_count, 'Destination without an exact consumer must fail before writing.' );

/* Inject failure at every write boundary and at commit; each transaction restores the shared state. */
$baseline = array( evt01b2a_row_count( $wpdb, 'faluss_events_events' ), evt01b2a_row_count( $wpdb, 'faluss_events_outbox' ), evt01b2a_row_count( $wpdb, 'faluss_events_inbox' ), evt01b2a_row_count( $wpdb, 'faluss_events_consumer_deliveries' ) );
$faults = array(
    array( 'local', 'faluss_events_events', '40000000-0000-4000-8000-000000000001', 'event-ref-0010' ),
    array( 'local', 'faluss_events_outbox', '40000000-0000-4000-8000-000000000002', 'event-ref-0011' ),
    array( 'inbound', 'faluss_events_events', '40000000-0000-4000-8000-000000000003', 'event-ref-0012' ),
    array( 'inbound', 'faluss_events_inbox', '40000000-0000-4000-8000-000000000004', 'event-ref-0013' ),
    array( 'inbound', 'faluss_events_consumer_deliveries', '40000000-0000-4000-8000-000000000005', 'event-ref-0014' )
);
foreach ( $faults as $fault ) {
    $wpdb->fail_insert_table = 'wp_' . $fault[1];
    $fault_event = evt01b2a_event( $fault[2], $fault[3] );
    $result = 'local' === $fault[0] ? Faluss_Events_Engine::accept_local_event( $fault_event ) : Faluss_Events_Engine::accept_inbound_event( $fault_event, $sender, $recipient );
    $after = array( evt01b2a_row_count( $wpdb, 'faluss_events_events' ), evt01b2a_row_count( $wpdb, 'faluss_events_outbox' ), evt01b2a_row_count( $wpdb, 'faluss_events_inbox' ), evt01b2a_row_count( $wpdb, 'faluss_events_consumer_deliveries' ) );
    evt01b2a_assert( is_wp_error( $result ) && $baseline === $after, 'Injected write failure must roll back the whole transaction: ' . $fault[1] );
}
foreach ( array( 'local', 'inbound' ) as $index => $direction ) {
    $wpdb->fail_commit = true; $fault_event = evt01b2a_event( '50000000-0000-4000-8000-' . sprintf( '%012d', $index + 1 ), 'event-ref-002' . $index );
    $result = 'local' === $direction ? Faluss_Events_Engine::accept_local_event( $fault_event ) : Faluss_Events_Engine::accept_inbound_event( $fault_event, $sender, $recipient );
    $after = array( evt01b2a_row_count( $wpdb, 'faluss_events_events' ), evt01b2a_row_count( $wpdb, 'faluss_events_outbox' ), evt01b2a_row_count( $wpdb, 'faluss_events_inbox' ), evt01b2a_row_count( $wpdb, 'faluss_events_consumer_deliveries' ) );
    evt01b2a_assert( is_wp_error( $result ) && $baseline === $after, 'Commit failure must roll back local or inbound writes: ' . $direction );
}

$private_event = Faluss_Events_Engine::event_by_id( $event['event_id'] );
$counts = Faluss_Events_Engine::technical_counts();
evt01b2a_assert( is_array( $private_event ) && Faluss_Events_Canonicalizer::canonicalize_event( $private_event ) === $event_canonical, 'Private event read must revalidate canonical bytes and hash.' );
evt01b2a_assert( array( 'catalogs', 'events', 'outbox', 'inbox', 'consumer_deliveries', 'tombstones' ) === array_keys( $counts ) && 2 === $counts['catalogs'] && 2 === $counts['events'] && 0 === $counts['tombstones'], 'Technical counters expose only bounded table counts.' );

$bootstrap = file_get_contents( $root . '/src/Events/Legacy/faluss-events.php' );
$engine_source = file_get_contents( $root . '/src/Events/Legacy/includes/class-faluss-events-engine.php' );
$runtime = $bootstrap . $engine_source . file_get_contents( $root . '/src/Events/Legacy/includes/class-faluss-events-schema.php' );
evt01b2a_assert( false !== strpos( $bootstrap, 'Version: 0.3.1' ) && false !== strpos( $bootstrap, "FALUSS_EVENTS_SCHEMA_VERSION', '2'" ) && false !== strpos( $bootstrap, "'Faluss_Events_Schema', 'maybe_upgrade'" ) && false === strpos( $runtime, 'dbDelta(' ), 'Events must use controlled schema 2 installation and migration without permissive dbDelta.' );
evt01b2a_assert( false !== strpos( $engine_source, 'Faluss_Events::read_remote_catalog(' ) && false !== strpos( $engine_source, 'private static function persist_validated_catalog' ), 'Remote refresh must obtain its own signed validated catalog before reaching private persistence.' );
evt01b2a_assert( false === strpos( $engine_source, '$wpdb->update(' ) && false === strpos( $engine_source, '$wpdb->delete(' ) && 1 !== preg_match( '/["\'](?:UPDATE|DELETE)\s/i', $engine_source ), 'Accepted catalogs and events must expose no functional UPDATE or DELETE path.' );
foreach ( array( 'register_rest_route', 'wp_ajax_', 'admin_post_', 'add_shortcode', 'setcookie', 'Faluss_Analytics', 'Faluss_Quests', 'Faluss_Progression', 'Faluss_Tracking' ) as $forbidden ) { evt01b2a_assert( false === stripos( $runtime, $forbidden ), 'Persistent core must not activate forbidden browser or business behavior: ' . $forbidden ); }
evt01b2a_assert( false === strpos( $runtime, 'faluss-hub' ) && false === strpos( $runtime, 'faluss-me' ), 'No real Hub or Me provider, catalog or event may ship.' );

echo 'EVT-01B.2A persistent engine: OK (' . $evt01b2a_assertions . ' assertions; faithful transactional wpdb harness)' . PHP_EOL;
