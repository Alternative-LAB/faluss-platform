<?php

declare(strict_types=1);

namespace Faluss\Platform\Link;

use Faluss\Platform\Core\Module;
use Faluss\Platform\Core\SiteRole;
use LogicException;

final class LinkModule implements Module
{
    public const VERSION = '0.4.0';

    public static function activate(): void
    {
        if (!defined('FALUSS_PLATFORM_LINK') || constant('FALUSS_PLATFORM_LINK') !== true) {
            return;
        }
        require_once __DIR__ . '/LegacyLinkSchema.php';
        \Faluss_Link_Schema::install(true);
    }

    public function id(): string
    {
        return 'link';
    }

    public function roles(): array
    {
        return [SiteRole::Me];
    }

    public function dependencies(): array
    {
        return ['admin-dashboard', 'catalog', 'token-engine-connector'];
    }

    public function boot(): void
    {
        foreach ([
            'Faluss_Link',
            'Faluss_Link_Schema',
            'Faluss_Link_Admin',
            'Faluss_Link_Manifest',
            'Faluss_Link_Events_Catalog',
            'Faluss_Link_Events_Runtime',
        ] as $legacyClass) {
            if (class_exists($legacyClass, false)) {
                throw new LogicException('The legacy Faluss Link is already loaded.');
            }
        }

        if (!defined('FALUSS_LINK_VERSION')) {
            define('FALUSS_LINK_VERSION', self::VERSION);
        }
        if (!defined('FALUSS_LINK_FILE')) {
            define('FALUSS_LINK_FILE', dirname(__DIR__, 2) . '/faluss-platform.php');
        }
        if (!defined('FALUSS_LINK_DIR')) {
            define('FALUSS_LINK_DIR', __DIR__ . '/');
        }

        require_once __DIR__ . '/LegacyLinkSchema.php';
        require_once __DIR__ . '/LegacyLinkAdmin.php';
        require_once __DIR__ . '/LegacyLinkService.php';
        require_once __DIR__ . '/LegacyLinkManifest.php';
        require_once __DIR__ . '/LegacyLinkEventsCatalog.php';
        require_once __DIR__ . '/LegacyLinkEventsRuntime.php';

        \Faluss_Link_Schema::maybe_install();
        \Faluss_Link::boot();
        \Faluss_Link_Admin::boot();
        LinkSchemaMigrationAction::register();
        \Faluss_Link_Manifest::boot();
        \Faluss_Link_Events_Catalog::boot();
        \Faluss_Link_Events_Runtime::boot();
    }
}
