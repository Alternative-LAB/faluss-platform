<?php

declare(strict_types=1);

// Run only with wp eval-file on the disposable database named below.
if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'faluss_text_publications_recipe') {
    throw new RuntimeException('Disposable local recipe only');
}

use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\Fans\Images\ImagesModule;
use Faluss\Platform\Fans\Images\ImageSchema;
use Faluss\Platform\Fans\Sso\FansSsoSchema;

if (!ImagesModule::available() || !ImageSchema::installOrVerify()) {
    throw new RuntimeException('Schemas not ready');
}
$sessions = [];
global $wpdb;
foreach (['owner', 'other', 'pending', 'unlinked', 'quota', 'churn', 'racer', 'racequota', 'daily'] as $name) {
    $existing = get_user_by('login', 'recipe_' . $name);
    $id = $existing ? $existing->ID : wp_insert_user(['user_login' => 'recipe_' . $name, 'user_pass' => wp_generate_password(40),
        'user_email' => $name . '@example.test', 'role' => 'subscriber']);
    if (is_wp_error($id)) { throw new RuntimeException('Use a fresh database for the recipe'); }
    if ($name !== 'unlinked' && !$existing) {
        $wpdb->insert(FansSsoSchema::tables()['links'], ['wp_user_id' => $id, 'faluss_id' => wp_generate_uuid4(),
            'created_at' => gmdate('Y-m-d H:i:s'), 'last_proved_at' => gmdate('Y-m-d H:i:s')]);
    }
    wp_set_current_user($id);
    $cookie = wp_generate_auth_cookie($id, time() + 3600, 'logged_in');
    $_COOKIE[LOGGED_IN_COOKIE] = $cookie;
    $profile = $name !== 'unlinked' ? CreatorProfileService::create('arts') : null;
    if (is_wp_error($profile)) { throw new RuntimeException('Profile fixture failed'); }
    $sessions[$name] = ['cookie' => $cookie, 'cookie_name' => LOGGED_IN_COOKIE, 'nonce' => wp_create_nonce('wp_rest'),
        'creator_id' => $profile['creator_id'] ?? null];
    if ($name !== 'pending' && $profile !== null) {
        wp_set_current_user(1);
        if (is_wp_error(CreatorProfileService::setStatus($profile['creator_id'], 'active'))) { throw new RuntimeException('Profile approval failed'); }
    }
}
wp_set_current_user(1);
$cookie = wp_generate_auth_cookie(1, time() + 3600, 'logged_in');
$_COOKIE[LOGGED_IN_COOKIE] = $cookie;
$sessions['admin'] = ['cookie' => $cookie, 'cookie_name' => LOGGED_IN_COOKIE, 'nonce' => wp_create_nonce('wp_rest')];
$directory = '/var/tmp/faluss-image-proof';
if (!is_dir($directory)) { mkdir($directory, 0700); }
file_put_contents($directory . '/sessions.json', wp_json_encode($sessions));
chmod($directory . '/sessions.json', 0600);
echo "Disposable sessions ready; no credentials printed.\n";
