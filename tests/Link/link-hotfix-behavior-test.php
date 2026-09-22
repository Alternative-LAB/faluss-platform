<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'FALUSS_LINK_FILE', dirname( __DIR__, 2 ) . '/faluss-platform.php' );

function fl_hotfix_assert( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); } }
function __( $value ) { return (string) $value; }
function _n( $single, $plural, $number ) { return 1 === (int) $number ? $single : $plural; }
function esc_html__( $value ) { return esc_html( $value ); }
function esc_html_e( $value ) { echo esc_html( $value ); }
function esc_attr_e( $value ) { echo esc_attr( $value ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_textarea( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return (string) $value; }
function esc_url_raw( $value ) { return trim( (string) $value ); }
function wp_unslash( $value ) { return $value; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_title( $value ) { return strtolower( trim( preg_replace( '/[^a-z0-9-]/i', '', (string) $value ) ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_hex_color( $value ) { return 1 === preg_match( '/^#[0-9a-f]{6}$/i', (string) $value ) ? strtoupper( (string) $value ) : null; }
function absint( $value ) { return abs( (int) $value ); }
function wp_json_encode( $value ) { return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); }
function wp_parse_url( $value ) { return parse_url( (string) $value ); }
function wp_parse_args( $value, $defaults ) { return array_merge( $defaults, (array) $value ); }
function current_time() { return '2026-09-12 12:00:00'; }
function get_current_user_id() { return 17; }
function home_url( $path = '' ) { return 'https://faluss.test' . $path; }
function admin_url( $path = '' ) { return 'https://faluss.test/wp-admin/' . ltrim( $path, '/' ); }
function plugins_url( $path = '' ) { return 'https://faluss.test/wp-content/plugins/faluss-platform/' . ltrim( $path, '/' ); }
function get_permalink() { global $fl_hotfix_permalink; return $fl_hotfix_permalink ?: 'https://faluss.test/studio/'; }
function add_query_arg( $args, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }
function wp_generate_uuid4() { static $suffix = 100; ++$suffix; return '99999999-9999-4999-8999-' . str_pad( (string) $suffix, 12, '0', STR_PAD_LEFT ); }
function wp_unique_id( $prefix = '' ) { static $id = 0; return $prefix . ++$id; }
function wp_attachment_is_image( $id ) { return 77 === (int) $id; }
function wp_get_attachment_image_url( $id ) { return wp_attachment_is_image( $id ) ? 'https://faluss.test/media/' . (int) $id . '.jpg' : ''; }
function wp_get_attachment_image( $id, $size, $icon = false, $attributes = array() ) { return wp_attachment_is_image( $id ) ? '<img src="https://faluss.test/media/' . (int) $id . '.jpg" alt="">' : ''; }
function is_user_logged_in() { return true; }
function wp_logout_url( $url ) { return $url; }
function wp_list_pluck( $items, $field ) { return array_map( static function( $item ) use ( $field ) { return $item[ $field ] ?? null; }, $items ); }
function wp_style_is() { return true; }
function wp_enqueue_style() {}
function wp_enqueue_script() {}
function wp_localize_script() {}
function wp_create_nonce( $action ) { return 'nonce-' . $action; }
function wp_nonce_field( $action, $name ) { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="nonce">'; }
function checked( $checked, $current = true, $echo = true ) { $value = (string) $checked === (string) $current ? 'checked="checked"' : ''; if ( $echo ) { echo $value; } return $value; }
function selected( $selected, $current = true, $echo = true ) { $value = (string) $selected === (string) $current ? 'selected="selected"' : ''; if ( $echo ) { echo $value; } return $value; }
function apply_filters( $hook, $value, ...$args ) { global $fl_hotfix_fail_stage; return 'faluss_link_studio_mutation_checkpoint' === $hook && ( $args[0] ?? '' ) === $fl_hotfix_fail_stage ? false : $value; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }

class WP_Error {
    private $code;
    public function __construct( $code ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
class WP_Post {
    public $post_author = 17;
    public $post_mime_type = 'image/jpeg';
}
function get_post( $id ) { return wp_attachment_is_image( $id ) ? new WP_Post() : null; }

final class Faluss_Link_Schema {
    public static function table() { return 'wp_faluss_link_cards'; }
    public static function blocks_table() { return 'wp_faluss_link_blocks'; }
}
final class Faluss_Identity_Registry {
    public static function is_valid_faluss_id( $value ) { return 1 === preg_match( '/^[0-9a-f-]{36}$/', (string) $value ); }
    public static function get_active_for_wp_user() { global $wpdb; return $wpdb->identity['faluss_id'] ?? ''; }
}
final class Faluss_Identity_Schema {
    public static function get_status() { return array( 'ready' => true ); }
}
final class Faluss_Catalog_Themes {
    public static function active_for_scope() { return self::themes(); }
    public static function all_for_scope() { return self::themes(); }
    public static function get_active_theme( $slug ) { $themes = self::themes(); return $themes[ $slug ] ?? null; }
    private static function themes() {
        return array( 'sunset' => array( 'name' => 'Sunset', 'slug' => 'sunset', 'active' => 1, 'preview_attachment_id' => 0, 'scope' => 'faluss-link', 'page_background' => '#AABBCC', 'hero_transition_color' => '#DDEEFF', 'name_color' => '#82206B', 'alignment' => 'left', 'social_variant' => 'full', 'link_style' => 'outline', 'entitlement_code' => '' ) );
    }
}

final class FL_Hotfix_WPDB {
    public $prefix = 'wp_';
    public $card = array();
    public $blocks = array();
    public $identity = array();
    public $fail_projection = false;
    public $fail_commit = false;
    public $write_count = 0;
    public $transaction_count = 0;
    private $snapshot = null;

    public function prepare( $query, ...$args ) {
        foreach ( $args as $arg ) {
            $query = preg_replace_callback( '/%[ds]/', static function( $match ) use ( $arg ) { return '%d' === $match[0] ? (string) (int) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'"; }, $query, 1 );
        }
        return $query;
    }
    public function query( $query ) {
        if ( 'START TRANSACTION' === $query ) { ++$this->transaction_count; $this->snapshot = serialize( array( $this->card, $this->blocks, $this->identity ) ); return 1; }
        if ( 'COMMIT' === $query ) { if ( $this->fail_commit ) { return false; } $this->snapshot = null; return 1; }
        if ( 'ROLLBACK' === $query ) { if ( null !== $this->snapshot ) { list( $this->card, $this->blocks, $this->identity ) = unserialize( $this->snapshot ); } $this->snapshot = null; return 1; }
        if ( 1 === preg_match( '/^(?:INSERT|UPDATE|DELETE)\b/i', trim( $query ) ) ) { ++$this->write_count; }
        if ( false !== strpos( $query, 'UPDATE wp_faluss_link_blocks SET sort_order=sort_order+100' ) ) {
            $minimum = preg_match( '/sort_order>=(\d+)/', $query, $matches ) ? (int) $matches[1] : null;
            foreach ( $this->blocks as &$row ) { if ( null === $minimum || (int) $row['sort_order'] >= $minimum ) { $row['sort_order'] += 100; } } unset( $row ); return 1;
        }
        if ( false !== strpos( $query, 'UPDATE wp_faluss_link_blocks SET sort_order=sort_order-99' ) ) {
            preg_match( '/sort_order>=(\d+)/', $query, $matches ); $minimum = (int) ( $matches[1] ?? 0 );
            foreach ( $this->blocks as &$row ) { if ( (int) $row['sort_order'] >= $minimum ) { $row['sort_order'] -= 99; } } unset( $row ); return 1;
        }
        if ( false !== strpos( $query, 'UPDATE wp_faluss_link_blocks SET sort_order=sort_order-1' ) ) {
            preg_match( '/sort_order>(\d+)/', $query, $matches ); $minimum = (int) ( $matches[1] ?? 0 );
            foreach ( $this->blocks as &$row ) { if ( (int) $row['sort_order'] > $minimum ) { --$row['sort_order']; } } unset( $row ); return 1;
        }
        return 1;
    }
    public function get_row( $query ) { return false !== strpos( $query, 'wp_faluss_link_cards' ) ? $this->card : null; }
    public function get_results( $query ) {
        if ( false === strpos( $query, 'wp_faluss_link_blocks' ) ) { return array(); }
        $rows = $this->blocks;
        usort( $rows, static function( $left, $right ) { return (int) $left['sort_order'] <=> (int) $right['sort_order']; } );
        return $rows;
    }
    public function insert( $table, $data ) {
        ++$this->write_count;
        if ( 'wp_faluss_link_cards' === $table ) { if ( $this->card ) { return false; } $this->card = $data; return 1; }
        foreach ( $this->blocks as $row ) { if ( $row['block_id'] === $data['block_id'] ) { return false; } }
        $data['id'] = count( $this->blocks ) + 1; $this->blocks[] = $data; return 1;
    }
    public function update( $table, $values, $where, $formats = null, $where_formats = null ) {
        ++$this->write_count;
        if ( 'wp_faluss_link_cards' === $table ) { if ( ! $this->card || $this->card['faluss_id'] !== ( $where['faluss_id'] ?? '' ) ) { return 0; } $this->card = array_merge( $this->card, $values ); return 1; }
        foreach ( $this->blocks as &$row ) {
            $matches = true;
            foreach ( $where as $key => $value ) { if ( (string) ( $row[ $key ] ?? '' ) !== (string) $value ) { $matches = false; break; } }
            if ( $matches ) { $row = array_merge( $row, $values ); unset( $row ); return 1; }
        }
        unset( $row ); return 0;
    }
    public function delete( $table, $where ) {
        ++$this->write_count;
        $deleted = 0;
        foreach ( array_keys( $this->blocks ) as $index ) {
            $matches = true;
            foreach ( $where as $key => $value ) { if ( (string) ( $this->blocks[ $index ][ $key ] ?? '' ) !== (string) $value ) { $matches = false; break; } }
            if ( $matches ) { unset( $this->blocks[ $index ] ); ++$deleted; }
        }
        $this->blocks = array_values( $this->blocks ); return $deleted;
    }
    public function digest() { return hash( 'sha256', serialize( array( $this->card, $this->blocks, $this->identity ) ) ); }
}

final class Faluss_Identity_Public_Profile {
    public static function lock_studio_profile_in_transaction() { global $wpdb; return $wpdb->identity ?: false; }
    public static function studio_profile() { global $wpdb; $profile = $wpdb->identity; $profile['links'] = json_decode( (string) ( $profile['external_links'] ?? '[]' ), true ) ?: array(); return $profile; }
    public static function persist_external_links_in_transaction( $faluss_id, $links ) { global $wpdb; $wpdb->identity['external_links'] = wp_json_encode( $links ); return ! $wpdb->fail_projection; }
    public static function persist_studio_profile_in_transaction( $faluss_id, $fields ) { global $wpdb; if ( isset( $fields['publication_status'] ) && ! in_array( $fields['publication_status'], array( 'draft', 'published' ), true ) ) { return false; } $wpdb->identity = array_merge( $wpdb->identity, $fields ); if ( isset( $fields['publication_status'] ) && 'published' === $fields['publication_status'] && empty( $wpdb->identity['published_at'] ) ) { $wpdb->identity['published_at'] = current_time(); } return true; }
}

$root = getenv( 'FALUSS_HOTFIX_SOURCE_ROOT' ) ?: dirname( __DIR__, 2 );
$source = file_get_contents( $root . '/src/Link/LegacyLinkService.php' );
$link_bootstrap = file_get_contents( $root . '/src/Link/LinkModule.php' );
$identity_source = file_get_contents( $root . '/src/Link/LinkIdentityAdapter.php' );
$portal_bootstrap = file_get_contents( $root . '/src/Portal/PortalModule.php' );
$editor = file_get_contents( $root . '/assets/link/js/faluss-link-editor.js' );
$onboarding = file_get_contents( $root . '/assets/link/js/faluss-link-onboarding.js' );
$studio_css = file_get_contents( $root . '/assets/link/css/faluss-link-studio.css' );
$immersive = file_get_contents( $root . '/assets/link/js/faluss-link-immersive.js' ) . file_get_contents( $root . '/assets/link/css/faluss-link-immersive.css' );

foreach ( array( 'save_appearance', 'save_header', 'save_link_style', 'create_link', 'update_link', 'delete_link', 'create_collection', 'update_collection', 'dissolve_collection', 'reorder_blocks', 'save_profile' ) as $mutation ) { fl_hotfix_assert( false !== strpos( $source, "'$mutation'" ), 'Missing targeted mutation: ' . $mutation ); }
foreach ( array( 'START TRANSACTION', 'FOR UPDATE', 'persistExternalLinksInTransaction', 'ROLLBACK', 'COMMIT', 'stale_version', 'aggregate_version_from_state', 'faluss_link_studio_mutation_checkpoint' ) as $needle ) { fl_hotfix_assert( false !== strpos( $source . $identity_source, $needle ), 'Missing atomicity or conflict primitive: ' . $needle ); }
fl_hotfix_assert( false === strpos( substr( $identity_source, strpos( $identity_source, 'public static function persistExternalLinksInTransaction' ), 900 ), 'START TRANSACTION' ), 'Identity transaction primitives must not own a nested transaction.' );
fl_hotfix_assert( false === strpos( $source, "add_action( 'faluss_catalog_theme_deactivated'" ) && false === strpos( $source, "add_action( 'admin_init', array( __CLASS__, 'migrate_inactive_catalog_theme_references'" ), 'Install, update, admin load, and theme deactivation must not rewrite member data.' );
foreach ( array( 'studio_cover_control', 'faluss-link-editor__select-cover', 'faluss-link-editor__remove-cover', 'cover_attachment_id', 'wp_ajax_faluss_link_upload_avatar', 'faluss_link_upload_avatar' ) as $needle ) { fl_hotfix_assert( false !== strpos( $source . $editor, $needle ), 'The existing member media contract is not restored: ' . $needle ); }
foreach ( array( 'faluss-link-card__cover', 'faluss-link-card--cover-yes', '--fl-immersive-depth', 'translate3d', 'scale(1.05)' ) as $needle ) { fl_hotfix_assert( false !== strpos( $source . $studio_css . $immersive, $needle ), 'The existing top-cover rendering or scroll effect is missing: ' . $needle ); }
foreach ( array( 'enqueueStudioMutation', 'queue.pending', 'queue.running', 'drainStudioMutations', 'hydrateCanonicalBlocks', 'hydrateCanonicalForm', 'collections_html', 'collection_html', 'active_collection', 'preview_html' ) as $needle ) { fl_hotfix_assert( false !== strpos( $editor, $needle ), 'The serialized queue or canonical rehydration is incomplete: ' . $needle ); }
fl_hotfix_assert( false !== strpos( $editor, 'if (state) { hydrateCanonicalBlocks(studio, state); hydrateCanonicalForm(studio, state); }' ), 'Any rejected mutation with canonical state must restore the form, including locked themes.' );
fl_hotfix_assert( false === strpos( $editor, 'new FormData(form[0])' ) && false === strpos( $editor, 'if (studio.data(\'falussLinkSaving\')) { return' ), 'A full form or active-save early return may not drive Studio persistence.' );
fl_hotfix_assert( false !== strpos( $onboarding, 'applyOnboardingTheme' ) && false !== strpos( $source, 'self::theme_picker( $preferences )' ), 'Onboarding must expose and persist the canonical theme selector.' );
fl_hotfix_assert( false === strpos( $source, "studio_empty_state( 'collection' )" ) && false !== strpos( $source, "array( 'links', 'collections' )" ), 'The generic Empty State helper must accept only the two real empty-panel kinds.' );
fl_hotfix_assert( false !== strpos( $link_bootstrap, "VERSION = '0.3.21'" ) && false !== strpos( $portal_bootstrap, "VERSION = '0.1.24'" ), 'FL-HOTFIX-01.3 behavior must remain present while Link and Portal advance independently.' );

global $wpdb, $fl_hotfix_fail_stage, $fl_hotfix_permalink;
$fl_hotfix_fail_stage = '';
$fl_hotfix_permalink = '';
$wpdb = new FL_Hotfix_WPDB();
$member = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$link_a = '11111111-1111-4111-8111-111111111111';
$collection_a = '22222222-2222-4222-8222-222222222222';
$description_a = '33333333-3333-4333-8333-333333333333';
$link_b = '44444444-4444-4444-8444-444444444444';
$wpdb->card = array( 'faluss_id' => $member, 'cover_attachment_id' => 0, 'avatar_visible' => 1, 'name_weight' => 'bold', 'name_treatment' => 'strong', 'available' => 0, 'bio_mode' => 'editorial', 'announcement' => '', 'announcement_variant' => 'accent', 'social_links' => wp_json_encode( array( 'networks' => array(), 'selected_theme' => 'faluss-default', 'theme_overrides' => array(), 'avatar_border' => 1, 'name_font' => 'outfit', 'alignment' => 'center', 'page_background' => '#FFFDF5', 'button_color' => '#080808', 'hero_transition_color' => '#FFFDF5', 'hero_transition_intensity' => 82, 'hero_transition_position' => 72, 'name_color' => '#000000', 'social_variant' => 'outline', 'link_style' => 'solid' ) ), 'social_layout' => 'bubbles', 'link_style' => 'solid', 'created_at' => current_time(), 'updated_at' => current_time() );
$wpdb->blocks = array(
    array( 'id' => 1, 'faluss_id' => $member, 'block_id' => $link_a, 'sort_order' => 1, 'block_type' => 'link', 'payload' => wp_json_encode( array( 'label' => 'Libre', 'url' => 'https://example.test/free' ) ) ),
    array( 'id' => 2, 'faluss_id' => $member, 'block_id' => $collection_a, 'sort_order' => 2, 'block_type' => 'section_title', 'payload' => wp_json_encode( array( 'value' => 'Collection A' ) ) ),
    array( 'id' => 3, 'faluss_id' => $member, 'block_id' => $description_a, 'sort_order' => 3, 'block_type' => 'text', 'payload' => wp_json_encode( array( 'value' => 'Description A' ) ) ),
    array( 'id' => 4, 'faluss_id' => $member, 'block_id' => $link_b, 'sort_order' => 4, 'block_type' => 'link', 'payload' => wp_json_encode( array( 'label' => 'Dans A', 'url' => 'https://example.test/a' ) ) ),
);
$wpdb->identity = array( 'faluss_id' => $member, 'public_slug' => 'membre', 'display_name' => 'Membre', 'bio' => 'Bio', 'avatar_attachment_id' => 0, 'publication_status' => 'draft', 'external_links' => wp_json_encode( array( array( 'label' => 'Libre', 'url' => 'https://example.test/free', 'position' => 1 ), array( 'label' => 'Dans A', 'url' => 'https://example.test/a', 'position' => 2 ) ) ), 'published_at' => null );

require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Link/LegacyLinkService.php';
$version_method = new ReflectionMethod( 'Faluss_Link', 'studio_aggregate_version' );
$run_method = new ReflectionMethod( 'Faluss_Link', 'run_studio_mutation' );
$request_method = new ReflectionMethod( 'Faluss_Link', 'studio_mutation_request' );
$prefs_method = new ReflectionMethod( 'Faluss_Link', 'prefs' );
$collections_panel_method = new ReflectionMethod( 'Faluss_Link', 'studio_collections_panel' );
$collection_panel_method = new ReflectionMethod( 'Faluss_Link', 'studio_collection_panel' );
$links_panel_method = new ReflectionMethod( 'Faluss_Link', 'studio_links_panel' );
$version = static function() use ( $version_method, $member ) { return $version_method->invoke( null, $member ); };
$run = static function( $mutation, $payload, $supplied_version = null, $active_collection = '' ) use ( $run_method, $version, $member ) { return $run_method->invoke( null, $member, array( 'mutation' => $mutation, 'payload' => $payload, 'version' => null === $supplied_version ? $version() : $supplied_version, 'active_collection' => $active_collection ) ); };
$render_panel = static function( $method, $payload ) { ob_start(); $method->invoke( null, $payload ); return (string) ob_get_clean(); };

$canonical_blocks = $wpdb->blocks;
$canonical_identity = $wpdb->identity;
$wpdb->blocks = array();
$before_render = $wpdb->digest();
$writes_before_render = $wpdb->write_count;
$transactions_before_render = $wpdb->transaction_count;
$_GET = array();
$legacy_markup = Faluss_Link::render_studio();
fl_hotfix_assert( false !== strpos( $legacy_markup, 'Libre' ) && false !== strpos( $legacy_markup, 'Dans A' ), 'A legacy Identity-only profile must remain visible in the Studio read model.' );
fl_hotfix_assert( $before_render === $wpdb->digest() && $writes_before_render === $wpdb->write_count && $transactions_before_render === $wpdb->transaction_count && array() === $wpdb->blocks, 'Opening render_studio must perform no write, transaction, projection, or implicit block import.' );
$legacy_version = $version();
$before_legacy_mutation = $wpdb->digest();
$legacy_mutation = $run( 'create_link', array( 'block_id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'label' => 'Refusé', 'url' => 'https://example.test/refused', 'collection_id' => '' ), $legacy_version );
fl_hotfix_assert( ! $legacy_mutation['ok'] && 409 === $legacy_mutation['status'] && 'legacy_blocks_not_initialized' === $legacy_mutation['code'] && $before_legacy_mutation === $wpdb->digest() && array() === $wpdb->blocks, 'A block mutation against a legacy-only state must fail explicitly without importing or changing data.' );
$wpdb->blocks = $canonical_blocks;
$wpdb->identity = $canonical_identity;

$before_empty_collection_render = $wpdb->digest();
$writes_before_empty_collection_render = $wpdb->write_count;
$transactions_before_empty_collection_render = $wpdb->transaction_count;
$no_collections_markup = $render_panel( $collections_panel_method, array() );
$empty_collection = array( 'block_id' => $collection_a, 'name' => 'Collection vide', 'description' => '', 'links' => array() );
$collections_with_empty_markup = $render_panel( $collections_panel_method, array( $collection_a => $empty_collection ) );
$empty_collection_markup = $render_panel( $collection_panel_method, $empty_collection );
$linked_collection = $empty_collection;
$linked_collection['links'][] = array( 'block_id' => $link_b, 'type' => 'link', 'label' => 'Lien conservé', 'url' => 'https://example.test/kept' );
$linked_collection_markup = $render_panel( $collection_panel_method, $linked_collection );
$no_links_markup = $render_panel( $links_panel_method, array() );
fl_hotfix_assert( 1 === substr_count( $no_collections_markup, 'Zéro collection' ) && 1 === substr_count( $no_collections_markup, 'class="faluss-link-studio__empty"' ), 'A truly empty Collections panel must retain exactly one global Zéro collection state.' );
fl_hotfix_assert( false !== strpos( $collections_with_empty_markup, 'faluss-link-studio__collection-card' ) && false !== strpos( $collections_with_empty_markup, 'Collection vide' ) && false === strpos( $collections_with_empty_markup, 'faluss-link-studio__empty' ), 'An existing empty collection must render its canonical card without a global Empty State.' );
fl_hotfix_assert( false !== strpos( $empty_collection_markup, 'Collection vide' ) && false !== strpos( $empty_collection_markup, '0 lien' ), 'Opening an existing empty collection must retain its name and zero-link counter.' );
fl_hotfix_assert( false === strpos( $empty_collection_markup, 'faluss-link-studio__empty' ) && false === strpos( $empty_collection_markup, 'Zéro collection' ) && false === strpos( $empty_collection_markup, 'Zéro lien' ), 'An open existing collection must never contain a contradictory generic Empty State.' );
fl_hotfix_assert( false !== strpos( $linked_collection_markup, 'Lien conservé' ) && false === strpos( $linked_collection_markup, 'faluss-link-studio__empty' ), 'A collection containing a link must keep rendering that link without an Empty State.' );
fl_hotfix_assert( 1 === substr_count( $no_links_markup, 'Zéro lien' ) && 1 === substr_count( $no_links_markup, 'class="faluss-link-studio__empty"' ), 'A truly empty All-links panel must retain exactly one Zéro lien state.' );
fl_hotfix_assert( $before_empty_collection_render === $wpdb->digest() && $writes_before_empty_collection_render === $wpdb->write_count && $transactions_before_empty_collection_render === $wpdb->transaction_count, 'Empty and populated collection renderers must perform no write, transaction, or migration.' );

$invalid_full_form = $request_method->invoke( null, array( 'mutation' => 'save_appearance', 'aggregate_version' => str_repeat( 'a', 64 ), 'page_background' => '#112233', 'content_blocks' => array() ) );
fl_hotfix_assert( is_wp_error( $invalid_full_form ), 'A complete or hidden DOM block payload must be rejected by a preference mutation.' );
$empty_delete = $request_method->invoke( null, array( 'mutation' => 'delete_link', 'aggregate_version' => str_repeat( 'a', 64 ) ) );
fl_hotfix_assert( is_array( $empty_delete ) && empty( $empty_delete['payload'] ), 'An absent UUID is not interpreted as a deletion.' );

$blocks_before_appearance = serialize( $wpdb->blocks );
$projection_before_appearance = $wpdb->identity['external_links'];
$appearance = $run( 'save_appearance', array( 'selected_theme' => 'sunset' ), null, $collection_a );
fl_hotfix_assert( $appearance['ok'] && $blocks_before_appearance === serialize( $wpdb->blocks ) && $projection_before_appearance === $wpdb->identity['external_links'], 'Theme mutation must leave canonical blocks and the Identity projection byte-for-byte unchanged: ' . wp_json_encode( array( 'result' => $appearance, 'blocks_same' => $blocks_before_appearance === serialize( $wpdb->blocks ), 'projection_same' => $projection_before_appearance === $wpdb->identity['external_links'] ) ) );
fl_hotfix_assert( false !== strpos( $appearance['state']['preview_html'], 'data-faluss-card-theme="sunset"' ) && false !== strpos( $appearance['state']['preview_html'], '#AABBCC' ), 'Studio and public-card resolver must return the selected theme presentation.' );

$custom = $run( 'save_appearance', array( 'page_background' => '#123456', 'hero_transition_color' => '#654321' ) );
fl_hotfix_assert( $custom['ok'] && '#123456' === $prefs_method->invoke( null, $member )['page_background'] && false !== strpos( $custom['state']['preview_html'], '#123456' ), 'Custom colors must persist and rehydrate through the shared card renderer.' );
$available_on = $run( 'save_header', array( 'available' => 1 ) );
fl_hotfix_assert( $available_on['ok'] && 1 === (int) $prefs_method->invoke( null, $member )['available'], 'Afficher Disponible must persist when enabled.' );
$available_off = $run( 'save_header', array( 'available' => 0 ) );
fl_hotfix_assert( $available_off['ok'] && 0 === (int) $prefs_method->invoke( null, $member )['available'], 'Afficher Disponible must persist when disabled.' );
$cover = $run( 'save_appearance', array( 'cover_attachment_id' => 77 ) );
fl_hotfix_assert( $cover['ok'] && 77 === (int) $prefs_method->invoke( null, $member )['cover_attachment_id'] && false !== strpos( $cover['state']['preview_html'], 'faluss-link-card--cover-yes' ), 'The existing owned cover attachment must persist and render at the top of the card.' );

$collection_b = '55555555-5555-4555-8555-555555555555';
$description_b = '66666666-6666-4666-8666-666666666666';
$link_c = '77777777-7777-4777-8777-777777777777';
$fl_hotfix_permalink = 'https://faluss.test/wp-admin/admin-post.php';
$created_collection = $run( 'create_collection', array( 'block_id' => $collection_b, 'description_block_id' => $description_b, 'name' => 'Collection B', 'description' => 'Description B' ), null, $collection_b );
fl_hotfix_assert( $created_collection['ok'] && false !== strpos( $created_collection['state']['collections_html'], 'Collection B' ), 'A created collection must remain in the canonical Collections panel.' );
preg_match( '/<a[^>]+href="([^"]+)"[^>]+data-fl-open-collection="' . preg_quote( $collection_b, '/' ) . '"/', $created_collection['state']['collections_html'], $collection_link_match );
$collection_url = html_entity_decode( $collection_link_match[1] ?? '', ENT_QUOTES, 'UTF-8' );
$collection_url_parts = parse_url( $collection_url );
parse_str( $collection_url_parts['query'] ?? '', $collection_query );
fl_hotfix_assert( 'https' === ( $collection_url_parts['scheme'] ?? '' ) && 'faluss.test' === ( $collection_url_parts['host'] ?? '' ) && '/mon-faluss/' === ( $collection_url_parts['path'] ?? '' ), 'Collections HTML produced during admin-post must use the canonical member route.' );
fl_hotfix_assert( array( 'faluss_studio_tab' => 'links', 'faluss_studio_section' => 'collection', 'faluss_studio_collection' => $collection_b ) === $collection_query, 'A collection link must contain only the three closed navigation parameters and the exact canonical block UUID.' );
fl_hotfix_assert( false === strpos( $collection_url, 'wp-admin' ) && false === strpos( $collection_url, 'admin-post' ), 'A canonical collection link may never inherit an admin or admin-post URL.' );
$created_link = $run( 'create_link', array( 'block_id' => $link_c, 'label' => 'Dans B', 'url' => 'https://example.test/b', 'collection_id' => $collection_b ), null, $collection_b );
fl_hotfix_assert( $created_link['ok'] && false !== strpos( $created_link['state']['collection_html'], 'Dans B' ) && false !== strpos( $created_link['state']['collections_html'], 'Collection A' ), 'Adding a link must preserve its collection and every other collection.' );
foreach ( array( 'blocks', 'links_html', 'collections_html', 'collection_html', 'active_collection', 'preview_html', 'version' ) as $field ) { fl_hotfix_assert( array_key_exists( $field, $created_link['state'] ), 'Canonical rehydration field missing: ' . $field ); }

$before_collection_navigation = $wpdb->digest();
$writes_before_collection_navigation = $wpdb->write_count;
$transactions_before_collection_navigation = $wpdb->transaction_count;
$_GET = array( 'faluss_studio_tab' => 'links', 'faluss_studio_section' => 'collection', 'faluss_studio_collection' => $collection_b );
$collection_markup = Faluss_Link::render_studio();
fl_hotfix_assert( false !== strpos( $collection_markup, 'data-faluss-studio-section="collection"' ) && false !== strpos( $collection_markup, 'data-faluss-studio-collection="' . $collection_b . '"' ) && false !== strpos( $collection_markup, 'Collection B' ) && false !== strpos( $collection_markup, 'Dans B' ), 'Opening the canonical route must select the requested collection and render its canonical links.' );
fl_hotfix_assert( $before_collection_navigation === $wpdb->digest() && $writes_before_collection_navigation === $wpdb->write_count && $transactions_before_collection_navigation === $wpdb->transaction_count, 'Opening or reloading a collection route must perform no write, transaction, or migration.' );

foreach ( array( null, 'not-a-block-uuid' ) as $requested_collection ) {
    $before_invalid_navigation = $wpdb->digest();
    $writes_before_invalid_navigation = $wpdb->write_count;
    $transactions_before_invalid_navigation = $wpdb->transaction_count;
    $_GET = array( 'faluss_studio_tab' => 'links', 'faluss_studio_section' => 'collection' );
    if ( null !== $requested_collection ) { $_GET['faluss_studio_collection'] = $requested_collection; }
    $invalid_markup = Faluss_Link::render_studio();
    fl_hotfix_assert( false !== strpos( $invalid_markup, 'data-faluss-studio-section="collections"' ) && false !== strpos( $invalid_markup, 'data-faluss-studio-collection=""' ), 'A missing or invalid collection UUID must fall back cleanly to the Collections panel.' );
    fl_hotfix_assert( $before_invalid_navigation === $wpdb->digest() && $writes_before_invalid_navigation === $wpdb->write_count && $transactions_before_invalid_navigation === $wpdb->transaction_count, 'Invalid collection navigation must change no member data.' );
}
$fl_hotfix_permalink = '';
$_GET = array();

$other_blocks_before = array_values( array_filter( $wpdb->blocks, static function( $row ) use ( $link_a ) { return $link_a !== $row['block_id']; } ) );
$updated_link = $run( 'update_link', array( 'block_id' => $link_a, 'label' => 'Libre modifié', 'url' => 'https://example.test/free-2' ) );
$other_blocks_after = array_values( array_filter( $wpdb->blocks, static function( $row ) use ( $link_a ) { return $link_a !== $row['block_id']; } ) );
fl_hotfix_assert( $updated_link['ok'] && $other_blocks_before === $other_blocks_after, 'Updating one link must leave all other collections and rows byte-for-byte unchanged.' );

$before_bad_delete = $wpdb->digest();
$bad_delete = $run( 'delete_link', array( 'block_id' => '88888888-8888-4888-8888-888888888888' ) );
fl_hotfix_assert( ! $bad_delete['ok'] && $before_bad_delete === $wpdb->digest(), 'Deletion without the exact existing link UUID must fail without changing data.' );
$before_empty_delete = $wpdb->digest();
$empty_delete_result = $run( 'delete_link', array() );
fl_hotfix_assert( ! $empty_delete_result['ok'] && $before_empty_delete === $wpdb->digest(), 'An empty block payload cannot erase the canonical stream.' );
$before_invalid_profile = $wpdb->digest();
$invalid_profile = $run( 'save_profile', array( 'publication_status' => 'unexpected' ) );
fl_hotfix_assert( ! $invalid_profile['ok'] && $before_invalid_profile === $wpdb->digest(), 'An invalid closed profile value must fail without silently changing publication state.' );

$stale_version = $version();
$first_tab = $run( 'update_link', array( 'block_id' => $link_a, 'label' => 'Premier onglet', 'url' => 'https://example.test/tab-1' ), $stale_version );
$after_first_tab = $wpdb->digest();
$second_tab = $run( 'update_link', array( 'block_id' => $link_a, 'label' => 'Second onglet', 'url' => 'https://example.test/tab-2' ), $stale_version );
fl_hotfix_assert( $first_tab['ok'] && ! $second_tab['ok'] && 409 === $second_tab['status'] && 'stale_version' === $second_tab['code'] && $after_first_tab === $wpdb->digest(), 'A stale concurrent mutation must return a conflict and change no data.' );

foreach ( array( 'transaction_started', 'before_block_update', 'before_projection', 'after_projection', 'before_commit' ) as $stage ) {
    $before = $wpdb->digest(); $fl_hotfix_fail_stage = $stage;
    $failed = $run( 'update_link', array( 'block_id' => $link_a, 'label' => 'Échec ' . $stage, 'url' => 'https://example.test/failure' ) );
    $fl_hotfix_fail_stage = '';
    fl_hotfix_assert( ! $failed['ok'] && $before === $wpdb->digest(), 'Injected failure must roll back the whole aggregate at stage ' . $stage . '.' );
}
$before_projection_failure = $wpdb->digest(); $wpdb->fail_projection = true;
$projection_failure = $run( 'update_link', array( 'block_id' => $link_a, 'label' => 'Projection en échec', 'url' => 'https://example.test/projection-failure' ) );
$wpdb->fail_projection = false;
fl_hotfix_assert( ! $projection_failure['ok'] && $before_projection_failure === $wpdb->digest(), 'A failed Identity projection write must roll back both Link and Identity representations.' );
$before_commit_failure = $wpdb->digest(); $wpdb->fail_commit = true;
$commit_failure = $run( 'update_link', array( 'block_id' => $link_a, 'label' => 'Commit en échec', 'url' => 'https://example.test/commit-failure' ) );
$wpdb->fail_commit = false;
fl_hotfix_assert( ! $commit_failure['ok'] && $before_commit_failure === $wpdb->digest(), 'A failed commit must trigger a full rollback.' );

echo "FL-HOTFIX-01.3 transactional, read-only render and collection route contract: OK\n";
