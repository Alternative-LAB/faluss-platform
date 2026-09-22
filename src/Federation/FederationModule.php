<?php

declare(strict_types=1);

namespace Faluss\Platform\Federation;

use Faluss\Platform\Core\Module;
use Faluss\Platform\Core\SiteRole;
use LogicException;

final class FederationModule implements Module
{
    public const VERSION = '0.3.0';
    public const SCHEMA_VERSION = '1';

    private static bool $compatibilityLoaded = false;

    public function id(): string
    {
        return 'federation';
    }

    public function roles(): array
    {
        return [SiteRole::Me, SiteRole::Hub];
    }

    public function dependencies(): array
    {
        return ['admin-dashboard'];
    }

    public function boot(): void
    {
        self::loadCompatibilityLayer();
        \Faluss_Federation::load();
    }

    public static function activate(): void
    {
        if (!self::enabledForConfiguredRole()) {
            return;
        }

        self::loadCompatibilityLayer();
        \Faluss_Federation_Schema::activate();
    }

    private static function enabledForConfiguredRole(): bool
    {
        $role = SiteRole::fromValue(
            defined('FALUSS_PLATFORM_ROLE') ? constant('FALUSS_PLATFORM_ROLE') : null
        );

        return in_array($role, [SiteRole::Me, SiteRole::Hub], true)
            && defined('FALUSS_PLATFORM_FEDERATION')
            && constant('FALUSS_PLATFORM_FEDERATION') === true;
    }

    private static function loadCompatibilityLayer(): void
    {
        if (self::$compatibilityLoaded) {
            return;
        }

        foreach ([
            'Faluss_Federation',
            'Faluss_Federation_Admin',
            'Faluss_Federation_Client',
            'Faluss_Federation_Crypto',
            'Faluss_Federation_Policy',
            'Faluss_Federation_Providers',
            'Faluss_Federation_Schema',
            'Faluss_Federation_Server',
        ] as $legacyClass) {
            if (class_exists($legacyClass, false)) {
                throw new LogicException('The legacy Faluss Federation plugin is already loaded.');
            }
        }

        if (!defined('FALUSS_FEDERATION_VERSION')) {
            define('FALUSS_FEDERATION_VERSION', self::VERSION);
        }
        if (!defined('FALUSS_FEDERATION_SCHEMA_VERSION')) {
            define('FALUSS_FEDERATION_SCHEMA_VERSION', self::SCHEMA_VERSION);
        }
        if (!defined('FALUSS_FEDERATION_DIR')) {
            define('FALUSS_FEDERATION_DIR', __DIR__ . '/Legacy/');
        }

        $legacy = __DIR__ . '/Legacy/includes/';
        foreach ([
            'class-faluss-federation-schema.php',
            'class-faluss-federation-crypto.php',
            'class-faluss-federation-policy.php',
            'class-faluss-federation-providers.php',
            'class-faluss-federation-server.php',
            'class-faluss-federation-client.php',
            'class-faluss-federation-admin.php',
        ] as $file) {
            require_once $legacy . $file;
        }
        require_once __DIR__ . '/LegacyFederationFacade.php';

        self::$compatibilityLoaded = true;
    }
}
