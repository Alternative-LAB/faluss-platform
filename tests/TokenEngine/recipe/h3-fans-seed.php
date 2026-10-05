<?php

declare(strict_types=1);

use Faluss\Platform\Fans\Profiles\CreatorProfileSchema;
use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\Fans\Sso\FansSsoSchema;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;

global $wpdb;
ClosedEnvironment::assertIsolated($wpdb, 'fans');
if (!defined('WP_CLI') || !WP_CLI || !FansSsoSchema::installOrVerify() || !CreatorProfileSchema::installOrVerify()) {
    throw new RuntimeException('Disposable Fans schema required.');
}
\Faluss\Platform\Fans\PfContract\ClosedProtocolSchema::installForRecipe($wpdb);
require_once WP_PLUGIN_DIR . '/faluss-platform/src/Federation/Legacy/includes/class-faluss-federation-crypto.php';
$pair = sodium_crypto_sign_seed_keypair(Faluss_Federation_Crypto::base64url_decode(FALUSS_FEDERATION_PRIVATE_SEED, 32));
$public = Faluss_Federation_Crypto::base64url_encode(sodium_crypto_sign_publickey($pair));
sodium_memzero($pair);
$sessions = $identities = [];
foreach (['fan' => 'subscriber', 'creator' => 'subscriber', 'unlinked' => 'subscriber', 'admin' => 'administrator'] as $name => $role) {
    $id = wp_insert_user(['user_login' => 'h3_fixture_' . $name, 'user_pass' => wp_generate_password(40),
        'user_email' => $name . '@example.invalid', 'role' => $role]);
    if (is_wp_error($id)) { throw new RuntimeException('Fixture user unavailable.'); }
    if (in_array($name, ['fan','creator'], true)) {
        $identities[$name] = wp_generate_uuid4();
        $wpdb->insert(FansSsoSchema::tables()['links'], ['wp_user_id' => $id, 'faluss_id' => $identities[$name],
            'created_at' => gmdate('Y-m-d H:i:s'), 'last_proved_at' => gmdate('Y-m-d H:i:s')]);
    }
    wp_set_current_user($id);
    $token = WP_Session_Tokens::get_instance($id)->create(time()+3600);
    $cookie = wp_generate_auth_cookie($id, time()+3600, 'logged_in', $token);
    $_COOKIE[LOGGED_IN_COOKIE] = $cookie;
    $sessions[$name] = ['cookie' => LOGGED_IN_COOKIE . '=' . $cookie, 'nonce' => wp_create_nonce('wp_rest'), 'id' => $id];
    if ($name === 'fan') {
        // Valid signed cookie/token with expired cookie deadline exercises WP's POST grace period.
        $expired = wp_generate_auth_cookie($id, time()-10, 'logged_in', $token);
        $_COOKIE[LOGGED_IN_COOKIE] = $expired;
        $sessions['expired'] = ['cookie' => LOGGED_IN_COOKIE . '=' . $expired, 'nonce' => wp_create_nonce('wp_rest'), 'id' => $id];
        $_COOKIE[LOGGED_IN_COOKIE] = $cookie;
    }
    if ($name === 'creator') {
        $profile = CreatorProfileService::create('arts');
        if (is_wp_error($profile)) { throw new RuntimeException('Fixture profile unavailable.'); }
        // Synthetic admission only. The production admission operation/flags remain unchanged.
        $wpdb->update(CreatorProfileSchema::table(), ['status' => 'active'], ['creator_id' => $profile['creator_id']]);
        $creatorId = $profile['creator_id'];
    }
}
file_put_contents($args[0], wp_json_encode(['sessions' => $sessions, 'identities' => $identities,
    'creator_profile_id' => $creatorId, 'public_key' => $public]));
chmod($args[0], 0600);
