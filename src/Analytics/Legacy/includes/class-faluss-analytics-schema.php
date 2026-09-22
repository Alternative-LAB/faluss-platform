<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Strict atomic installation for the three private Analytics tables. */
final class Faluss_Analytics_Schema {
    const OPTION = 'faluss_analytics_schema_version';
    const VERSION = '1';

    public static function receipts_table() { return self::table( 'faluss_analytics_receipts' ); }
    public static function daily_metrics_table() { return self::table( 'faluss_analytics_daily_metrics' ); }
    public static function daily_objects_table() { return self::table( 'faluss_analytics_daily_objects' ); }

    public static function activate() {
        if ( ! self::install() ) {
            wp_die( esc_html__( 'Le schéma Faluss Analytics ne peut pas être installé sans risque.', 'faluss-analytics' ) );
        }
    }

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
        if ( self::existing_tables() || null !== get_option( self::OPTION, null ) ) {
            return false;
        }
        $lock = 'faluss_analytics_schema_' . substr( hash( 'sha256', (string) $wpdb->prefix ), 0, 20 );
        if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $lock, 10 ) ) || self::database_has_error() ) {
            return false;
        }
        $temporary = array();
        try {
            if ( self::current_schema_ready() || self::existing_tables() || null !== get_option( self::OPTION, null ) ) {
                return false;
            }
            try {
                $suffix = substr( bin2hex( random_bytes( 8 ) ), 0, 12 );
            } catch ( Throwable $throwable ) {
                return false;
            }
            foreach ( self::tables() as $name => $table ) {
                $temporary[ $name ] = $table . '__fa_' . $suffix;
                if ( strlen( $temporary[ $name ] ) > 64 || self::table_exists( $temporary[ $name ] ) || false === $wpdb->query( self::create_query( $name, $temporary[ $name ] ) ) || self::database_has_error() || ! self::verify_table( $name, $temporary[ $name ] ) ) {
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
            if ( '' === $table || strlen( $table ) > 64 ) { return false; }
        }
        return true;
    }

    private static function tables() {
        return array( 'receipts' => self::receipts_table(), 'daily_metrics' => self::daily_metrics_table(), 'daily_objects' => self::daily_objects_table() );
    }

    private static function existing_tables() {
        foreach ( self::tables() as $table ) {
            if ( self::table_exists( $table ) ) { return true; }
        }
        return false;
    }

    private static function table_exists( $table ) {
        global $wpdb;
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        return is_string( $found ) && hash_equals( $table, $found );
    }

    private static function create_query( $name, $table ) {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        if ( 'receipts' === $name ) {
            return 'CREATE TABLE ' . self::quote_identifier( $table ) . ' (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,`receipt_uuid` char(36) NOT NULL,`event_id` char(36) NOT NULL,`idempotency_sha256` char(64) NOT NULL,`event_sha256` char(64) NOT NULL,`subject_identity_sha256` char(64) NOT NULL,`processed_at` datetime NOT NULL,`expires_at` datetime NOT NULL,`created_at` datetime NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `receipt_uuid_unique` (`receipt_uuid`),UNIQUE KEY `receipt_event_unique` (`event_id`),UNIQUE KEY `receipt_idempotency_unique` (`idempotency_sha256`),KEY `receipt_expiry` (`expires_at`)) ENGINE=InnoDB ' . $charset;
        }
        if ( 'daily_metrics' === $name ) {
            return 'CREATE TABLE ' . self::quote_identifier( $table ) . ' (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,`subject_identity_sha256` char(64) NOT NULL,`aggregate_date` date NOT NULL,`metric_key` varchar(64) NOT NULL,`metric_value` bigint(20) unsigned NOT NULL,`created_at` datetime NOT NULL,`updated_at` datetime NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `metric_subject_date_key` (`subject_identity_sha256`,`aggregate_date`,`metric_key`),KEY `metric_retention` (`aggregate_date`)) ENGINE=InnoDB ' . $charset;
        }
        return 'CREATE TABLE ' . self::quote_identifier( $table ) . ' (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,`subject_identity_sha256` char(64) NOT NULL,`aggregate_date` date NOT NULL,`object_type` varchar(16) NOT NULL,`object_reference` varchar(84) NOT NULL,`metric_value` bigint(20) unsigned NOT NULL,`created_at` datetime NOT NULL,`updated_at` datetime NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `object_subject_date_reference` (`subject_identity_sha256`,`aggregate_date`,`object_type`,`object_reference`),KEY `object_retention` (`aggregate_date`)) ENGINE=InnoDB ' . $charset;
    }

    private static function current_schema_ready() {
        if ( ! self::valid_prefix() || ! self::valid_table_names() ) { return false; }
        foreach ( self::tables() as $name => $table ) {
            if ( ! self::table_exists( $table ) || ! self::verify_table( $name, $table ) ) { return false; }
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
            if ( ! isset( $column['Field'], $column['Type'], $column['Null'] ) || ! array_key_exists( 'Default', $column ) || ! array_key_exists( 'Extra', $column ) || ! array_key_exists( 'Collation', $column ) ) { return false; }
            $textual = 1 === preg_match( '/^(?:char|varchar)(?:\(|$)/', strtolower( $column['Type'] ) );
            if ( ( $textual && 0 !== strcasecmp( (string) $status['Collation'], (string) $column['Collation'] ) ) || ( ! $textual && null !== $column['Collation'] ) ) { return false; }
            $actual[ $column['Field'] ] = array( strtolower( $column['Type'] ), $column['Null'], $column['Default'], strtolower( (string) $column['Extra'] ) );
        }
        $expected_columns = self::expected_columns()[ $name ];
        if ( array_keys( $actual ) !== array_keys( $expected_columns ) || $actual !== $expected_columns ) { return false; }
        $indexes = $wpdb->get_results( 'SHOW INDEX FROM ' . self::quote_identifier( $table ), ARRAY_A );
        $actual_indexes = array();
        foreach ( is_array( $indexes ) ? $indexes : array() as $index ) {
            if ( ! isset( $index['Key_name'], $index['Seq_in_index'], $index['Column_name'], $index['Non_unique'], $index['Index_type'] ) || ! array_key_exists( 'Sub_part', $index ) || null !== $index['Sub_part'] || 'BTREE' !== strtoupper( $index['Index_type'] ) ) { return false; }
            $key = $index['Key_name'];
            $actual_indexes[ $key ]['non_unique'] = (int) $index['Non_unique'];
            $actual_indexes[ $key ]['columns'][ (int) $index['Seq_in_index'] ] = $index['Column_name'];
        }
        foreach ( $actual_indexes as &$index ) { ksort( $index['columns'], SORT_NUMERIC ); $index['columns'] = array_values( $index['columns'] ); }
        unset( $index );
        $expected_indexes = self::expected_indexes()[ $name];
        ksort( $actual_indexes, SORT_STRING ); ksort( $expected_indexes, SORT_STRING );
        return $actual_indexes === $expected_indexes;
    }

    private static function expected_columns() {
        $id = array( 'bigint(20) unsigned', 'NO', null, 'auto_increment' );
        $required = static function ( $type ) { return array( $type, 'NO', null, '' ); };
        return array(
            'receipts' => array( 'id' => $id, 'receipt_uuid' => $required( 'char(36)' ), 'event_id' => $required( 'char(36)' ), 'idempotency_sha256' => $required( 'char(64)' ), 'event_sha256' => $required( 'char(64)' ), 'subject_identity_sha256' => $required( 'char(64)' ), 'processed_at' => $required( 'datetime' ), 'expires_at' => $required( 'datetime' ), 'created_at' => $required( 'datetime' ) ),
            'daily_metrics' => array( 'id' => $id, 'subject_identity_sha256' => $required( 'char(64)' ), 'aggregate_date' => $required( 'date' ), 'metric_key' => $required( 'varchar(64)' ), 'metric_value' => $required( 'bigint(20) unsigned' ), 'created_at' => $required( 'datetime' ), 'updated_at' => $required( 'datetime' ) ),
            'daily_objects' => array( 'id' => $id, 'subject_identity_sha256' => $required( 'char(64)' ), 'aggregate_date' => $required( 'date' ), 'object_type' => $required( 'varchar(16)' ), 'object_reference' => $required( 'varchar(84)' ), 'metric_value' => $required( 'bigint(20) unsigned' ), 'created_at' => $required( 'datetime' ), 'updated_at' => $required( 'datetime' ) ),
        );
    }

    private static function expected_indexes() {
        return array(
            'receipts' => array( 'PRIMARY' => array( 'non_unique' => 0, 'columns' => array( 'id' ) ), 'receipt_uuid_unique' => array( 'non_unique' => 0, 'columns' => array( 'receipt_uuid' ) ), 'receipt_event_unique' => array( 'non_unique' => 0, 'columns' => array( 'event_id' ) ), 'receipt_idempotency_unique' => array( 'non_unique' => 0, 'columns' => array( 'idempotency_sha256' ) ), 'receipt_expiry' => array( 'non_unique' => 1, 'columns' => array( 'expires_at' ) ) ),
            'daily_metrics' => array( 'PRIMARY' => array( 'non_unique' => 0, 'columns' => array( 'id' ) ), 'metric_subject_date_key' => array( 'non_unique' => 0, 'columns' => array( 'subject_identity_sha256', 'aggregate_date', 'metric_key' ) ), 'metric_retention' => array( 'non_unique' => 1, 'columns' => array( 'aggregate_date' ) ) ),
            'daily_objects' => array( 'PRIMARY' => array( 'non_unique' => 0, 'columns' => array( 'id' ) ), 'object_subject_date_reference' => array( 'non_unique' => 0, 'columns' => array( 'subject_identity_sha256', 'aggregate_date', 'object_type', 'object_reference' ) ), 'object_retention' => array( 'non_unique' => 1, 'columns' => array( 'aggregate_date' ) ) ),
        );
    }

    private static function collation_matches( $collation ) {
        global $wpdb;
        $definition = (string) $wpdb->get_charset_collate();
        if ( 1 !== preg_match( '/\bCOLLATE\s+([A-Za-z0-9_]+)/i', $definition, $matches ) ) {
            return is_string( $collation ) && '' !== $collation;
        }
        return is_string( $collation ) && 0 === strcasecmp( $matches[1], $collation );
    }

    private static function database_has_error() {
        global $wpdb;
        return ! isset( $wpdb->last_error ) || ! is_string( $wpdb->last_error ) || '' !== $wpdb->last_error;
    }
}
