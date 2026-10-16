<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Ui;

use DateTimeZone;
use Faluss\Platform\Fans\Hof\RankingCalendar;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Native local date fields delegate DST decisions to the existing session calendar. */
final class FansUiSessionDates
{
    public static function utc(string $local, string $timezone, string $offset = '', ?string $previous = null, ?string $previousZone = null): string
    {
        $canonical = self::canonical($local); $zone = self::zone($timezone);
        $seconds = $offset === '' ? null : self::seconds($offset);
        // datetime-local omits fractional seconds. An unchanged field must not rewrite a stored instant.
        if ($previous !== null && $previousZone === $timezone && !str_contains($local, '.')) {
            $old = RankingCalendar::utc($previous)->setTimezone($zone);
            if ($canonical === $old->format('Y-m-d H:i:s') . '.000000'
                && ($seconds === null || $seconds === $old->getOffset())) { return $previous; }
        }
        return RankingCalendar::local($canonical, $timezone, $seconds);
    }

    public static function localValue(string $utc, string $timezone): string
    { return RankingCalendar::utc($utc)->setTimezone(self::zone($timezone))->format('Y-m-d\TH:i:s'); }

    public static function offsetValue(string $utc, string $timezone): string
    { return self::formatOffset(RankingCalendar::utc($utc)->setTimezone(self::zone($timezone))->getOffset()); }

    /** Options are actual valid offsets for this local time, never a new timestamp authority.
     * @return list<string> */
    public static function choices(string $local, string $timezone): array
    {
        $canonical = self::canonical($local); $naive = RankingCalendar::utc($canonical); $zone = self::zone($timezone); $choices = [];
        foreach ($zone->getTransitions($naive->getTimestamp() - 172800, $naive->getTimestamp() + 172800) ?: [] as $transition) {
            $seconds = (int) $transition['offset'];
            try { RankingCalendar::local($canonical, $timezone, $seconds); $choices[self::formatOffset($seconds)] = true; }
            catch (ModelViolation) { /* A transition's other offset need not exist at the requested local time. */ }
        }
        return array_keys($choices);
    }

    private static function canonical(string $local): string
    {
        if (preg_match('/^([0-9]{4}-[0-9]{2}-[0-9]{2})T([0-9]{2}:[0-9]{2})(?::([0-9]{2})(?:\.([0-9]{1,6}))?)?$/D', $local, $parts) !== 1) {
            throw new ModelViolation('hof_invalid_session_input');
        }
        $value = $parts[1] . ' ' . $parts[2] . ':' . ($parts[3] ?? '00') . '.' . str_pad($parts[4] ?? '', 6, '0');
        RankingCalendar::utc($value);
        return $value;
    }

    private static function zone(string $timezone): DateTimeZone
    {
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) { throw new ModelViolation('hof_invalid_timezone'); }
        return new DateTimeZone($timezone);
    }

    private static function seconds(string $offset): int
    {
        if (preg_match('/^([+-])([0-9]{2}):([0-5][0-9])(?::([0-5][0-9]))?$/D', $offset, $parts) !== 1 || (int) $parts[2] > 23) {
            throw new ModelViolation('hof_invalid_local_offset');
        }
        return ($parts[1] === '+' ? 1 : -1) * ((int) $parts[2] * 3600 + (int) $parts[3] * 60 + (int) ($parts[4] ?? 0));
    }

    private static function formatOffset(int $seconds): string
    {
        $absolute = abs($seconds); $value = ($seconds < 0 ? '-' : '+') . sprintf('%02d:%02d', intdiv($absolute, 3600), intdiv($absolute % 3600, 60));
        return $value . ($absolute % 60 === 0 ? '' : sprintf(':%02d', $absolute % 60));
    }
}
