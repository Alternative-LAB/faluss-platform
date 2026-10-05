<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf\Protocol;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Public recipe gate. Constants alone can never open HTTP on a normal/FPM site. */
final class ClosedEnvironment
{
    public static function assertIsolated(\wpdb $database, string $role): void
    {
        if (!in_array($role, ['hub', 'fans'], true) || PHP_INT_SIZE < 8
            || !defined('FALUSS_PF_H3_RECIPE_ONLY') || constant('FALUSS_PF_H3_RECIPE_ONLY') !== true
            || !defined('FALUSS_PLATFORM_ROLE') || constant('FALUSS_PLATFORM_ROLE') !== $role
            || !function_exists('wp_get_environment_type') || wp_get_environment_type() !== 'local'
            || !defined('ABSPATH') || !is_string(constant('ABSPATH'))
            || !defined('WP_HTTP_BLOCK_EXTERNAL') || constant('WP_HTTP_BLOCK_EXTERNAL') !== true
            || !defined('FALUSS_PF_H3_LEASE_SHA256') || !is_string(constant('FALUSS_PF_H3_LEASE_SHA256'))
        ) {
            throw new ModelViolation('isolated_h3_recipe_required');
        }
        $path = realpath(rtrim(constant('ABSPATH'), '/'));
        $root = is_string($path) ? dirname($path) : false;
        $lease = is_string($root) ? $root . '/h3-lease' : '';
        if (!is_string($root) || preg_match('#^/var/tmp/hub-pf-wp-[A-Za-z0-9_-]+$#D', $root) !== 1
            || (fileperms($root) & 0777) !== 0700 || $path !== $root . ($role === 'hub' ? '/wordpress' : '/fans')
            || !is_file($lease) || is_link($lease) || (fileperms($lease) & 0777) !== 0600
            || hash_file('sha256', $lease) !== constant('FALUSS_PF_H3_LEASE_SHA256')
            || !defined('DB_HOST') || constant('DB_HOST') !== 'localhost:' . $root . '/sql.sock'
            || ($GLOBALS['wpdb'] ?? null) !== $database
        ) {
            throw new ModelViolation('isolated_h3_recipe_required');
        }
        $sapi = PHP_SAPI;
        if ($sapi === 'cli-server') {
            $origin = defined('WP_HOME') ? constant('WP_HOME') : '';
            if (!is_string($origin) || preg_match('#^http://127\.0\.0\.1:([1-9][0-9]{3,4})$#D', $origin, $match) !== 1
                || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
                || ($_SERVER['SERVER_ADDR'] ?? '127.0.0.1') !== '127.0.0.1'
                || ($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:' . $match[1]
                || (string) ($_SERVER['SERVER_PORT'] ?? '') !== $match[1]
            ) {
                throw new ModelViolation('isolated_h3_http_required');
            }
        } elseif ($sapi !== 'cli' || !defined('WP_CLI') || constant('WP_CLI') !== true) {
            throw new ModelViolation('isolated_h3_http_required');
        }
        $primary = $database->get_row('SELECT DATABASE() AS name, @@read_only AS read_only', 'ARRAY_A');
        if ($database->last_error !== '' || $primary === null || $primary['name'] !== ($role === 'hub' ? 'hub_pf_recipe' : 'fans_pf_recipe')
            || (string) $primary['read_only'] !== '0'
        ) {
            throw new ModelViolation('isolated_h3_primary_required');
        }
    }
}
