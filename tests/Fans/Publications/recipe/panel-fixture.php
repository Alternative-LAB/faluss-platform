<?php

declare(strict_types=1);

if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'faluss_text_publications_recipe') {
    throw new RuntimeException('Disposable local recipe only');
}
require dirname(__DIR__, 2) . '/Images/recipe/fixture.php';
$path = '/var/tmp/faluss-image-proof/sessions.json';
$sessions = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
foreach ($sessions as &$session) {
    $parsed = wp_parse_auth_cookie($session['cookie'], 'logged_in');
    $user = get_user_by('login', $parsed['username']);
    $session['admin_cookie_name'] = SECURE_AUTH_COOKIE;
    $session['admin_cookie'] = wp_generate_auth_cookie($user->ID, (int) $parsed['expiration'], 'secure_auth', $parsed['token']);
}
unset($session);
file_put_contents($path, wp_json_encode($sessions));
chmod($path, 0600);
