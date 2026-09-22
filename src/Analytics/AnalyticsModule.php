<?php

declare(strict_types=1);

namespace Faluss\Platform\Analytics;

use Faluss\Platform\Core\Module;
use Faluss\Platform\Core\SiteRole;
use LogicException;

final class AnalyticsModule implements Module
{
    public const VERSION = '0.1.1';
    public const SCHEMA_VERSION = '1';

    private static bool $compatibilityLoaded = false;

    public function id(): string
    {
        return 'analytics';
    }

    public function roles(): array
    {
        return [SiteRole::Hub];
    }

    public function dependencies(): array
    {
        return ['admin-dashboard', 'events'];
    }

    public function boot(): void
    {
        self::loadCompatibilityLayer();

        if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb']) && !\Faluss_Analytics_Schema::maybe_upgrade()) {
            throw new LogicException('Faluss Analytics schema is unavailable.');
        }

        (new \ReflectionMethod('Faluss_Analytics', 'boot'))->invoke(null);
        \Faluss_Analytics_Retention::boot();
    }

    public static function activate(): void
    {
        if (!self::enabledForHub()) {
            return;
        }

        self::loadCompatibilityLayer();
        \Faluss_Analytics_Schema::activate();
        \Faluss_Analytics_Retention::activate();
    }

    public static function deactivate(): void
    {
        if (!self::enabledForHub()) {
            return;
        }

        self::loadCompatibilityLayer();
        \Faluss_Analytics_Retention::deactivate();
    }

    private static function enabledForHub(): bool
    {
        return SiteRole::fromValue(
            defined('FALUSS_PLATFORM_ROLE') ? constant('FALUSS_PLATFORM_ROLE') : null
        ) === SiteRole::Hub
            && defined('FALUSS_PLATFORM_ANALYTICS')
            && constant('FALUSS_PLATFORM_ANALYTICS') === true;
    }

    private static function loadCompatibilityLayer(): void
    {
        if (self::$compatibilityLoaded) {
            return;
        }

        foreach ([
            'Faluss_Analytics',
            'Faluss_Analytics_Schema',
            'Faluss_Analytics_Event_Validator',
            'Faluss_Analytics_Consumer',
            'Faluss_Analytics_Read_Model',
            'Faluss_Analytics_Retention',
        ] as $legacyClass) {
            if (class_exists($legacyClass, false)) {
                throw new LogicException('The legacy Faluss Analytics plugin is already loaded.');
            }
        }

        if (!defined('FALUSS_ANALYTICS_VERSION')) {
            define('FALUSS_ANALYTICS_VERSION', self::VERSION);
        }
        if (!defined('FALUSS_ANALYTICS_SCHEMA_VERSION')) {
            define('FALUSS_ANALYTICS_SCHEMA_VERSION', self::SCHEMA_VERSION);
        }

        $legacy = __DIR__ . '/Legacy/includes/';
        foreach ([
            'class-faluss-analytics-schema.php',
            'class-faluss-analytics-event-validator.php',
            'class-faluss-analytics-consumer.php',
            'class-faluss-analytics-read-model.php',
            'class-faluss-analytics-retention.php',
            'class-faluss-analytics.php',
        ] as $file) {
            require_once $legacy . $file;
        }

        self::$compatibilityLoaded = true;
    }
}
