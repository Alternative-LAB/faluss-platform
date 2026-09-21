<?php

declare(strict_types=1);

namespace Faluss\Platform\AppsRegistry;

use Faluss\Platform\Core\Module;
use Faluss\Platform\Core\SiteRole;
use LogicException;

final class AppsRegistryModule implements Module
{
    public function id(): string
    {
        return 'apps-registry';
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
        if (class_exists('Faluss_Apps_Registry', false)
            || class_exists('Faluss_Apps_Registry_Manifest_Validator', false)
            || class_exists('Faluss_Apps_Registry_Read_Model_Validator', false)
        ) {
            throw new LogicException('The legacy Apps Registry is already loaded.');
        }

        require_once __DIR__ . '/LegacyAppsRegistryFacades.php';
        AppsRegistryService::boot();
    }
}
