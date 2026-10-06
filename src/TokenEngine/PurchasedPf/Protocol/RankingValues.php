<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf\Protocol;

use DateTimeImmutable;
use DateTimeZone;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Exact owner instants for the additive ranking protocol; legacy second precision is unchanged. */
final class RankingValues
{
    public static function utc(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}$/D',$value) !== 1) { throw new ModelViolation('pf_ranking_invalid_instant'); }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u',$value,new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d H:i:s.u') !== $value || substr($value,0,4) < '1970') { throw new ModelViolation('pf_ranking_invalid_instant'); }
        return $value;
    }

    public static function digest(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D',$value) !== 1) { throw new ModelViolation('pf_ranking_invalid_digest'); }
        return $value;
    }

    public static function category(mixed $value): string
    {
        if (!is_string($value) || !in_array($value,['arts','music','games','learning','lifestyle'],true)) { throw new ModelViolation('pf_ranking_invalid_category'); }
        return $value;
    }
}
