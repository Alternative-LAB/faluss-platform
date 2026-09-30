<?php
// Disposable, isolated recipe only. Fault injection is never included in release ZIPs.
add_filter('pre_http_request', static fn () => new WP_Error('recipe_offline'), PHP_INT_MAX);
add_filter('pre_wp_mail', static fn () => false);
add_filter('query', static function ($sql) {
    if (file_exists(ABSPATH . '../fail-grant') && str_starts_with($sql, 'INSERT INTO `wp_faluss_identity_consents`')) {
        $GLOBALS['wpdb']->suppress_errors(true);
        return 'INSERT INTO deliberately_missing_consent_table VALUES (1)';
    }
    return $sql;
});
