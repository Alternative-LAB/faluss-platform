<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Fresh-only, verified schema for Federation technical state. */
final class Faluss_Federation_Schema {
    const OPTION = 'faluss_federation_schema_version';
    const VERSION = '1';
    const RETENTION_SECONDS = 900;
    const AUDIT_RETENTION_SECONDS = 2592000;

    public static function peers_table() { return self::table( 'faluss_federation_peers' ); }
    public static function request_bindings_table() { return self::table( 'faluss_federation_request_bindings' ); }
    public static function nonces_table() { return self::table( 'faluss_federation_nonces' ); }
    public static function audit_table() { return self::table( 'faluss_federation_audit' ); }

    public static function activate() {
        if ( ! self::install() ) {
            wp_die( esc_html__( 'Le schéma Faluss Federation ne peut pas être installé sans risque.', 'faluss-federation' ) );
        }
    }

    public static function is_ready() {
        return self::VERSION === (string) get_option( self::OPTION ) && self::current_schema_ready();
    }

    /** Never repairs, mutates or adopts a pre-existing partial schema. */
    public static function install() {
        global $wpdb;
        if ( ! self::valid_prefix() || ! method_exists( $wpdb, 'get_charset_collate' ) ) {
            return false;
        }
        if ( self::current_schema_ready() ) {
            return self::VERSION === (string) get_option( self::OPTION );
        }
        if ( self::existing_tables( self::tables() ) || null !== get_option( self::OPTION, null ) ) {
            return false;
        }
        $lock = 'faluss_federation_schema_' . substr( hash( 'sha256', (string) $wpdb->prefix ), 0, 24 );
        if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $lock, 10 ) ) ) {
            return false;
        }
        $temporary = array();
        try {
            if ( self::current_schema_ready() || self::existing_tables( self::tables() ) || null !== get_option( self::OPTION, null ) ) {
                return false;
            }
            try {
                $suffix = bin2hex( random_bytes( 8 ) );
            } catch ( Exception $exception ) {
                return false;
            }
            foreach ( self::tables() as $name => $table ) {
                $temporary[ $name ] = $table . '__ff_' . substr( $suffix, 0, 12 );
                if ( strlen( $temporary[ $name ] ) > 64 || self::table_exists( $temporary[ $name ] ) ) {
                    return false;
                }
                if ( false === $wpdb->query( self::create_query( $name, $temporary[ $name ] ) ) || ! self::verify_table( $name, $temporary[ $name ] ) ) {
                    return false;
                }
            }
            $renames = array();
            foreach ( $temporary as $name => $table ) {
                $renames[] = self::quote_identifier( $table ) . ' TO ' . self::quote_identifier( self::tables()[ $name ] );
            }
            if ( false === $wpdb->query( 'RENAME TABLE ' . implode( ',', $renames ) ) || ! self::current_schema_ready() ) {
                return false;
            }
            return update_option( self::OPTION, self::VERSION, false );
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
        return array(
            'version' => self::VERSION,
            'stored_version' => (string) get_option( self::OPTION ),
            'ready' => self::is_ready(),
            'tables' => self::tables(),
        );
    }

    /** Atomic replay and request-binding consumption, before a provider can run. */
    public static function consume_replay_and_limit( $request, $body_hash, $limit ) {
        global $wpdb;
        if ( ! self::is_ready() || ! is_array( $request ) || ! is_string( $body_hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $body_hash ) || ! is_int( $limit ) || $limit < 1 ) {
            return new WP_Error( 'faluss_federation_fail_closed' );
        }
        $sender = $request['sender']['node_id'] ?? '';
        $key_id = $request['sender']['key_id'] ?? '';
        $request_id = $request['request_id'] ?? '';
        $nonce = $request['nonce'] ?? '';
        $operation = $request['operation'] ?? '';
        if ( ! self::is_node( $sender ) || ! self::is_key_id( $key_id ) || ! self::is_uuid( $request_id ) || ! Faluss_Federation_Crypto::is_nonce( $nonce ) || ! in_array( $operation, Faluss_Federation_Policy::operations(), true ) ) {
            return new WP_Error( 'faluss_federation_invalid_request' );
        }
        return self::consume_transaction( $request, $body_hash, $limit );
    }

    /** @return true|WP_Error */
    private static function consume_transaction( $request, $body_hash, $limit ) {
        global $wpdb;
        $sender = $request['sender']['node_id'];
        $key_id = $request['sender']['key_id'];
        $operation = $request['operation'];
        $lock_name = self::rate_lock_name( $wpdb->prefix, $sender, $key_id, $operation );
        if ( is_wp_error( $lock_name ) ) {
            return new WP_Error( 'faluss_federation_fail_closed' );
        }
        $acquired = false;
        $committed = false;
        $released = false;
        $result = new WP_Error( 'faluss_federation_fail_closed' );
        try {
            $lock_result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $lock_name, 1 ) );
            if ( self::lock_result_is_one( $lock_result ) && ! self::database_has_error() ) {
                $acquired = true;
                $result = self::consume_locked_transaction( $request, $body_hash, $limit, $committed );
            }
        } catch ( Throwable $exception ) {
            if ( $acquired ) {
                try {
                    self::rollback_safely();
                } catch ( Throwable $rollback_exception ) {
                    // The result remains fail-closed and the acquired lock is still released below.
                }
            }
            $result = new WP_Error( 'faluss_federation_fail_closed' );
        }
        if ( $acquired ) {
            try {
                $release_result = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
                $released = self::lock_result_is_one( $release_result ) && ! self::database_has_error();
            } catch ( Throwable $exception ) {
                $released = false;
            }
            if ( ! $released ) {
                $result = new WP_Error( 'faluss_federation_fail_closed' );
            }
        }
        if ( $committed && $released ) {
            self::purge_opportunistically();
        }
        return $result;
    }

    /** The bucket lock remains held for this entire transaction. @return true|WP_Error */
    private static function consume_locked_transaction( $request, $body_hash, $limit, &$committed ) {
        global $wpdb;
        $sender = $request['sender']['node_id'];
        $key_id = $request['sender']['key_id'];
        $request_id = $request['request_id'];
        $nonce = $request['nonce'];
        $operation = $request['operation'];
        $now = gmdate( 'Y-m-d H:i:s' );
        $expires = gmdate( 'Y-m-d H:i:s', time() + self::RETENTION_SECONDS );
        $nonce_hash = hash( 'sha256', $nonce );
        try {
            $started = $wpdb->query( 'START TRANSACTION' );
            if ( false === $started || self::database_has_error() ) {
                self::rollback_safely();
                return new WP_Error( 'faluss_federation_fail_closed' );
            }
            $existing = $wpdb->get_var( $wpdb->prepare( 'SELECT request_body_sha256 FROM ' . self::quote_identifier( self::request_bindings_table() ) . ' WHERE sender_node_id = %s AND request_id = %s FOR UPDATE', $sender, $request_id ) );
            if ( self::database_has_error() ) {
                self::rollback_safely();
                return new WP_Error( 'faluss_federation_fail_closed' );
            }
            if ( null !== $existing ) {
                return self::rollback_safely() ? new WP_Error( 'faluss_federation_replay_rejected' ) : new WP_Error( 'faluss_federation_fail_closed' );
            }
            $nonce_exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::quote_identifier( self::nonces_table() ) . ' WHERE sender_node_id = %s AND sender_key_id = %s AND nonce_hash = %s FOR UPDATE', $sender, $key_id, $nonce_hash ) );
            if ( self::database_has_error() ) {
                self::rollback_safely();
                return new WP_Error( 'faluss_federation_fail_closed' );
            }
            if ( null !== $nonce_exists ) {
                return self::rollback_safely() ? new WP_Error( 'faluss_federation_replay_rejected' ) : new WP_Error( 'faluss_federation_fail_closed' );
            }
            $rate_count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::quote_identifier( self::nonces_table() ) . ' WHERE sender_node_id = %s AND sender_key_id = %s AND operation_name = %s AND consumed_at >= %s', $sender, $key_id, $operation, gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );
            if ( self::database_has_error() || null === $rate_count || ! is_numeric( $rate_count ) ) {
                self::rollback_safely();
                return new WP_Error( 'faluss_federation_fail_closed' );
            }
            $rate_count = (int) $rate_count;
            $binding_inserted = $wpdb->insert( self::request_bindings_table(), array( 'sender_node_id' => $sender, 'request_id' => $request_id, 'request_body_sha256' => $body_hash, 'created_at' => $now, 'expires_at' => $expires ), array( '%s', '%s', '%s', '%s', '%s' ) );
            if ( false === $binding_inserted || self::database_has_error() ) {
                if ( ! self::rollback_safely() ) {
                    return new WP_Error( 'faluss_federation_fail_closed' );
                }
                return self::confirmed_replay( $sender, $key_id, $request_id, $nonce_hash ) ? new WP_Error( 'faluss_federation_replay_rejected' ) : new WP_Error( 'faluss_federation_fail_closed' );
            }
            $nonce_inserted = $wpdb->insert( self::nonces_table(), array( 'sender_node_id' => $sender, 'sender_key_id' => $key_id, 'nonce_hash' => $nonce_hash, 'operation_name' => $operation, 'consumed_at' => $now, 'expires_at' => $expires ), array( '%s', '%s', '%s', '%s', '%s', '%s' ) );
            if ( false === $nonce_inserted || self::database_has_error() ) {
                if ( ! self::rollback_safely() ) {
                    return new WP_Error( 'faluss_federation_fail_closed' );
                }
                return self::confirmed_replay( $sender, $key_id, $request_id, $nonce_hash ) ? new WP_Error( 'faluss_federation_replay_rejected' ) : new WP_Error( 'faluss_federation_fail_closed' );
            }
            $committed = $wpdb->query( 'COMMIT' );
            if ( false === $committed || self::database_has_error() ) {
                self::rollback_safely();
                return new WP_Error( 'faluss_federation_fail_closed' );
            }
            $committed = true;
            return $rate_count >= $limit ? new WP_Error( 'faluss_federation_rate_limited' ) : true;
        } catch ( Throwable $exception ) {
            self::rollback_safely();
            return new WP_Error( 'faluss_federation_fail_closed' );
        }
    }

    /** @return string|WP_Error */
    private static function rate_lock_name( $prefix, $sender, $key_id, $operation ) {
        foreach ( array( $prefix, $sender, $key_id, $operation ) as $component ) {
            if ( ! is_string( $component ) || '' === $component ) {
                return new WP_Error( 'faluss_federation_fail_closed' );
            }
        }
        if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $prefix ) ) {
            return new WP_Error( 'faluss_federation_fail_closed' );
        }
        $material = '';
        foreach ( array( $prefix, $sender, $key_id, $operation ) as $component ) {
            $material .= strlen( $component ) . ':' . $component . ';';
        }
        return 'faluss_fed_rate_' . substr( hash( 'sha256', $material ), 0, 48 );
    }

    private static function lock_result_is_one( $value ) {
        return 1 === $value || '1' === $value;
    }

    private static function confirmed_replay( $sender, $key_id, $request_id, $nonce_hash ) {
        global $wpdb;
        $existing = $wpdb->get_var( $wpdb->prepare( 'SELECT request_body_sha256 FROM ' . self::quote_identifier( self::request_bindings_table() ) . ' WHERE sender_node_id = %s AND request_id = %s', $sender, $request_id ) );
        if ( self::database_has_error() ) {
            return false;
        }
        $nonce_exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::quote_identifier( self::nonces_table() ) . ' WHERE sender_node_id = %s AND sender_key_id = %s AND nonce_hash = %s', $sender, $key_id, $nonce_hash ) );
        return ! self::database_has_error() && ( null !== $existing || null !== $nonce_exists );
    }

    private static function rollback_safely() {
        global $wpdb;
        $rolled_back = $wpdb->query( 'ROLLBACK' );
        return false !== $rolled_back && ! self::database_has_error();
    }

    private static function database_has_error() {
        global $wpdb;
        return ! isset( $wpdb->last_error ) || ! is_string( $wpdb->last_error ) || '' !== $wpdb->last_error;
    }

    /** Audit accepts only opaque technical values and never payload, key or subject material. */
    public static function audit( $data ) {
        global $wpdb;
        if ( ! self::is_ready() || ! is_array( $data ) ) {
            return false;
        }
        $allowed = array( 'request_id', 'sender_node_id', 'recipient_node_id', 'operation_name', 'capability_key', 'result_code', 'opaque_code', 'duration_ms' );
        if ( array_diff( array_keys( $data ), $allowed ) ) {
            return false;
        }
        $operation = isset( $data['operation_name'] ) && in_array( $data['operation_name'], Faluss_Federation_Policy::operations(), true ) ? $data['operation_name'] : null;
        $is_event_publish = 'event.publish' === $operation;
        $request_id = ! $is_event_publish && isset( $data['request_id'] ) && self::is_uuid( $data['request_id'] ) ? $data['request_id'] : null;
        $sender = isset( $data['sender_node_id'] ) && self::is_node( $data['sender_node_id'] ) ? $data['sender_node_id'] : null;
        $recipient = isset( $data['recipient_node_id'] ) && self::is_node( $data['recipient_node_id'] ) ? $data['recipient_node_id'] : null;
        $capability = isset( $data['capability_key'] ) && is_string( $data['capability_key'] ) && strlen( $data['capability_key'] ) <= 160 ? $data['capability_key'] : null;
        $result = isset( $data['result_code'] ) && is_string( $data['result_code'] ) && 1 === preg_match( '/^[a-z_]{1,64}$/D', $data['result_code'] ) ? $data['result_code'] : 'fail_closed';
        $opaque = $is_event_publish ? '' : ( isset( $data['opaque_code'] ) && is_string( $data['opaque_code'] ) && 1 === preg_match( '/^[a-z_]{1,64}$/D', $data['opaque_code'] ) ? $data['opaque_code'] : 'unknown' );
        $duration = isset( $data['duration_ms'] ) ? absint( $data['duration_ms'] ) : 0;
        return false !== $wpdb->insert( self::audit_table(), array( 'audit_uuid' => wp_generate_uuid4(), 'request_id' => $request_id, 'sender_node_id' => $sender, 'recipient_node_id' => $recipient, 'operation_name' => $operation, 'capability_key' => $capability, 'result_code' => $result, 'opaque_code' => $opaque, 'duration_ms' => $duration, 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::AUDIT_RETENTION_SECONDS ) ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ) );
    }

    /** Bounded operational counters, never audit payload or identifiers. */
    public static function audit_counts() {
        global $wpdb;
        if ( ! self::is_ready() ) {
            return array();
        }
        $rows = $wpdb->get_results( 'SELECT result_code,COUNT(*) AS count_value FROM ' . self::quote_identifier( self::audit_table() ) . ' GROUP BY result_code ORDER BY result_code ASC LIMIT 32', ARRAY_A );
        $counts = array();
        foreach ( is_array( $rows ) ? $rows : array() as $row ) {
            if ( is_string( $row['result_code'] ) && isset( $row['count_value'] ) ) {
                $counts[ $row['result_code'] ] = (int) $row['count_value'];
            }
        }
        return $counts;
    }

    public static function purge_opportunistically() {
        global $wpdb;
        if ( ! self::is_ready() ) {
            return;
        }
        $now = gmdate( 'Y-m-d H:i:s' );
        foreach ( array( self::request_bindings_table(), self::nonces_table(), self::audit_table() ) as $table ) {
            $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::quote_identifier( $table ) . ' WHERE expires_at < %s LIMIT 50', $now ) );
        }
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

    private static function tables() {
        return array( 'peers' => self::peers_table(), 'request_bindings' => self::request_bindings_table(), 'nonces' => self::nonces_table(), 'audit' => self::audit_table() );
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
        return null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    }

    private static function create_query( $name, $table ) {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        if ( 'peers' === $name ) {
            return 'CREATE TABLE ' . self::quote_identifier( $table ) . ' (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,`peer_node_id` varchar(64) NOT NULL,`peer_app_key` varchar(64) NOT NULL,`canonical_origin` varchar(191) NOT NULL,`key_id` varchar(128) NOT NULL,`public_key` varchar(64) NOT NULL,`key_state` varchar(16) NOT NULL,`valid_from` datetime NOT NULL,`valid_until` datetime NOT NULL,`operations_json` longtext NOT NULL,`owner_apps_json` longtext NOT NULL,`capabilities_json` longtext NOT NULL,`audiences_json` longtext NOT NULL,`created_at` datetime NOT NULL,`updated_at` datetime NOT NULL,`revoked_at` datetime NULL,PRIMARY KEY (`id`),UNIQUE KEY `peer_key_unique` (`peer_node_id`,`peer_app_key`,`key_id`),KEY `peer_state_validity` (`peer_node_id`,`peer_app_key`,`key_state`,`valid_until`)) ENGINE=InnoDB ' . $charset;
        }
        if ( 'request_bindings' === $name ) {
            return 'CREATE TABLE ' . self::quote_identifier( $table ) . ' (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,`sender_node_id` varchar(64) NOT NULL,`request_id` char(36) NOT NULL,`request_body_sha256` char(64) NOT NULL,`created_at` datetime NOT NULL,`expires_at` datetime NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `sender_request_unique` (`sender_node_id`,`request_id`),KEY `binding_expiry` (`expires_at`)) ENGINE=InnoDB ' . $charset;
        }
        if ( 'nonces' === $name ) {
            return 'CREATE TABLE ' . self::quote_identifier( $table ) . ' (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,`sender_node_id` varchar(64) NOT NULL,`sender_key_id` varchar(128) NOT NULL,`nonce_hash` char(64) NOT NULL,`operation_name` varchar(32) NOT NULL,`consumed_at` datetime NOT NULL,`expires_at` datetime NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `sender_key_nonce_unique` (`sender_node_id`,`sender_key_id`,`nonce_hash`),KEY `rate_window` (`sender_node_id`,`sender_key_id`,`operation_name`,`consumed_at`),KEY `nonce_expiry` (`expires_at`)) ENGINE=InnoDB ' . $charset;
        }
        return 'CREATE TABLE ' . self::quote_identifier( $table ) . ' (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,`audit_uuid` char(36) NOT NULL,`request_id` char(36) NULL,`sender_node_id` varchar(64) NULL,`recipient_node_id` varchar(64) NULL,`operation_name` varchar(32) NULL,`capability_key` varchar(160) NULL,`result_code` varchar(64) NOT NULL,`opaque_code` varchar(64) NOT NULL,`duration_ms` bigint(20) unsigned NOT NULL,`created_at` datetime NOT NULL,`expires_at` datetime NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `audit_uuid_unique` (`audit_uuid`),KEY `audit_created` (`created_at`),KEY `audit_expiry` (`expires_at`)) ENGINE=InnoDB ' . $charset;
    }

    private static function current_schema_ready() {
        foreach ( self::tables() as $name => $table ) {
            if ( ! self::table_exists( $table ) || ! self::verify_table( $name, $table ) ) {
                return false;
            }
        }
        return true;
    }

    private static function verify_table( $name, $table ) {
        global $wpdb;
        $status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ), ARRAY_A );
        if ( ! is_array( $status ) || 'InnoDB' !== ( $status['Engine'] ?? '' ) || ! self::collation_matches( $status['Collation'] ?? '' ) ) {
            return false;
        }
        $columns = $wpdb->get_results( 'SHOW COLUMNS FROM ' . self::quote_identifier( $table ), ARRAY_A );
        $actual = array();
        foreach ( is_array( $columns ) ? $columns : array() as $column ) {
            $actual[ $column['Field'] ] = array( strtolower( $column['Type'] ), $column['Null'] );
        }
        $expected_columns = self::expected_columns()[ $name ];
        if ( array_diff( array_keys( $actual ), array_keys( $expected_columns ) ) || array_diff( array_keys( $expected_columns ), array_keys( $actual ) ) ) {
            return false;
        }
        foreach ( $expected_columns as $field => $expectation ) {
            if ( ! isset( $actual[ $field ] ) || $actual[ $field ][0] !== $expectation[0] || $actual[ $field ][1] !== $expectation[1] ) {
                return false;
            }
        }
        $indexes = $wpdb->get_results( 'SHOW INDEX FROM ' . self::quote_identifier( $table ), ARRAY_A );
        $actual_indexes = array();
        foreach ( is_array( $indexes ) ? $indexes : array() as $index ) {
            if ( ! isset( $index['Key_name'], $index['Seq_in_index'], $index['Column_name'], $index['Non_unique'] ) ) {
                return false;
            }
            $index_name = $index['Key_name'];
            $actual_indexes[ $index_name ]['non_unique'] = (int) $index['Non_unique'];
            $actual_indexes[ $index_name ]['columns'][ (int) $index['Seq_in_index'] ] = $index['Column_name'];
        }
        $expected_indexes = self::expected_indexes()[ $name ];
        if ( array_diff( array_keys( $actual_indexes ), array_keys( $expected_indexes ) ) || array_diff( array_keys( $expected_indexes ), array_keys( $actual_indexes ) ) ) {
            return false;
        }
        foreach ( $expected_indexes as $index_name => $expected ) {
            if ( ! isset( $actual_indexes[ $index_name ] ) || $expected['non_unique'] !== $actual_indexes[ $index_name ]['non_unique'] ) {
                return false;
            }
            ksort( $actual_indexes[ $index_name ]['columns'], SORT_NUMERIC );
            if ( $expected['columns'] !== array_values( $actual_indexes[ $index_name ]['columns'] ) ) {
                return false;
            }
        }
        return true;
    }

    private static function expected_columns() {
        return array(
            'peers' => array( 'id' => array( 'bigint(20) unsigned', 'NO' ), 'peer_node_id' => array( 'varchar(64)', 'NO' ), 'peer_app_key' => array( 'varchar(64)', 'NO' ), 'canonical_origin' => array( 'varchar(191)', 'NO' ), 'key_id' => array( 'varchar(128)', 'NO' ), 'public_key' => array( 'varchar(64)', 'NO' ), 'key_state' => array( 'varchar(16)', 'NO' ), 'valid_from' => array( 'datetime', 'NO' ), 'valid_until' => array( 'datetime', 'NO' ), 'operations_json' => array( 'longtext', 'NO' ), 'owner_apps_json' => array( 'longtext', 'NO' ), 'capabilities_json' => array( 'longtext', 'NO' ), 'audiences_json' => array( 'longtext', 'NO' ), 'created_at' => array( 'datetime', 'NO' ), 'updated_at' => array( 'datetime', 'NO' ), 'revoked_at' => array( 'datetime', 'YES' ) ),
            'request_bindings' => array( 'id' => array( 'bigint(20) unsigned', 'NO' ), 'sender_node_id' => array( 'varchar(64)', 'NO' ), 'request_id' => array( 'char(36)', 'NO' ), 'request_body_sha256' => array( 'char(64)', 'NO' ), 'created_at' => array( 'datetime', 'NO' ), 'expires_at' => array( 'datetime', 'NO' ) ),
            'nonces' => array( 'id' => array( 'bigint(20) unsigned', 'NO' ), 'sender_node_id' => array( 'varchar(64)', 'NO' ), 'sender_key_id' => array( 'varchar(128)', 'NO' ), 'nonce_hash' => array( 'char(64)', 'NO' ), 'operation_name' => array( 'varchar(32)', 'NO' ), 'consumed_at' => array( 'datetime', 'NO' ), 'expires_at' => array( 'datetime', 'NO' ) ),
            'audit' => array( 'id' => array( 'bigint(20) unsigned', 'NO' ), 'audit_uuid' => array( 'char(36)', 'NO' ), 'request_id' => array( 'char(36)', 'YES' ), 'sender_node_id' => array( 'varchar(64)', 'YES' ), 'recipient_node_id' => array( 'varchar(64)', 'YES' ), 'operation_name' => array( 'varchar(32)', 'YES' ), 'capability_key' => array( 'varchar(160)', 'YES' ), 'result_code' => array( 'varchar(64)', 'NO' ), 'opaque_code' => array( 'varchar(64)', 'NO' ), 'duration_ms' => array( 'bigint(20) unsigned', 'NO' ), 'created_at' => array( 'datetime', 'NO' ), 'expires_at' => array( 'datetime', 'NO' ) ),
        );
    }

    private static function expected_indexes() {
        return array(
            'peers' => array( 'PRIMARY' => array( 'non_unique' => 0, 'columns' => array( 'id' ) ), 'peer_key_unique' => array( 'non_unique' => 0, 'columns' => array( 'peer_node_id', 'peer_app_key', 'key_id' ) ), 'peer_state_validity' => array( 'non_unique' => 1, 'columns' => array( 'peer_node_id', 'peer_app_key', 'key_state', 'valid_until' ) ) ),
            'request_bindings' => array( 'PRIMARY' => array( 'non_unique' => 0, 'columns' => array( 'id' ) ), 'sender_request_unique' => array( 'non_unique' => 0, 'columns' => array( 'sender_node_id', 'request_id' ) ), 'binding_expiry' => array( 'non_unique' => 1, 'columns' => array( 'expires_at' ) ) ),
            'nonces' => array( 'PRIMARY' => array( 'non_unique' => 0, 'columns' => array( 'id' ) ), 'sender_key_nonce_unique' => array( 'non_unique' => 0, 'columns' => array( 'sender_node_id', 'sender_key_id', 'nonce_hash' ) ), 'rate_window' => array( 'non_unique' => 1, 'columns' => array( 'sender_node_id', 'sender_key_id', 'operation_name', 'consumed_at' ) ), 'nonce_expiry' => array( 'non_unique' => 1, 'columns' => array( 'expires_at' ) ) ),
            'audit' => array( 'PRIMARY' => array( 'non_unique' => 0, 'columns' => array( 'id' ) ), 'audit_uuid_unique' => array( 'non_unique' => 0, 'columns' => array( 'audit_uuid' ) ), 'audit_created' => array( 'non_unique' => 1, 'columns' => array( 'created_at' ) ), 'audit_expiry' => array( 'non_unique' => 1, 'columns' => array( 'expires_at' ) ) ),
        );
    }

    private static function collation_matches( $actual ) {
        global $wpdb;
        $definition = $wpdb->get_charset_collate();
        if ( ! preg_match( '/\\bCOLLATE\\s+([A-Za-z0-9_]+)/i', $definition, $matches ) ) {
            return is_string( $actual ) && '' !== $actual;
        }
        return is_string( $actual ) && 0 === strcasecmp( $matches[1], $actual );
    }

    private static function is_node( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-z][a-z0-9-]{1,63}$/D', $value ); }
    private static function is_key_id( $value ) { return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{7,127}$/D', $value ); }
    private static function is_uuid( $value ) { return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value ); }
}
