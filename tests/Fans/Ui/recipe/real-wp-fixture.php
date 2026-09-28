<?php

declare(strict_types=1);

if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'faluss_fans_ui_v2_recipe') {
    throw new RuntimeException('Disposable Fans UI WordPress recipe only.');
}

global $wpdb;
$passwords = [];
$users = [];
foreach (['fan', 'creator', 'suspended', 'withdrawn'] as $kind) {
    $password = bin2hex(random_bytes(16));
    $id = wp_create_user('recipe_' . $kind, $password, 'recipe_' . $kind . '@example.test');
    if (!is_int($id)) {
        throw new RuntimeException('Unable to create fixture user.');
    }
    $users[$kind] = $id;
    $passwords[$kind] = $password;
    $wpdb->insert($wpdb->prefix . 'faluss_fans_identity_links', [
        'wp_user_id' => $id,
        'faluss_id' => wp_generate_uuid4(),
        'created_at' => gmdate('Y-m-d H:i:s'),
        'last_proved_at' => gmdate('Y-m-d H:i:s'),
    ]);
    if ($wpdb->last_error !== '') {
        throw new RuntimeException('Unable to link fixture user.');
    }
}

$profiles = [];
foreach (['creator' => 'active', 'suspended' => 'suspended', 'withdrawn' => 'active'] as $kind => $status) {
    $creatorId = wp_generate_uuid4();
    $profiles[$kind] = $creatorId;
    $wpdb->insert($wpdb->prefix . 'faluss_fans_creator_profiles', [
        'creator_id' => $creatorId,
        'wp_user_id' => $users[$kind],
        'category' => 'arts',
        'status' => $status,
        'created_at' => gmdate('Y-m-d H:i:s'),
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ]);
    if ($wpdb->last_error !== '') {
        throw new RuntimeException('Unable to create fixture profile.');
    }
}
$wpdb->delete($wpdb->prefix . 'faluss_fans_creator_profiles', ['creator_id' => $profiles['withdrawn']]);

$unlinkedPassword = bin2hex(random_bytes(16));
if (!is_int(wp_create_user('recipe_unlinked', $unlinkedPassword, 'recipe_unlinked@example.test'))) {
    throw new RuntimeException('Unable to create unlinked fixture user.');
}

$fixture = [
    'fan' => ['login' => 'recipe_fan', 'password' => $passwords['fan']],
    'creator' => ['login' => 'recipe_creator', 'password' => $passwords['creator']],
    'unlinked' => ['login' => 'recipe_unlinked', 'password' => $unlinkedPassword],
    'active' => $profiles['creator'],
    'suspended' => $profiles['suspended'],
    'withdrawn' => $profiles['withdrawn'],
    'unknown' => wp_generate_uuid4(),
];
file_put_contents(ABSPATH . 'fans-ui-recipe-fixture.json', wp_json_encode($fixture));
chmod(ABSPATH . 'fans-ui-recipe-fixture.json', 0600);
