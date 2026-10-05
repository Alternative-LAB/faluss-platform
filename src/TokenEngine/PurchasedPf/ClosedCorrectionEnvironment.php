<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

final class ClosedCorrectionEnvironment
{
    public static function assertIsolated(\wpdb $database): void
    {
        if (!defined('FALUSS_PF_H4_RECIPE') || FALUSS_PF_H4_RECIPE !== true) {
            throw new ModelViolation('closed_h4_recipe_required');
        }
        ClosedReservationEnvironment::assertIsolated($database);
    }
}
