<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine;

use Faluss\Platform\Core\Module;
use Faluss\Platform\Core\SiteRole;
use LogicException;

final class TokenEngineModule implements Module
{
    public const VERSION = '0.4.1';

    private static bool $compatibilityLoaded = false;

    public function id(): string
    {
        return 'token-engine';
    }

    public function roles(): array
    {
        return [SiteRole::Hub];
    }

    public function dependencies(): array
    {
        return ['admin-dashboard'];
    }

    public function boot(): void
    {
        self::loadCompatibilityLayer();
        if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb'])) {
            \Token_Engine_Schema::maybe_install();
        }
        \Token_Engine_Connector_Access::boot();
        \Token_Engine_Admin::boot();
    }

    public static function activate(): void
    {
        if (!self::enabledForHub()) {
            return;
        }

        self::loadCompatibilityLayer();
        \Token_Engine_Schema::activate();
    }

    private static function enabledForHub(): bool
    {
        return SiteRole::fromValue(
            defined('FALUSS_PLATFORM_ROLE') ? constant('FALUSS_PLATFORM_ROLE') : null
        ) === SiteRole::Hub
            && defined('FALUSS_PLATFORM_TOKEN_ENGINE')
            && constant('FALUSS_PLATFORM_TOKEN_ENGINE') === true;
    }

    private static function loadCompatibilityLayer(): void
    {
        if (self::$compatibilityLoaded) {
            return;
        }

        foreach ([
            'Token_Engine_Schema',
            'Token_Engine_Service',
            'Token_Engine_Points_Service',
            'Token_Engine_Entitlements',
            'Token_Engine_Connector_Access',
            'Token_Engine_Admin',
        ] as $legacyClass) {
            if (class_exists($legacyClass, false)) {
                throw new LogicException('The legacy Token Engine plugin is already loaded.');
            }
        }

        $root = dirname(__DIR__, 2);
        if (!defined('TOKEN_ENGINE_FILE')) {
            define('TOKEN_ENGINE_FILE', $root . '/faluss-platform.php');
        }
        if (!defined('TOKEN_ENGINE_DIR')) {
            define('TOKEN_ENGINE_DIR', $root . '/');
        }
        if (!defined('TOKEN_ENGINE_VERSION')) {
            define('TOKEN_ENGINE_VERSION', self::VERSION);
        }

        $legacy = __DIR__ . '/Legacy/includes/';
        foreach ([
            'class-token-engine-schema.php',
            'class-token-engine-service.php',
            'class-token-engine-points-service.php',
            'class-token-engine-entitlements.php',
            'class-token-engine-connector-access.php',
            'class-token-engine-admin.php',
        ] as $file) {
            require_once $legacy . $file;
        }

        self::$compatibilityLoaded = true;
    }
}
