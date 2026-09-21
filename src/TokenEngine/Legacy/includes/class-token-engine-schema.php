<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Versioned, additive schema installer for the generic engine and private connector access. */
final class Token_Engine_Schema {
    const OPTION = 'token_engine_schema_version';
    const VERSION = '5';
    const V4_VERSION = '4';
    const V3_VERSION = '3';
    const V2_VERSION = '2';
    const LEGACY_VERSION = '1';

    public static function projects_table() { return self::table( 'token_engine_projects' ); }
    public static function rules_table() { return self::table( 'token_engine_rules' ); }
    public static function ledger_table() { return self::table( 'token_engine_ledger' ); }
    public static function pf_ledger_table() { return self::table( 'token_engine_pf_ledger' ); }
    public static function connector_tokens_table() { return self::table( 'token_engine_connector_tokens' ); }
    public static function entitlement_definitions_table() { return self::table( 'token_engine_entitlement_definitions' ); }
    public static function entitlement_grants_table() { return self::table( 'token_engine_entitlement_grants' ); }

    public static function maybe_install() { return self::install(); }

    /** Activation fails closed when an existing schema is incomplete or divergent. */
    public static function activate() {
        if ( ! self::install() ) {
            wp_die( esc_html__( 'Le schéma Token Engine ne peut pas être installé sans risque.', 'token-engine' ) );
        }
    }

    public static function is_ready() {
        return self::VERSION === get_option( self::OPTION ) && self::current_schema_ready();
    }

    public static function install() {
        global $wpdb;
        if ( ! self::valid_prefix() || ! method_exists( $wpdb, 'get_charset_collate' ) ) {
            return false;
        }
        if ( self::current_schema_ready() ) {
            update_option( self::OPTION, self::VERSION, false );
            return true;
        }
        $lock = 'token_engine_' . substr( hash( 'sha256', (string) $wpdb->prefix ), 0, 32 );
        if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $lock, 10 ) ) ) {
            return false;
        }
        try {
            if ( self::current_schema_ready() ) {
                update_option( self::OPTION, self::VERSION, false );
                return true;
            }
            if ( self::legacy_schema_ready() && ! self::migrate_v1_to_v2() ) {
                return false;
            }
            if ( self::v2_schema_ready() && ! self::migrate_v2_to_v3() ) {
                return false;
            }
            if ( self::v3_schema_ready() && ! self::migrate_v3_to_v4() ) {
                return false;
            }
            if ( self::v4_schema_ready() && ! self::migrate_v4_to_v5() ) {
                return false;
            }
            if ( self::current_schema_ready() ) {
                update_option( self::OPTION, self::VERSION, false );
                return true;
            }
            if ( self::existing_tables( self::tables() ) ) {
                return false;
            }
            foreach ( self::tables() as $name => $table ) {
                if ( false === $wpdb->query( self::create_query( $name, $table, self::VERSION ) ) ) {
                    return false;
                }
            }
            if ( ! self::current_schema_ready() ) {
                return false;
            }
            update_option( self::OPTION, self::VERSION, false );
            return true;
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        }
    }

    private static function migrate_v1_to_v2() {
        global $wpdb;
        $projects = self::projects_table();
        $alter = 'ALTER TABLE `' . $projects . '` ADD COLUMN `connector_client_id` varchar(64) NULL AFTER `active`, ADD COLUMN `connector_secret_hash` varchar(255) NULL AFTER `connector_client_id`, ADD COLUMN `connector_secret_version` char(36) NULL AFTER `connector_secret_hash`, ADD COLUMN `connector_permissions` varchar(191) NULL AFTER `connector_secret_version`, ADD UNIQUE KEY `connector_client_id_unique` (`connector_client_id`)';
        if ( false === $wpdb->query( $alter ) ) {
            return false;
        }
        if ( false === $wpdb->query( self::create_query( 'connector_tokens', self::connector_tokens_table(), self::V2_VERSION ) ) ) {
            return false;
        }
        update_option( self::OPTION, self::V2_VERSION, false );
        return self::v2_schema_ready();
    }

    /** Adds only the two EC-02 entitlement tables to a verified TE-02 schema. */
    private static function migrate_v2_to_v3() {
        global $wpdb;
        if ( ! self::v2_schema_ready() ) {
            return false;
        }
        $existing = self::existing_tables( self::entitlement_tables() );
        if ( $existing && count( $existing ) !== count( self::entitlement_tables() ) ) {
            return false;
        }
        if ( ! $existing ) {
            foreach ( self::entitlement_tables() as $name => $table ) {
                if ( false === $wpdb->query( self::create_query( $name, $table, self::VERSION ) ) ) {
                    return false;
                }
            }
        }
        update_option( self::OPTION, self::V3_VERSION, false );
        return self::v3_schema_ready();
    }

    /** Adds only the dedicated PF ledger to a verified TE-03 schema. */
    private static function migrate_v3_to_v4() {
        global $wpdb;
        if ( ! self::v3_schema_ready() ) {
            return false;
        }
        $table = self::pf_ledger_table();
        if ( self::exists( $table ) ) {
            if ( ! self::verify_table( 'pf_ledger', $table, self::V4_VERSION ) ) {
                return false;
            }
        } elseif ( false === $wpdb->query( self::create_query( 'pf_ledger', $table, self::V4_VERSION ) ) ) {
            return false;
        }
        update_option( self::OPTION, self::V4_VERSION, false );
        return self::v4_schema_ready();
    }

    /** Replaces only the PF compensation lookup index with a unique nullable index. */
    private static function migrate_v4_to_v5() {
        global $wpdb;
        if ( ! self::v4_schema_ready() ) {
            return false;
        }
        $table = self::pf_ledger_table();
        $duplicate = $wpdb->get_var( 'SELECT `compensates_entry_uuid` FROM `' . $table . '` WHERE `compensates_entry_uuid` IS NOT NULL GROUP BY `compensates_entry_uuid` HAVING COUNT(*) > 1 LIMIT 1' );
        if ( '' !== (string) $wpdb->last_error || null !== $duplicate ) {
            return false;
        }
        $alter = 'ALTER TABLE `' . $table . '` DROP INDEX `pf_compensates_entry`, ADD UNIQUE KEY `pf_compensates_entry_unique` (`compensates_entry_uuid`)';
        if ( false === $wpdb->query( $alter ) ) {
            return false;
        }
        return self::current_schema_ready();
    }

    private static function current_schema_ready() {
        $tables = self::tables();
        $existing = self::existing_tables( $tables );
        if ( count( $existing ) !== count( $tables ) ) {
            return false;
        }
        foreach ( $existing as $name => $table ) {
            if ( ! self::verify_table( $name, $table, self::VERSION ) ) {
                return false;
            }
        }
        return true;
    }

    private static function legacy_schema_ready() {
        if ( self::LEGACY_VERSION !== get_option( self::OPTION ) ) {
            return false;
        }
        $tables = self::legacy_tables();
        $existing = self::existing_tables( $tables );
        if ( count( $existing ) !== count( $tables ) ) {
            return false;
        }
        foreach ( $existing as $name => $table ) {
            if ( ! self::verify_table( $name, $table, self::LEGACY_VERSION ) ) {
                return false;
            }
        }
        return true;
    }

    private static function v2_schema_ready() {
        if ( self::V2_VERSION !== get_option( self::OPTION ) ) {
            return false;
        }
        $tables = self::v2_tables();
        $existing = self::existing_tables( $tables );
        if ( count( $existing ) !== count( $tables ) ) {
            return false;
        }
        foreach ( $existing as $name => $table ) {
            if ( ! self::verify_table( $name, $table, self::V2_VERSION ) ) {
                return false;
            }
        }
        return true;
    }

    private static function v3_schema_ready() {
        if ( self::V3_VERSION !== get_option( self::OPTION ) ) {
            return false;
        }
        $tables = self::v3_tables();
        $existing = self::existing_tables( $tables );
        if ( count( $existing ) !== count( $tables ) ) {
            return false;
        }
        foreach ( $existing as $name => $table ) {
            if ( ! self::verify_table( $name, $table, self::V3_VERSION ) ) {
                return false;
            }
        }
        return true;
    }

    private static function v4_schema_ready() {
        if ( self::V4_VERSION !== get_option( self::OPTION ) ) {
            return false;
        }
        $tables = self::tables();
        $existing = self::existing_tables( $tables );
        if ( count( $existing ) !== count( $tables ) ) {
            return false;
        }
        foreach ( $existing as $name => $table ) {
            if ( ! self::verify_table( $name, $table, self::V4_VERSION ) ) {
                return false;
            }
        }
        return true;
    }

    private static function tables() {
        return array(
            'projects' => self::projects_table(),
            'rules' => self::rules_table(),
            'ledger' => self::ledger_table(),
            'pf_ledger' => self::pf_ledger_table(),
            'connector_tokens' => self::connector_tokens_table(),
            'entitlement_definitions' => self::entitlement_definitions_table(),
            'entitlement_grants' => self::entitlement_grants_table(),
        );
    }

    private static function v2_tables() {
        return array(
            'projects' => self::projects_table(),
            'rules' => self::rules_table(),
            'ledger' => self::ledger_table(),
            'connector_tokens' => self::connector_tokens_table(),
        );
    }

    private static function v3_tables() {
        return array(
            'projects' => self::projects_table(),
            'rules' => self::rules_table(),
            'ledger' => self::ledger_table(),
            'connector_tokens' => self::connector_tokens_table(),
            'entitlement_definitions' => self::entitlement_definitions_table(),
            'entitlement_grants' => self::entitlement_grants_table(),
        );
    }

    private static function entitlement_tables() {
        return array(
            'entitlement_definitions' => self::entitlement_definitions_table(),
            'entitlement_grants' => self::entitlement_grants_table(),
        );
    }

    private static function legacy_tables() {
        return array( 'projects' => self::projects_table(), 'rules' => self::rules_table(), 'ledger' => self::ledger_table() );
    }

    private static function table( $suffix ) {
        global $wpdb;
        return self::valid_prefix() ? $wpdb->prefix . $suffix : '';
    }

    private static function valid_prefix() {
        global $wpdb;
        return is_object( $wpdb ) && preg_match( '/^[A-Za-z0-9_]+$/', $wpdb->prefix ?? '' );
    }

    private static function existing_tables( $tables ) {
        $existing = array();
        foreach ( $tables as $name => $table ) {
            if ( '' !== $table && self::exists( $table ) ) {
                $existing[ $name ] = $table;
            }
        }
        return $existing;
    }

    private static function exists( $table ) {
        global $wpdb;
        return null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    }

    private static function create_query( $name, $table, $version ) {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        if ( 'projects' === $name ) {
            $connector_columns = self::VERSION === $version ? ',`connector_client_id` varchar(64) NULL,`connector_secret_hash` varchar(255) NULL,`connector_secret_version` char(36) NULL,`connector_permissions` varchar(191) NULL,UNIQUE KEY `connector_client_id_unique` (`connector_client_id`)' : '';
            return 'CREATE TABLE `' . $table . '` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,`project_key` varchar(64) NOT NULL,`name` varchar(120) NOT NULL,`active` tinyint(1) NOT NULL' . $connector_columns . ',`created_at` datetime NOT NULL,`updated_at` datetime NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `project_key_unique` (`project_key`),KEY `project_active` (`active`)) ENGINE=InnoDB ' . $charset;
        }
        if ( 'rules' === $name ) {
            return 'CREATE TABLE `' . $table . '` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,`rule_key` varchar(96) NOT NULL,`project_id` bigint(20) unsigned NULL,`scope` varchar(20) NOT NULL,`trigger_type` varchar(20) NOT NULL,`periodicity` varchar(20) NOT NULL,`cooldown_seconds` int(10) unsigned NULL,`amount` bigint(20) unsigned NOT NULL,`active` tinyint(1) NOT NULL,`created_at` datetime NOT NULL,`updated_at` datetime NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `rule_key_unique` (`rule_key`),KEY `rule_project` (`project_id`),KEY `rule_scope_active` (`scope`,`active`)) ENGINE=InnoDB ' . $charset;
        }
        if ( 'ledger' === $name ) {
            return 'CREATE TABLE `' . $table . '` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,`transaction_uuid` char(36) NOT NULL,`subject_id` varchar(191) NOT NULL,`project_key` varchar(64) NOT NULL,`rule_key` varchar(96) NULL,`direction` varchar(10) NOT NULL,`amount` bigint(20) unsigned NOT NULL,`idempotency_key` varchar(191) NOT NULL,`source_reference` varchar(191) NULL,`metadata` longtext NULL,`created_at` datetime NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `transaction_uuid_unique` (`transaction_uuid`),UNIQUE KEY `idempotency_key_unique` (`idempotency_key`),KEY `ledger_subject_project_date` (`subject_id`,`project_key`,`created_at`),KEY `ledger_project_rule_date` (`project_key`,`rule_key`,`created_at`),KEY `ledger_rule_date` (`rule_key`,`created_at`),KEY `ledger_created_at` (`created_at`)) ENGINE=InnoDB ' . $charset;
        }
        if ( 'pf_ledger' === $name ) {
            $compensation_index = self::V4_VERSION === $version ? 'KEY `pf_compensates_entry` (`compensates_entry_uuid`)' : 'UNIQUE KEY `pf_compensates_entry_unique` (`compensates_entry_uuid`)';
            return 'CREATE TABLE `' . $table . '` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,`entry_uuid` char(36) NOT NULL,`faluss_id` char(36) NOT NULL,`amount_pf` bigint(20) unsigned NOT NULL,`direction` varchar(12) NOT NULL,`economic_class` varchar(20) NOT NULL,`category` varchar(64) NOT NULL,`category_version` varchar(32) NOT NULL,`source_owner` varchar(64) NOT NULL,`source_event_reference` varchar(191) NOT NULL,`idempotency_key` varchar(191) NOT NULL,`policy_version` varchar(32) NOT NULL,`occurred_at` datetime NOT NULL,`compensates_entry_uuid` char(36) NULL,`administrative_reason` varchar(191) NULL,`metadata` longtext NULL,`created_at` datetime NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `pf_entry_uuid_unique` (`entry_uuid`),UNIQUE KEY `pf_idempotency_key_unique` (`idempotency_key`),KEY `pf_subject_class_date` (`faluss_id`,`economic_class`,`occurred_at`),KEY `pf_subject_category_date` (`faluss_id`,`category`,`occurred_at`),KEY `pf_source_category_date` (`source_owner`,`category`,`occurred_at`),' . $compensation_index . ') ENGINE=InnoDB ' . $charset;
        }
        if ( 'entitlement_definitions' === $name ) {
            return 'CREATE TABLE `' . $table . '` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,`entitlement_code` varchar(120) NOT NULL,`label` varchar(120) NOT NULL,`project_key` varchar(64) NOT NULL,`entitlement_type` varchar(20) NOT NULL,`active` tinyint(1) NOT NULL,`created_at` datetime NOT NULL,`updated_at` datetime NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `entitlement_code_unique` (`entitlement_code`),KEY `entitlement_project_active` (`project_key`,`active`),KEY `entitlement_type_active` (`entitlement_type`,`active`)) ENGINE=InnoDB ' . $charset;
        }
        if ( 'entitlement_grants' === $name ) {
            return 'CREATE TABLE `' . $table . '` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,`grant_uuid` char(36) NOT NULL,`subject_id` varchar(191) NOT NULL,`entitlement_id` bigint(20) unsigned NOT NULL,`source` varchar(20) NOT NULL,`operation_reference` varchar(191) NOT NULL,`starts_at` datetime NOT NULL,`ends_at` datetime NULL,`revoked_at` datetime NULL,`revoke_reason` varchar(191) NULL,`created_at` datetime NOT NULL,`updated_at` datetime NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `grant_uuid_unique` (`grant_uuid`),UNIQUE KEY `grant_operation_unique` (`operation_reference`),KEY `grant_subject_entitlement` (`subject_id`,`entitlement_id`,`starts_at`),KEY `grant_entitlement_state` (`entitlement_id`,`revoked_at`,`ends_at`)) ENGINE=InnoDB ' . $charset;
        }
        return 'CREATE TABLE `' . $table . '` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,`token_hash` char(64) NOT NULL,`project_key` varchar(64) NOT NULL,`secret_version` char(36) NOT NULL,`permissions` varchar(191) NOT NULL,`expires_at` datetime NOT NULL,`created_at` datetime NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `token_hash_unique` (`token_hash`),KEY `token_project_expires` (`project_key`,`expires_at`),KEY `token_expires` (`expires_at`)) ENGINE=InnoDB ' . $charset;
    }

    private static function verify_table( $name, $table, $version ) {
        global $wpdb;
        $status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ), ARRAY_A );
        if ( ! is_array( $status ) || 0 !== strcasecmp( 'InnoDB', $status['Engine'] ?? '' ) ) {
            return false;
        }
        $columns = self::columns( $name, $version );
        $actual = $wpdb->get_results( 'SHOW FULL COLUMNS FROM `' . $table . '`', ARRAY_A );
        if ( ! is_array( $actual ) || count( $actual ) !== count( $columns ) ) {
            return false;
        }
        foreach ( $actual as $column ) {
            $field = $column['Field'] ?? '';
            if ( ! isset( $columns[ $field ] ) || strtolower( $column['Type'] ?? '' ) !== $columns[ $field ][0] || strtoupper( $column['Null'] ?? '' ) !== $columns[ $field ][1] ) {
                return false;
            }
        }
        $found = array();
        foreach ( (array) $wpdb->get_results( 'SHOW INDEX FROM `' . $table . '`', ARRAY_A ) as $index ) {
            $key = $index['Key_name'] ?? '';
            if ( '' === $key ) { return false; }
            $found[ $key ][] = $index;
        }
        $needed = self::indexes( $name, $version );
        if ( count( $found ) !== count( $needed ) || array_diff( array_keys( $needed ), array_keys( $found ) ) ) {
            return false;
        }
        foreach ( $needed as $key => $expected ) {
            if ( count( $found[ $key ] ) !== count( $expected['columns'] ) ) { return false; }
            usort( $found[ $key ], static function( $left, $right ) { return (int) $left['Seq_in_index'] <=> (int) $right['Seq_in_index']; } );
            foreach ( $found[ $key ] as $position => $index ) {
                if ( (int) $index['Non_unique'] !== ( $expected['unique'] ? 0 : 1 ) || $expected['columns'][ $position ] !== ( $index['Column_name'] ?? '' ) ) { return false; }
            }
        }
        return true;
    }

    private static function columns( $name, $version ) {
        if ( 'projects' === $name ) {
            $columns = array( 'id' => array( 'bigint(20) unsigned', 'NO' ), 'project_key' => array( 'varchar(64)', 'NO' ), 'name' => array( 'varchar(120)', 'NO' ), 'active' => array( 'tinyint(1)', 'NO' ) );
            if ( $version >= self::V2_VERSION ) {
                $columns += array( 'connector_client_id' => array( 'varchar(64)', 'YES' ), 'connector_secret_hash' => array( 'varchar(255)', 'YES' ), 'connector_secret_version' => array( 'char(36)', 'YES' ), 'connector_permissions' => array( 'varchar(191)', 'YES' ) );
            }
            return $columns + array( 'created_at' => array( 'datetime', 'NO' ), 'updated_at' => array( 'datetime', 'NO' ) );
        }
        if ( 'rules' === $name ) {
            return array( 'id' => array( 'bigint(20) unsigned', 'NO' ), 'rule_key' => array( 'varchar(96)', 'NO' ), 'project_id' => array( 'bigint(20) unsigned', 'YES' ), 'scope' => array( 'varchar(20)', 'NO' ), 'trigger_type' => array( 'varchar(20)', 'NO' ), 'periodicity' => array( 'varchar(20)', 'NO' ), 'cooldown_seconds' => array( 'int(10) unsigned', 'YES' ), 'amount' => array( 'bigint(20) unsigned', 'NO' ), 'active' => array( 'tinyint(1)', 'NO' ), 'created_at' => array( 'datetime', 'NO' ), 'updated_at' => array( 'datetime', 'NO' ) );
        }
        if ( 'ledger' === $name ) {
            return array( 'id' => array( 'bigint(20) unsigned', 'NO' ), 'transaction_uuid' => array( 'char(36)', 'NO' ), 'subject_id' => array( 'varchar(191)', 'NO' ), 'project_key' => array( 'varchar(64)', 'NO' ), 'rule_key' => array( 'varchar(96)', 'YES' ), 'direction' => array( 'varchar(10)', 'NO' ), 'amount' => array( 'bigint(20) unsigned', 'NO' ), 'idempotency_key' => array( 'varchar(191)', 'NO' ), 'source_reference' => array( 'varchar(191)', 'YES' ), 'metadata' => array( 'longtext', 'YES' ), 'created_at' => array( 'datetime', 'NO' ) );
        }
        if ( 'pf_ledger' === $name ) {
            return array( 'id' => array( 'bigint(20) unsigned', 'NO' ), 'entry_uuid' => array( 'char(36)', 'NO' ), 'faluss_id' => array( 'char(36)', 'NO' ), 'amount_pf' => array( 'bigint(20) unsigned', 'NO' ), 'direction' => array( 'varchar(12)', 'NO' ), 'economic_class' => array( 'varchar(20)', 'NO' ), 'category' => array( 'varchar(64)', 'NO' ), 'category_version' => array( 'varchar(32)', 'NO' ), 'source_owner' => array( 'varchar(64)', 'NO' ), 'source_event_reference' => array( 'varchar(191)', 'NO' ), 'idempotency_key' => array( 'varchar(191)', 'NO' ), 'policy_version' => array( 'varchar(32)', 'NO' ), 'occurred_at' => array( 'datetime', 'NO' ), 'compensates_entry_uuid' => array( 'char(36)', 'YES' ), 'administrative_reason' => array( 'varchar(191)', 'YES' ), 'metadata' => array( 'longtext', 'YES' ), 'created_at' => array( 'datetime', 'NO' ) );
        }
        if ( 'entitlement_definitions' === $name ) {
            return array( 'id' => array( 'bigint(20) unsigned', 'NO' ), 'entitlement_code' => array( 'varchar(120)', 'NO' ), 'label' => array( 'varchar(120)', 'NO' ), 'project_key' => array( 'varchar(64)', 'NO' ), 'entitlement_type' => array( 'varchar(20)', 'NO' ), 'active' => array( 'tinyint(1)', 'NO' ), 'created_at' => array( 'datetime', 'NO' ), 'updated_at' => array( 'datetime', 'NO' ) );
        }
        if ( 'entitlement_grants' === $name ) {
            return array( 'id' => array( 'bigint(20) unsigned', 'NO' ), 'grant_uuid' => array( 'char(36)', 'NO' ), 'subject_id' => array( 'varchar(191)', 'NO' ), 'entitlement_id' => array( 'bigint(20) unsigned', 'NO' ), 'source' => array( 'varchar(20)', 'NO' ), 'operation_reference' => array( 'varchar(191)', 'NO' ), 'starts_at' => array( 'datetime', 'NO' ), 'ends_at' => array( 'datetime', 'YES' ), 'revoked_at' => array( 'datetime', 'YES' ), 'revoke_reason' => array( 'varchar(191)', 'YES' ), 'created_at' => array( 'datetime', 'NO' ), 'updated_at' => array( 'datetime', 'NO' ) );
        }
        return array( 'id' => array( 'bigint(20) unsigned', 'NO' ), 'token_hash' => array( 'char(64)', 'NO' ), 'project_key' => array( 'varchar(64)', 'NO' ), 'secret_version' => array( 'char(36)', 'NO' ), 'permissions' => array( 'varchar(191)', 'NO' ), 'expires_at' => array( 'datetime', 'NO' ), 'created_at' => array( 'datetime', 'NO' ) );
    }

    private static function indexes( $name, $version ) {
        if ( 'projects' === $name ) {
            $indexes = array( 'PRIMARY' => array( 'unique' => true, 'columns' => array( 'id' ) ), 'project_key_unique' => array( 'unique' => true, 'columns' => array( 'project_key' ) ), 'project_active' => array( 'unique' => false, 'columns' => array( 'active' ) ) );
            if ( $version >= self::V2_VERSION ) { $indexes['connector_client_id_unique'] = array( 'unique' => true, 'columns' => array( 'connector_client_id' ) ); }
            return $indexes;
        }
        if ( 'rules' === $name ) { return array( 'PRIMARY' => array( 'unique' => true, 'columns' => array( 'id' ) ), 'rule_key_unique' => array( 'unique' => true, 'columns' => array( 'rule_key' ) ), 'rule_project' => array( 'unique' => false, 'columns' => array( 'project_id' ) ), 'rule_scope_active' => array( 'unique' => false, 'columns' => array( 'scope', 'active' ) ) ); }
        if ( 'ledger' === $name ) { return array( 'PRIMARY' => array( 'unique' => true, 'columns' => array( 'id' ) ), 'transaction_uuid_unique' => array( 'unique' => true, 'columns' => array( 'transaction_uuid' ) ), 'idempotency_key_unique' => array( 'unique' => true, 'columns' => array( 'idempotency_key' ) ), 'ledger_subject_project_date' => array( 'unique' => false, 'columns' => array( 'subject_id', 'project_key', 'created_at' ) ), 'ledger_project_rule_date' => array( 'unique' => false, 'columns' => array( 'project_key', 'rule_key', 'created_at' ) ), 'ledger_rule_date' => array( 'unique' => false, 'columns' => array( 'rule_key', 'created_at' ) ), 'ledger_created_at' => array( 'unique' => false, 'columns' => array( 'created_at' ) ) ); }
        if ( 'pf_ledger' === $name ) { $indexes = array( 'PRIMARY' => array( 'unique' => true, 'columns' => array( 'id' ) ), 'pf_entry_uuid_unique' => array( 'unique' => true, 'columns' => array( 'entry_uuid' ) ), 'pf_idempotency_key_unique' => array( 'unique' => true, 'columns' => array( 'idempotency_key' ) ), 'pf_subject_class_date' => array( 'unique' => false, 'columns' => array( 'faluss_id', 'economic_class', 'occurred_at' ) ), 'pf_subject_category_date' => array( 'unique' => false, 'columns' => array( 'faluss_id', 'category', 'occurred_at' ) ), 'pf_source_category_date' => array( 'unique' => false, 'columns' => array( 'source_owner', 'category', 'occurred_at' ) ) ); $indexes[ self::V4_VERSION === $version ? 'pf_compensates_entry' : 'pf_compensates_entry_unique' ] = array( 'unique' => self::V4_VERSION !== $version, 'columns' => array( 'compensates_entry_uuid' ) ); return $indexes; }
        if ( 'entitlement_definitions' === $name ) { return array( 'PRIMARY' => array( 'unique' => true, 'columns' => array( 'id' ) ), 'entitlement_code_unique' => array( 'unique' => true, 'columns' => array( 'entitlement_code' ) ), 'entitlement_project_active' => array( 'unique' => false, 'columns' => array( 'project_key', 'active' ) ), 'entitlement_type_active' => array( 'unique' => false, 'columns' => array( 'entitlement_type', 'active' ) ) ); }
        if ( 'entitlement_grants' === $name ) { return array( 'PRIMARY' => array( 'unique' => true, 'columns' => array( 'id' ) ), 'grant_uuid_unique' => array( 'unique' => true, 'columns' => array( 'grant_uuid' ) ), 'grant_operation_unique' => array( 'unique' => true, 'columns' => array( 'operation_reference' ) ), 'grant_subject_entitlement' => array( 'unique' => false, 'columns' => array( 'subject_id', 'entitlement_id', 'starts_at' ) ), 'grant_entitlement_state' => array( 'unique' => false, 'columns' => array( 'entitlement_id', 'revoked_at', 'ends_at' ) ) ); }
        return array( 'PRIMARY' => array( 'unique' => true, 'columns' => array( 'id' ) ), 'token_hash_unique' => array( 'unique' => true, 'columns' => array( 'token_hash' ) ), 'token_project_expires' => array( 'unique' => false, 'columns' => array( 'project_key', 'expires_at' ) ), 'token_expires' => array( 'unique' => false, 'columns' => array( 'expires_at' ) ) );
    }
}
