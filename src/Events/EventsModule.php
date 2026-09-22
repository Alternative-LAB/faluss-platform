<?php

declare(strict_types=1);

namespace Faluss\Platform\Events;

use Faluss\Platform\Core\Module;
use Faluss\Platform\Core\SiteRole;
use LogicException;

final class EventsModule implements Module
{
    public const VERSION = '0.3.1';
    public const SCHEMA_VERSION = '2';

    private static bool $compatibilityLoaded = false;

    public function id(): string
    {
        return 'events';
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

        if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb']) && !\Faluss_Events_Schema::maybe_upgrade()) {
            throw new LogicException('Faluss Events schema is unavailable.');
        }

        \Faluss_Events::boot();
        \Faluss_Events_Workers::boot();
        \Faluss_Events_Retention::boot();

        if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb'])) {
            \Faluss_Events_Workers::schedule();
            \Faluss_Events_Retention::schedule();
        }
    }

    public static function activate(): void
    {
        if (!self::enabledForConfiguredRole()) {
            return;
        }

        self::loadCompatibilityLayer();
        \Faluss_Events_Schema::activate();
        \Faluss_Events_Workers::activate();
        \Faluss_Events_Retention::activate();
    }

    public static function deactivate(): void
    {
        if (!self::enabledForConfiguredRole()) {
            return;
        }

        self::loadCompatibilityLayer();
        \Faluss_Events_Workers::deactivate();
        \Faluss_Events_Retention::deactivate();
    }

    private static function enabledForConfiguredRole(): bool
    {
        $role = SiteRole::fromValue(
            defined('FALUSS_PLATFORM_ROLE') ? constant('FALUSS_PLATFORM_ROLE') : null
        );

        return in_array($role, [SiteRole::Me, SiteRole::Hub], true)
            && defined('FALUSS_PLATFORM_EVENTS')
            && constant('FALUSS_PLATFORM_EVENTS') === true;
    }

    private static function loadCompatibilityLayer(): void
    {
        if (self::$compatibilityLoaded) {
            return;
        }

        foreach ([
            'Faluss_Events',
            'Faluss_Events_Canonicalizer',
            'Faluss_Events_Catalog_Validator',
            'Faluss_Events_Engine',
            'Faluss_Events_Envelope_Validator',
            'Faluss_Events_Retention',
            'Faluss_Events_Schema',
            'Faluss_Events_Workers',
        ] as $legacyClass) {
            if (class_exists($legacyClass, false)) {
                throw new LogicException('The legacy Faluss Events plugin is already loaded.');
            }
        }

        if (!defined('FALUSS_EVENTS_VERSION')) {
            define('FALUSS_EVENTS_VERSION', self::VERSION);
        }
        if (!defined('FALUSS_EVENTS_SCHEMA_VERSION')) {
            define('FALUSS_EVENTS_SCHEMA_VERSION', self::SCHEMA_VERSION);
        }

        $legacy = __DIR__ . '/Legacy/includes/';
        foreach ([
            'class-faluss-events-catalog-validator.php',
            'class-faluss-events-envelope-validator.php',
            'class-faluss-events-canonicalizer.php',
            'class-faluss-events.php',
            'class-faluss-events-schema.php',
            'class-faluss-events-engine.php',
            'class-faluss-events-retention.php',
            'class-faluss-events-workers.php',
        ] as $file) {
            require_once $legacy . $file;
        }

        self::$compatibilityLoaded = true;
    }
}
