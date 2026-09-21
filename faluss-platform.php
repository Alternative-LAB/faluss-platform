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
    if ($role === \Faluss\Platform\Core\SiteRole::Me
        && defined('FALUSS_PLATFORM_TOKEN_ENGINE_CONNECTOR')
        && constant('FALUSS_PLATFORM_TOKEN_ENGINE_CONNECTOR') === true
        && !class_exists('Token_Engine_Connector_Service', false)
    ) {
        $registry->register(new \Faluss\Platform\TokenEngineConnector\TokenEngineConnectorModule());
    }
    if ($role === \Faluss\Platform\Core\SiteRole::Me
        && defined('FALUSS_PLATFORM_LINK')
        && constant('FALUSS_PLATFORM_LINK') === true
        && !class_exists('Faluss_Link', false)
        && !class_exists('Faluss_Link_Schema', false)
        && !class_exists('Faluss_Link_Admin', false)
        && !class_exists('Faluss_Link_Manifest', false)
        && !class_exists('Faluss_Link_Events_Catalog', false)
        && !class_exists('Faluss_Link_Events_Runtime', false)
    ) {
        $registry->register(new \Faluss\Platform\Link\LinkModule());
    }
    if (defined('FALUSS_PLATFORM_APPS_REGISTRY')
        && constant('FALUSS_PLATFORM_APPS_REGISTRY') === true
        && !class_exists('Faluss_Apps_Registry', false)
        && !class_exists('Faluss_Apps_Registry_Manifest_Validator', false)
        && !class_exists('Faluss_Apps_Registry_Read_Model_Validator', false)
    ) {
        $registry->register(new \Faluss\Platform\AppsRegistry\AppsRegistryModule());
    }
    if ($role === \Faluss\Platform\Core\SiteRole::Hub
        && defined('FALUSS_PLATFORM_IDENTITY_CLIENT')
        && constant('FALUSS_PLATFORM_IDENTITY_CLIENT') === true
        && !class_exists('Faluss_Identity_Client', false)
        && !class_exists('Faluss_Identity_Client_Schema', false)
    ) {
        $registry->register(new \Faluss\Platform\IdentityClient\IdentityClientModule());
    }
    if ($role === \Faluss\Platform\Core\SiteRole::Hub
        && defined('FALUSS_PLATFORM_PORTAL')
        && constant('FALUSS_PLATFORM_PORTAL') === true
        && !class_exists('Faluss_Portal', false)
        && !class_exists('Faluss_Portal_Manifest', false)
        && !class_exists('Faluss_Portal_Apps_Registry_Adapter', false)
        && !class_exists('Faluss_Portal_Events_Catalog', false)
        && !class_exists('Faluss_Portal_Events_Runtime', false)
    ) {
        $registry->register(new \Faluss\Platform\Portal\PortalModule());
    }
    $registry->boot();
}, 20);
