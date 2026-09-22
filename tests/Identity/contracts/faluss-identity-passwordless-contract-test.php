<?php

define( 'ABSPATH', __DIR__ . '/' );

function wp_salt( $scheme = '' ) { return 'test-salt-' . $scheme; }
function is_email( $email ) { return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ); }
function wp_unslash( $value ) { return $value; }
function home_url( $path = '/' ) {
    if ( '' === $path || '/' === $path ) { return 'https://faluss.me/'; }
    if ( '?' === $path[0] ) { return 'https://faluss.me/' . $path; }
    return 'https://faluss.me' . ( '/' === $path[0] ? $path : '/' . $path );
}
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function untrailingslashit( $value ) { return rtrim( $value, '/\\' ); }
function wp_validate_redirect( $url, $fallback = '' ) { return $url ?: $fallback; }
function add_query_arg( $key, $value, $url ) { return $url . '?' . rawurlencode( $key ) . '=' . rawurlencode( $value ); }
function admin_url( $path = '' ) { return 'https://faluss.me/wp-admin/' . ltrim( $path, '/' ); }
function esc_url( $value ) { return $value; }
function wp_nonce_field( $action, $name ) { echo '<input type="hidden" name="' . $name . '" value="fixture">'; }
function esc_html_e( $value ) { echo $value; }

require_once dirname( __DIR__, 3 ) . '/src/Identity/Legacy/includes/class-faluss-identity-passwordless.php';

function fi02_passwordless_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
        exit( 1 );
    }
}

function fi02_passwordless_private( $method, ...$arguments ) {
    $reflection = new ReflectionMethod( 'Faluss_Identity_Passwordless', $method );
    $reflection->setAccessible( true );
    return $reflection->invoke( null, ...$arguments );
}

// Positive scenario: an e-mail and an exact six-digit OTP can enter a ceremony.
fi02_passwordless_assert( 'member@example.test' === fi02_passwordless_private( 'normalize_email', ' Member@Example.Test ' ), 'Email normalization is canonical.' );
fi02_passwordless_assert( fi02_passwordless_private( 'is_valid_otp', '004281' ), 'A six-digit code with a leading zero is accepted.' );
$encoded = fi02_passwordless_private( 'base64url_encode', random_bytes( 64 ) );
fi02_passwordless_assert( is_array( fi02_passwordless_private( 'read_cookie_state' ) ) === false, 'No cookie never creates an implicit ceremony.' );
fi02_passwordless_assert( 64 === strlen( fi02_passwordless_private( 'base64url_decode', $encoded ) ), 'The cookie payload preserves both 256-bit secrets.' );
fi02_passwordless_assert( 'otp' === fi02_passwordless_private( 'login_stage_for_challenge_email', 'member@example.test' ), 'Only a server-confirmed valid challenge e-mail can select the OTP stage.' );
fi02_passwordless_assert( 'email' === fi02_passwordless_private( 'login_stage_for_challenge_email', null ), 'A fresh visit has no implicit OTP stage.' );
$initial_markup = fi02_passwordless_private( 'render_email_stage', 'https://faluss.me/mon-faluss/', 'https://faluss.me/login/' );
fi02_passwordless_assert( false !== strpos( $initial_markup, 'name="email"' ) && false === strpos( $initial_markup, 'name="otp"' ), 'The initial login stage renders an e-mail field, never an OTP field.' );
$otp_markup = fi02_passwordless_private( 'render_otp_stage', 'https://faluss.me/mon-faluss/', 'https://faluss.me/login/' );
fi02_passwordless_assert( false !== strpos( $otp_markup, 'name="otp"' ) && false === strpos( $otp_markup, 'name="email"' ), 'The OTP markup is isolated from the fresh e-mail stage.' );

// Negative scenarios: malformed values cannot broaden proof, correlate buckets, or decode a cookie.
fi02_passwordless_assert( null === fi02_passwordless_private( 'normalize_email', 'not-an-email' ), 'Invalid email is rejected.' );
fi02_passwordless_assert( ! fi02_passwordless_private( 'is_valid_otp', '4281' ) && ! fi02_passwordless_private( 'is_valid_otp', '4281ab' ), 'Only exact numeric OTPs are accepted.' );
fi02_passwordless_assert( null === fi02_passwordless_private( 'base64url_decode', 'not/a-cookie' ), 'Cookie decoding rejects non-base64url input.' );
fi02_passwordless_assert( fi02_passwordless_private( 'secret_hash', 'same-value', 'email' ) !== fi02_passwordless_private( 'secret_hash', 'same-value', 'ip' ), 'Rate-limit buckets are domain separated.' );
fi02_passwordless_assert( 'email' === fi02_passwordless_private( 'login_stage_for_challenge_email', 'not-an-email' ), 'An incomplete or incoherent local state returns to the e-mail stage.' );

// The nonce and cookie-backed login document is private to one live request.
$_SERVER['REQUEST_URI'] = '/login/?redirect_to=https%3A%2F%2Ffaluss.me%2Forigin';
fi02_passwordless_assert( fi02_passwordless_private( 'is_login_request' ), 'The local /login route is recognized with a safe return query.' );
fi02_passwordless_assert( fi02_passwordless_private( 'is_login_request', (object) array( 'request' => 'login' ) ), 'The parsed WordPress request recognizes /login early.' );
$login_headers = Faluss_Identity_Passwordless::login_no_cache_headers( array( 'X-Fixture' => 'kept' ) );
fi02_passwordless_assert( 'no-cache' === $login_headers['X-LiteSpeed-Cache-Control'] && false !== strpos( $login_headers['Cache-Control'], 'no-store' ) && 'kept' === $login_headers['X-Fixture'], '/login receives no-store headers without dropping unrelated headers.' );
$_SERVER['REQUEST_URI'] = '/mon-faluss/';
fi02_passwordless_assert( ! fi02_passwordless_private( 'is_login_request' ), 'The cache exclusion does not affect member or ordinary pages.' );
fi02_passwordless_assert( array( 'X-Fixture' => 'kept' ) === Faluss_Identity_Passwordless::login_no_cache_headers( array( 'X-Fixture' => 'kept' ) ), 'Non-login headers remain unchanged.' );

// FI-02 corrections: new users always receive the least-privileged role and
// each post retains an exact local redirect, never an external URL.
fi02_passwordless_assert( 'https://faluss.me/espace-membre' === fi02_passwordless_private( 'local_redirect', '/espace-membre' ), 'A local path is retained.' );
fi02_passwordless_assert( 'https://faluss.me/retour?state=ok' === fi02_passwordless_private( 'local_redirect', 'https://faluss.me/retour?state=ok' ), 'A same-origin absolute URL is retained.' );
fi02_passwordless_assert( 'https://faluss.me/' === fi02_passwordless_private( 'local_redirect', 'https://attacker.example/collect' ), 'An external redirect falls back to the local home page.' );
fi02_passwordless_assert( 'https://faluss.me/' === fi02_passwordless_private( 'local_redirect', '//attacker.example/collect' ), 'A protocol-relative external redirect falls back to the local home page.' );
fi02_passwordless_assert( 'https://faluss.me/login/' === fi02_passwordless_private( 'login_return_url', 'https://faluss.me/mon-faluss/' ), 'The non-JavaScript login step always returns to /login/ before OTP verification.' );

$source = file_get_contents( dirname( __DIR__, 3 ) . '/src/Identity/Legacy/includes/class-faluss-identity-passwordless.php' );
foreach ( array( 'wp_hash_password', 'wp_check_password', 'START TRANSACTION', 'FOR UPDATE', "'secure' => true", "'httponly' => true", "'samesite' => 'Lax'", 'wp_set_auth_cookie' ) as $required ) {
    fi02_passwordless_assert( false !== strpos( $source, $required ), 'Missing FI-02 security primitive: ' . $required );
}
fi02_passwordless_assert( false === strpos( $source, '$_REQUEST' ), 'Passwordless endpoints do not accept merged request input.' );
fi02_passwordless_assert( false !== strpos( $source, "'role' => 'subscriber'" ), 'New passwordless accounts explicitly receive subscriber.' );
fi02_passwordless_assert( false === strpos( $source, "get_option( 'default_role'" ), 'New passwordless accounts do not inherit default_role.' );
fi02_passwordless_assert( strpos( $source, "get_user_by( 'email'" ) < strpos( $source, 'wp_insert_user' ), 'Existing accounts are returned before any role-bearing insert.' );
fi02_passwordless_assert( 3 === substr_count( $source, 'name="redirect_to"' ), 'Every passwordless form carries the redirect target.' );
fi02_passwordless_assert( 3 === substr_count( $source, 'name="return_to"' ), 'Every non-JavaScript passwordless form carries the /login/ return target.' );
foreach ( array( 'handle_request_code_ajax', 'handle_verify_code_ajax', 'request_code_result', 'verify_code_result', 'login_return_url', "home_url( '/mon-faluss/' )", 'wp_send_json_success' ) as $required ) {
    fi02_passwordless_assert( false !== strpos( $source, $required ), 'Missing FI-06 in-place passwordless invariant: ' . $required );
}
foreach ( array( 'has_active_challenge', 'email_for_current_challenge', 'render_email_stage', "if ( 'sent' !== \$notice )", "return 'unavailable'", "! self::issue_challenge( \$email, self::client_ip() )", "'reset_to_email'", "'email_html'", 'wp_set_auth_cookie', 'self::posted_redirect()' ) as $required ) {
    fi02_passwordless_assert( false !== strpos( $source, $required ), 'The FI-02 stage or successful-session transition is incomplete: ' . $required );
}
fi02_passwordless_assert( false === strpos( $source, '$has_challenge = self::read_cookie_state() !== null;' ), 'A decodable cookie alone must never select the OTP stage.' );
fi02_passwordless_assert( false === strpos( $source, 'Faluss_Identity_Authorization' ), 'Ordinary passwordless login remains separate from the FI-04 authorization flow.' );
$login_js = file_get_contents( dirname( __DIR__, 3 ) . '/assets/js/faluss-identity-passwordless-login.js' );
foreach ( array( 'fetch(', "action.value + '_ajax'", 'replaceWithOtp', 'window.location.assign' ) as $required ) {
    fi02_passwordless_assert( false !== strpos( $login_js, $required ), 'Missing FI-06 in-place login client behavior: ' . $required );
}
foreach ( array( 'replaceStage', 'replaceWithEmail', "'otp'", "'email'", 'reset_to_email', 'email_html' ) as $required ) {
    fi02_passwordless_assert( false !== strpos( $login_js, $required ), 'The client must apply only the server-confirmed login stage: ' . $required );
}
fi02_passwordless_assert( false === strpos( $login_js, 'sessionStorage' ) && false === strpos( $login_js, 'localStorage' ), 'No residual browser storage can select the OTP stage.' );
$bootstrap = file_get_contents( dirname( __DIR__, 3 ) . '/src/Identity/IdentityModule.php' );
fi02_passwordless_assert( false !== strpos( $bootstrap, "public const VERSION = '0.4.15'" ) && false !== strpos( $source, 'FALUSS_IDENTITY_VERSION' ), 'The corrected frontend asset is versioned from the platform module.' );
$navigation = file_get_contents( dirname( __DIR__, 3 ) . '/src/Identity/Legacy/includes/class-faluss-identity-navigation.php' );
fi02_passwordless_assert( false === strpos( $source . $login_js, 'Faluss_Identity_Navigation' ) && false !== strpos( $navigation, 'current_local_return_url' ), 'FI-02 does not alter FI-07 Navigation Faluss.' );
foreach ( array( 'exclude_login_from_cache', 'send_login_no_cache_headers', 'login_no_cache_headers', 'DONOTCACHEPAGE', 'litespeed_control_set_nocache', 'X-LiteSpeed-Cache-Control', 'no-store, no-cache' ) as $required ) {
    fi02_passwordless_assert( false !== strpos( $source, $required ), 'The nonce and cookie-backed /login document must bypass shared page caches: ' . $required );
}
foreach ( array( 'falussIdentityPending', 'setSubmitting(form, true)', "button[type=\"submit\"]", "aria-busy" ) as $required ) {
    fi02_passwordless_assert( false !== strpos( $login_js, $required ), 'A repeated browser event must not issue a second passwordless request: ' . $required );
}
foreach ( array( '#FFFDF5', '#FFFFFF', '#000000', '#FF3D16', 'Outfit', '--faluss-pill-radius' ) as $required ) {
    fi02_passwordless_assert( false !== strpos( $source . file_get_contents( dirname( __DIR__, 3 ) . '/assets/css/faluss-identity-passwordless.css' ), $required ), 'Missing Faluss.me design token: ' . $required );
}
$widget_source = file_get_contents( dirname( __DIR__, 3 ) . '/src/Identity/Legacy/includes/class-faluss-identity-elementor-widget.php' );
foreach ( array( "'redirect_url'", 'Group_Control_Typography', 'Group_Control_Border', 'Group_Control_Box_Shadow', "'button_hover'", '#FFFDF5', '#080808', 'get_script_depends' ) as $required ) {
    fi02_passwordless_assert( false !== strpos( $widget_source, $required ), 'Missing Elementor FI-02 control: ' . $required );
}

echo 'FI-02 passwordless contract: OK' . PHP_EOL;
