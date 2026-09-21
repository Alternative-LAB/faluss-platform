<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngineConnector;

use Faluss\Platform\Core\Module;
use Faluss\Platform\Core\SiteRole;
use LogicException;

final class TokenEngineConnectorModule implements Module
{
    public function id(): string
    {
        return 'token-engine-connector';
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
        if (class_exists('Token_Engine_Connector_Service', false)) {
            throw new LogicException('The legacy Token Engine Connector is already loaded.');
        }

        require_once __DIR__ . '/LegacyConnectorFacade.php';
        ConnectorSettingsUpgrade::maybeUpgrade();
        ConnectorAdmin::boot();
    }
}
