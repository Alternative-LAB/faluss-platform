<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Pure display projection, not a proof validator or an authorization service. */
final class RankingVisibleProjection
{
    /** The caller authenticates the complete current corpus and rechecks visibility before delivery.
     * No UUID, private rank or tie-break chronology escapes this projection.
     * @param array<array-key,array<string,string>> $ranked
     * @param callable(string):?string $approvedName
     * @return list<array{name:string,points:string,position:string}> */
    public static function build(array $ranked, callable $approvedName): array
    {
        if (!array_is_list($ranked)) { throw new ModelViolation('hof_invalid_display_source'); }
        $seen = []; $previous = null; $visible = [];
        foreach ($ranked as $index => $row) {
            ModelValues::exactKeys($row,['faluss_id','points','reached_at','consumption_order','position']);
            $id = ModelValues::uuid($row['faluss_id']); ModelValues::integer($row['points'],true);
            ModelValues::integer($row['consumption_order'],true); RankingCalendar::utc($row['reached_at']);
            if ($row['position'] !== (string) ($index + 1) || isset($seen[$id])
                || ($previous !== null && self::compare($previous,$row) >= 0)) {
                throw new ModelViolation('hof_invalid_display_source');
            }
            $seen[$id] = true; $previous = $row;
            $name = $approvedName($id);
            if ($name === null) { continue; }
            if ($name === '' || preg_match('//u',$name) !== 1) { throw new ModelViolation('hof_invalid_display_identity'); }
            $visible[] = ['name' => $name,'points' => $row['points'],'position' => (string) (count($visible) + 1)];
        }
        return $visible;
    }

    /** @param array<string,string> $left
     * @param array<string,string> $right */
    private static function compare(array $left, array $right): int
    {
        return ((int) $right['points'] <=> (int) $left['points'])
            ?: strcmp($left['reached_at'],$right['reached_at'])
            ?: ((int) $left['consumption_order'] <=> (int) $right['consumption_order'])
            ?: strcmp($left['faluss_id'],$right['faluss_id']);
    }
}
