<?php

declare(strict_types=1);

use Faluss\Platform\Fans\Profiles\CreatorProfileSchema;
use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\Fans\Sso\FansSsoSchema;
use Faluss\Platform\Fans\Store\StoreCatalogSchema;

if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'faluss_store_archive_recipe'
    || ABSPATH !== '/var/tmp/faluss-store-archive-recipe/') {
    throw new RuntimeException('Fresh disposable local instance only');
}
global $wpdb;
if (!FansSsoSchema::ready() || !CreatorProfileSchema::ready()) {
    throw new RuntimeException('Local SSO and profiles must be ready');
}
$table = StoreCatalogSchema::table();
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== null) {
    throw new RuntimeException('Fresh catalog required; nothing deleted');
}
$wpdb->query("CREATE TABLE `$table` (
 product_id char(36) NOT NULL, request_key char(36) NOT NULL, creator_id char(36) NOT NULL,
 category varchar(32) NOT NULL, visibility varchar(16) NOT NULL, created_at datetime NOT NULL,
 PRIMARY KEY(product_id), UNIQUE KEY request_key_unique(request_key),
 KEY category_visibility(category,visibility), KEY creator_id(creator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
update_option(StoreCatalogSchema::OPTION, '1', false);
$id = wp_insert_user(['user_login' => 'archive_creator', 'user_pass' => wp_generate_password(40),
    'user_email' => 'creator@example.test', 'role' => 'subscriber']);
if (is_wp_error($id)) { throw new RuntimeException('Creator fixture failed'); }
$wpdb->insert(FansSsoSchema::tables()['links'], ['wp_user_id' => $id, 'faluss_id' => wp_generate_uuid4(),
    'created_at' => gmdate('Y-m-d H:i:s'), 'last_proved_at' => gmdate('Y-m-d H:i:s')]);
wp_set_current_user($id);
$profile = CreatorProfileService::create('arts');
if (is_wp_error($profile)) { throw new RuntimeException('Profile fixture failed'); }
wp_set_current_user(1);
\Faluss\Platform\Fans\Profiles\CreatorStatusSchema::installOrVerify();
CreatorProfileService::setStatus($profile['creator_id'], 'active');
$ids = ['adult' => [], 'hosted' => []];
foreach (['adult' => 'external_adult_delivery_right', 'hosted' => 'hosted_allowed_content'] as $kind => $category) {
    for ($i = 1; $i <= 25; $i++) {
        $product = sprintf('%08d-1111-4111-8111-%012d', $kind === 'adult' ? 1 : 2, $i);
        $ids[$kind][] = $product;
        if ($wpdb->insert($table, ['product_id' => $product, 'request_key' => wp_generate_uuid4(),
            'creator_id' => $profile['creator_id'], 'category' => $category,
            'visibility' => $kind === 'adult' && $i % 2 ? 'hidden' : 'visible',
            'created_at' => '2026-09-01 00:00:00']) !== 1) { throw new RuntimeException('Seed failed'); }
    }
}
$columns = 'product_id,request_key,creator_id,category,visibility,created_at';
$before = $wpdb->get_results("SELECT $columns FROM `$table` ORDER BY product_id", ARRAY_A);
// Real DDL succeeds, then a synthetic database fault interrupts backfill.
$wpdb->query("CREATE TRIGGER archive_recipe_failure BEFORE UPDATE ON `$table` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic archive failure'");
$previous = $wpdb->suppress_errors(true);
$failed = !StoreCatalogSchema::installOrVerify() && !StoreCatalogSchema::ready()
    && get_option(StoreCatalogSchema::OPTION) === '1';
$wpdb->suppress_errors($previous);
$wpdb->query('DROP TRIGGER archive_recipe_failure');
if (!$failed || !StoreCatalogSchema::installOrVerify() || !StoreCatalogSchema::ready()
    || !StoreCatalogSchema::installOrVerify()) { throw new RuntimeException('Migration/retry failed'); }
$after = $wpdb->get_results("SELECT $columns FROM `$table` ORDER BY product_id", ARRAY_A);
if ($before !== $after || (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table` WHERE archived=1") !== 25) {
    throw new RuntimeException('Original fields changed or archive missing');
}
$sessions = [];
foreach (['admin' => 1, 'creator' => $id] as $name => $user) {
    wp_set_current_user($user);
    $cookie = wp_generate_auth_cookie($user, time() + 3600, 'logged_in');
    $_COOKIE[LOGGED_IN_COOKIE] = $cookie;
    $sessions[$name] = ['cookie' => $cookie, 'cookie_name' => LOGGED_IN_COOKIE, 'nonce' => wp_create_nonce('wp_rest')];
}
$dir = '/var/tmp/faluss-store-archive-proof';
if (!is_dir($dir)) { mkdir($dir, 0700); }
file_put_contents($dir . '/sessions.json', wp_json_encode(['sessions' => $sessions, 'ids' => $ids,
    'creator_id' => $profile['creator_id'], 'user_id' => $id, 'original' => $before]));
chmod($dir . '/sessions.json', 0600);
echo "PASS migration v1->v2; interrupted backfill/retry; repeated activation; 50 original rows unchanged; 25 archived.\n";
