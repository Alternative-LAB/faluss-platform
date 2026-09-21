<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Strict, additive schema for Faluss Link.
 *
 * Discovery data is deliberately split from card preferences: it is private to
 * the viewer and never extends the Identity public-profile model.
 */
final class Faluss_Link_Schema {
    const OPTION = 'faluss_link_schema_version';
    const VERSION = '3';

    public static function table() { global $wpdb; return self::valid_prefix() ? $wpdb->prefix . 'faluss_link_cards' : ''; }
    public static function blocks_table() { global $wpdb; return self::valid_prefix() ? $wpdb->prefix . 'faluss_link_blocks' : ''; }
    public static function discoveries_table() { global $wpdb; return self::valid_prefix() ? $wpdb->prefix . 'faluss_link_discoveries' : ''; }
    public static function discovery_settings_table() { global $wpdb; return self::valid_prefix() ? $wpdb->prefix . 'faluss_link_discovery_settings' : ''; }
    public static function maybe_install() { return self::install(); }

    public static function install() {
        global $wpdb;
        $cards = self::table(); $blocks = self::blocks_table(); $discoveries = self::discoveries_table(); $settings = self::discovery_settings_table();
        if ( '' === $cards || '' === $blocks || '' === $discoveries || '' === $settings || ! method_exists( $wpdb, 'get_charset_collate' ) ) { return false; }

        $has_cards = self::exists( $cards ); $has_blocks = self::exists( $blocks ); $has_discoveries = self::exists( $discoveries ); $has_settings = self::exists( $settings );
        if ( $has_cards && ! self::verify_cards( $cards ) ) { return false; }
        if ( $has_blocks && ! self::verify_blocks( $blocks ) ) { return false; }
        if ( $has_discoveries && ! self::verify_discoveries( $discoveries ) ) { return false; }
        if ( $has_settings && ! self::verify_discovery_settings( $settings ) ) { return false; }

        /* A partial installation: never repair it automatically. Never infer, repair or merge a partial schema. */
        if ( $has_cards !== $has_blocks || $has_discoveries !== $has_settings ) { return false; }
        if ( $has_cards && $has_blocks && $has_discoveries && $has_settings ) { update_option( self::OPTION, self::VERSION, false ); return true; }
        if ( ! $has_cards && ! $has_blocks && ! $has_discoveries && ! $has_settings ) {
            return self::create_under_lock( $cards, $blocks, $discoveries, $settings, true );
        }
        /* FL-01 to FL-17: cards and blocks were complete, discoveries did not exist. */
        if ( $has_cards && $has_blocks && ! $has_discoveries && ! $has_settings ) {
            return self::create_under_lock( $cards, $blocks, $discoveries, $settings, false );
        }
        return false;
    }

    private static function create_under_lock( $cards, $blocks, $discoveries, $settings, $new_install ) {
        global $wpdb;
        $lock = 'faluss_link_' . substr( hash( 'sha256', $wpdb->prefix ), 0, 32 );
        if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $lock, 10 ) ) ) { return false; }
        try {
            $has_cards = self::exists( $cards ); $has_blocks = self::exists( $blocks ); $has_discoveries = self::exists( $discoveries ); $has_settings = self::exists( $settings );
            if ( $new_install ? ( $has_cards || $has_blocks || $has_discoveries || $has_settings ) : ( ! $has_cards || ! $has_blocks || $has_discoveries || $has_settings ) ) { return false; }
            if ( $new_install && ( false === $wpdb->query( self::cards_query( $cards ) ) || false === $wpdb->query( self::blocks_query( $blocks ) ) ) ) { return false; }
            if ( false === $wpdb->query( self::discoveries_query( $discoveries ) ) || false === $wpdb->query( self::discovery_settings_query( $settings ) ) ) { return false; }
            if ( ! self::verify_cards( $cards ) || ! self::verify_blocks( $blocks ) || ! self::verify_discoveries( $discoveries ) || ! self::verify_discovery_settings( $settings ) ) { return false; }
            update_option( self::OPTION, self::VERSION, false );
            return true;
        } finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
    }

    private static function valid_prefix() { global $wpdb; return is_object( $wpdb ) && preg_match( '/^[A-Za-z0-9_]+$/', $wpdb->prefix ?? '' ); }
    private static function exists( $table ) { global $wpdb; return null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); }
    private static function cards_query( $table ) { global $wpdb; return 'CREATE TABLE `' . $table . '` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,`faluss_id` char(36) NOT NULL,`cover_attachment_id` bigint(20) unsigned NULL,`avatar_visible` tinyint(1) NOT NULL,`name_weight` varchar(20) NOT NULL,`name_treatment` varchar(20) NOT NULL,`available` tinyint(1) NOT NULL,`bio_mode` varchar(20) NOT NULL,`announcement` varchar(120) NULL,`announcement_variant` varchar(20) NOT NULL,`social_links` longtext NOT NULL,`social_layout` varchar(20) NOT NULL,`link_style` varchar(20) NOT NULL,`created_at` datetime NOT NULL,`updated_at` datetime NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `faluss_id_unique` (`faluss_id`)) ENGINE=InnoDB ' . $wpdb->get_charset_collate(); }
    private static function blocks_query( $table ) { global $wpdb; return 'CREATE TABLE `' . $table . '` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,`faluss_id` char(36) NOT NULL,`block_id` char(36) NOT NULL,`sort_order` smallint(5) unsigned NOT NULL,`block_type` varchar(20) NOT NULL,`payload` longtext NOT NULL,`created_at` datetime NOT NULL,`updated_at` datetime NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `faluss_block_id` (`faluss_id`,`block_id`),UNIQUE KEY `faluss_block_order` (`faluss_id`,`sort_order`)) ENGINE=InnoDB ' . $wpdb->get_charset_collate(); }
    private static function discoveries_query( $table ) { global $wpdb; return 'CREATE TABLE `' . $table . '` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,`viewer_faluss_id` char(36) NOT NULL,`discovered_faluss_id` char(36) NOT NULL,`first_seen_at` datetime NOT NULL,`last_seen_at` datetime NOT NULL,`view_count` int(10) unsigned NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `faluss_discovery_pair` (`viewer_faluss_id`,`discovered_faluss_id`),KEY `faluss_discovery_recent` (`viewer_faluss_id`,`last_seen_at`,`id`)) ENGINE=InnoDB ' . $wpdb->get_charset_collate(); }
    private static function discovery_settings_query( $table ) { global $wpdb; return 'CREATE TABLE `' . $table . '` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,`viewer_faluss_id` char(36) NOT NULL,`recording_enabled` tinyint(1) NOT NULL,`updated_at` datetime NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `faluss_discovery_viewer` (`viewer_faluss_id`)) ENGINE=InnoDB ' . $wpdb->get_charset_collate(); }
    private static function verify_cards( $table ) { return self::verify_table( $table, array( 'id' => 'bigint(20) unsigned', 'faluss_id' => 'char(36)', 'cover_attachment_id' => 'bigint(20) unsigned', 'avatar_visible' => 'tinyint(1)', 'name_weight' => 'varchar(20)', 'name_treatment' => 'varchar(20)', 'available' => 'tinyint(1)', 'bio_mode' => 'varchar(20)', 'announcement' => 'varchar(120)', 'announcement_variant' => 'varchar(20)', 'social_links' => 'longtext', 'social_layout' => 'varchar(20)', 'link_style' => 'varchar(20)', 'created_at' => 'datetime', 'updated_at' => 'datetime' ), array( 'PRIMARY', 'faluss_id_unique' ) ); }
    private static function verify_blocks( $table ) { return self::verify_table( $table, array( 'id' => 'bigint(20) unsigned', 'faluss_id' => 'char(36)', 'block_id' => 'char(36)', 'sort_order' => 'smallint(5) unsigned', 'block_type' => 'varchar(20)', 'payload' => 'longtext', 'created_at' => 'datetime', 'updated_at' => 'datetime' ), array( 'PRIMARY', 'faluss_block_id', 'faluss_block_order' ) ); }
    private static function verify_discoveries( $table ) { return self::verify_table( $table, array( 'id' => 'bigint(20) unsigned', 'viewer_faluss_id' => 'char(36)', 'discovered_faluss_id' => 'char(36)', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'view_count' => 'int(10) unsigned' ), array( 'PRIMARY', 'faluss_discovery_pair', 'faluss_discovery_recent' ), array_fill_keys( array( 'id', 'viewer_faluss_id', 'discovered_faluss_id', 'first_seen_at', 'last_seen_at', 'view_count' ), 'NO' ) ); }
    private static function verify_discovery_settings( $table ) { return self::verify_table( $table, array( 'id' => 'bigint(20) unsigned', 'viewer_faluss_id' => 'char(36)', 'recording_enabled' => 'tinyint(1)', 'updated_at' => 'datetime' ), array( 'PRIMARY', 'faluss_discovery_viewer' ), array_fill_keys( array( 'id', 'viewer_faluss_id', 'recording_enabled', 'updated_at' ), 'NO' ) ); }
    private static function verify_table( $table, $needed, $indexes, $nullability = array() ) {
        global $wpdb;
        $status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ), ARRAY_A );
        if ( ! is_array( $status ) || 0 !== strcasecmp( 'InnoDB', $status['Engine'] ?? '' ) ) { return false; }
        $columns = $wpdb->get_results( 'SHOW FULL COLUMNS FROM `' . $table . '`', ARRAY_A );
        if ( ! is_array( $columns ) || count( $columns ) !== count( $needed ) ) { return false; }
        foreach ( $columns as $column ) { if ( ! isset( $needed[ $column['Field'] ] ) || strtolower( $column['Type'] ) !== $needed[ $column['Field'] ] || ( isset( $nullability[ $column['Field'] ] ) && strtoupper( $column['Null'] ?? '' ) !== $nullability[ $column['Field'] ] ) ) { return false; } }
        $found = array_unique( array_column( (array) $wpdb->get_results( 'SHOW INDEX FROM `' . $table . '`', ARRAY_A ), 'Key_name' ) );
        return count( $found ) === count( $indexes ) && ! array_diff( $indexes, $found );
    }
}
