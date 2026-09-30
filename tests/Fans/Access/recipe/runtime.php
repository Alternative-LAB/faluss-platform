<?php

declare(strict_types=1);

// MU fixture inside a disposable WordPress only; no existing site/configuration is used.
add_filter('pre_http_request', static fn () => new WP_Error('recipe_offline'), PHP_INT_MAX);
add_filter('pre_wp_mail', static fn () => false);
add_filter('redirect_canonical', '__return_false'); // Virtual HTTPS home is served via loopback HTTP in this fixture.
add_filter('elementor/frontend/print_google_fonts', '__return_false');
define('FALUSS_FANS_SSO_CLIENT_ID', 'unusable-access-recipe');
define('FALUSS_FANS_SSO_CLIENT_SECRET', str_repeat('x', 43));
add_action('plugins_loaded', static function (): void {
    if (!class_exists(\Faluss\Platform\Fans\Sso\FansSsoService::class)) { return; }
    // Register production route adapters directly, without changing any feature flags.
    \Faluss\Platform\Fans\Sso\FansSsoService::register();
    \Faluss\Platform\Fans\Ui\FansUiRoutes::register();
}, 20);
foreach (['admin_post_access_probe', 'admin_post_nopriv_access_probe', 'wp_ajax_access_probe', 'wp_ajax_nopriv_access_probe'] as $hook) {
    add_action($hook, static function (): void {
        if (!isset($_REQUEST['nonce']) || !wp_verify_nonce((string) $_REQUEST['nonce'], 'access_probe')) {
            wp_send_json_error(['reason' => 'nonce'], 403);
        }
        wp_send_json_success(['reached' => true]);
    });
}
add_action('wp_head', static function (): void {
    echo '<meta name="access-probe-nonce" content="' . esc_attr(wp_create_nonce('access_probe')) . '">';
    echo '<meta name="access-linked" content="' . (\Faluss\Platform\Fans\Sso\FansSsoService::currentLinkedSubject() !== null ? 'yes' : 'no') . '">';
});
