<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;

/** Local derived cache representation, never a signed Hub contract or source of authority. */
final class RankingProjectionDocument
{
    /** Trusted output of RankingCorpusProjection::build(). Month identifiers are values,
     * because the unchanged signed codec forbids hyphens in object property names.
     * @param array<string,mixed> $document */
    public static function encode(array $document): string
    {
        if (!isset($document['months']) || !is_array($document['months'])) { throw new ModelViolation('hof_projection_invalid_document'); }
        $months = $document['months']; ksort($months,SORT_STRING); $rows = [];
        foreach ($months as $month => $statement) {
            if (!is_string($month) || !is_array($statement)) { throw new ModelViolation('hof_projection_invalid_document'); }
            RankingCalendar::month($month);
            if (array_key_exists('month',$statement)) { throw new ModelViolation('hof_projection_invalid_document'); }
            $rows[] = ['month' => $month] + $statement;
        }
        return CanonicalJson::encode(array_replace($document,['months' => $rows]));
    }
}
