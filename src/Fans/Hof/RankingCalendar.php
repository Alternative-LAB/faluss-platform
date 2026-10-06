<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use DateTimeImmutable;
use DateTimeZone;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

final class RankingCalendar
{
    private const FORMAT = 'Y-m-d H:i:s.u';

    public static function utc(mixed $value): DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}$/D', $value) !== 1) {
            throw new ModelViolation('hof_invalid_instant');
        }
        $date = DateTimeImmutable::createFromFormat('!' . self::FORMAT, $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format(self::FORMAT) !== $value || substr($value, 0, 4) < '1970') {
            throw new ModelViolation('hof_invalid_instant');
        }
        return $date;
    }

    /** @return array{timezone:string,start:string,end:string} */
    public static function month(string $month): array
    {
        if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $month) !== 1
            || substr($month, 0, 4) < '1970' || substr($month, 0, 4) > '9998'
        ) {
            throw new ModelViolation('hof_invalid_month');
        }
        $start = new DateTimeImmutable($month . '-01 00:00:00', new DateTimeZone(RankingPolicy::MONTH_TIMEZONE));
        return ['timezone' => RankingPolicy::MONTH_TIMEZONE, 'start' => self::format($start), 'end' => self::format($start->modify('+1 month'))];
    }

    public static function monthOf(string $confirmedAt): string
    {
        return self::utc($confirmedAt)->setTimezone(new DateTimeZone(RankingPolicy::MONTH_TIMEZONE))->format('Y-m');
    }

    /** @return array{timezone:string,start:string,end:string} */
    public static function session(string $start, string $end, string $timezone): array
    {
        self::timezone($timezone);
        $first = self::utc($start);
        $last = self::utc($end);
        if ($last <= $first || $last > $first->modify('+' . RankingPolicy::MAX_SESSION_DAYS . ' days')) {
            throw new ModelViolation('hof_invalid_session_duration');
        }
        return ['timezone' => $timezone, 'start' => $start, 'end' => $end];
    }

    public static function contains(string $start, string $end, string $confirmedAt): bool
    {
        $first = self::utc($start); $last = self::utc($end); $instant = self::utc($confirmedAt);
        if ($last <= $first) { throw new ModelViolation('hof_invalid_interval'); }
        return $first <= $instant && $instant < $last;
    }

    /** Reject silent DST normalization; an ambiguous time needs its explicit UTC offset. */
    public static function local(string $local, string $timezone, ?int $offsetSeconds = null): string
    {
        $naive = self::utc($local);
        $zone = self::timezone($timezone);
        $offsets = [];
        foreach ($zone->getTransitions($naive->getTimestamp() - 172800, $naive->getTimestamp() + 172800) ?: [] as $transition) {
            $offsets[(int) $transition['offset']] = true;
        }
        $candidates = [];
        foreach (array_keys($offsets) as $offset) {
            $candidate = $naive->modify(sprintf('%+d seconds', -$offset));
            if ($candidate->setTimezone($zone)->format(self::FORMAT) === $local) { $candidates[$offset] = $candidate; }
        }
        if ($candidates === []) { throw new ModelViolation('hof_nonexistent_local_time'); }
        if ($offsetSeconds === null && count($candidates) !== 1) { throw new ModelViolation('hof_ambiguous_local_time'); }
        if ($offsetSeconds !== null && !isset($candidates[$offsetSeconds])) { throw new ModelViolation('hof_invalid_local_offset'); }
        return self::format($offsetSeconds === null ? array_values($candidates)[0] : $candidates[$offsetSeconds]);
    }

    private static function timezone(string $value): DateTimeZone
    {
        if (!in_array($value, DateTimeZone::listIdentifiers(), true)) { throw new ModelViolation('hof_invalid_timezone'); }
        return new DateTimeZone($value);
    }

    private static function format(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT);
    }
}
