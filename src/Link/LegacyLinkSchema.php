<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Strict, additive schema for Faluss Link.
 *
 * Version 4 adds the canonical card composition document. Existing columns
 * remain authoritative for the values they already own; the document carries
 * only presentation properties that previously had no dedicated storage.
 */
final class Faluss_Link_Schema {
    const OPTION = 'faluss_link_schema_version';
    const VERSION = '4';
    const LEGACY_VERSION = '3';

    public static function table() { global $wpdb; return self::valid_prefix() ? $wpdb->prefix . 'faluss_link_cards' : ''; }
    public static function blocks_table() { global $wpdb; return self::valid_prefix() ? $wpdb->prefix . 'faluss_link_blocks' : ''; }
    public static function discoveries_table() { global $wpdb; return self::valid_prefix() ? $wpdb->prefix . 'faluss_link_discoveries' : ''; }
    public static function discovery_settings_table() { global $wpdb; return self::valid_prefix() ? $wpdb->prefix . 'faluss_link_discovery_settings' : ''; }

    /** Ordinary requests only verify the installed schema. They never mutate it. */
    public static function maybe_install() {
        return self::verify_current();
    }

    /** Read-only compatibility check used during ordinary module boot. */
    public static function verify_current() {
        $cards = self::table();
        $blocks = self::blocks_table();
        $discoveries = self::discoveries_table();
        $settings = self::discovery_settings_table();
        if ( '' === $cards || '' === $blocks || '' === $discoveries || '' === $settings
            || ! self::exists( $cards ) || ! self::exists( $blocks ) || ! self::exists( $discoveries ) || ! self::exists( $settings )
        ) { return false; }
        return ( self::verify_cards_v3( $cards ) || self::verify_cards_v4( $cards ) )
            && self::verify_blocks( $blocks )
            && self::verify_discoveries( $discoveries )
            && self::verify_discovery_settings( $settings );
    }

    /** Explicit V4 promotion entrypoint. Callers must enforce their own authorization. */
    public static function migrate_v4() {
        return self::install( true );
    }

    public static function composition_ready() {
        $table = self::table();
        return (string) get_option( self::OPTION, '' ) === self::VERSION
            && '' !== $table
            && self::exists( $table )
            && self::verify_cards_v4( $table );
    }

    public static function install( $allow_schema_change = true ) {
        global $wpdb;
        $cards = self::table();
        $blocks = self::blocks_table();
        $discoveries = self::discoveries_table();
        $settings = self::discovery_settings_table();
        if ( '' === $cards || '' === $blocks || '' === $discoveries || '' === $settings || ! method_exists( $wpdb, 'get_charset_collate' ) ) { return false; }

        $has_cards = self::exists( $cards );
        $has_blocks = self::exists( $blocks );
        $has_discoveries = self::exists( $discoveries );
        $has_settings = self::exists( $settings );

        if ( $has_blocks && ! self::verify_blocks( $blocks ) ) { return false; }
        if ( $has_discoveries && ! self::verify_discoveries( $discoveries ) ) { return false; }
        if ( $has_settings && ! self::verify_discovery_settings( $settings ) ) { return false; }
        if ( $has_cards && ! self::verify_cards_v3( $cards ) && ! self::verify_cards_v4( $cards ) && ! self::verify_cards_v4_partial( $cards ) ) { return false; }

        /* A partial installation is never inferred, merged or repaired. */
        if ( $has_cards !== $has_blocks || $has_discoveries !== $has_settings ) { return false; }
        if ( $has_cards && $has_blocks && $has_discoveries && $has_settings ) {
            if ( self::verify_cards_v4( $cards ) ) {
                if ( (string) get_option( self::OPTION, '' ) !== self::VERSION ) { update_option( self::OPTION, self::VERSION, false ); }
                return true;
            }
            if ( ! $allow_schema_change ) {
                /* Link V1 can keep running on schema 3. Studio V2 stays inactive. */
                return self::verify_cards_v3( $cards );
            }
            return self::migrate_cards_to_v4( $cards );
        }
        if ( ! $allow_schema_change ) { return false; }
        if ( ! $has_cards && ! $has_blocks && ! $has_discoveries && ! $has_settings ) {
            return self::create_under_lock( $cards, $blocks, $discoveries, $settings, true );
        }
        /* FL-01 to FL-17: cards and blocks were complete, discoveries did not exist. */
        if ( $has_cards && $has_blocks && ! $has_discoveries && ! $has_settings ) {
            if ( ! self::verify_cards_v4( $cards ) && ! self::migrate_cards_to_v4( $cards ) ) { return false; }
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
            if ( ! self::verify_cards_v4( $cards ) || ! self::verify_blocks( $blocks ) || ! self::verify_discoveries( $discoveries ) || ! self::verify_discovery_settings( $settings ) ) { return false; }
            update_option( self::OPTION, self::VERSION, false );
            return true;
        } finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
    }

    private static function migrate_cards_to_v4( $table ) {
        global $wpdb;
        $lock = 'faluss_link_v4_' . substr( hash( 'sha256', $wpdb->prefix ), 0, 29 );
        if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $lock, 10 ) ) ) { return false; }
        try {
            if ( self::verify_cards_v4( $table ) ) { update_option( self::OPTION, self::VERSION, false ); return true; }
            if ( self::verify_cards_v3( $table ) && false === $wpdb->query( 'ALTER TABLE `' . $table . '` ADD `composition` longtext NULL AFTER `link_style`' ) ) { return false; }
            if ( ! self::verify_cards_v4_partial( $table ) ) { return false; }
            $rows = $wpdb->get_results( 'SELECT faluss_id,social_links,composition FROM `' . $table . '`', ARRAY_A );
            if ( ! is_array( $rows ) ) { return false; }
            foreach ( $rows as $row ) {
                $existing = is_string( $row['composition'] ?? null ) ? trim( $row['composition'] ) : '';
                $decoded = '' !== $existing ? json_decode( $existing, true ) : null;
                if ( self::valid_composition( $decoded ) ) { continue; }
                $composition = wp_json_encode( self::legacy_composition( $row['social_links'] ?? '[]' ) );
                if ( ! is_string( $composition ) || false === $wpdb->query( $wpdb->prepare( 'UPDATE `' . $table . '` SET composition=%s WHERE faluss_id=%s', $composition, $row['faluss_id'] ) ) ) { return false; }
            }
            if ( false === $wpdb->query( 'ALTER TABLE `' . $table . '` MODIFY `composition` longtext NOT NULL' ) || ! self::verify_cards_v4( $table ) ) { return false; }
            update_option( self::OPTION, self::VERSION, false );
            return true;
        } finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
    }

    private static function legacy_composition( $social_links ) {
        $payload = is_string( $social_links ) ? json_decode( $social_links, true ) : array();
        $payload = is_array( $payload ) && ! array_is_list( $payload ) ? $payload : array();
        $pick = static function( $value, $allowed, $fallback ) { return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : $fallback; };
        $hex = static function( $value, $fallback ) { return is_string( $value ) && 1 === preg_match( '/^#[0-9A-Fa-f]{6}$/D', $value ) ? strtoupper( $value ) : $fallback; };
        $setting = static function( $value, $fallback ) { $value = is_string( $value ) ? strtolower( trim( $value ) ) : ''; return 1 === preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $value ) ? $value : $fallback; };
        $theme = static function( $value ) { $value = is_string( $value ) ? strtolower( trim( $value ) ) : ''; return 1 === preg_match( '/^[a-z][a-z0-9-]{0,63}$/D', $value ) ? $value : 'faluss-default'; };
        $social_selected = array();
        foreach ( array_slice( is_array( $payload['social_selected'] ?? null ) ? $payload['social_selected'] : array(), 0, 10 ) as $network ) {
            $network = is_string( $network ) ? strtolower( trim( $network ) ) : '';
            if ( 1 === preg_match( '/^[a-z][a-z0-9-]{0,63}$/D', $network ) && ! in_array( $network, $social_selected, true ) ) { $social_selected[] = $network; }
        }
        $theme_overrides = array();
        $allowed_overrides = array( 'page_background', 'hero_transition_color', 'name_color', 'alignment', 'social_variant', 'link_style' );
        foreach ( is_array( $payload['theme_overrides'] ?? null ) ? $payload['theme_overrides'] : array() as $override ) {
            if ( is_string( $override ) && in_array( $override, $allowed_overrides, true ) && ! in_array( $override, $theme_overrides, true ) ) { $theme_overrides[] = $override; }
        }
        return array(
            'version' => 2,
            'structure' => 'simple',
            'presentation' => array(
                'avatar_border' => ! empty( $payload['avatar_border'] ) ? 1 : 0,
                'name_font' => $setting( $payload['name_font'] ?? 'outfit', 'outfit' ),
                'social_selected' => $social_selected,
                'alignment' => $pick( $payload['alignment'] ?? 'center', array( 'left', 'center', 'right' ), 'center' ),
                'page_background' => $hex( $payload['page_background'] ?? null, '#FFFDF5' ),
                'button_color' => $hex( $payload['button_color'] ?? null, '#080808' ),
                'hero_transition_color' => $hex( $payload['hero_transition_color'] ?? null, '#FFFDF5' ),
                'hero_transition_intensity' => min( 100, max( 0, (int) ( $payload['hero_transition_intensity'] ?? 82 ) ) ),
                'hero_transition_position' => min( 100, max( 35, (int) ( $payload['hero_transition_position'] ?? 72 ) ) ),
                'name_color' => $hex( $payload['name_color'] ?? null, '#000000' ),
                'social_variant' => $pick( $payload['social_variant'] ?? $payload['social_appearance'] ?? 'outline', array( 'outline', 'full' ), 'outline' ),
                'selected_theme' => $theme( $payload['selected_theme'] ?? 'faluss-default' ),
                'theme_overrides' => $theme_overrides,
            ),
            'atomic' => array(
                'button_texture' => 'smooth',
                'avatar_shape' => 'round',
                'avatar_effect' => ! empty( $payload['avatar_border'] ) ? 'border' : 'none',
                'wallpaper_size' => 'compact',
                'wallpaper_effect' => 'gradient',
                'social_style' => 'brand-light',
                'social_color' => '',
                'links_mode' => 'neutral',
                'link_width' => 'wide',
            ),
        );
    }

    private static function valid_composition( $document ) {
        if ( ! is_array( $document ) || 2 !== (int) ( $document['version'] ?? 0 ) ) { return false; }
        $top = array_keys( $document ); sort( $top );
        if ( $top !== array( 'atomic', 'presentation', 'structure', 'version' ) || ! in_array( $document['structure'], array( 'simple', 'atomic' ), true ) || ! is_array( $document['presentation'] ) || ! is_array( $document['atomic'] ) ) { return false; }
        $presentation = array( 'alignment', 'avatar_border', 'button_color', 'hero_transition_color', 'hero_transition_intensity', 'hero_transition_position', 'name_color', 'name_font', 'selected_theme', 'social_selected', 'social_variant', 'theme_overrides' );
        $presentation[] = 'page_background'; sort( $presentation );
        $atomic = array( 'avatar_effect', 'avatar_shape', 'button_texture', 'link_width', 'links_mode', 'social_color', 'social_style', 'wallpaper_effect', 'wallpaper_size' );
        $presentation_keys = array_keys( $document['presentation'] ); sort( $presentation_keys );
        $atomic_keys = array_keys( $document['atomic'] ); sort( $atomic_keys );
        if ( $presentation_keys !== $presentation || $atomic_keys !== $atomic ) { return false; }
        $p = $document['presentation']; $a = $document['atomic'];
        $allowed_overrides = array( 'page_background', 'hero_transition_color', 'name_color', 'alignment', 'social_variant', 'link_style' );
        $hex = static function( $value ) { return is_string( $value ) && 1 === preg_match( '/^#[0-9A-F]{6}$/D', $value ); };
        $key = static function( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-z][a-z0-9-]{0,63}$/D', $value ); };
        $setting = static function( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $value ); };
        if ( ! in_array( $p['avatar_border'], array( 0, 1 ), true ) || ! $setting( $p['name_font'] ) || ! is_array( $p['social_selected'] ) || ! array_is_list( $p['social_selected'] ) || count( $p['social_selected'] ) > 10 || count( $p['social_selected'] ) !== count( array_unique( $p['social_selected'] ) ) || ! in_array( $p['alignment'], array( 'left', 'center', 'right' ), true ) || ! $hex( $p['page_background'] ) || ! $hex( $p['button_color'] ) || ! $hex( $p['hero_transition_color'] ) || ! is_int( $p['hero_transition_intensity'] ) || $p['hero_transition_intensity'] < 0 || $p['hero_transition_intensity'] > 100 || ! is_int( $p['hero_transition_position'] ) || $p['hero_transition_position'] < 35 || $p['hero_transition_position'] > 100 || ! $hex( $p['name_color'] ) || ! in_array( $p['social_variant'], array( 'outline', 'full' ), true ) || ! $key( $p['selected_theme'] ) || ! is_array( $p['theme_overrides'] ) || ! array_is_list( $p['theme_overrides'] ) || count( $p['theme_overrides'] ) > 6 || count( $p['theme_overrides'] ) !== count( array_unique( $p['theme_overrides'] ) ) ) { return false; }
        foreach ( $p['social_selected'] as $item ) { if ( ! $key( $item ) ) { return false; } }
        foreach ( $p['theme_overrides'] as $item ) { if ( ! $setting( $item ) || ! in_array( $item, $allowed_overrides, true ) ) { return false; } }
        return in_array( $a['button_texture'], array( 'grain', 'smooth', 'camo' ), true )
            && in_array( $a['avatar_shape'], array( 'round', 'rounded', 'square' ), true )
            && in_array( $a['avatar_effect'], array( 'none', 'border', 'shadow', 'both' ), true )
            && in_array( $a['wallpaper_size'], array( 'compact', 'cover' ), true )
            && in_array( $a['wallpaper_effect'], array( 'none', 'gradient' ), true )
            && in_array( $a['social_style'], array( 'brand-light', 'brand-dark', 'outline-dark', 'outline-light', 'mono-light', 'mono-dark', 'tint-pink', 'tint-mint', 'solid-custom' ), true )
            && ( '' === $a['social_color'] || $hex( $a['social_color'] ) )
            && in_array( $a['links_mode'], array( 'neutral', 'image-grid' ), true )
            && in_array( $a['link_width'], array( 'wide', 'compact' ), true );
    }

    private static function valid_prefix() { global $wpdb; return is_object( $wpdb ) && preg_match( '/^[A-Za-z0-9_]+$/', $wpdb->prefix ?? '' ); }
    private static function exists( $table ) { global $wpdb; return null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); }
    private static function cards_columns_v3() { return array( 'id' => 'bigint(20) unsigned', 'faluss_id' => 'char(36)', 'cover_attachment_id' => 'bigint(20) unsigned', 'avatar_visible' => 'tinyint(1)', 'name_weight' => 'varchar(20)', 'name_treatment' => 'varchar(20)', 'available' => 'tinyint(1)', 'bio_mode' => 'varchar(20)', 'announcement' => 'varchar(120)', 'announcement_variant' => 'varchar(20)', 'social_links' => 'longtext', 'social_layout' => 'varchar(20)', 'link_style' => 'varchar(20)', 'created_at' => 'datetime', 'updated_at' => 'datetime' ); }
    private static function cards_columns_v4() { $columns = self::cards_columns_v3(); $before_dates = array_slice( $columns, 0, 13, true ); return $before_dates + array( 'composition' => 'longtext' ) + array_slice( $columns, 13, null, true ); }
    private static function cards_query( $table ) { global $wpdb; return 'CREATE TABLE `' . $table . '` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,`faluss_id` char(36) NOT NULL,`cover_attachment_id` bigint(20) unsigned NULL,`avatar_visible` tinyint(1) NOT NULL,`name_weight` varchar(20) NOT NULL,`name_treatment` varchar(20) NOT NULL,`available` tinyint(1) NOT NULL,`bio_mode` varchar(20) NOT NULL,`announcement` varchar(120) NULL,`announcement_variant` varchar(20) NOT NULL,`social_links` longtext NOT NULL,`social_layout` varchar(20) NOT NULL,`link_style` varchar(20) NOT NULL,`composition` longtext NOT NULL,`created_at` datetime NOT NULL,`updated_at` datetime NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `faluss_id_unique` (`faluss_id`)) ENGINE=InnoDB ' . $wpdb->get_charset_collate(); }
    private static function blocks_query( $table ) { global $wpdb; return 'CREATE TABLE `' . $table . '` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,`faluss_id` char(36) NOT NULL,`block_id` char(36) NOT NULL,`sort_order` smallint(5) unsigned NOT NULL,`block_type` varchar(20) NOT NULL,`payload` longtext NOT NULL,`created_at` datetime NOT NULL,`updated_at` datetime NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `faluss_block_id` (`faluss_id`,`block_id`),UNIQUE KEY `faluss_block_order` (`faluss_id`,`sort_order`)) ENGINE=InnoDB ' . $wpdb->get_charset_collate(); }
    private static function discoveries_query( $table ) { global $wpdb; return 'CREATE TABLE `' . $table . '` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,`viewer_faluss_id` char(36) NOT NULL,`discovered_faluss_id` char(36) NOT NULL,`first_seen_at` datetime NOT NULL,`last_seen_at` datetime NOT NULL,`view_count` int(10) unsigned NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `faluss_discovery_pair` (`viewer_faluss_id`,`discovered_faluss_id`),KEY `faluss_discovery_recent` (`viewer_faluss_id`,`last_seen_at`,`id`)) ENGINE=InnoDB ' . $wpdb->get_charset_collate(); }
    private static function discovery_settings_query( $table ) { global $wpdb; return 'CREATE TABLE `' . $table . '` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,`viewer_faluss_id` char(36) NOT NULL,`recording_enabled` tinyint(1) NOT NULL,`updated_at` datetime NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `faluss_discovery_viewer` (`viewer_faluss_id`)) ENGINE=InnoDB ' . $wpdb->get_charset_collate(); }
    private static function verify_cards_v3( $table ) { return self::verify_table( $table, self::cards_columns_v3(), array( 'PRIMARY', 'faluss_id_unique' ) ); }
    private static function verify_cards_v4( $table ) { return self::verify_table( $table, self::cards_columns_v4(), array( 'PRIMARY', 'faluss_id_unique' ), array( 'composition' => 'NO' ) ); }
    private static function verify_cards_v4_partial( $table ) { return self::verify_table( $table, self::cards_columns_v4(), array( 'PRIMARY', 'faluss_id_unique' ), array( 'composition' => 'YES' ) ); }
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
