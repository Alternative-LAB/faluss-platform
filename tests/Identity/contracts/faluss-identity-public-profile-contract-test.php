<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'OBJECT', 'OBJECT' );

class WP_Post { public $post_type = 'page'; public $post_status = 'publish'; public $post_title = 'Profile template'; }
function sanitize_title( $value ) { return trim( preg_replace( '/-+/', '-', preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $value ) ) ), '-' ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function sanitize_textarea_field( $value ) { return trim( (string) $value ); }
function esc_url_raw( $url, $protocols = array() ) { return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : ''; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function get_query_var( $key ) { global $fi03_route_slug; return 'faluss_public_profile' === $key ? $fi03_route_slug : ''; }
function get_post_types( $args = array(), $output = 'names' ) { return array( 'post', 'page' ); }
function get_page_by_path( $slug, $output = OBJECT, $post_types = array() ) { return null; }
function get_post( $id ) { global $fi03_template_post; return $fi03_template_post; }
function get_post_meta( $id, $key, $single = false ) { return '_elementor_data' === $key ? '{"content":[]}' : ''; }
function elementor_theme_do_location( $location ) { if ( 'header' === $location ) { echo '<header class="elementor-location-header">header</header>'; return true; } return false; }
global $fi03_schema_version, $fi03_schema_ready;
$fi03_schema_version = '3'; $fi03_schema_ready = true;
function get_option( $key, $default = '' ) { global $fi03_schema_version; return 'faluss_identity_schema_version' === $key ? $fi03_schema_version : $default; }
final class Faluss_Identity_Schema { const FI03_VERSION = '3'; const OPTION_VERSION = 'faluss_identity_schema_version'; public static function get_status() { global $fi03_schema_ready; return array( 'ready' => $fi03_schema_ready ); } }

require_once dirname( __DIR__, 3 ) . '/src/Identity/Legacy/includes/class-faluss-identity-public-profile.php';

function fi03_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
        exit( 1 );
    }
}

function fi03_private( $method, ...$arguments ) {
    $reflection = new ReflectionMethod( 'Faluss_Identity_Public_Profile', $method );
    $reflection->setAccessible( true );
    return $reflection->invoke( null, ...$arguments );
}

// FI-03 remains available under FI-04; older schemas and an unready schema fail closed.
$schema_ready = new ReflectionMethod( 'Faluss_Identity_Public_Profile', 'schema_ready' );
$schema_ready->setAccessible( true );
$fi03_schema_version = '3'; $fi03_schema_ready = true;
fi03_assert( true === $schema_ready->invoke( null ), 'Public profiles are available on FI-03.' );
$fi03_schema_version = '4';
fi03_assert( true === $schema_ready->invoke( null ), 'Public profiles remain available on FI-04.' );
$fi03_schema_version = '1';
fi03_assert( false === $schema_ready->invoke( null ), 'Public profiles stay unavailable before FI-03.' );
$fi03_schema_version = '2';
fi03_assert( false === $schema_ready->invoke( null ), 'Public profiles stay unavailable on FI-02.' );
$fi03_schema_version = '4'; $fi03_schema_ready = false;
fi03_assert( false === $schema_ready->invoke( null ), 'Public profiles fail closed when the current schema is not ready.' );
$fi03_schema_ready = true;

// Positive scenario: a readable stable handle and ordered HTTPS links form a public profile.
fi03_assert( 'alice-lab' === fi03_private( 'normalize_slug', 'Alice Lab' ), 'Readable identifiers normalize to a stable slug.' );
$links = fi03_private( 'sanitize_links', array(
    array( 'position' => 2, 'label' => 'Site', 'url' => 'https://example.test/' ),
    array( 'position' => 1, 'label' => 'Portfolio', 'url' => 'https://portfolio.example.test/' ),
) );
fi03_assert( is_array( $links ) && 'Portfolio' === $links[0]['label'] && 1 === $links[0]['position'], 'External links retain their explicit order.' );
fi03_assert( ! fi03_private( 'is_reserved_slug', 'alice-lab' ), 'A non-conflicting handle is allowed.' );

// Negative scenario: core routes, malformed handles and unsafe links cannot be published.
fi03_assert( '' === fi03_private( 'normalize_slug', 'x' ) && '' === fi03_private( 'normalize_slug', '!!!' ), 'Invalid handle shapes are rejected.' );
fi03_assert( fi03_private( 'is_reserved_slug', 'wp-json' ) && fi03_private( 'is_reserved_slug', 'login' ), 'Core and login routes are reserved.' );
fi03_assert( null === fi03_private( 'validate_external_url', 'javascript:alert(1)' ) && null === fi03_private( 'validate_external_url', 'http://example.test/' ), 'Only safe HTTPS external links are accepted.' );

// A public widget with no configured identifier inherits the root-route slug.
global $fi03_route_slug;
$fi03_route_slug = 'alice-lab';
fi03_assert( 'alice-lab' === fi03_private( 'resolve_public_slug', '' ), 'An empty public widget resolves the current profile route.' );
fi03_assert( 'portfolio' === fi03_private( 'resolve_public_slug', 'portfolio' ), 'An explicit public-widget identifier remains supported.' );
fi03_assert( false === fi03_private( 'render_elementor_template', 42 ), 'Without Elementor, route rendering reliably falls back to the standalone profile.' );
$fi03_template_post = new WP_Post();
eval( 'namespace Elementor { final class Frontend { public function get_builder_content_for_display( $id ) { return "<section class=\\"elementor-profile-template\\">template</section>"; } } final class Plugin { public $frontend; private static $instance; public static function instance() { if ( null === self::$instance ) { self::$instance = new self(); self::$instance->frontend = new Frontend(); } return self::$instance; } } }' );
ob_start();
fi03_assert( true === fi03_private( 'render_elementor_template', 42 ), 'A valid selected Elementor page renders its builder content.' );
$template_markup = ob_get_clean();
fi03_assert( false !== strpos( $template_markup, 'elementor-profile-template' ), 'The routed profile emits the selected Elementor model.' );
ob_start();
fi03_private( 'render_elementor_header' );
$header_markup = ob_get_clean();
fi03_assert( false !== strpos( $header_markup, 'elementor-location-header' ), 'The routed public shell emits the configured Elementor header location.' );

$source = file_get_contents( dirname( __DIR__, 3 ) . '/src/Identity/Legacy/includes/class-faluss-identity-public-profile.php' );
$identity_css = file_get_contents( dirname( __DIR__, 3 ) . '/assets/css/faluss-identity-public-profile.css' );
$link_css = file_get_contents( dirname( __DIR__, 3 ) . '/assets/link/css/faluss-link-immersive.css' );
foreach ( array( 'faluss_id', 'public_slug', 'publication_status', 'FOR UPDATE', 'target="_blank"', 'noopener noreferrer nofollow', 'add_rewrite_rule', 'get_page_by_path', 'get_builder_content_for_display', 'OPTION_TEMPLATE_ID', 'get_elementor_templates' ) as $required ) {
    fi03_assert( false !== strpos( $source, $required ), 'Missing FI-03 invariant: ' . $required );
}
foreach ( array( 'render_public_shell', 'wp_head();', 'wp_body_open();', 'wp_footer();', 'show_admin_bar( false )' ) as $required ) {
    fi03_assert( false !== strpos( $source, $required ), 'Missing FI-06 public-shell invariant: ' . $required );
}
fi03_assert( false === strpos( $source, 'get_header();' ) && false === strpos( $source, 'get_footer();' ), 'Public profile routes do not render the theme header or footer.');
foreach ( array( 'faluss-identity-public-route', 'render_elementor_header', "elementor_theme_do_location( 'header' )", 'faluss-identity-public-header-layer' ) as $required ) {
    fi03_assert( false !== strpos( $source, $required ), 'Public routes expose the Elementor header location and its dedicated body class: ' . $required );
}
$faluss_css = preg_replace( '/\s+/', '', strtolower( $identity_css . $link_css ) );
foreach ( array( 'header{display:none', 'footer{display:none', '.elementor-location-header{display:none' ) as $forbidden ) {
    fi03_assert( false === strpos( $faluss_css, $forbidden ), 'Faluss route CSS must not hide Elementor or theme chrome: ' . $forbidden );
}
fi03_assert( false !== strpos( $link_css, 'body.faluss-identity-public-route .faluss-link-card--presentation-immersive' ) && false !== strpos( $link_css, 'position: absolute;' ), 'The immersive profile remains under an out-of-flow designer-owned header.');
fi03_assert( false !== strpos( $link_css, '@media (max-width: 799px)' ) && 2 <= substr_count( $link_css, 'body.faluss-identity-public-route > .faluss-identity-public-header-layer' ), 'The header collision guard is explicit on mobile too.');
fi03_assert( false === strpos( $link_css, 'pointer-events:' ) && false === strpos( $link_css, 'body.faluss-identity-public-route > .faluss-identity-public-header-layer {\n  isolation:' ), 'The route-only header shell keeps native Elementor hit testing without trapping an external popup layer.' );
fi03_assert( false === strpos( $source, 'user_email' ), 'Public profiles never store or render e-mail data.' );

echo 'FI-03 public-profile contract: OK' . PHP_EOL;
