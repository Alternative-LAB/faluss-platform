<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\PfProjection;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SnapshotEnvironment;

/** F1a is never opened by an application flag or by the marker alone. */
final class ClosedProjectionEnvironment
{
    public static function assertIsolated(\wpdb $database): void
    {
        if (!defined('FALUSS_FANS_F1A_RECIPE') || constant('FALUSS_FANS_F1A_RECIPE') !== true) {
            throw new ModelViolation('isolated_f1a_recipe_required');
        }
        SnapshotEnvironment::assertIsolated($database, 'fans');
    }
}
