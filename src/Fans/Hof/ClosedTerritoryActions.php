<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Explicit bounded recovery from the exact journal, with no HTTP or state change on GET. */
final class ClosedTerritoryActions
{
    public function __construct(private readonly \wpdb $db)
    { ClosedSessionManagement::services($db); }

    /** One collection page or one confirmed target per POST; the durable keys belong to the bridges.
     * @return array{change:array<string,string>,next:?array<string,string>,total:int,applied:int} */
    public function resume(string $id): array
    {
        $journal = new ClosedTerritoryChanges($this->db); $current = $journal->progress($id);
        if ($current['change']['phase'] === 'collecting') { $journal->collect($id); return $journal->progress($id); }
        if ($current['change']['phase'] === 'completed') { return $current; }
        $item = $current['next'] ?? throw new ModelViolation('hof_territory_checkpoint_conflict');
        if ($item['kind'] === 'admission') {
            ClosedSessionManagement::participation($this->db)->retireTerritory($id,$item['session_id'],$item['barrier_version']);
        } elseif ($item['kind'] === 'session') {
            // The physical gate precedes this application-owned transport factory.
            $bridge = apply_filters('faluss_fans_hof_closed_territory_retirement',null);
            if (!$bridge instanceof ClosedTerritorySessionRetirement || !$bridge->usesConnection($this->db)) {
                throw new ModelViolation('hof_territory_transport_unavailable');
            }
            $bridge->advance($id,$item['session_id'],$item['barrier_version']);
        } else { throw new ModelViolation('hof_territory_checkpoint_conflict'); }
        return $journal->progress($id);
    }
}
