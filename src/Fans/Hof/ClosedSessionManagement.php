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

    public static function lifecycle(\wpdb $db): ClosedSessionLifecycle
    {
        self::services($db);
        if (!ClosedSessionLifecycleSchema::ready($db)) { throw new ModelViolation('hof_lifecycle_schema_unavailable'); }
        // Server-owned fixture configuration only; the physical gate runs before the hook.
        $bridge = apply_filters('faluss_fans_hof_closed_sessions', null);
        if (!$bridge instanceof ClosedSessionLifecycle || !$bridge->usesConnection($db)) {
            throw new ModelViolation('hof_lifecycle_transport_unavailable');
        }
        return $bridge;
    }

    public static function freshness(\wpdb $db): ClosedSessionFreshness
    {
        self::services($db);
        if (!\Faluss\Platform\Fans\PfContract\ClosedBarrierReadSchema::ready($db)) { throw new ModelViolation('hof_lifecycle_primary_unavailable'); }
        $reader = apply_filters('faluss_fans_hof_closed_session_freshness', null);
        if (!$reader instanceof ClosedSessionFreshness || !$reader->usesConnection($db)) {
            throw new ModelViolation('hof_lifecycle_primary_unavailable');
        }
        return $reader;
    }
}
