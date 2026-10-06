<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Approved product rules. No operator activation or economic operation. */
final class RankingPolicy
{
    public const VERSION = '1.0.0';
    public const MONTH_TIMEZONE = 'Europe/Paris';
    public const MAX_SESSION_DAYS = 90;
    public const MAX_OPEN_SESSIONS = 3;
    public const MAX_ATTRIBUTION_SESSIONS = 10;
    public const CATEGORIES = ['arts', 'music', 'games', 'learning', 'lifestyle'];

    public static function category(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::CATEGORIES, true)) {
            throw new ModelViolation('hof_invalid_category');
        }
        return $value;
    }

    public static function scope(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['local', 'national', 'international'], true)) {
            throw new ModelViolation('hof_invalid_scope');
        }
        return $value;
    }
}
