<?php

declare(strict_types=1);

namespace Faluss\Platform\Portal;

use Faluss\Platform\Core\Module;
use Faluss\Platform\Core\SiteRole;
use LogicException;

final class PortalModule implements Module
{
    public const VERSION = '0.1.24';

    public function id(): string
    {
        return 'portal';
    }

    public function roles(): array
    {
        return [SiteRole::Hub];
    }

    public function dependencies(): array
    {
        return ['admin-dashboard', 'apps-registry', 'identity-client'];
    }

    public function boot(): void
    {
        foreach ([
            'Faluss_Portal',
            'Faluss_Portal_Manifest',
            'Faluss_Portal_Apps_Registry_Adapter',
            'Faluss_Portal_Events_Catalog',
            'Faluss_Portal_Events_Runtime',
        ] as $legacyClass) {
            if (class_exists($legacyClass, false)) {
                throw new LogicException('The legacy Portal is already loaded.');
            }
        }

        if (!defined('FALUSS_PORTAL_VERSION')) {
            define('FALUSS_PORTAL_VERSION', self::VERSION);
        }
        if (!defined('FALUSS_PORTAL_URL')) {
            define('FALUSS_PORTAL_URL', rtrim(plugins_url('', dirname(__DIR__, 2) . '/faluss-platform.php'), '/') . '/');
        }

        require_once __DIR__ . '/LegacyPortalService.php';
        require_once __DIR__ . '/LegacyPortalManifest.php';
        require_once __DIR__ . '/LegacyPortalEventsCatalog.php';
        require_once __DIR__ . '/LegacyPortalEventsRuntime.php';
        require_once __DIR__ . '/LegacyPortalAppsRegistryAdapter.php';

        \Faluss_Portal::boot();
        \Faluss_Portal_Manifest::boot();
        \Faluss_Portal_Events_Catalog::boot();
        \Faluss_Portal_Events_Runtime::boot();
        \Faluss_Portal_Apps_Registry_Adapter::boot();
    }
}
