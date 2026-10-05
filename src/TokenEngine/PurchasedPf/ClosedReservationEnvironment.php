<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

/** H2 has no bootstrap, route, producer or operator command. */
final class ClosedReservationEnvironment
{
    public static function assertIsolated(\wpdb $database): void
    {
        ClosedModelEnvironment::assertIsolated($database);
        if (!defined('FALUSS_HUB_PF_H2_RECIPE_ONLY') || constant('FALUSS_HUB_PF_H2_RECIPE_ONLY') !== true) {
            throw new ModelViolation('isolated_h2_recipe_required');
        }
        $primary = $database->get_row('SELECT @@read_only AS read_only, @@in_transaction AS in_transaction', 'ARRAY_A');
        if ($database->last_error !== '' || $primary === null || (string) $primary['read_only'] !== '0') {
            throw new ModelViolation('primary_required');
        }
    }
}
