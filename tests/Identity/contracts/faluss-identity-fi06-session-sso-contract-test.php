<?php

define( 'ABSPATH', __DIR__ . '/' );

class WP_User {
    public $ID;
    public $roles;
    public $user_login = 'member';
    public function __construct( $id, $roles ) { $this->ID = $id; $this->roles = $roles; }
}

$fi06_sso_users = array();
$fi06_sso_active_profiles = array();
function get_userdata( $user_id ) { global $fi06_sso_users; return isset( $fi06_sso_users[ (int) $user_id ] ) ? $fi06_sso_users[ (int) $user_id ] : false; }
function wp_parse_url( $url ) { return parse_url( $url ); }

final class Faluss_Identity_Registry {
    public static function get_active_for_wp_user( $user_id ) {
        global $fi06_sso_active_profiles;
        return isset( $fi06_sso_active_profiles[ (int) $user_id ] ) ? $fi06_sso_active_profiles[ (int) $user_id ] : null;
    }
}

function fi06_sso_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
        exit( 1 );
    }
}

$root = dirname( __DIR__, 3 );
$identity_session_source = file_get_contents( $root . '/src/Identity/Legacy/includes/class-faluss-identity-member-session.php' );
$passwordless_source = file_get_contents( $root . '/src/Identity/Legacy/includes/class-faluss-identity-passwordless.php' );
$authorization_source = file_get_contents( $root . '/src/Identity/Legacy/includes/class-faluss-identity-authorization.php' );
$client_source = file_get_contents( $root . '/src/IdentityClient/IdentityClientService.php' );
$client_admin_source = file_get_contents( $root . '/src/IdentityClient/IdentityClientAdmin.php' );

foreach ( array( 'const TTL = 3600', 'auth_cookie_expiration', "array( 'subscriber' )", 'get_active_for_wp_user' ) as $needle ) {
    fi06_sso_assert( false !== strpos( $identity_session_source, $needle ), 'Identity one-hour member-session invariant is missing: ' . $needle );
}
fi06_sso_assert( false !== strpos( $passwordless_source, 'const COOKIE_TTL = 600' ) && false !== strpos( $passwordless_source, 'const REQUEST_WINDOW = 900' ), 'OTP challenge and request rate limit stay independent and short.' );
fi06_sso_assert( false !== strpos( $authorization_source, 'const REQUEST_TTL = 600' ) && false !== strpos( $authorization_source, 'const CODE_TTL = 60' ), 'OAuth request and authorization-code TTLs stay independent and short.' );

global $fi06_sso_users, $fi06_sso_active_profiles;
$fi06_sso_users = array(
    10 => new WP_User( 10, array( 'subscriber' ) ),
    11 => new WP_User( 11, array( 'administrator' ) ),
    12 => new WP_User( 12, array( 'subscriber', 'customer' ) ),
    13 => new WP_User( 13, array( 'subscriber' ) ),
);
$fi06_sso_active_profiles = array( 10 => '550e8400-e29b-41d4-a716-446655440000', 11 => '550e8400-e29b-41d4-a716-446655440000', 12 => '550e8400-e29b-41d4-a716-446655440000' );
require_once $root . '/src/Identity/Legacy/includes/class-faluss-identity-member-session.php';
fi06_sso_assert( 3600 === Faluss_Identity_Member_Session::member_cookie_expiration( 172800, 10, false ), 'An active sole-subscriber Identity member receives a fixed one-hour session.' );
fi06_sso_assert( 172800 === Faluss_Identity_Member_Session::member_cookie_expiration( 172800, 11, false ), 'An administrator session is not widened or modified.' );
fi06_sso_assert( 172800 === Faluss_Identity_Member_Session::member_cookie_expiration( 172800, 12, false ), 'A combined-role session is not modified.' );
fi06_sso_assert( 172800 === Faluss_Identity_Member_Session::member_cookie_expiration( 172800, 13, false ), 'A subscriber without an active Faluss profile is not modified.' );

require_once $root . '/src/Identity/Legacy/includes/class-faluss-identity-authorization.php';
$first_party = new ReflectionMethod( 'Faluss_Identity_Authorization', 'first_party_auto_approval_allowed' );
$first_party->setAccessible( true );
$callback = Faluss_Identity_Authorization::FIRST_PARTY_FALUSS_COM_CALLBACK;
$request = array(
    'client' => array( 'first_party' => 1, 'redirect_uris' => array( $callback ) ),
    'redirect_uri' => $callback,
    'scopes' => array( 'identity.basic', 'identity.email' ),
);
fi06_sso_assert( true === $first_party->invoke( null, $request ), 'Only the explicit Faluss.com callback can receive automatic first-party approval.' );
$request['client']['first_party'] = 0;
fi06_sso_assert( false === $first_party->invoke( null, $request ), 'An unmarked client cannot bypass consent.' );
$request['client']['first_party'] = 1;
$request['redirect_uri'] = 'https://third-party.example/faluss-identity/callback';
$request['client']['redirect_uris'] = array( $request['redirect_uri'] );
fi06_sso_assert( false === $first_party->invoke( null, $request ), 'A marked client with any non-Faluss.com callback cannot bypass consent.' );
foreach ( array( 'FIRST_PARTY_FALUSS_COM_CALLBACK', 'first_party_auto_approval_allowed', 'authorization_first_party_auto_approved', 'complete_authorization', "home_url( '/login' )" ) as $needle ) {
    fi06_sso_assert( false !== strpos( $authorization_source, $needle ), 'First-party authorization invariant is missing: ' . $needle );
}
fi06_sso_assert( strpos( $authorization_source, 'first_party_auto_approval_allowed' ) < strpos( $authorization_source, 'render_consent' ), 'First-party approval is considered before rendering consent, not after a browser-side bypass.' );

require_once $root . '/src/Identity/Legacy/includes/class-faluss-identity-sso-clients-admin.php';
$first_party_requested = new ReflectionMethod( 'Faluss_Identity_SSO_Clients_Admin', 'first_party_requested' );
$first_party_requested->setAccessible( true );
$_POST['first_party'] = '1';
fi06_sso_assert( true === $first_party_requested->invoke( null, array( $callback ) ), 'Administration can mark only the exact Faluss.com callback as first party.' );
fi06_sso_assert( false === $first_party_requested->invoke( null, array( $callback, 'https://faluss.com/other-callback' ) ), 'Administration cannot mark a multi-callback client as first party.' );
unset( $_POST['first_party'] );
$schema_source = file_get_contents( $root . '/src/Identity/Legacy/includes/class-faluss-identity-schema.php' );
foreach ( array( "const FI06_SSO_VERSION = '6'", 'ADD first_party tinyint(3) unsigned NOT NULL DEFAULT 0', 'fi_schema_fi06_sso_source_invalid' ) as $needle ) {
    fi06_sso_assert( false !== strpos( $schema_source, $needle ), 'The additive first-party client migration is missing: ' . $needle );
}

final class Faluss_Identity_Client_Schema {
    public static function tables() { return array( 'links' => 'wp_faluss_identity_client_links' ); }
}
class_alias( 'Faluss_Identity_Client_Schema', 'Faluss\\Platform\\IdentityClient\\IdentityClientSchema' );
final class FI06_SSO_Client_Wpdb {
    public $linked = array();
    public $prepared_id = 0;
    public function prepare( $query, ...$args ) { $this->prepared_id = (int) $args[0]; return $query; }
    public function get_var( $query ) { return ! empty( $this->linked[ $this->prepared_id ] ) ? '550e8400-e29b-41d4-a716-446655440000' : null; }
}
$wpdb = new FI06_SSO_Client_Wpdb();
$wpdb->linked = array( 10 => true, 11 => true, 12 => true );
require_once $root . '/src/IdentityClient/IdentityClientService.php';
fi06_sso_assert( 3600 === \Faluss\Platform\IdentityClient\IdentityClientService::memberCookieExpiration( 172800, 10, false ), 'A linked Faluss.com member receives a one-hour local session.' );
fi06_sso_assert( 172800 === \Faluss\Platform\IdentityClient\IdentityClientService::memberCookieExpiration( 172800, 11, false ), 'A privileged Faluss.com user keeps WordPress session semantics.' );
fi06_sso_assert( 172800 === \Faluss\Platform\IdentityClient\IdentityClientService::memberCookieExpiration( 172800, 12, false ), 'A local role combination keeps WordPress session semantics.' );
foreach ( array( 'faluss_identity_client_continue', 'MEMBER_SESSION_TTL = 3600', 'auth_cookie_expiration', 'isLocalMemberSession', 'START TRANSACTION', 'FOR UPDATE', 'consumed_at IS NULL', "'S256'", 'wp_safe_redirect', "'httponly' => true", "'samesite' => 'Lax'", 'FALUSS_IDENTITY_CLIENT_SECRET' ) as $needle ) {
    fi06_sso_assert( false !== strpos( $client_source, $needle ), 'Client continuation or SSO invariant is missing: ' . $needle );
}
fi06_sso_assert( strpos( $client_source, 'if (self::isLocalMemberSession())' ) < strpos( $client_source, "'response_type' => 'code'" ), 'An active local member avoids an unnecessary SSO redirection.' );
fi06_sso_assert( strpos( $client_source, 'if ($row === null || !self::isOpaque($code))') < strpos( $client_source, 'wp_set_auth_cookie' ), 'Invalid, altered, expired or replayed callback state cannot open a local session.' );
fi06_sso_assert( false !== strpos( $client_source, "self::redirectToLocal((string) \$row['redirect_url']);" ) && false === strpos( $client_source, "localNotice('authenticated'" ), 'A successful callback removes OAuth parameters by redirecting directly to a local return.' );
fi06_sso_assert( false === strpos( $identity_session_source . $authorization_source . $client_source, 'Faluss_Subscriptions' ), 'FI-06 does not couple Identity SSO or member sessions to subscriptions.' );
fi06_sso_assert( false === strpos( $authorization_source . $client_source, 'access_token' ) && false === strpos( $authorization_source . $client_source, 'refresh_token' ), 'The SSO flow does not introduce bearer-token persistence.' );
fi06_sso_assert( false !== strpos( $client_admin_source, "isset(\$candidate['user'])" ) && false !== strpos( $client_admin_source, "isset(\$candidate['pass'])" ) && false !== strpos( $client_admin_source, '$candidatePort === $homePort' ), 'The configured local-return whitelist rejects credentials and a different port.' );

$protocol = file_get_contents( $root . '/docs/modules/IDENTITY.md' );
$client_docs = file_get_contents( $root . '/docs/modules/IDENTITY-CLIENT.md' );
fi06_sso_assert( false !== strpos( $protocol, 'une heure' ) && false !== strpos( $protocol, 'first_party' ) && false !== strpos( $client_docs, 'faluss_identity_client_continue' ), 'FI-06 session and first-party SSO decisions are documented.' );

echo "FI-06 session and first-party SSO contract: OK\n";
