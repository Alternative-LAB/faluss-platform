<?php
declare(strict_types=1);

use Faluss\Platform\Fans\Profiles\CreatorProfileSchema;
use Faluss\Platform\Fans\Profiles\CreatorStatusSchema;
use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\Fans\Profiles\EditorialSchema;
use Faluss\Platform\Fans\Sso\FansSsoSchema;

if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'admission_recipe') { throw new RuntimeException('Disposable fixture only'); }
global $wpdb;
if (!FansSsoSchema::installOrVerify() || !CreatorProfileSchema::installOrVerify() || !EditorialSchema::installOrVerify()) {
    throw new RuntimeException('Fixture schemas unavailable');
}
$sessions = $profiles = [];
foreach (['member' => 'subscriber', 'other' => 'subscriber', 'waiting' => 'subscriber', 'admin' => 'administrator', 'admin-two' => 'administrator', 'editor' => 'editor', 'unlinked' => 'subscriber'] as $name => $role) {
    $id = wp_insert_user(['user_login' => 'recipe_' . $name, 'user_pass' => wp_generate_password(40), 'user_email' => $name . '@example.invalid', 'role' => $role]);
    if (is_wp_error($id)) { throw new RuntimeException('Fixture account failed'); }
    if (in_array($name, ['member', 'other', 'waiting'], true)) {
        $wpdb->insert(FansSsoSchema::tables()['links'], ['wp_user_id' => $id, 'faluss_id' => wp_generate_uuid4(), 'created_at' => gmdate('Y-m-d H:i:s'), 'last_proved_at' => gmdate('Y-m-d H:i:s')]);
    }
    wp_set_current_user($id);
    $cookies = [LOGGED_IN_COOKIE => wp_generate_auth_cookie($id, time()+3600, 'logged_in'), AUTH_COOKIE => wp_generate_auth_cookie($id, time()+3600, 'auth')];
    $_COOKIE[LOGGED_IN_COOKIE] = $cookies[LOGGED_IN_COOKIE];
    $sessions[$name] = ['cookies' => $cookies, 'nonce' => wp_create_nonce('wp_rest'), 'id' => $id];
    if (in_array($name, ['member', 'other', 'waiting'], true)) {
        $profile = CreatorProfileService::create('arts');
        if (is_wp_error($profile)) { throw new RuntimeException('Fixture profile failed'); }
        $profiles[$name] = $profile['creator_id'];
    }
}
// Simulate an existing opted-in installation upgraded from before the admission journal.
$wpdb->query('DROP TABLE IF EXISTS `' . CreatorStatusSchema::table() . '`');
delete_option(CreatorStatusSchema::OPTION);
update_option('permalink_structure', '/%postname%/');
\Faluss\Platform\Fans\Ui\FansUiRoutes::rewrite();
\Faluss\Platform\Fans\Sso\FansSsoService::rewrite();
flush_rewrite_rules(false);
file_put_contents($args[0], wp_json_encode(['sessions' => $sessions, 'profiles' => $profiles]));
