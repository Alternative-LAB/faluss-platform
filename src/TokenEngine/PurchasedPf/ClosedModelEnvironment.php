<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

/** This H1 model is intentionally unreachable from the WordPress bootstrap. */
final class ClosedModelEnvironment
{
    public static function assertIsolated(\wpdb $database): void
    {
        if (PHP_INT_SIZE < 8
            || !defined('WP_CLI') || constant('WP_CLI') !== true
            || !defined('FALUSS_HUB_PF_H1_RECIPE_ONLY') || constant('FALUSS_HUB_PF_H1_RECIPE_ONLY') !== true
            || !defined('FALUSS_PLATFORM_ROLE') || constant('FALUSS_PLATFORM_ROLE') !== 'hub'
            || !defined('FALUSS_PLATFORM_TOKEN_ENGINE') || constant('FALUSS_PLATFORM_TOKEN_ENGINE') !== true
            || !function_exists('wp_get_environment_type') || wp_get_environment_type() !== 'local'
            || !defined('ABSPATH') || !is_string(constant('ABSPATH'))
        ) {
            throw new ModelViolation('isolated_h1_recipe_required');
        }
        $root = realpath(dirname(rtrim(constant('ABSPATH'), '/')));
        if (!is_string($root) || preg_match('#^/var/tmp/hub-pf-wp-[A-Za-z0-9_-]+$#D', $root) !== 1
            || (fileperms($root) & 0777) !== 0700
            || !defined('DB_HOST') || constant('DB_HOST') !== 'localhost:' . $root . '/sql.sock'
            || ($GLOBALS['wpdb'] ?? null) !== $database
            || $database->get_var('SELECT DATABASE()') !== 'hub_pf_recipe'
            || $database->last_error !== ''
            || get_option('token_engine_schema_version') !== '5'
        ) {
            throw new ModelViolation('isolated_h1_recipe_required');
        }
    }
}
