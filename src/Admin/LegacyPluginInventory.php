<?php

declare(strict_types=1);

namespace Faluss\Platform\Admin;

use Faluss\Platform\Core\SiteRole;

final class LegacyPluginInventory
{
    /** @param list<string> $activeBasenames */
    public function __construct(
        private readonly SiteRole $role,
        private readonly array $activeBasenames
    ) {
    }

    /** @return list<array{name: string, basename: string, active: bool}> */
    public function rows(): array
    {
        $rows = [];

        foreach (self::manifest($this->role) as $basename => $name) {
            $rows[] = [
                'name' => $name,
                'basename' => $basename,
                'active' => in_array($basename, $this->activeBasenames, true),
            ];
        }

        return $rows;
    }

    public function activeCount(): int
    {
        return count(array_filter($this->rows(), static fn (array $row): bool => $row['active']));
    }

    /** @return array<string, string> */
    private static function manifest(SiteRole $role): array
    {
        $shared = [
            'faluss-apps-registry/faluss-apps-registry.php' => 'Faluss Apps Registry',
            'faluss-events/faluss-events.php' => 'Faluss Events',
            'faluss-federation/faluss-federation.php' => 'Faluss Federation',
        ];

        if ($role === SiteRole::Me) {
            return $shared + [
                'faluss-catalog/faluss-catalog.php' => 'Faluss Catalog',
                'faluss-identity/faluss-identity.php' => 'Faluss Identity',
                'faluss-link/faluss-link.php' => 'Faluss Link',
                'faluss-theme/faluss-theme.php' => 'Faluss Theme',
                'token-engine-connector/token-engine-connector.php' => 'Token Engine Connector',
            ];
        }

        if ($role === SiteRole::Fans) {
            return [];
        }

        return $shared + [
            'faluss-analytics/faluss-analytics.php' => 'Faluss Analytics',
            'faluss-identity-client/faluss-identity-client.php' => 'Faluss Identity Client',
            'faluss-portal/faluss-portal.php' => 'Faluss Portal',
            'faluss-subscriptions/faluss-subscriptions.php' => 'Faluss Subscriptions',
            'token-engine/token-engine.php' => 'Token Engine',
        ];
    }
}
