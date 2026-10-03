<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Profiles;

/** Public keyset position bound to its category; not an authorization token. */
final class DiscoveryCursor
{
    /** @return array{date:string,id:string}|null|false */
    public static function decode(mixed $cursor, string $prefix): array|null|false
    {
        if ($cursor === null) { return null; }
        if (!is_string($cursor) || strlen($cursor) > 128
            || preg_match('/^' . preg_quote($prefix, '/') . '(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})\.([0-9a-f-]{36})$/D', $cursor, $matches) !== 1
            || !EditorialService::validId($matches[2])) { return false; }
        $date = str_replace('T', ' ', $matches[1]);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date, new \DateTimeZone('UTC'));
        return $parsed !== false && $parsed->format('Y-m-d H:i:s') === $date ? ['date' => $date, 'id' => $matches[2]] : false;
    }
}
