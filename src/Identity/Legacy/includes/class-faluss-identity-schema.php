<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Faluss_Identity_Schema {

    const VERSION = '6';
    const FI02_VERSION = '2';
    const FI03_VERSION = '3';
    const FI04_VERSION = '4';
    const ONB01_VERSION = '5';
    const FI06_SSO_VERSION = '6';
    const OPTION_VERSION = 'faluss_identity_schema_version';
    const OPTION_DIAGNOSTIC = 'faluss_identity_schema_diagnostic';
    const INSTALL_LOCK_TIMEOUT = 10;
    const MYSQL_IDENTIFIER_MAX_LENGTH = 64;

    /**
     * The FI-01 schema contract contains structure only and no member data.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function get_fi01_schema() {
        return array(
            'profiles' => array(
                'suffix' => 'faluss_identity_profiles',
                'columns' => array(
                    'id' => array( 'type' => 'bigint(20) unsigned', 'null' => false, 'auto_increment' => true ),
                    'faluss_id' => array( 'type' => 'char(36)', 'null' => false ),
                    'wp_user_id' => array( 'type' => 'bigint(20) unsigned', 'null' => false ),
                    'status' => array( 'type' => 'varchar(20)', 'null' => false ),
                    'consent_version' => array( 'type' => 'varchar(64)', 'null' => true ),
                    'created_at' => array( 'type' => 'datetime', 'null' => false ),
                    'updated_at' => array( 'type' => 'datetime', 'null' => true ),
                ),
                'indexes' => array(
                    'PRIMARY' => array( 'unique' => true, 'columns' => array( 'id' ) ),
                    'faluss_id_unique' => array( 'unique' => true, 'columns' => array( 'faluss_id' ) ),
                    'wp_user_id_unique' => array( 'unique' => true, 'columns' => array( 'wp_user_id' ) ),
                    'status_created_at' => array( 'unique' => false, 'columns' => array( 'status', 'created_at' ) ),
                ),
            ),
            'challenges' => array(
                'suffix' => 'faluss_identity_challenges',
                'columns' => array(
                    'id' => array( 'type' => 'bigint(20) unsigned', 'null' => false, 'auto_increment' => true ),
                    'challenge_hash' => array( 'type' => 'char(64)', 'null' => false ),
                    'otp_hash' => array( 'type' => 'char(64)', 'null' => true ),
                    'browser_fingerprint_hash' => array( 'type' => 'char(64)', 'null' => true ),
                    'attempt_count' => array( 'type' => 'tinyint(3) unsigned', 'null' => false ),
                    'status' => array( 'type' => 'varchar(20)', 'null' => false ),
                    'expires_at' => array( 'type' => 'datetime', 'null' => false ),
                    'consumed_at' => array( 'type' => 'datetime', 'null' => true ),
                    'created_at' => array( 'type' => 'datetime', 'null' => false ),
                ),
                'indexes' => array(
                    'PRIMARY' => array( 'unique' => true, 'columns' => array( 'id' ) ),
                    'challenge_hash_unique' => array( 'unique' => true, 'columns' => array( 'challenge_hash' ) ),
                    'status_expires_at' => array( 'unique' => false, 'columns' => array( 'status', 'expires_at' ) ),
                ),
            ),
            'rate_limits' => array(
                'suffix' => 'faluss_identity_rate_limits',
                'columns' => array(
                    'id' => array( 'type' => 'bigint(20) unsigned', 'null' => false, 'auto_increment' => true ),
                    'bucket_type' => array( 'type' => 'varchar(32)', 'null' => false ),
                    'bucket_hash' => array( 'type' => 'char(64)', 'null' => false ),
                    'attempt_count' => array( 'type' => 'int(10) unsigned', 'null' => false ),
                    'window_started_at' => array( 'type' => 'datetime', 'null' => false ),
                    'expires_at' => array( 'type' => 'datetime', 'null' => false ),
                    'updated_at' => array( 'type' => 'datetime', 'null' => false ),
                ),
                'indexes' => array(
                    'PRIMARY' => array( 'unique' => true, 'columns' => array( 'id' ) ),
                    'bucket_type_hash_unique' => array( 'unique' => true, 'columns' => array( 'bucket_type', 'bucket_hash' ) ),
                    'expires_at' => array( 'unique' => false, 'columns' => array( 'expires_at' ) ),
                ),
            ),
            'clients' => array(
                'suffix' => 'faluss_identity_clients',
                'columns' => array(
                    'id' => array( 'type' => 'bigint(20) unsigned', 'null' => false, 'auto_increment' => true ),
                    'client_id' => array( 'type' => 'varchar(191)', 'null' => false ),
                    'client_name' => array( 'type' => 'varchar(191)', 'null' => false ),
                    'status' => array( 'type' => 'varchar(20)', 'null' => false ),
                    'client_secret_hash' => array( 'type' => 'char(64)', 'null' => true ),
                    'allowed_scopes' => array( 'type' => 'varchar(255)', 'null' => false ),
                    'redirect_uris' => array( 'type' => 'longtext', 'null' => false ),
                    'created_at' => array( 'type' => 'datetime', 'null' => false ),
                    'updated_at' => array( 'type' => 'datetime', 'null' => true ),
                ),
                'indexes' => array(
                    'PRIMARY' => array( 'unique' => true, 'columns' => array( 'id' ) ),
                    'client_id_unique' => array( 'unique' => true, 'columns' => array( 'client_id' ) ),
                    'status' => array( 'unique' => false, 'columns' => array( 'status' ) ),
                ),
            ),
            'auth_codes' => array(
                'suffix' => 'faluss_identity_auth_codes',
                'columns' => array(
                    'id' => array( 'type' => 'bigint(20) unsigned', 'null' => false, 'auto_increment' => true ),
                    'code_hash' => array( 'type' => 'char(64)', 'null' => false ),
                    'faluss_id' => array( 'type' => 'char(36)', 'null' => false ),
                    'client_id' => array( 'type' => 'varchar(191)', 'null' => false ),
                    'redirect_uri' => array( 'type' => 'varchar(2048)', 'null' => false ),
                    'pkce_challenge' => array( 'type' => 'char(43)', 'null' => false ),
                    'scopes' => array( 'type' => 'varchar(255)', 'null' => false ),
                    'expires_at' => array( 'type' => 'datetime', 'null' => false ),
                    'consumed_at' => array( 'type' => 'datetime', 'null' => true ),
                    'created_at' => array( 'type' => 'datetime', 'null' => false ),
                ),
                'indexes' => array(
                    'PRIMARY' => array( 'unique' => true, 'columns' => array( 'id' ) ),
                    'code_hash_unique' => array( 'unique' => true, 'columns' => array( 'code_hash' ) ),
                    'client_expires_at' => array( 'unique' => false, 'columns' => array( 'client_id', 'expires_at' ) ),
                ),
            ),
            'audit' => array(
                'suffix' => 'faluss_identity_audit',
                'columns' => array(
                    'id' => array( 'type' => 'bigint(20) unsigned', 'null' => false, 'auto_increment' => true ),
                    'event_id' => array( 'type' => 'char(36)', 'null' => false ),
                    'event_type' => array( 'type' => 'varchar(64)', 'null' => false ),
                    'client_id' => array( 'type' => 'varchar(191)', 'null' => true ),
                    'occurred_at' => array( 'type' => 'datetime', 'null' => false ),
                    'expires_at' => array( 'type' => 'datetime', 'null' => false ),
                ),
                'indexes' => array(
                    'PRIMARY' => array( 'unique' => true, 'columns' => array( 'id' ) ),
                    'event_id_unique' => array( 'unique' => true, 'columns' => array( 'event_id' ) ),
                    'expires_at' => array( 'unique' => false, 'columns' => array( 'expires_at' ) ),
                ),
            ),
        );
    }

    public static function get_fi02_schema() {
        $schema = self::get_fi01_schema();
        $schema['challenges']['columns']['otp_hash']['type'] = 'varchar(255)';
        $schema['challenges']['columns']['email'] = array( 'type' => 'varchar(320)', 'null' => true );
        $schema['challenges']['columns']['email_hash'] = array( 'type' => 'char(64)', 'null' => true );
        return $schema;
    }

    public static function get_fi03_schema() {
        $schema = self::get_fi02_schema();
        $schema['public_profiles'] = array(
            'suffix' => 'faluss_identity_public_profiles',
            'columns' => array(
                'id' => array( 'type' => 'bigint(20) unsigned', 'null' => false, 'auto_increment' => true ),
                'faluss_id' => array( 'type' => 'char(36)', 'null' => false ),
                'public_slug' => array( 'type' => 'varchar(40)', 'null' => false ),
                'display_name' => array( 'type' => 'varchar(80)', 'null' => false ),
                'bio' => array( 'type' => 'varchar(280)', 'null' => true ),
                'avatar_attachment_id' => array( 'type' => 'bigint(20) unsigned', 'null' => true ),
                'publication_status' => array( 'type' => 'varchar(20)', 'null' => false ),
                'external_links' => array( 'type' => 'longtext', 'null' => false ),
                'created_at' => array( 'type' => 'datetime', 'null' => false ),
                'updated_at' => array( 'type' => 'datetime', 'null' => false ),
                'published_at' => array( 'type' => 'datetime', 'null' => true ),
            ),
            'indexes' => array(
                'PRIMARY' => array( 'unique' => true, 'columns' => array( 'id' ) ),
                'faluss_id_unique' => array( 'unique' => true, 'columns' => array( 'faluss_id' ) ),
                'public_slug_unique' => array( 'unique' => true, 'columns' => array( 'public_slug' ) ),
                'publication_slug' => array( 'unique' => false, 'columns' => array( 'publication_status', 'public_slug' ) ),
            ),
        );
        return $schema;
    }

    /**
     * FI-04 keeps an authorization request server-side while a member signs in
     * or reviews consent. The browser receives only an opaque HttpOnly handle.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function get_fi04_schema() {
        $schema = self::get_fi03_schema();
        $schema['authorization_requests'] = array(
            'suffix' => 'faluss_identity_authorization_requests',
            'columns' => array(
                'id' => array( 'type' => 'bigint(20) unsigned', 'null' => false, 'auto_increment' => true ),
                'request_hash' => array( 'type' => 'char(64)', 'null' => false ),
                'client_id' => array( 'type' => 'varchar(191)', 'null' => false ),
                'redirect_uri' => array( 'type' => 'varchar(2048)', 'null' => false ),
                'scopes' => array( 'type' => 'varchar(255)', 'null' => false ),
                'pkce_challenge' => array( 'type' => 'char(43)', 'null' => false ),
                'state' => array( 'type' => 'varchar(2048)', 'null' => false ),
                'status' => array( 'type' => 'varchar(20)', 'null' => false ),
                'expires_at' => array( 'type' => 'datetime', 'null' => false ),
                'created_at' => array( 'type' => 'datetime', 'null' => false ),
                'updated_at' => array( 'type' => 'datetime', 'null' => true ),
            ),
            'indexes' => array(
                'PRIMARY' => array( 'unique' => true, 'columns' => array( 'id' ) ),
                'request_hash_unique' => array( 'unique' => true, 'columns' => array( 'request_hash' ) ),
                'status_expires_at' => array( 'unique' => false, 'columns' => array( 'status', 'expires_at' ) ),
                'client_expires_at' => array( 'unique' => false, 'columns' => array( 'client_id', 'expires_at' ) ),
            ),
        );
        return $schema;
    }

    /**
     * ONB-01 keeps a resumable flow state on the existing Identity profile.
     * It deliberately creates neither a second identity registry nor a copy of
     * the public profile/card: the public slug remains in FI-03.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function get_onb01_schema() {
        $schema = self::get_fi04_schema();
        $schema['profiles']['columns']['onboarding_choice'] = array( 'type' => 'varchar(20)', 'null' => true );
        $schema['profiles']['columns']['onboarding_slug_status'] = array( 'type' => 'varchar(20)', 'null' => true );
        $schema['profiles']['columns']['onboarding_next_step'] = array( 'type' => 'varchar(32)', 'null' => true );
        $schema['profiles']['columns']['onboarding_flow_version'] = array( 'type' => 'tinyint(3) unsigned', 'null' => true );
        $schema['profiles']['columns']['onboarding_updated_at'] = array( 'type' => 'datetime', 'null' => true );
        return $schema;
    }

    /**
     * FI-06 adds only an explicit client classification. It does not add a
     * second identity, session, consent, or authorization-code store.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function get_fi06_sso_schema() {
        $schema = self::get_onb01_schema();
        $schema['clients']['columns']['first_party'] = array( 'type' => 'tinyint(3) unsigned', 'null' => false );
        return $schema;
    }

    public static function get_expected_schema() {
        $version = function_exists( 'get_option' ) ? (string) get_option( self::OPTION_VERSION, '' ) : '';
        if ( '1' === $version ) {
            return self::get_fi01_schema();
        }
        if ( self::FI02_VERSION === $version ) {
            return self::get_fi02_schema();
        }
        if ( self::FI03_VERSION === $version ) {
            return self::get_fi03_schema();
        }
        if ( self::FI04_VERSION === $version ) {
            return self::get_fi04_schema();
        }
        if ( self::ONB01_VERSION === $version ) {
            return self::get_onb01_schema();
        }
        return self::get_fi06_sso_schema();
    }

    /**
     * Creates a complete empty schema or verifies an existing complete schema.
     * A partial or incompatible schema is never repaired or deleted.
     *
     * @return bool
     */
    public static function install_or_verify() {
        $status = self::get_status();
        if ( 'fi_schema_ready' === $status['code'] ) {
            if ( '' === (string) get_option( self::OPTION_VERSION, '' ) ) { update_option( self::OPTION_VERSION, self::VERSION, false ); }
            self::store_diagnostic( $status['code'] );
            return true;
        }
        if ( 'fi_schema_missing' !== $status['code'] ) {
            self::store_diagnostic( $status['code'] );
            return false;
        }
        $result = self::create_all_tables();
        if ( 'fi_schema_ready' !== $result ) {
            $status = self::get_status();
            if ( 'fi_schema_ready' === $status['code'] ) {
                self::store_diagnostic( $status['code'] );
                return true;
            }
            self::store_diagnostic( $result );
            return false;
        }

        $status = self::get_status();
        if ( 'fi_schema_ready' === $status['code'] && '' === (string) get_option( self::OPTION_VERSION, '' ) ) { update_option( self::OPTION_VERSION, self::VERSION, false ); }
        self::store_diagnostic( $status['code'] );
        return 'fi_schema_ready' === $status['code'];
    }

    public static function migrate_fi02() {
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! current_user_can( 'manage_options' ) ) { return false; }
        if ( in_array( (string) get_option( self::OPTION_VERSION, '' ), array( self::FI02_VERSION, self::FI03_VERSION, self::FI04_VERSION, self::ONB01_VERSION, self::FI06_SSO_VERSION ), true ) ) { return self::verify_fi02(); }
        if ( 'fi_schema_ready' !== self::get_status()['code'] ) { self::store_diagnostic( 'fi_schema_fi02_source_invalid' ); return false; }
        $tables = self::get_table_names();
        $sql = 'ALTER TABLE ' . self::quote_identifier( $tables['challenges'] ) . ' MODIFY otp_hash varchar(255) NULL, ADD email varchar(320) NULL, ADD email_hash char(64) NULL';
        if ( false === $wpdb->query( $sql ) || ! self::verify_fi02() ) { self::store_diagnostic( 'fi_schema_fi02_failed' ); return false; }
        update_option( self::OPTION_VERSION, self::FI02_VERSION, false ); update_option( self::OPTION_DIAGNOSTIC, 'fi_schema_ready', false ); return true;
    }

    /**
     * Adds the isolated Faluss-ID keyed public-profile table. Existing FI-02
     * tables are verified first and never altered or rebuilt by this migration.
     */
    public static function migrate_fi03() {
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! current_user_can( 'manage_options' ) || ! method_exists( $wpdb, 'get_charset_collate' ) ) { return false; }
        $version = (string) get_option( self::OPTION_VERSION, '' );
        if ( in_array( $version, array( self::FI03_VERSION, self::FI04_VERSION, self::ONB01_VERSION, self::FI06_SSO_VERSION ), true ) ) { return 'fi_schema_ready' === self::get_status()['code']; }
        if ( self::FI02_VERSION !== $version || 'fi_schema_ready' !== self::get_status()['code'] ) { self::store_diagnostic( 'fi_schema_fi03_source_invalid' ); return false; }

        $table = self::get_public_profiles_table();
        $definition = self::get_fi03_schema()['public_profiles'];
        if ( '' === $table ) { self::store_diagnostic( 'fi_schema_prefix_invalid' ); return false; }
        $query = preg_replace( '/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', self::build_create_query( $table, $definition, $wpdb->get_charset_collate() ) );
        if ( ! is_string( $query ) || false === $wpdb->query( $query ) || null !== self::verify_table( $table, $definition ) ) { self::store_diagnostic( 'fi_schema_fi03_failed' ); return false; }

        update_option( self::OPTION_VERSION, self::FI03_VERSION, false );
        $status = self::get_status();
        self::store_diagnostic( $status['code'] );
        return ! empty( $status['ready'] );
    }

    /**
     * Adds the FI-04 authorization request ledger without rebuilding earlier
     * Identity tables. It is deliberately a one-way, additive migration.
     */
    public static function migrate_fi04() {
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! current_user_can( 'manage_options' ) || ! method_exists( $wpdb, 'get_charset_collate' ) ) { return false; }
        $version = (string) get_option( self::OPTION_VERSION, '' );
        if ( in_array( $version, array( self::FI04_VERSION, self::ONB01_VERSION, self::FI06_SSO_VERSION ), true ) ) { return 'fi_schema_ready' === self::get_status()['code']; }
        if ( self::FI03_VERSION !== $version || 'fi_schema_ready' !== self::get_status()['code'] ) { self::store_diagnostic( 'fi_schema_fi04_source_invalid' ); return false; }

        $table = self::get_authorization_requests_table();
        $definition = self::get_fi04_schema()['authorization_requests'];
        if ( '' === $table ) { self::store_diagnostic( 'fi_schema_prefix_invalid' ); return false; }
        $query = preg_replace( '/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', self::build_create_query( $table, $definition, $wpdb->get_charset_collate() ) );
        if ( ! is_string( $query ) || false === $wpdb->query( $query ) || null !== self::verify_table( $table, $definition ) ) { self::store_diagnostic( 'fi_schema_fi04_failed' ); return false; }

        update_option( self::OPTION_VERSION, self::FI04_VERSION, false );
        $status = self::get_status();
        self::store_diagnostic( $status['code'] );
        return ! empty( $status['ready'] );
    }

    /**
     * Adds only resumable onboarding markers to the existing Faluss-ID
     * profile table. No identity, public profile or card data is copied.
     */
    public static function migrate_onb01() {
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! current_user_can( 'manage_options' ) ) { return false; }
        $version = (string) get_option( self::OPTION_VERSION, '' );
        if ( in_array( $version, array( self::ONB01_VERSION, self::FI06_SSO_VERSION ), true ) ) { return 'fi_schema_ready' === self::get_status()['code']; }
        if ( self::FI04_VERSION !== $version || 'fi_schema_ready' !== self::get_status()['code'] ) { self::store_diagnostic( 'fi_schema_onb01_source_invalid' ); return false; }
        $tables = self::get_table_names();
        if ( empty( $tables['profiles'] ) ) { self::store_diagnostic( 'fi_schema_prefix_invalid' ); return false; }
        $sql = 'ALTER TABLE ' . self::quote_identifier( $tables['profiles'] )
            . ' ADD onboarding_choice varchar(20) NULL'
            . ', ADD onboarding_slug_status varchar(20) NULL'
            . ', ADD onboarding_next_step varchar(32) NULL'
            . ', ADD onboarding_flow_version tinyint(3) unsigned NULL'
            . ', ADD onboarding_updated_at datetime NULL';
        if ( false === $wpdb->query( $sql ) ) { self::store_diagnostic( 'fi_schema_onb01_failed' ); return false; }
        update_option( self::OPTION_VERSION, self::ONB01_VERSION, false );
        $status = self::get_status();
        self::store_diagnostic( $status['code'] );
        return ! empty( $status['ready'] );
    }

    /**
     * Adds the server-controlled first-party classification to the existing
     * client registry. The marker defaults to false; an administrator must
     * opt in an exact Faluss.com callback explicitly after the migration.
     */
    public static function migrate_fi06_sso() {
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! current_user_can( 'manage_options' ) ) { return false; }

        $version = (string) get_option( self::OPTION_VERSION, '' );
        if ( self::FI06_SSO_VERSION === $version ) {
            return 'fi_schema_ready' === self::get_status()['code'];
        }
        if ( self::ONB01_VERSION !== $version || 'fi_schema_ready' !== self::get_status()['code'] ) {
            self::store_diagnostic( 'fi_schema_fi06_sso_source_invalid' );
            return false;
        }
        $tables = self::get_table_names();
        if ( empty( $tables['clients'] ) ) {
            self::store_diagnostic( 'fi_schema_prefix_invalid' );
            return false;
        }

        $sql = 'ALTER TABLE ' . self::quote_identifier( $tables['clients'] ) . ' ADD first_party tinyint(3) unsigned NOT NULL DEFAULT 0';
        if ( false === $wpdb->query( $sql ) ) {
            self::store_diagnostic( 'fi_schema_fi06_sso_failed' );
            return false;
        }
        update_option( self::OPTION_VERSION, self::FI06_SSO_VERSION, false );
        $status = self::get_status();
        self::store_diagnostic( $status['code'] );
        return ! empty( $status['ready'] );
    }

    private static function verify_fi02() {
        global $wpdb; $tables = self::get_table_names(); if ( empty( $tables ) ) { return false; }
        $rows = $wpdb->get_results( 'SHOW FULL COLUMNS FROM ' . self::quote_identifier( $tables['challenges'] ), ARRAY_A ); if ( ! is_array( $rows ) || 11 !== count( $rows ) ) { return false; }
        $columns = array(); foreach ( $rows as $row ) { $columns[ $row['Field'] ] = $row; }
        foreach ( array( 'otp_hash'=>array('varchar(255)','YES'),'email'=>array('varchar(320)','YES'),'email_hash'=>array('char(64)','YES') ) as $name=>$want ) { if ( ! isset($columns[$name]) || strtolower($columns[$name]['Type']) !== $want[0] || $columns[$name]['Null'] !== $want[1] ) { return false; } }
        return true;
    }

    /**
     * @return array{ready: bool, code: string}
     */
    public static function get_status() {
        global $wpdb;

        if ( ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) || ! method_exists( $wpdb, 'get_var' ) ) {
            return self::status( false, 'fi_schema_unverifiable' );
        }
        $tables = self::get_table_names();
        if ( empty( $tables ) ) {
            return self::status( false, 'fi_schema_prefix_invalid' );
        }

        $existing = 0;
        foreach ( $tables as $table ) {
            $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
            if ( null === $found && ! empty( $wpdb->last_error ) ) {
                return self::status( false, 'fi_schema_unverifiable' );
            }
            if ( $table === $found ) {
                ++$existing;
            }
        }
        if ( 0 === $existing ) {
            return self::status( false, 'fi_schema_missing' );
        }
        if ( count( $tables ) !== $existing ) {
            return self::status( false, 'fi_schema_partial' );
        }

        foreach ( self::get_expected_schema() as $key => $definition ) {
            $code = self::verify_table( $tables[ $key ], $definition );
            if ( null !== $code ) {
                return self::status( false, $code );
            }
        }
        return self::status( true, 'fi_schema_ready' );
    }

    private static function verify_table( $table, $definition ) {
        global $wpdb;

        $table_status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ), ARRAY_A );
        if ( ! is_array( $table_status ) || empty( $table_status['Engine'] ) ) {
            return 'fi_schema_unverifiable';
        }
        if ( 0 !== strcasecmp( 'InnoDB', $table_status['Engine'] ) ) {
            return 'fi_schema_unexpected';
        }

        $columns = $wpdb->get_results( 'SHOW FULL COLUMNS FROM ' . self::quote_identifier( $table ), ARRAY_A );
        if ( ! is_array( $columns ) || count( $columns ) !== count( $definition['columns'] ) ) {
            return 'fi_schema_unexpected';
        }
        $actual_columns = array();
        foreach ( $columns as $column ) {
            if ( empty( $column['Field'] ) ) {
                return 'fi_schema_unverifiable';
            }
            $actual_columns[ $column['Field'] ] = $column;
        }
        foreach ( $definition['columns'] as $name => $expected ) {
            if ( ! isset( $actual_columns[ $name ] ) ) {
                return 'fi_schema_unexpected';
            }
            $actual = $actual_columns[ $name ];
            $is_auto_increment = isset( $actual['Extra'] ) && false !== strpos( strtolower( $actual['Extra'] ), 'auto_increment' );
            if ( ! self::types_match( $expected['type'], $actual['Type'] ) || $expected['null'] !== ( 'YES' === $actual['Null'] ) || ( ! empty( $expected['auto_increment'] ) ) !== $is_auto_increment ) {
                return 'fi_schema_unexpected';
            }
        }

        $index_rows = $wpdb->get_results( 'SHOW INDEX FROM ' . self::quote_identifier( $table ), ARRAY_A );
        if ( ! is_array( $index_rows ) ) {
            return 'fi_schema_unverifiable';
        }
        $actual_indexes = array();
        foreach ( $index_rows as $index ) {
            if ( ! isset( $index['Key_name'], $index['Seq_in_index'], $index['Column_name'], $index['Non_unique'] ) ) {
                return 'fi_schema_unverifiable';
            }
            $name = $index['Key_name'];
            if ( ! isset( $actual_indexes[ $name ] ) ) {
                $actual_indexes[ $name ] = array( 'unique' => '0' === (string) $index['Non_unique'], 'columns' => array() );
            }
            $actual_indexes[ $name ]['columns'][ (int) $index['Seq_in_index'] ] = $index['Column_name'];
        }
        if ( count( $actual_indexes ) !== count( $definition['indexes'] ) ) {
            return 'fi_schema_unexpected';
        }
        foreach ( $definition['indexes'] as $name => $expected ) {
            if ( ! isset( $actual_indexes[ $name ] ) ) {
                return 'fi_schema_unexpected';
            }
            $actual = $actual_indexes[ $name ];
            ksort( $actual['columns'] );
            if ( $expected['unique'] !== $actual['unique'] || $expected['columns'] !== array_values( $actual['columns'] ) ) {
                return 'fi_schema_unexpected';
            }
        }
        return null;
    }

    /**
     * Builds, verifies and atomically promotes one complete temporary schema.
     *
     * @return string A bounded diagnostic code.
     */
    private static function create_all_tables() {
        global $wpdb;

        if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_charset_collate' ) ) {
            return 'fi_schema_unverifiable';
        }

        $lock_name = self::get_install_lock_name();
        if ( null === $lock_name || ! self::acquire_install_lock( $lock_name ) ) {
            return 'fi_schema_lock_failed';
        }

        try {
            // Re-check while holding the targeted lock so concurrent activation is safe.
            $status = self::get_status();
            if ( 'fi_schema_ready' === $status['code'] ) {
                return 'fi_schema_ready';
            }
            if ( 'fi_schema_missing' !== $status['code'] ) {
                return $status['code'];
            }

            $plan = self::get_install_plan();
            if ( null === $plan ) {
                return 'fi_schema_temp_name_invalid';
            }
            if ( ! self::prepare_temporary_tables( $plan ) ) {
                return 'fi_schema_prepare_failed';
            }
            foreach ( $plan['temporary_tables'] as $key => $temporary_table ) {
                $code = self::verify_table( $temporary_table, self::get_expected_schema()[ $key ] );
                if ( null !== $code ) {
                    return 'fi_schema_temp_verify_failed';
                }
            }
            if ( ! self::final_tables_are_absent( $plan['final_tables'] ) ) {
                return 'fi_schema_promotion_blocked';
            }
            if ( ! self::promote_temporary_tables( $plan ) ) {
                return 'fi_schema_promotion_failed';
            }

            return self::get_status()['code'];
        } finally {
            self::release_install_lock( $lock_name );
        }
    }

    /**
     * Produces one validated, non-secret temporary install plan.
     *
     * @param string|null $attempt_token Optional test-only hexadecimal token.
     * @return array<string, mixed>|null
     */
    public static function get_install_plan( $attempt_token = null ) {
        global $wpdb;

        $final_tables = self::get_table_names();
        if ( empty( $final_tables ) || ! is_object( $wpdb ) ) {
            return null;
        }
        if ( null === $attempt_token ) {
            try {
                $attempt_token = bin2hex( random_bytes( 8 ) );
            } catch ( Exception $exception ) {
                return null;
            }
        }
        if ( ! is_string( $attempt_token ) || 1 !== preg_match( '/^[a-f0-9]{16}$/D', $attempt_token ) ) {
            return null;
        }

        $temporary_tables = array();
        foreach ( self::get_expected_schema() as $key => $definition ) {
            $temporary_table = $wpdb->prefix . 'faluss_fi01_tmp_' . $attempt_token . '_' . $key;
            if ( ! self::is_valid_identifier( $temporary_table ) ) {
                return null;
            }
            $temporary_tables[ $key ] = $temporary_table;
        }

        return array(
            'final_tables' => $final_tables,
            'temporary_tables' => $temporary_tables,
        );
    }

    /**
     * Returns SQL exclusively for temporary preparation followed by one promotion.
     * This narrow test seam performs no database write.
     *
     * @return array<string, mixed>|null
     */
    public static function get_install_queries( $plan, $charset_collate ) {
        if ( ! self::is_valid_install_plan( $plan ) ) {
            return null;
        }

        $creates = array();
        foreach ( self::get_expected_schema() as $key => $definition ) {
            $creates[ $key ] = self::build_create_query( $plan['temporary_tables'][ $key ], $definition, $charset_collate );
        }

        return array(
            'temporary_creates' => $creates,
            'promotion' => self::build_promotion_query( $plan ),
        );
    }

    private static function prepare_temporary_tables( $plan ) {
        global $wpdb;

        $queries = self::get_install_queries( $plan, $wpdb->get_charset_collate() );
        if ( null === $queries ) {
            return false;
        }
        foreach ( $queries['temporary_creates'] as $query ) {
            if ( false === $wpdb->query( $query ) ) {
                return false;
            }
        }
        return true;
    }

    private static function promote_temporary_tables( $plan ) {
        global $wpdb;

        return false !== $wpdb->query( self::build_promotion_query( $plan ) );
    }

    private static function build_promotion_query( $plan ) {
        $renames = array();
        foreach ( self::get_expected_schema() as $key => $definition ) {
            $renames[] = self::quote_identifier( $plan['temporary_tables'][ $key ] ) . ' TO ' . self::quote_identifier( $plan['final_tables'][ $key ] );
        }
        return 'RENAME TABLE ' . implode( ', ', $renames );
    }

    private static function final_tables_are_absent( $tables ) {
        global $wpdb;

        foreach ( $tables as $table ) {
            $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
            if ( null === $found && ! empty( $wpdb->last_error ) ) {
                return false;
            }
            if ( $table === $found ) {
                return false;
            }
        }
        return true;
    }

    private static function get_install_lock_name() {
        global $wpdb;

        if ( ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) ) {
            return null;
        }
        $lock_name = 'faluss_identity_fi01_' . substr( hash( 'sha256', $wpdb->prefix ), 0, 32 );
        return self::is_valid_lock_name( $lock_name ) ? $lock_name : null;
    }

    private static function acquire_install_lock( $lock_name ) {
        global $wpdb;

        if ( ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
            return false;
        }
        return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $lock_name, self::INSTALL_LOCK_TIMEOUT ) );
    }

    private static function release_install_lock( $lock_name ) {
        global $wpdb;

        if ( is_object( $wpdb ) && method_exists( $wpdb, 'get_var' ) && method_exists( $wpdb, 'prepare' ) ) {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock_name ) );
        }
    }

    private static function is_valid_install_plan( $plan ) {
        if ( ! is_array( $plan ) || ! isset( $plan['final_tables'], $plan['temporary_tables'] ) ) {
            return false;
        }
        foreach ( array( 'final_tables', 'temporary_tables' ) as $table_set ) {
            if ( count( $plan[ $table_set ] ) !== count( self::get_expected_schema() ) ) {
                return false;
            }
            foreach ( self::get_expected_schema() as $key => $definition ) {
                if ( ! isset( $plan[ $table_set ][ $key ] ) || ! self::is_valid_identifier( $plan[ $table_set ][ $key ] ) ) {
                    return false;
                }
            }
        }
        return true;
    }

    private static function build_create_query( $table, $definition, $charset_collate ) {
        $lines = array();
        foreach ( $definition['columns'] as $name => $column ) {
            $line = self::quote_identifier( $name ) . ' ' . $column['type'] . ( $column['null'] ? ' NULL' : ' NOT NULL' );
            if ( ! empty( $column['auto_increment'] ) ) {
                $line .= ' AUTO_INCREMENT';
            }
            $lines[] = $line;
        }
        foreach ( $definition['indexes'] as $name => $index ) {
            $columns = implode( ', ', array_map( array( __CLASS__, 'quote_identifier' ), $index['columns'] ) );
            if ( 'PRIMARY' === $name ) {
                $lines[] = 'PRIMARY KEY (' . $columns . ')';
            } elseif ( $index['unique'] ) {
                $lines[] = 'UNIQUE KEY ' . self::quote_identifier( $name ) . ' (' . $columns . ')';
            } else {
                $lines[] = 'KEY ' . self::quote_identifier( $name ) . ' (' . $columns . ')';
            }
        }
        return 'CREATE TABLE ' . self::quote_identifier( $table ) . ' (' . implode( ', ', $lines ) . ') ENGINE=InnoDB ' . $charset_collate;
    }

    /**
     * @return array<string, string>
     */
    public static function get_table_names() {
        global $wpdb;

        if ( ! is_object( $wpdb ) || empty( $wpdb->prefix ) || 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $wpdb->prefix ) ) {
            return array();
        }
        $tables = array();
        foreach ( self::get_expected_schema() as $key => $definition ) {
            $table = $wpdb->prefix . $definition['suffix'];
            if ( ! self::is_valid_identifier( $table ) ) {
                return array();
            }
            $tables[ $key ] = $table;
        }
        return $tables;
    }

    /** @return string Empty when the current WordPress table prefix is invalid. */
    public static function get_public_profiles_table() {
        global $wpdb;

        if ( ! is_object( $wpdb ) || empty( $wpdb->prefix ) || 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $wpdb->prefix ) ) {
            return '';
        }
        $table = $wpdb->prefix . self::get_fi03_schema()['public_profiles']['suffix'];
        return self::is_valid_identifier( $table ) ? $table : '';
    }

    /** @return string Empty when the current WordPress table prefix is invalid. */
    public static function get_authorization_requests_table() {
        global $wpdb;

        if ( ! is_object( $wpdb ) || empty( $wpdb->prefix ) || 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $wpdb->prefix ) ) {
            return '';
        }
        $table = $wpdb->prefix . self::get_fi04_schema()['authorization_requests']['suffix'];
        return self::is_valid_identifier( $table ) ? $table : '';
    }

    private static function is_valid_identifier( $identifier ) {
        return is_string( $identifier ) && strlen( $identifier ) <= self::MYSQL_IDENTIFIER_MAX_LENGTH && 1 === preg_match( '/^[A-Za-z0-9_]+$/D', $identifier );
    }

    private static function is_valid_lock_name( $lock_name ) {
        return is_string( $lock_name ) && strlen( $lock_name ) <= self::MYSQL_IDENTIFIER_MAX_LENGTH && 1 === preg_match( '/^[A-Za-z0-9_]+$/D', $lock_name );
    }

    private static function quote_identifier( $identifier ) {
        return chr( 96 ) . str_replace( chr( 96 ), chr( 96 ) . chr( 96 ), $identifier ) . chr( 96 );
    }

    private static function types_match( $expected, $actual ) {
        $expected = strtolower( $expected );
        $actual = strtolower( $actual );
        if ( $expected === $actual ) {
            return true;
        }

        // MySQL 8 omits deprecated integer display widths; MariaDB may retain them.
        $integer_pattern = '/^(?:tinyint|smallint|mediumint|int|bigint)(?:\([0-9]+\))?(?: unsigned)?$/';
        if ( 1 === preg_match( $integer_pattern, $expected ) && 1 === preg_match( $integer_pattern, $actual ) ) {
            return preg_replace( '/\([0-9]+\)/', '', $expected ) === preg_replace( '/\([0-9]+\)/', '', $actual );
        }

        return false;
    }

    private static function store_diagnostic( $code ) {
        update_option( self::OPTION_DIAGNOSTIC, $code, false );
    }

    private static function status( $ready, $code ) {
        return array( 'ready' => $ready, 'code' => $code );
    }
}
