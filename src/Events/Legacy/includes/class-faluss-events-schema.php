<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Verified fresh installation and additive schema-1-to-2 migration. */
final class Faluss_Events_Schema {
    const OPTION = 'faluss_events_schema_version';
    const VERSION = '2';

    public static function catalogs_table() { return self::table( 'faluss_events_catalogs' ); }
    public static function events_table() { return self::table( 'faluss_events_events' ); }
    public static function outbox_table() { return self::table( 'faluss_events_outbox' ); }
    public static function inbox_table() { return self::table( 'faluss_events_inbox' ); }
    public static function consumer_deliveries_table() { return self::table( 'faluss_events_consumer_deliveries' ); }
    public static function tombstones_table() { return self::table( 'faluss_events_tombstones' ); }

    public static function activate() {
        if ( ! self::install() ) {
            wp_die( esc_html__( 'Le schéma Faluss Events ne peut pas être installé sans risque.', 'faluss-events' ) );
        }
    }

    /** Ready requests only read the version option; schema 1 takes the sole additive path. */
    public static function maybe_upgrade() {
        return self::VERSION === (string) get_option( self::OPTION ) || self::install();
    }

    public static function is_ready() {
        return self::VERSION === (string) get_option( self::OPTION ) && self::current_schema_ready();
    }

    /** Never repairs, mutates or adopts a pre-existing partial or divergent schema. */
    public static function install() {
        global $wpdb;
        if ( ! self::valid_prefix() || ! self::valid_table_names() || ! method_exists( $wpdb, 'get_charset_collate' ) || ! method_exists( $wpdb, 'esc_like' ) ) {
            return false;
        }
        if ( self::current_schema_ready() ) {
            return self::VERSION === (string) get_option( self::OPTION );
        }
        $stored = get_option( self::OPTION, null );
        if ( '1' === (string) $stored ) {
            return self::migrate_from_one();
        }
        if ( self::existing_tables( self::tables() ) || null !== $stored ) {
            return false;
        }
        $lock = 'faluss_events_schema_' . substr( hash( 'sha256', (string) $wpdb->prefix ), 0, 24 );
        if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $lock, 10 ) ) || self::database_has_error() ) {
            return false;
        }
        $temporary = array();
        try {
            if ( self::current_schema_ready() || self::existing_tables( self::tables() ) || null !== get_option( self::OPTION, null ) ) {
                return false;
            }
            try {
                $suffix = bin2hex( random_bytes( 8 ) );
            } catch ( Throwable $throwable ) {
                return false;
            }
            foreach ( self::tables() as $name => $table ) {
                $temporary[ $name ] = $table . '__fe_' . substr( $suffix, 0, 12 );
                if ( strlen( $temporary[ $name ] ) > 64 || self::table_exists( $temporary[ $name ] ) ) {
                    return false;
                }
                if ( false === $wpdb->query( self::create_query( $name, $temporary[ $name ] ) ) || self::database_has_error() || ! self::verify_table( $name, $temporary[ $name ] ) ) {
                    return false;
                }
            }
            $renames = array();
            foreach ( $temporary as $name => $table ) {
                $renames[] = self::quote_identifier( $table ) . ' TO ' . self::quote_identifier( self::tables()[ $name ] );
            }
            if ( false === $wpdb->query( 'RENAME TABLE ' . implode( ',', $renames ) ) || self::database_has_error() || ! self::current_schema_ready() ) {
                return false;
            }
            return true === update_option( self::OPTION, self::VERSION, false );
        } finally {
            foreach ( $temporary as $table ) {
                if ( self::table_exists( $table ) ) {
                    $wpdb->query( 'DROP TABLE ' . self::quote_identifier( $table ) );
                }
            }
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        }
    }

    public static function status() {
        return array( 'version' => self::VERSION, 'stored_version' => (string) get_option( self::OPTION ), 'ready' => self::is_ready(), 'tables' => self::tables() );
    }

    public static function quote_identifier( $identifier ) {
        return '`' . str_replace( '`', '``', (string) $identifier ) . '`';
    }

    private static function table( $suffix ) {
        global $wpdb;
        return self::valid_prefix() ? $wpdb->prefix . $suffix : '';
    }

    private static function valid_prefix() {
        global $wpdb;
        return is_object( $wpdb ) && isset( $wpdb->prefix ) && 1 === preg_match( '/^[A-Za-z0-9_]+$/D', (string) $wpdb->prefix );
    }

    private static function valid_table_names() {
        foreach ( self::tables() as $table ) {
            if ( '' === $table || strlen( $table ) > 64 ) {
                return false;
            }
        }
        return true;
    }

    private static function tables() {
        return self::legacy_tables() + array( 'tombstones' => self::tombstones_table() );
    }

    private static function legacy_tables() {
        return array(
            'catalogs' => self::catalogs_table(),
            'events' => self::events_table(),
            'outbox' => self::outbox_table(),
            'inbox' => self::inbox_table(),
            'consumer_deliveries' => self::consumer_deliveries_table(),
        );
    }

    /** Schema 1 is accepted only when all five historical tables are exact. */
    private static function migrate_from_one() {
        global $wpdb;
        if ( ! self::legacy_schema_ready() || self::table_exists( self::tombstones_table() ) ) {
            return false;
        }
        $lock = 'faluss_events_schema_' . substr( hash( 'sha256', (string) $wpdb->prefix ), 0, 24 );
        if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $lock, 10 ) ) || self::database_has_error() ) {
            return false;
        }
        $temporary = '';
        try {
            if ( '1' !== (string) get_option( self::OPTION, null ) || ! self::legacy_schema_ready() || self::table_exists( self::tombstones_table() ) ) {
                return false;
            }
            try {
                $temporary = self::tombstones_table() . '__fe_' . substr( bin2hex( random_bytes( 8 ) ), 0, 12 );
            } catch ( Throwable $throwable ) {
                return false;
            }
            if ( strlen( $temporary ) > 64 || self::table_exists( $temporary ) || false === $wpdb->query( self::create_query( 'tombstones', $temporary ) ) || self::database_has_error() || ! self::verify_table( 'tombstones', $temporary ) ) {
                return false;
            }
            if ( false === $wpdb->query( 'RENAME TABLE ' . self::quote_identifier( $temporary ) . ' TO ' . self::quote_identifier( self::tombstones_table() ) ) || self::database_has_error() ) {
                return false;
            }
            $temporary = '';
            return self::current_schema_ready() && true === update_option( self::OPTION, self::VERSION, false );
        } finally {
            if ( '' !== $temporary && self::table_exists( $temporary ) ) {
                $wpdb->query( 'DROP TABLE ' . self::quote_identifier( $temporary ) );
            }
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        }
    }

    private static function existing_tables( $tables ) {
        $existing = array();
        foreach ( $tables as $name => $table ) {
            if ( '' !== $table && self::table_exists( $table ) ) {
                $existing[ $name ] = $table;
            }
        }
        return $existing;
    }

    private static function table_exists( $table ) {
        global $wpdb;
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        return is_string( $found ) && hash_equals( $table, $found );
    }

    private static function create_query( $name, $table ) {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        if ( 'catalogs' === $name ) {
            return 'CREATE TABLE ' . self::quote_identifier( $table ) . ' (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,`catalog_uuid` char(36) NOT NULL,`source_node_id` varchar(64) NOT NULL,`source_app_key` varchar(64) NOT NULL,`capability_key` varchar(512) NOT NULL,`catalog_version` varchar(32) NOT NULL,`catalog_sha256` char(64) NOT NULL,`catalog_json` longtext NOT NULL,`accepted_at` datetime NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `catalog_uuid_unique` (`catalog_uuid`),UNIQUE KEY `catalog_tuple_unique` (`source_node_id`,`source_app_key`,`capability_key`,`catalog_version`)) ENGINE=InnoDB ' . $charset;
        }
        if ( 'events' === $name ) {
            return 'CREATE TABLE ' . self::quote_identifier( $table ) . ' (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,`event_id` char(36) NOT NULL,`source_identity_sha256` char(64) NOT NULL,`event_sha256` char(64) NOT NULL,`source_node_id` varchar(64) NOT NULL,`source_app_key` varchar(64) NOT NULL,`source_owner` varchar(64) NOT NULL,`source_capability_key` varchar(512) NOT NULL,`catalog_version` varchar(32) NOT NULL,`event_type` varchar(512) NOT NULL,`event_version` varchar(32) NOT NULL,`source_event_reference` varchar(128) NOT NULL,`direction` varchar(8) NOT NULL,`occurred_at` datetime NOT NULL,`produced_at` datetime NOT NULL,`accepted_at` datetime NOT NULL,`retention_until` datetime NOT NULL,`envelope_json` longtext NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `event_id_unique` (`event_id`),UNIQUE KEY `source_identity_unique` (`source_identity_sha256`),KEY `event_retention` (`retention_until`)) ENGINE=InnoDB ' . $charset;
        }
        if ( 'outbox' === $name ) {
            return 'CREATE TABLE ' . self::quote_identifier( $table ) . ' (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,`delivery_uuid` char(36) NOT NULL,`event_id` char(36) NOT NULL,`destination` varchar(64) NOT NULL,`target_node_id` varchar(64) NOT NULL,`target_app_key` varchar(64) NOT NULL,`status` varchar(16) NOT NULL,`attempt_count` bigint(20) unsigned NOT NULL,`next_attempt_at` datetime NOT NULL,`lease_token` varchar(128) NULL,`lease_expires_at` datetime NULL,`last_result_code` varchar(64) NULL,`created_at` datetime NOT NULL,`delivered_at` datetime NULL,PRIMARY KEY (`id`),UNIQUE KEY `outbox_delivery_unique` (`delivery_uuid`),UNIQUE KEY `outbox_route_unique` (`event_id`,`destination`,`target_node_id`,`target_app_key`),KEY `outbox_due` (`status`,`next_attempt_at`)) ENGINE=InnoDB ' . $charset;
        }
        if ( 'inbox' === $name ) {
            return 'CREATE TABLE ' . self::quote_identifier( $table ) . ' (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,`receipt_uuid` char(36) NOT NULL,`sender_node_id` varchar(64) NOT NULL,`sender_app_key` varchar(64) NOT NULL,`event_id` char(36) NOT NULL,`event_sha256` char(64) NOT NULL,`received_at` datetime NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `inbox_receipt_unique` (`receipt_uuid`),UNIQUE KEY `inbox_sender_event_unique` (`sender_node_id`,`sender_app_key`,`event_id`),KEY `inbox_received` (`received_at`)) ENGINE=InnoDB ' . $charset;
        }
        if ( 'consumer_deliveries' === $name ) {
            return 'CREATE TABLE ' . self::quote_identifier( $table ) . ' (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,`delivery_uuid` char(36) NOT NULL,`event_id` char(36) NOT NULL,`destination` varchar(64) NOT NULL,`consumer_key` varchar(128) NOT NULL,`status` varchar(16) NOT NULL,`attempt_count` bigint(20) unsigned NOT NULL,`lease_token` varchar(128) NULL,`lease_expires_at` datetime NULL,`last_result_code` varchar(64) NULL,`created_at` datetime NOT NULL,`processed_at` datetime NULL,PRIMARY KEY (`id`),UNIQUE KEY `consumer_delivery_unique` (`delivery_uuid`),UNIQUE KEY `consumer_event_unique` (`event_id`,`destination`,`consumer_key`),KEY `consumer_pending` (`status`,`created_at`)) ENGINE=InnoDB ' . $charset;
        }
        return 'CREATE TABLE ' . self::quote_identifier( $table ) . ' (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,`tombstone_uuid` char(36) NOT NULL,`event_id` char(36) NOT NULL,`source_identity_sha256` char(64) NOT NULL,`event_sha256` char(64) NOT NULL,`purged_at` datetime NOT NULL,`expires_at` datetime NOT NULL,`created_at` datetime NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `tombstone_uuid_unique` (`tombstone_uuid`),UNIQUE KEY `tombstone_event_unique` (`event_id`),UNIQUE KEY `tombstone_source_identity_unique` (`source_identity_sha256`),KEY `tombstone_expiry` (`expires_at`)) ENGINE=InnoDB ' . $charset;
    }

    private static function current_schema_ready() {
        if ( ! self::valid_prefix() || ! self::valid_table_names() ) {
            return false;
        }
        foreach ( self::tables() as $name => $table ) {
            if ( ! self::table_exists( $table ) || ! self::verify_table( $name, $table ) ) {
                return false;
            }
        }
        return true;
    }

    private static function legacy_schema_ready() {
        if ( ! self::valid_prefix() || ! self::valid_table_names() ) {
            return false;
        }
        foreach ( self::legacy_tables() as $name => $table ) {
            if ( ! self::table_exists( $table ) || ! self::verify_table( $name, $table ) ) {
                return false;
            }
        }
        return true;
    }

    private static function verify_table( $name, $table ) {
        global $wpdb;
        $status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );
        if ( ! is_array( $status ) || $table !== ( $status['Name'] ?? null ) || 'InnoDB' !== ( $status['Engine'] ?? '' ) || ! self::collation_matches( $status['Collation'] ?? '' ) ) {
            return false;
        }
        $columns = $wpdb->get_results( 'SHOW FULL COLUMNS FROM ' . self::quote_identifier( $table ), ARRAY_A );
        $actual = array();
        foreach ( is_array( $columns ) ? $columns : array() as $column ) {
            if ( ! isset( $column['Field'], $column['Type'], $column['Null'] ) || ! array_key_exists( 'Default', $column ) || ! array_key_exists( 'Extra', $column ) || ! array_key_exists( 'Collation', $column ) ) {
                return false;
            }
            $textual = 1 === preg_match( '/^(?:char|varchar|longtext)(?:\(|$)/', strtolower( $column['Type'] ) );
            if ( ( $textual && 0 !== strcasecmp( (string) $status['Collation'], (string) $column['Collation'] ) ) || ( ! $textual && null !== $column['Collation'] ) ) {
                return false;
            }
            $actual[ $column['Field'] ] = array( strtolower( $column['Type'] ), $column['Null'], $column['Default'], strtolower( (string) $column['Extra'] ) );
        }
        $expected_columns = self::expected_columns()[ $name ];
        if ( array_keys( $actual ) !== array_keys( $expected_columns ) || $actual !== $expected_columns ) {
            return false;
        }
        $indexes = $wpdb->get_results( 'SHOW INDEX FROM ' . self::quote_identifier( $table ), ARRAY_A );
        $actual_indexes = array();
        foreach ( is_array( $indexes ) ? $indexes : array() as $index ) {
            if ( ! isset( $index['Key_name'], $index['Seq_in_index'], $index['Column_name'], $index['Non_unique'], $index['Index_type'] ) || ! array_key_exists( 'Sub_part', $index ) || null !== $index['Sub_part'] || 'BTREE' !== strtoupper( $index['Index_type'] ) ) {
                return false;
            }
            $index_name = $index['Key_name'];
            $actual_indexes[ $index_name ]['non_unique'] = (int) $index['Non_unique'];
            $actual_indexes[ $index_name ]['columns'][ (int) $index['Seq_in_index'] ] = $index['Column_name'];
        }
        foreach ( $actual_indexes as &$index ) {
            ksort( $index['columns'], SORT_NUMERIC );
            $index['columns'] = array_values( $index['columns'] );
        }
        unset( $index );
        $expected_indexes = self::expected_indexes()[ $name ];
        ksort( $actual_indexes, SORT_STRING );
        ksort( $expected_indexes, SORT_STRING );
        return $actual_indexes === $expected_indexes;
    }

    private static function expected_columns() {
        $id = array( 'bigint(20) unsigned', 'NO', null, 'auto_increment' );
        $required = static function ( $type ) { return array( $type, 'NO', null, '' ); };
        $nullable = static function ( $type ) { return array( $type, 'YES', null, '' ); };
        return array(
            'catalogs' => array( 'id' => $id, 'catalog_uuid' => $required( 'char(36)' ), 'source_node_id' => $required( 'varchar(64)' ), 'source_app_key' => $required( 'varchar(64)' ), 'capability_key' => $required( 'varchar(512)' ), 'catalog_version' => $required( 'varchar(32)' ), 'catalog_sha256' => $required( 'char(64)' ), 'catalog_json' => $required( 'longtext' ), 'accepted_at' => $required( 'datetime' ) ),
            'events' => array( 'id' => $id, 'event_id' => $required( 'char(36)' ), 'source_identity_sha256' => $required( 'char(64)' ), 'event_sha256' => $required( 'char(64)' ), 'source_node_id' => $required( 'varchar(64)' ), 'source_app_key' => $required( 'varchar(64)' ), 'source_owner' => $required( 'varchar(64)' ), 'source_capability_key' => $required( 'varchar(512)' ), 'catalog_version' => $required( 'varchar(32)' ), 'event_type' => $required( 'varchar(512)' ), 'event_version' => $required( 'varchar(32)' ), 'source_event_reference' => $required( 'varchar(128)' ), 'direction' => $required( 'varchar(8)' ), 'occurred_at' => $required( 'datetime' ), 'produced_at' => $required( 'datetime' ), 'accepted_at' => $required( 'datetime' ), 'retention_until' => $required( 'datetime' ), 'envelope_json' => $required( 'longtext' ) ),
            'outbox' => array( 'id' => $id, 'delivery_uuid' => $required( 'char(36)' ), 'event_id' => $required( 'char(36)' ), 'destination' => $required( 'varchar(64)' ), 'target_node_id' => $required( 'varchar(64)' ), 'target_app_key' => $required( 'varchar(64)' ), 'status' => $required( 'varchar(16)' ), 'attempt_count' => $required( 'bigint(20) unsigned' ), 'next_attempt_at' => $required( 'datetime' ), 'lease_token' => $nullable( 'varchar(128)' ), 'lease_expires_at' => $nullable( 'datetime' ), 'last_result_code' => $nullable( 'varchar(64)' ), 'created_at' => $required( 'datetime' ), 'delivered_at' => $nullable( 'datetime' ) ),
            'inbox' => array( 'id' => $id, 'receipt_uuid' => $required( 'char(36)' ), 'sender_node_id' => $required( 'varchar(64)' ), 'sender_app_key' => $required( 'varchar(64)' ), 'event_id' => $required( 'char(36)' ), 'event_sha256' => $required( 'char(64)' ), 'received_at' => $required( 'datetime' ) ),
            'consumer_deliveries' => array( 'id' => $id, 'delivery_uuid' => $required( 'char(36)' ), 'event_id' => $required( 'char(36)' ), 'destination' => $required( 'varchar(64)' ), 'consumer_key' => $required( 'varchar(128)' ), 'status' => $required( 'varchar(16)' ), 'attempt_count' => $required( 'bigint(20) unsigned' ), 'lease_token' => $nullable( 'varchar(128)' ), 'lease_expires_at' => $nullable( 'datetime' ), 'last_result_code' => $nullable( 'varchar(64)' ), 'created_at' => $required( 'datetime' ), 'processed_at' => $nullable( 'datetime' ) ),
            'tombstones' => array( 'id' => $id, 'tombstone_uuid' => $required( 'char(36)' ), 'event_id' => $required( 'char(36)' ), 'source_identity_sha256' => $required( 'char(64)' ), 'event_sha256' => $required( 'char(64)' ), 'purged_at' => $required( 'datetime' ), 'expires_at' => $required( 'datetime' ), 'created_at' => $required( 'datetime' ) ),
        );
    }

    private static function expected_indexes() {
        return array(
            'catalogs' => array( 'PRIMARY' => array( 'non_unique' => 0, 'columns' => array( 'id' ) ), 'catalog_uuid_unique' => array( 'non_unique' => 0, 'columns' => array( 'catalog_uuid' ) ), 'catalog_tuple_unique' => array( 'non_unique' => 0, 'columns' => array( 'source_node_id', 'source_app_key', 'capability_key', 'catalog_version' ) ) ),
            'events' => array( 'PRIMARY' => array( 'non_unique' => 0, 'columns' => array( 'id' ) ), 'event_id_unique' => array( 'non_unique' => 0, 'columns' => array( 'event_id' ) ), 'source_identity_unique' => array( 'non_unique' => 0, 'columns' => array( 'source_identity_sha256' ) ), 'event_retention' => array( 'non_unique' => 1, 'columns' => array( 'retention_until' ) ) ),
            'outbox' => array( 'PRIMARY' => array( 'non_unique' => 0, 'columns' => array( 'id' ) ), 'outbox_delivery_unique' => array( 'non_unique' => 0, 'columns' => array( 'delivery_uuid' ) ), 'outbox_route_unique' => array( 'non_unique' => 0, 'columns' => array( 'event_id', 'destination', 'target_node_id', 'target_app_key' ) ), 'outbox_due' => array( 'non_unique' => 1, 'columns' => array( 'status', 'next_attempt_at' ) ) ),
            'inbox' => array( 'PRIMARY' => array( 'non_unique' => 0, 'columns' => array( 'id' ) ), 'inbox_receipt_unique' => array( 'non_unique' => 0, 'columns' => array( 'receipt_uuid' ) ), 'inbox_sender_event_unique' => array( 'non_unique' => 0, 'columns' => array( 'sender_node_id', 'sender_app_key', 'event_id' ) ), 'inbox_received' => array( 'non_unique' => 1, 'columns' => array( 'received_at' ) ) ),
            'consumer_deliveries' => array( 'PRIMARY' => array( 'non_unique' => 0, 'columns' => array( 'id' ) ), 'consumer_delivery_unique' => array( 'non_unique' => 0, 'columns' => array( 'delivery_uuid' ) ), 'consumer_event_unique' => array( 'non_unique' => 0, 'columns' => array( 'event_id', 'destination', 'consumer_key' ) ), 'consumer_pending' => array( 'non_unique' => 1, 'columns' => array( 'status', 'created_at' ) ) ),
            'tombstones' => array( 'PRIMARY' => array( 'non_unique' => 0, 'columns' => array( 'id' ) ), 'tombstone_uuid_unique' => array( 'non_unique' => 0, 'columns' => array( 'tombstone_uuid' ) ), 'tombstone_event_unique' => array( 'non_unique' => 0, 'columns' => array( 'event_id' ) ), 'tombstone_source_identity_unique' => array( 'non_unique' => 0, 'columns' => array( 'source_identity_sha256' ) ), 'tombstone_expiry' => array( 'non_unique' => 1, 'columns' => array( 'expires_at' ) ) ),
        );
    }

    private static function collation_matches( $actual ) {
        global $wpdb;
        $definition = $wpdb->get_charset_collate();
        if ( ! preg_match( '/\bCOLLATE\s+([A-Za-z0-9_]+)/i', $definition, $matches ) ) {
            return is_string( $actual ) && '' !== $actual;
        }
        return is_string( $actual ) && 0 === strcasecmp( $matches[1], $actual );
    }

    private static function database_has_error() {
        global $wpdb;
        return ! isset( $wpdb->last_error ) || ! is_string( $wpdb->last_error ) || '' !== $wpdb->last_error;
    }
}
