<?php

declare(strict_types=1);

namespace Faluss\Platform\Portal;

final class PortalAppsRegistryAdapter
{
    /** @return array<string, mixed>|null */
    public static function readForMember(string $falussId): ?array
    {
        if (!class_exists('Faluss_Apps_Registry')) {
            return null;
        }
        $snapshot = \Faluss_Apps_Registry::read_for_member($falussId, 'portal', '1.0.0');

        return is_array($snapshot) ? $snapshot : null;
    }
}
