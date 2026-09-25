<?php
/**
 * Plugin Name: Faluss Platform
 * Description: Modular foundation for the Faluss WordPress ecosystem.
 * Version: 0.5.4
 * Requires at least: 7.1
 * Tested up to: 7.1.2
 * Requires PHP: 8.2
 * Text Domain: faluss-platform
 */

declare(strict_types=1);

define('FALUSS_PLATFORM_VERSION', '0.5.4');

if (!defined('ABSPATH')) {
    exit;
}

$composerAutoloader = __DIR__ . '/vendor/autoload.php';
if (is_file($composerAutoloader)) {
    require_once $composerAutoloader;
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

\Faluss\Platform\Core\UpdateClient::boot(__FILE__);

register_activation_hook(
    __FILE__,
    [\Faluss\Platform\Subscriptions\SubscriptionsModule::class, 'activate']
);
register_activation_hook(
    __FILE__,
    [\Faluss\Platform\TokenEngine\TokenEngineModule::class, 'activate']
);
register_activation_hook(
    __FILE__,
    [\Faluss\Platform\Identity\IdentityModule::class, 'activate']
);
register_activation_hook(
    __FILE__,
    [\Faluss\Platform\Federation\FederationModule::class, 'activate']
);
register_activation_hook(
    __FILE__,
    [\Faluss\Platform\Events\EventsModule::class, 'activate']
);
register_activation_hook(
    __FILE__,
    [\Faluss\Platform\Analytics\AnalyticsModule::class, 'activate']
);
register_activation_hook(
    __FILE__,
    [\Faluss\Platform\Link\LinkModule::class, 'activate']
);
register_activation_hook(
    __FILE__,
    [\Faluss\Platform\Fans\Sso\FansSsoModule::class, 'activate']
);
register_activation_hook(
    __FILE__,
    [\Faluss\Platform\Fans\Profiles\CreatorProfilesModule::class, 'activate']
);
register_activation_hook(
    __FILE__,
    [\Faluss\Platform\Fans\Followers\FollowersModule::class, 'activate']
);
register_deactivation_hook(
    __FILE__,
    [\Faluss\Platform\Subscriptions\SubscriptionsModule::class, 'deactivate']
);
register_deactivation_hook(
    __FILE__,
    [\Faluss\Platform\Identity\IdentityModule::class, 'deactivate']
);
register_deactivation_hook(
    __FILE__,
    [\Faluss\Platform\Events\EventsModule::class, 'deactivate']
);
register_deactivation_hook(
    __FILE__,
    [\Faluss\Platform\Analytics\AnalyticsModule::class, 'deactivate']
);
register_deactivation_hook(
    __FILE__,
    [\Faluss\Platform\Fans\Sso\FansSsoModule::class, 'deactivate']
);

add_action('plugins_loaded', static function (): void {
    $role = \Faluss\Platform\Core\SiteRole::fromValue(
        defined('FALUSS_PLATFORM_ROLE') ? constant('FALUSS_PLATFORM_ROLE') : null
    );

    if ($role === null) {
        return;
    }

    $registry = new \Faluss\Platform\Core\ModuleRegistry($role);
    $registry->register(new \Faluss\Platform\Admin\DashboardModule($role, $registry));
    if ($role === \Faluss\Platform\Core\SiteRole::Fans
        && defined('FALUSS_PLATFORM_FANS_SSO')
        && constant('FALUSS_PLATFORM_FANS_SSO') === true
        && \Faluss\Platform\Fans\Sso\FansSsoSchema::ready()
        && \Faluss\Platform\Fans\Sso\FansSsoService::configured()
    ) {
        $registry->register(new \Faluss\Platform\Fans\Sso\FansSsoModule());
        if (defined('FALUSS_PLATFORM_FANS_CREATOR_PROFILES')
            && constant('FALUSS_PLATFORM_FANS_CREATOR_PROFILES') === true
            && \Faluss\Platform\Fans\Profiles\CreatorProfileSchema::ready()
        ) {
            $registry->register(new \Faluss\Platform\Fans\Profiles\CreatorProfilesModule());
            if (defined('FALUSS_PLATFORM_FANS_FOLLOWERS')
                && constant('FALUSS_PLATFORM_FANS_FOLLOWERS') === true
                && \Faluss\Platform\Fans\Followers\FollowersSchema::ready()
            ) {
                $registry->register(new \Faluss\Platform\Fans\Followers\FollowersModule());
            }
        }
    }
    if (defined('FALUSS_PLATFORM_FEDERATION')
        && constant('FALUSS_PLATFORM_FEDERATION') === true
        && !class_exists('Faluss_Federation', false)
        && !class_exists('Faluss_Federation_Admin', false)
        && !class_exists('Faluss_Federation_Client', false)
        && !class_exists('Faluss_Federation_Crypto', false)
        && !class_exists('Faluss_Federation_Policy', false)
        && !class_exists('Faluss_Federation_Providers', false)
        && !class_exists('Faluss_Federation_Schema', false)
        && !class_exists('Faluss_Federation_Server', false)
    ) {
        $registry->register(new \Faluss\Platform\Federation\FederationModule());
    }
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
        && defined('FALUSS_PLATFORM_IDENTITY')
        && constant('FALUSS_PLATFORM_IDENTITY') === true
        && !class_exists('Faluss_Identity_Plugin', false)
        && !class_exists('Faluss_Identity_Schema', false)
        && !class_exists('Faluss_Identity_Registry', false)
        && !class_exists('Faluss_Identity_Passwordless', false)
        && !class_exists('Faluss_Identity_Authorization', false)
    ) {
        $registry->register(new \Faluss\Platform\Identity\IdentityModule());
    }
    if (defined('FALUSS_PLATFORM_EVENTS')
        && constant('FALUSS_PLATFORM_EVENTS') === true
        && !class_exists('Faluss_Events', false)
        && !class_exists('Faluss_Events_Catalog_Validator', false)
        && !class_exists('Faluss_Events_Envelope_Validator', false)
        && !class_exists('Faluss_Events_Canonicalizer', false)
        && !class_exists('Faluss_Events_Schema', false)
        && !class_exists('Faluss_Events_Engine', false)
        && !class_exists('Faluss_Events_Retention', false)
        && !class_exists('Faluss_Events_Workers', false)
    ) {
        $registry->register(new \Faluss\Platform\Events\EventsModule());
    }
    if ($role === \Faluss\Platform\Core\SiteRole::Hub
        && defined('FALUSS_PLATFORM_ANALYTICS')
        && constant('FALUSS_PLATFORM_ANALYTICS') === true
        && !class_exists('Faluss_Analytics', false)
        && !class_exists('Faluss_Analytics_Schema', false)
        && !class_exists('Faluss_Analytics_Event_Validator', false)
        && !class_exists('Faluss_Analytics_Consumer', false)
        && !class_exists('Faluss_Analytics_Read_Model', false)
        && !class_exists('Faluss_Analytics_Retention', false)
    ) {
        $registry->register(new \Faluss\Platform\Analytics\AnalyticsModule());
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
    if ($role === \Faluss\Platform\Core\SiteRole::Me
        && ((defined('FALUSS_PLATFORM_ME_STUDIO_V2') && constant('FALUSS_PLATFORM_ME_STUDIO_V2') === true)
            || (defined('FALUSS_PLATFORM_ONBOARDING_V3') && constant('FALUSS_PLATFORM_ONBOARDING_V3') === true))
        && defined('FALUSS_PLATFORM_LINK')
        && constant('FALUSS_PLATFORM_LINK') === true
        && defined('FALUSS_PLATFORM_IDENTITY')
        && constant('FALUSS_PLATFORM_IDENTITY') === true
        && defined('FALUSS_PLATFORM_CATALOG')
        && constant('FALUSS_PLATFORM_CATALOG') === true
        && defined('FALUSS_PLATFORM_APPS_REGISTRY')
        && constant('FALUSS_PLATFORM_APPS_REGISTRY') === true
    ) {
        $registry->register(new \Faluss\Platform\MeStudio\MeStudioModule());
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
        && defined('FALUSS_PLATFORM_SUBSCRIPTIONS')
        && constant('FALUSS_PLATFORM_SUBSCRIPTIONS') === true
        && !class_exists('Faluss_Subscriptions_Schema', false)
        && !class_exists('Faluss_Subscriptions_Catalog', false)
        && !class_exists('Faluss_Subscriptions_Repository', false)
        && !class_exists('Faluss_Subscriptions_Resolver', false)
        && !class_exists('Faluss_Subscriptions_Billing', false)
        && !class_exists('Faluss_Subscriptions_Webhooks', false)
        && !class_exists('Faluss_Subscriptions_Admin', false)
    ) {
        $registry->register(new \Faluss\Platform\Subscriptions\SubscriptionsModule());
    }
    if ($role === \Faluss\Platform\Core\SiteRole::Hub
        && defined('FALUSS_PLATFORM_TOKEN_ENGINE')
        && constant('FALUSS_PLATFORM_TOKEN_ENGINE') === true
        && !class_exists('Token_Engine_Schema', false)
        && !class_exists('Token_Engine_Service', false)
        && !class_exists('Token_Engine_Points_Service', false)
        && !class_exists('Token_Engine_Entitlements', false)
        && !class_exists('Token_Engine_Connector_Access', false)
        && !class_exists('Token_Engine_Admin', false)
    ) {
        $registry->register(new \Faluss\Platform\TokenEngine\TokenEngineModule());
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
