<?php

$fed01b_contract_assertions = 0;

function fed01b_contract_assert( $condition, $message ) {
    global $fed01b_contract_assertions;
    $fed01b_contract_assertions++;
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
        exit( 1 );
    }
}

function fed01b_source( $root, $path ) {
    $contents = file_get_contents( $root . DIRECTORY_SEPARATOR . $path );
    fed01b_contract_assert( is_string( $contents ), 'Unreadable artifact: ' . $path );
    return $contents;
}

function fed01b_contains_all( $source, $needles, $label ) {
    foreach ( $needles as $needle ) {
        fed01b_contract_assert( false !== strpos( $source, $needle ), $label . ' must contain ' . $needle );
    }
}

$root = dirname( __DIR__, 3 );
$paths = array(
    'docs/FALUSS_FEDERATION.md',
    'src/Federation/Legacy/faluss-federation.php',
    'src/Federation/Legacy/includes/class-faluss-federation-schema.php',
    'src/Federation/Legacy/includes/class-faluss-federation-crypto.php',
    'src/Federation/Legacy/includes/class-faluss-federation-policy.php',
    'src/Federation/Legacy/includes/class-faluss-federation-providers.php',
    'src/Federation/Legacy/includes/class-faluss-federation-server.php',
    'src/Federation/Legacy/includes/class-faluss-federation-client.php',
    'src/Federation/Legacy/includes/class-faluss-federation-admin.php',
    'tests/Federation/contracts/faluss-federation-fed01b-contract-test.php',
    'tests/Federation/contracts/faluss-federation-fed01b-behavior-test.php',
    'docs/FALUSS_FEDERATION_CONTRACT.md',
    'docs/modules/FEDERATION.md',
    'docs/ARCHITECTURE.md',
    'README.md',
    'faluss-platform.php',
);
fed01b_contract_assert( 16 === count( $paths ), 'FED-01B source/document/test manifest must stay at 16 paths.' );
foreach ( $paths as $path ) { fed01b_source( $root, $path ); }

$plugin_files = glob( $root . '/src/Federation/Legacy/**/*.php' );
$plugin_files = array_merge( array( $root . '/src/Federation/Legacy/faluss-federation.php' ), is_array( $plugin_files ) ? $plugin_files : array() );
$plugin_files = array_unique( $plugin_files );
fed01b_contract_assert( 8 === count( $plugin_files ), 'Plugin package must contain exactly eight PHP files.' );

$bootstrap = fed01b_source( $root, 'src/Federation/Legacy/faluss-federation.php' );
$schema = fed01b_source( $root, 'src/Federation/Legacy/includes/class-faluss-federation-schema.php' );
$crypto = fed01b_source( $root, 'src/Federation/Legacy/includes/class-faluss-federation-crypto.php' );
$policy = fed01b_source( $root, 'src/Federation/Legacy/includes/class-faluss-federation-policy.php' );
$providers = fed01b_source( $root, 'src/Federation/Legacy/includes/class-faluss-federation-providers.php' );
$server = fed01b_source( $root, 'src/Federation/Legacy/includes/class-faluss-federation-server.php' );
$client = fed01b_source( $root, 'src/Federation/Legacy/includes/class-faluss-federation-client.php' );
$admin = fed01b_source( $root, 'src/Federation/Legacy/includes/class-faluss-federation-admin.php' );
$guide = fed01b_source( $root, 'docs/FALUSS_FEDERATION.md' );

fed01b_contains_all( $bootstrap, array( 'Version: 0.3.0', "FALUSS_FEDERATION_VERSION', '0.3.0'", 'Requires at least: 6.4', 'Requires PHP: 7.4', "FALUSS_FEDERATION_SCHEMA_VERSION', '1'" ), 'bootstrap' );
fed01b_contains_all( $schema, array( 'GET_LOCK', 'RELEASE_LOCK', 'rate_lock_name', 'RENAME TABLE', 'ENGINE=InnoDB', 'faluss_federation_peers', 'faluss_federation_request_bindings', 'faluss_federation_nonces', 'faluss_federation_audit', 'START TRANSACTION', 'sender_request_unique', 'sender_key_nonce_unique' ), 'schema' );
fed01b_contract_assert( false !== strpos( $schema, 'Never repairs') && false === strpos( $schema, 'dbDelta(' ), 'Migration must be fresh-only and avoid dbDelta.' );
fed01b_contains_all( $crypto, array( "extension_loaded( 'sodium' )", 'SODIUM_CRYPTO_SIGN_SEEDBYTES', 'SODIUM_CRYPTO_SIGN_KEYPAIRBYTES', 'SODIUM_CRYPTO_SIGN_SECRETKEYBYTES', 'SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES', 'SODIUM_CRYPTO_SIGN_BYTES', 'sodium_crypto_sign_seed_keypair', 'sodium_crypto_sign_detached', 'sodium_crypto_sign_verify_detached', 'sodium_memzero', 'catch ( Throwable $throwable )', 'return $cleaned && $result;', 'base64url_decode', 'canonical_join', "'POST'", 'FALUSS_FEDERATION_PRIVATE_SEED' ), 'crypto' );
foreach ( array( 'hash_hmac', 'openssl_', 'rsa' ) as $forbidden ) { fed01b_contract_assert( false === stripos( $crypto, $forbidden ), 'Crypto facade must not provide a forbidden fallback: ' . $forbidden ); }
fed01b_contains_all( $policy, array( 'rotation_refused', 'revoke_peer', 'peer_summaries', 'update_peer_policy', 'peer_policy_updated', 'FOR UPDATE' ), 'policy' );
fed01b_contract_assert( false === strpos( $policy, "array( 'active', 'rotating' ) ), array( '%d', '%s' )" ), 'Peer revocation must not pass an array to wpdb update conditions.' );
fed01b_contract_assert( false !== strpos( $policy, 'WHEN %s THEN 0 WHEN %s THEN 1' ) && false === strpos( $policy, '\\"active\\"' ) && false === strpos( $policy, '\\"rotating\\"' ), 'Outbound peer state ordering must use prepared values without escaped SQL literals.' );
fed01b_contains_all( $providers, array( 'diagnostic.read', 'manifest.read', 'read_model.read', 'event_catalog.read', 'event.publish', "failure( 'not_available' )", 'register_manifest_provider', 'register_read_model_provider', 'register_event_catalog_provider', 'register_event_publish_adapter' ), 'providers' );
fed01b_contract_assert( false === strpos( $providers, 'register_manifest_provider( \'faluss' ), 'FED-01B must not ship a manifest provider.' );
fed01b_contains_all( $server, array( "'POST'", "'/exchange'", 'MAX_BODY = 65536', 'JSON_THROW_ON_ERROR', 'request_canonical', 'consume_replay_and_limit', 'get_header_as_array', 'FALUSS_FEDERATION_DIAGNOSTIC_TRACE', '[Faluss Federation trace] side=server stage=', 'serve_pre_serialized', 'X-Faluss-Federation-Signature', 'private, no-store' ), 'receiver' );
fed01b_contract_assert( false === strpos( $server, 'register_rest_route( self::NAMESPACE, \'/' ), 'Receiver must expose no alternate REST route.' );
fed01b_contract_assert( false !== strpos( $server, 'self::has_keys( $result, $keys )' ) && false !== strpos( $server, 'self::only_keys( $result, $keys )' ) && false === strpos( $server, "isset( \$result['status'], \$result['payload_contract'], \$result['payload'], \$result['error'] )" ), 'Nullable provider results must use an exact key-set check without isset().' );
fed01b_contains_all( $client, array( 'diagnostic_read', 'manifest_read', 'read_model_read', 'event_catalog_read', 'event_publish', 'wp_remote_post', "'sslverify' => true", "'redirection' => 0", "'timeout' => 3", "'limit_response_size' => self::MAX_RESPONSE", 'FALUSS_FEDERATION_DIAGNOSTIC_TRACE', '[Faluss Federation trace] side=client stage=', 'validate_response' ), 'client' );
fed01b_contract_assert( false === strpos( $client, 'connect_timeout' ), 'Client must use only supported WordPress HTTP arguments.' );
fed01b_contract_assert( false === strpos( $client, '$_GET' ) && false === strpos( $client, '$_POST' ), 'Client facades must not receive a destination from browser input.' );
fed01b_contains_all( $admin, array( "add_management_page", "'manage_options'", 'check_admin_referer', 'ENREGISTRER LE PAIR FEDERATION', 'REVOQUER LA CLE FEDERATION', 'METTRE A JOUR LA POLITIQUE FEDERATION', 'faluss_federation_update_policy', 'Transport indisponible : Sodium absent ou invalide' ), 'admin' );
fed01b_contract_assert( false === strpos( $admin, '<script' ) && false === strpos( $admin, '<style' ), 'Administration must not add custom CSS or JavaScript.' );
fed01b_contains_all( $guide, array( 'hub-node', 'me-node', 'FALUSS_FEDERATION_PRIVATE_SEED', '15 minutes', '30 jours', 'Recette WordPress' ), 'operations guide' );

$contract = fed01b_source( $root, 'docs/FALUSS_FEDERATION_CONTRACT.md' );
$module = fed01b_source( $root, 'docs/modules/FEDERATION.md' );
$architecture = fed01b_source( $root, 'docs/ARCHITECTURE.md' );
$readme = fed01b_source( $root, 'README.md' );
$platform = fed01b_source( $root, 'faluss-platform.php' );
fed01b_contains_all( $contract, array( 'Implémentation FED-01B', 'CAP-01B' ), 'federation contract' );
fed01b_contains_all( $module, array( 'Ed25519', 'FALUSS_PLATFORM_FEDERATION', 'faluss_federation_peers', 'aucune clé' ), 'Platform module guide' );
fed01b_contains_all( $architecture, array( 'module Faluss Federation', 'désactivé par défaut' ), 'architecture' );
fed01b_contains_all( $readme, array( 'module Faluss Federation', 'docs/modules/FEDERATION.md' ), 'readme' );
fed01b_contains_all( $platform, array( "defined('FALUSS_PLATFORM_FEDERATION')", 'FederationModule' ), 'Platform bootstrap' );

echo 'FED-01B Federation contract: OK (' . $fed01b_contract_assertions . ' assertions)' . PHP_EOL;
