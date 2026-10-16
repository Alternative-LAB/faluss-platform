<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;

/** Local governance only; no implicit install, economic opening or transport admission. */
final class ClosedSessionManagement
{
    /** @return array{sessions:SessionService,reviews:SessionModeration} */
    public static function services(\wpdb $db): array
    {
        ClosedEnvironment::assertIsolated($db, 'fans');
        if ($db !== ($GLOBALS['wpdb'] ?? null) || !SessionSchema::ready($db) || !SessionReviewSchema::ready($db)) {
            throw new ModelViolation('hof_session_schema_unavailable');
        }
        return ['sessions' => new SessionService($db), 'reviews' => new SessionModeration($db)];
    }
}
