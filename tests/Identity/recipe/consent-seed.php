<?php
if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'button_recipe') { throw new RuntimeException('Isolated fixture only'); }
global $wpdb;
if (!Faluss_Identity_Consent::install()) { throw new RuntimeException('Consent schema unavailable'); }
$sessions = [];
foreach (['member', 'other', 'suspended'] as $name) {
    $id = wp_insert_user(['user_login'=>'consent_' . $name, 'user_pass'=>wp_generate_password(40), 'user_email'=>$name . '@example.invalid', 'role'=>'subscriber']);
    $subject = Faluss_Identity_Registry::activate_for_wp_user($id);
    if (!$subject) { throw new RuntimeException('Fixture identity unavailable'); }
    if ($name === 'suspended') { $wpdb->update($wpdb->prefix . 'faluss_identity_profiles', ['status'=>'suspended'], ['wp_user_id'=>$id]); }
    $sessions[$name] = [LOGGED_IN_COOKIE=>wp_generate_auth_cookie($id, time()+3600, 'logged_in')];
}
$sessions['admin'] = [LOGGED_IN_COOKIE=>wp_generate_auth_cookie(1, time()+3600, 'logged_in'), AUTH_COOKIE=>wp_generate_auth_cookie(1, time()+3600, 'auth'), SECURE_AUTH_COOKIE=>wp_generate_auth_cookie(1, time()+3600, 'secure_auth')];
$secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$wpdb->insert($wpdb->prefix . 'faluss_identity_clients', ['client_id'=>'consent-fixture', 'client_name'=>'Fans · recette locale', 'status'=>'active', 'client_secret_hash'=>Faluss_Identity_Authorization::hash_client_secret($secret), 'allowed_scopes'=>'identity.basic identity.email', 'redirect_uris'=>wp_json_encode(['https://fans.example.test/faluss-fans/sso/callback']), 'first_party'=>0, 'created_at'=>gmdate('Y-m-d H:i:s')]);
update_option('permalink_structure', '/%postname%/');
Faluss_Identity_Authorization::register_rewrite_rules();
flush_rewrite_rules(false);
file_put_contents($args[0], wp_json_encode(['sessions'=>$sessions, 'secret'=>$secret]));
