<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;

/** Local preference adapters remain closed independently of corpus/network availability. */
final class ClosedRankingPreferences
{
    public static function visibility(\wpdb $db): RankingVisibility
    {
        ClosedEnvironment::assertIsolated($db, 'fans');
        if ($db !== ($GLOBALS['wpdb'] ?? null) || !RankingSchema::ready($db)) { throw new ModelViolation('hof_schema_unavailable'); }
        return new RankingVisibility($db);
    }
}
