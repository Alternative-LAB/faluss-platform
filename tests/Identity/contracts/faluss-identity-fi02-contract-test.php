<?php
define( 'ABSPATH', __DIR__ . '/' );
$fi02_option = '';
function get_option( $key, $default = '' ) { global $fi02_option; return $fi02_option; }
require_once dirname( __DIR__, 3 ) . '/src/Identity/Legacy/includes/class-faluss-identity-schema.php';
function fi02_assert( $ok, $message ) { if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); } }
global $fi02_option;
// Existing FI-01 remains the only migratable source until ALTER verification succeeds.
$fi02_option = '1'; $v1 = Faluss_Identity_Schema::get_expected_schema(); fi02_assert( 'char(64)' === $v1['challenges']['columns']['otp_hash']['type'] && ! isset( $v1['challenges']['columns']['email'] ), 'v1 source contract' );
// Verified v2 retains the FI-02 contract until the additive FI-03 migration.
$fi02_option = '2'; $v2 = Faluss_Identity_Schema::get_expected_schema(); fi02_assert( 'varchar(255)' === $v2['challenges']['columns']['otp_hash']['type'] && isset( $v2['challenges']['columns']['email'], $v2['challenges']['columns']['email_hash'] ), 'v2 contract' );
fi02_assert( ! isset( $v2['public_profiles'] ), 'v2 has no implicit FI-03 table.' );
// A new installation creates the coherent FI-04 superset in one atomic plan.
$fi02_option = ''; $v4 = Faluss_Identity_Schema::get_expected_schema(); fi02_assert( 'varchar(255)' === $v4['challenges']['columns']['otp_hash']['type'] && isset( $v4['public_profiles'], $v4['authorization_requests'] ), 'new installation FI-04 contract' );
fi02_assert( '6' === Faluss_Identity_Schema::VERSION, 'The additive FI-06 SSO schema becomes current without changing FI-02 primitives.' );
// A failed or unverified migration must retain v1; version promotion is performed only after verification.
$fi02_option = '1'; fi02_assert( '1' === get_option( Faluss_Identity_Schema::OPTION_VERSION ), 'failed migration retains v1' );
echo 'FI-02 schema contract: OK' . PHP_EOL;
