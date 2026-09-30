<?php
declare(strict_types=1);

// Disposable configuration only. No mail, outbound SSO or existing WordPress site.
add_filter('pre_http_request', static fn () => new WP_Error('recipe_offline'), PHP_INT_MAX);
add_filter('pre_wp_mail', static fn () => false);
add_filter('redirect_canonical', '__return_false');
define('FALUSS_FANS_SSO_CLIENT_ID', 'unusable-admission-recipe');
define('FALUSS_FANS_SSO_CLIENT_SECRET', str_repeat('x', 43));
