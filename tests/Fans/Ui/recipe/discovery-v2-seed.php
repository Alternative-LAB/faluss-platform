<?php
declare(strict_types=1);

// Extends the original PR fixture; never invoke against an installed site.
use Faluss\Platform\Fans\Profiles\{CreatorProfileService, CreatorProfileSchema, EditorialService};
use Faluss\Platform\Fans\Sso\FansSsoSchema;

if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'admission_recipe' || WP_HOME !== 'https://fans.example.test') { throw new RuntimeException('Disposable fixture only'); }
$fixture = json_decode(file_get_contents($args[0]), true);
$check = static function ($value) { if (is_wp_error($value)) { throw new RuntimeException($value->get_error_code()); } return $value; };
global $wpdb;
for ($i = 14; $i < 42; ++$i) {
    $uid = $check(wp_insert_user(['user_login' => 'discovery_' . $i, 'user_pass' => wp_generate_password(40), 'user_email' => 'discovery_' . $i . '@example.invalid', 'role' => 'subscriber']));
    $wpdb->insert(FansSsoSchema::tables()['links'], ['wp_user_id' => $uid, 'faluss_id' => wp_generate_uuid4(), 'created_at' => gmdate('Y-m-d H:i:s'), 'last_proved_at' => gmdate('Y-m-d H:i:s')]);
    wp_set_current_user($uid); $profile = $check(CreatorProfileService::create('arts')); $id = $profile['creator_id'];
    wp_set_current_user($fixture['sessions']['admin']['id']); $check(CreatorProfileService::setStatus($id, 'active', 0));
    $name = 'Atelier de recette ' . ($i + 1);
    if ($i !== 39) {
        wp_set_current_user($uid); $draft = $check(EditorialService::submit(0, $name, 'Créateur fictif de recette locale. Exploration des formes et des matières.', '', 0));
        wp_set_current_user($fixture['sessions']['admin']['id']);
        if ($i !== 40) { $check(EditorialService::decide($id, $draft['revision'], $i === 41 ? 'reject' : 'approve', $i === 41 ? 'needs_revision' : 'allowed_editorial')); }
    }
    if ($i < 37) {
        $fixture['discovery'][] = ['id' => $id, 'user' => $uid, 'name' => $name, 'category' => 'arts'];
    } else {
        $fixture['excluded'][] = $id;
        if ($i === 37) { $check(CreatorProfileService::setStatus($id, 'suspended', 1)); }
        if ($i === 38) { $wpdb->update(CreatorProfileSchema::table(), ['status' => 'pending'], ['creator_id' => $id]); }
    }
}
// Force two equal newest-after-member arrivals outside the old UUID-ascending LIMIT 20 window.
$sorted = array_column(array_filter($fixture['discovery'], static fn ($p) => $p['category'] === 'arts'), 'id'); sort($sorted);
$ties = array_slice(array_values(array_diff(array_reverse($sorted), [$fixture['discovery'][0]['id']])), 0, 2);
foreach ($fixture['discovery'] as $i => &$profile) {
    $date = $i === 0 ? '2026-10-03 12:00:00' : (in_array($profile['id'], $ties, true) ? '2026-10-02 12:00:00' : (new DateTimeImmutable('2026-09-01 12:00:00', new DateTimeZone('UTC')))->modify('+' . $i . ' minutes')->format('Y-m-d H:i:s'));
    $profile['created_at'] = $date;
    $wpdb->update(CreatorProfileSchema::table(), ['created_at' => $date], ['creator_id' => $profile['id']]);
    // The newest creator has a much older WP account; WP registration must not affect discovery.
    $wpdb->update($wpdb->users, ['user_registered' => $i === 0 ? '2020-01-01 00:00:00' : '2026-10-04 00:00:00'], ['ID' => $profile['user']]);
}
unset($profile);
file_put_contents($args[0], wp_json_encode($fixture));
echo "37 eligible creators (35 Arts), five excluded presentations/profiles; distinct WP registration dates.\n";
