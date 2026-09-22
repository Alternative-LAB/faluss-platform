<?php

define( 'ABSPATH', __DIR__ . '/' );
function wp_parse_url( $url ) { return parse_url( $url ); }
function wp_unslash( $value ) { return $value; }

require_once dirname( __DIR__, 3 ) . '/src/Identity/Legacy/includes/class-faluss-identity-schema.php';
require_once dirname( __DIR__, 3 ) . '/src/Identity/Legacy/includes/class-faluss-identity-authorization.php';
require_once dirname( __DIR__, 3 ) . '/src/Identity/Legacy/includes/class-faluss-identity-sso-clients-admin.php';

function fi04_assert( $condition, $message ) {
    if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); }
}

// Positive contract: an exact HTTPS redirect, explicit basic scope and S256 shape are accepted.
fi04_assert( Faluss_Identity_Authorization::valid_redirect_uri( 'https://pro.faluss.com/auth/callback?source=faluss' ), 'An HTTPS exact redirect URI is accepted.' );
fi04_assert( array( 'identity.basic', 'identity.email' ) === Faluss_Identity_Authorization::normalize_scopes( 'identity.email identity.basic' ), 'The two permitted scopes normalize to a stable order.' );
fi04_assert( Faluss_Identity_Authorization::is_valid_pkce_challenge( str_repeat( 'A', 43 ) ), 'A S256 PKCE challenge has its exact base64url shape.' );
fi04_assert( Faluss_Identity_Authorization::is_valid_code_verifier( str_repeat( 'a', 43 ) ), 'A compliant PKCE verifier is accepted.' );

// Negative contract: no HTTP, fragment, unknown scope, duplicate scope or plain PKCE verifier can pass.
fi04_assert( ! Faluss_Identity_Authorization::valid_redirect_uri( 'http://pro.faluss.com/callback' ) && ! Faluss_Identity_Authorization::valid_redirect_uri( 'https://pro.faluss.com/callback#fragment' ), 'Unsafe redirect URI variants are refused.' );
fi04_assert( null === Faluss_Identity_Authorization::normalize_scopes( 'identity.basic profile.read' ) && null === Faluss_Identity_Authorization::normalize_scopes( 'identity.basic identity.basic' ), 'Unknown or duplicated scopes are refused.' );
fi04_assert( ! Faluss_Identity_Authorization::is_valid_pkce_challenge( str_repeat( 'A', 42 ) ) && ! Faluss_Identity_Authorization::is_valid_code_verifier( 'short' ), 'Malformed PKCE values are refused.' );

$_POST['scopes'] = array( 'identity.basic', 'identity.email' );
$posted_scopes = new ReflectionMethod( 'Faluss_Identity_SSO_Clients_Admin', 'posted_scopes' );
$posted_scopes->setAccessible( true );
fi04_assert( array( 'identity.basic', 'identity.email' ) === $posted_scopes->invoke( null ), 'SSO administration preserves the two valid dotted scope names.' );
$_POST['scopes'] = array( 'identity.basic', 'identityevil' );
fi04_assert( null === $posted_scopes->invoke( null ), 'SSO administration refuses invalid scope names without rewriting them.' );

$schema = Faluss_Identity_Schema::get_fi04_schema();
fi04_assert( isset( $schema['authorization_requests'] ) && $schema['authorization_requests']['indexes']['request_hash_unique']['unique'], 'The FI-04 request handle is persisted only as a unique hash.' );
fi04_assert( isset( $schema['auth_codes']['columns']['consumed_at'] ) && 'char(64)' === $schema['auth_codes']['columns']['code_hash']['type'], 'Codes remain hashed and have a one-use marker.' );

$source = file_get_contents( dirname( __DIR__, 3 ) . '/src/Identity/Legacy/includes/class-faluss-identity-authorization.php' );
foreach ( array( 'code_challenge_method', "'S256'", 'START TRANSACTION', 'FOR UPDATE', 'consumed_at IS NULL', 'CODE_TTL = 60', 'identity.basic', 'identity.email', "home_url( '/login' )", 'wp_send_json', 'client_secret_hash', 'HttpOnly' ) as $required ) {
    fi04_assert( false !== strpos( $source, $required ), 'Missing FI-04 invariant: ' . $required );
}
fi04_assert( false === strpos( $source, 'access_token' ) && false === strpos( $source, 'refresh_token' ), 'FI-04 does not issue bearer tokens.' );

$admin_source = file_get_contents( dirname( __DIR__, 3 ) . '/src/Identity/Legacy/includes/class-faluss-identity-sso-clients-admin.php' );
fi04_assert( false !== strpos( $admin_source, 'client_secret_hash' ) && 2 === substr_count( $admin_source, 'self::render_secret_confirmation( array( \'client_id\' => $client_id, \'secret\' => $secret ) )' ), 'An administrator receives a new secret only in its creation or rotation response.' );
fi04_assert( false === strpos( $admin_source, 'set_transient' ) && false === strpos( $admin_source, 'update_option' ), 'Raw client secrets are never retained in WordPress options or transients.' );
fi04_assert( false !== strpos( $admin_source, "ABSPATH . 'wp-admin/admin-header.php'" ) && false !== strpos( $admin_source, "ABSPATH . 'wp-admin/admin-footer.php'" ) && false !== strpos( $admin_source, "'options-general.php'" ), 'Secret confirmation renders inside the native WordPress administration shell.' );

echo 'FI-04 authorization contract: OK' . PHP_EOL;
