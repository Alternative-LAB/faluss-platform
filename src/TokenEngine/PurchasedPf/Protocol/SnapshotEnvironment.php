<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf\Protocol;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Both roles retain H3's physical lease/SAPI/socket gates; the marker alone cannot admit HTTP. */
final class SnapshotEnvironment
{
    public static function assertIsolated(\wpdb $database, string $role): void
    {
        if (!defined('FALUSS_PF_H4_RECIPE') || constant('FALUSS_PF_H4_RECIPE') !== true) {
            throw new ModelViolation('isolated_h4_recipe_required');
        }
        ClosedEnvironment::assertIsolated($database, $role);
    }
}
