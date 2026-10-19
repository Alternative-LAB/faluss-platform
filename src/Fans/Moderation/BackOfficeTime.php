<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Moderation;

/** Presentation only: persisted UTC values and retention clocks are never rewritten. */
final class BackOfficeTime
{
    public static function utc(?string $value, ?\DateTimeZone $zone = null): string
    {
        if ($value === null) { return 'Non renseigné'; }
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{6})?$/D', $value) !== 1) { return 'Date indisponible'; }
        $format = str_contains($value, '.') ? 'Y-m-d H:i:s.u' : 'Y-m-d H:i:s';
        $date = \DateTimeImmutable::createFromFormat('!' . $format, $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))) { return 'Date indisponible'; }
        return self::display($date, $zone ?? wp_timezone());
    }

    public static function timestamp(?int $value, ?\DateTimeZone $zone = null): string
    {
        if ($value === null) { return 'Non renseigné'; }
        return self::display(new \DateTimeImmutable('@' . $value), $zone ?? wp_timezone());
    }

    /** Convert only named date columns in a copy; identifiers, reasons and order stay unchanged.
     * @param array<string,mixed> $row
     * @return array<string,mixed> */
    public static function journal(array $row, ?\DateTimeZone $zone = null): array
    {
        foreach (['occurred_at', 'created_at', 'until_at'] as $field) {
            if (array_key_exists($field, $row) && (is_string($row[$field]) || $row[$field] === null)) {
                $row[$field] = self::utc($row[$field], $zone);
            }
        }
        return $row;
    }

    private static function display(\DateTimeImmutable $date, \DateTimeZone $zone): string
    {
        // Offset disambiguates the repeated autumn hour while naming the actual WordPress zone.
        return $date->setTimezone($zone)->format('d/m/Y à H:i:s P') . ' · ' . $zone->getName();
    }
}
