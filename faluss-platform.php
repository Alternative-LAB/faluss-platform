<?php
/**
 * Plugin Name: Faluss Platform
 * Description: Modular foundation for the Faluss WordPress ecosystem.
 * Version: 0.1.0
 * Requires PHP: 8.2
 * Text Domain: faluss-platform
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Faluss\\Platform\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

add_action('plugins_loaded', static function (): void {
    $role = \Faluss\Platform\Core\SiteRole::fromValue(
        defined('FALUSS_PLATFORM_ROLE') ? constant('FALUSS_PLATFORM_ROLE') : null
    );

    if ($role === null) {
        return;
    }

    $registry = new \Faluss\Platform\Core\ModuleRegistry($role);
    $registry->register(new \Faluss\Platform\Admin\DashboardModule($role, $registry));
    if ($role === \Faluss\Platform\Core\SiteRole::Me
        && defined('FALUSS_PLATFORM_THEME_TOKENS')
        && constant('FALUSS_PLATFORM_THEME_TOKENS') === true
        && !class_exists('Faluss_Theme', false)
    ) {
        $registry->register(new \Faluss\Platform\Theme\ThemeTokensModule());
    }
    if ($role === \Faluss\Platform\Core\SiteRole::Me
        && defined('FALUSS_PLATFORM_CATALOG')
        && constant('FALUSS_PLATFORM_CATALOG') === true
        && !class_exists('Faluss_Catalog_Themes', false)
    ) {
        $registry->register(new \Faluss\Platform\Catalog\CatalogModule());
    }
    $registry->boot();
}, 20);
