<?php

declare(strict_types=1);

namespace Faluss\Platform\Catalog;

use Faluss\Platform\Core\Module;
use Faluss\Platform\Core\SiteRole;
use LogicException;

final class CatalogModule implements Module
{
    public function id(): string
    {
        return 'catalog';
    }

    public function roles(): array
    {
        return [SiteRole::Me];
    }

    public function dependencies(): array
    {
        return ['admin-dashboard'];
    }

    public function boot(): void
    {
        if (class_exists('Faluss_Catalog_Themes', false)) {
            throw new LogicException('The legacy catalog is already loaded.');
        }

        require_once __DIR__ . '/LegacyCatalogFacade.php';

        $entitlements = new CatalogEntitlementProvider();
        (new CatalogAdminActions($entitlements))->boot();
        (new CatalogAdminPage($entitlements))->boot();
    }
}
