<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'FALUSS_IDENTITY_FILE', dirname( __DIR__, 3 ) . '/faluss-platform.php' );
define( 'FALUSS_IDENTITY_VERSION', '0.4.5' );

$fi07_logged_in = false;
$fi07_registered = array();
$_SERVER['REQUEST_URI'] = '/origin?source=menu';

function __( $value ) { return $value; }
function wp_unslash( $value ) { return $value; }
function home_url( $path = '/' ) { return 'https://faluss.me' . ( '/' === $path ? '/' : '/' . ltrim( $path, '/' ) ); }
function wp_validate_redirect( $url, $fallback = '' ) { return 0 === strpos( $url, 'https://faluss.me/' ) ? $url : $fallback; }
function add_query_arg( $key, $value, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . rawurlencode( $key ) . '=' . rawurlencode( $value ); }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, $args ); }
function is_user_logged_in() { global $fi07_logged_in; return $fi07_logged_in; }
function wp_logout_url( $return ) { return 'https://faluss.me/wp-login.php?action=logout&_wpnonce=fixture&redirect_to=' . rawurlencode( $return ); }
function plugins_url( $path, $file ) { return 'https://faluss.me/wp-content/plugins/faluss-platform/' . $path; }
function wp_register_style( $handle, $url, $deps, $version ) { global $fi07_registered; $fi07_registered['style'] = compact( 'handle', 'url', 'deps', 'version' ); }
function wp_register_script( $handle, $url, $deps, $version, $footer ) { global $fi07_registered; $fi07_registered['script'] = compact( 'handle', 'url', 'deps', 'version', 'footer' ); }

function fi07_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
        exit( 1 );
    }
}

$root = dirname( __DIR__, 3 );
require_once $root . '/src/Identity/Legacy/includes/class-faluss-identity-navigation.php';

// The three actions use the exact local paths, with no page creation or remote redirect.
$fi07_logged_in = false;
$anonymous_actions = Faluss_Identity_Navigation::actions();
fi07_assert( 3 === count( $anonymous_actions ), 'Navigation renders exactly three actions.' );
fi07_assert( 'https://faluss.me/mon-faluss/' === $anonymous_actions[0]['url'] && 'https://faluss.me/list/' === $anonymous_actions[1]['url'], 'The two fixed actions use their exact local paths.' );
fi07_assert( 'Connexion' === $anonymous_actions[2]['label'] && 0 === strpos( $anonymous_actions[2]['url'], 'https://faluss.me/login/?redirect_to=' ), 'Anonymous users receive the local login action.' );
fi07_assert( false !== strpos( rawurldecode( $anonymous_actions[2]['url'] ), 'https://faluss.me/origin?source=menu' ), 'The login return keeps the current local URL.' );

$_SERVER['REQUEST_URI'] = '//attacker.example/return';
fi07_assert( 'https://faluss.me/' === Faluss_Identity_Navigation::current_local_return_url(), 'Protocol-relative returns fail closed to the local homepage.' );
$fi07_logged_in = true;
$logged_actions = Faluss_Identity_Navigation::actions();
fi07_assert( 'Déconnexion' === $logged_actions[2]['label'] && false !== strpos( $logged_actions[2]['url'], 'wp-login.php?action=logout&_wpnonce=' ), 'Logged-in users receive a WordPress nonce-protected logout action.' );
fi07_assert( false !== strpos( rawurldecode( $logged_actions[2]['url'] ), 'redirect_to=https://faluss.me/' ), 'Logout keeps a safe local return URL.' );

Faluss_Identity_Navigation::register_assets();
fi07_assert( 'faluss-identity-navigation' === $fi07_registered['style']['handle'] && false !== strpos( $fi07_registered['style']['url'], 'assets/css/faluss-identity-navigation.css' ), 'The navigation stylesheet is registered as an Elementor dependency.' );
fi07_assert( 'faluss-identity-navigation' === $fi07_registered['script']['handle'] && true === $fi07_registered['script']['footer'] && false !== strpos( $fi07_registered['script']['url'], 'assets/js/faluss-identity-navigation.js' ), 'The navigation script is registered in the footer as an Elementor dependency.' );

$plugin = file_get_contents( $root . '/src/Identity/Legacy/includes/class-faluss-identity-plugin.php' );
$widget = file_get_contents( $root . '/src/Identity/Legacy/includes/class-faluss-identity-navigation-elementor-widget.php' );
$css = file_get_contents( $root . '/assets/css/faluss-identity-navigation.css' );
$script = file_get_contents( $root . '/assets/js/faluss-identity-navigation.js' );
$public_profile = file_get_contents( $root . '/src/Identity/Legacy/includes/class-faluss-identity-public-profile.php' );

foreach ( array( "'Faluss_Identity_Navigation', 'register_assets'", 'elementor/frontend/after_register_scripts', 'elementor/frontend/after_register_styles', 'class-faluss-identity-navigation-elementor-widget.php', 'Faluss_Identity_Navigation_Elementor_Widget' ) as $required ) {
    fi07_assert( false !== strpos( $plugin, $required ), 'The plugin bootstraps the Navigation Faluss assets and widget early: ' . $required );
}
foreach ( array( "return 'faluss_identity_navigation'", "return __( 'Navigation Faluss'", 'get_style_depends', 'get_script_depends', '<template data-faluss-navigation-template>', '<dialog class="faluss-identity-navigation-portal"', 'wp_unique_id', 'home_url( \'/mon-faluss/\' )', 'home_url( \'/list/\' )', 'wp_logout_url', 'login_url' ) as $required ) {
    fi07_assert( false !== strpos( $widget, $required ) || false !== strpos( file_get_contents( $root . '/src/Identity/Legacy/includes/class-faluss-identity-navigation.php' ), $required ), 'The widget/service retains its navigation contract: ' . $required );
}
fi07_assert( false === strpos( $widget, 'theplus' ) && false === strpos( $script, 'theplus' ), 'Navigation Faluss has no dependency on The Plus Popup Builder.' );
fi07_assert( false === strpos( $script, "document.addEventListener('click'") && false === strpos( $script, 'stopPropagation' ), 'The script does not intercept global clicks or external components.' );
fi07_assert( false === strpos( $css, 'html { pointer-events:' ) && false === strpos( $css, 'body { pointer-events:' ) && false === strpos( $css, '.faluss-identity-navigation { pointer-events:' ), 'The component never neutralizes page, header, or card interactions with pointer-events.' );
fi07_assert( false !== strpos( $css, '.faluss-identity-navigation-portal__sidebar::before' ) && false !== strpos( $css, 'pointer-events: none;' ), 'Only the local decorative sidebar pseudo-element is non-interactive.' );
foreach ( array( 'dialog.faluss-identity-navigation-portal', '100dvh', 'position: fixed', 'margin-block-start: auto', 'safe-area-inset-top', 'prefers-reduced-motion' ) as $required ) {
    fi07_assert( false !== strpos( $css, $required ), 'The sidebar has the required viewport, split layout, safe-area, and motion treatment: ' . $required );
}
foreach ( array( 'document.body.appendChild(portal)', 'portal.showModal()', "portal.addEventListener('cancel'", "querySelector('[data-faluss-navigation-close]')", "querySelector('[data-faluss-navigation-sidebar]')", 'trigger.focus', 'WeakSet', 'elementor/frontend/init', 'frontend/element_ready/faluss_identity_navigation.default' ) as $required ) {
    fi07_assert( false !== strpos( $script, $required ), 'The body portal, accessibility lifecycle, and idempotent Elementor initialization are present: ' . $required );
}
// FI-07.2: mouse close returns to normal colors while keyboard close keeps a visible focus path.
foreach ( array( "var returnFocusMode = 'pointer'", "returnFocusMode = 'keyboard'", "root.dataset.falussNavigationReturnMode = returnFocusMode", "trigger.addEventListener('pointerdown'", "trigger.addEventListener('keydown'" ) as $required ) {
    fi07_assert( false !== strpos( $script, $required ), 'FI-07.2 retains an explicit pointer/keyboard return-focus distinction: ' . $required );
}
foreach ( array( '[data-faluss-navigation-return-mode="pointer"]', '[aria-expanded="false"]:focus:not(:hover):not(:active)', '--faluss-navigation-trigger-background-normal', '--faluss-navigation-trigger-icon-normal' ) as $required ) {
    fi07_assert( false !== strpos( $css, $required ), 'FI-07.2 restores only the configured normal trigger colors after a pointer close: ' . $required );
}
fi07_assert( false !== strpos( $css, ':focus-visible' ) && false === strpos( $css, 'body.faluss-identity-navigation' ), 'FI-07.2 preserves a local keyboard focus indicator without styling the global header.' );
fi07_assert( false === strpos( $public_profile, 'Faluss_Identity_Navigation' ), 'FI-07 does not alter the public-profile shell or take ownership of existing headers.' );

// FI-07.1: visual controls are rendered through the portaled component rather than inherited from the theme.
foreach ( array( 'register_backdrop_style_controls', "array( 'normal' => __( 'Normal'", "'backdrop_' . \$state . '_color'", "'backdrop_' . \$state . '_opacity'", "'backdrop_' . \$state . '_transition'", 'trigger_width', 'trigger_height', 'trigger_icon_size', 'trigger_backdrop_blur', 'Durée de transition', "'max' => 1200" ) as $required ) {
    fi07_assert( false !== strpos( $widget, $required ), 'FI-07.1 exposes the required real Elementor control: ' . $required );
}
fi07_assert( false !== strpos( $widget, '<div class="faluss-identity-navigation-portal__backdrop" role="button"' ) && false === strpos( $widget, '<button class="faluss-identity-navigation-portal__backdrop"' ), 'The full-screen backdrop is a neutral accessible element, not a theme-styled button.' );
foreach ( array( 'all: initial;', '--faluss-navigation-backdrop-normal-color', '--faluss-navigation-backdrop-hover-color', 'background-color: color-mix', '.faluss-identity-navigation-portal__backdrop:hover', '.faluss-identity-navigation-portal__backdrop:focus-visible' ) as $required ) {
    fi07_assert( false !== strpos( $css, $required ), 'Backdrop normal/hover rendering is locally reset and controlled: ' . $required );
}
foreach ( array( '--faluss-navigation-trigger-icon-size', 'fill: currentColor;', 'stroke: currentColor;', '-webkit-backdrop-filter', 'backdrop-filter', 'box-sizing: content-box;' ) as $required ) {
    fi07_assert( false !== strpos( $css, $required ), 'Trigger icon color, independent size, blur, and padding behavior are explicit: ' . $required );
}
$trigger_css = '';
if ( preg_match( '/\\.faluss-identity-navigation__trigger\\s*\\{(.*?)\\}/s', $css, $trigger_match ) ) {
    $trigger_css = $trigger_match[1];
}
fi07_assert( '' !== $trigger_css && false === strpos( $trigger_css, 'min-block-size' ) && false === strpos( $trigger_css, 'min-inline-size' ), 'The trigger has no hidden 44px minimum and can render below 44px.' );
fi07_assert( false !== strpos( $script, 'window.scrollTo(snapshot.x, snapshot.y)') && false !== strpos( $script, 'scrollLockSnapshot') && false !== strpos( $script, 'if (isNaN(duration))') && false === strpos( $script, '.style.transform' ) && false === strpos( $script, '.style.zoom' ), 'Scroll is restored exactly without document transform, scale, or zoom manipulation, including a true zero-duration setting.' );
fi07_assert( ! preg_match( '/(?:^|\\n)(?:html|body|#page|\\.elementor)[^{]*\\{[^}]*?(?:transform|scale|zoom)/s', $css ), 'Only the local panel and its backdrop animate; the document is never scaled.' );

echo 'FI-07 Navigation Faluss contract: OK' . PHP_EOL;
