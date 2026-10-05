<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf\Protocol;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use JsonException;

/** Public PF wire codec: JCS restricted to ASCII strings, objects and lists; no JSON numbers. */
final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        try {
            return json_encode(self::ordered($value, 0), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new ModelViolation('pf_noncanonical_json');
        }
    }

    /** @return array<string,mixed> */
    public static function object(string $bytes, int $limit = 32768): array
    {
        if ($bytes === '' || strlen($bytes) > $limit) {
            throw new ModelViolation('pf_payload_size');
        }
        try {
            $value = json_decode($bytes, false, 24, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ModelViolation('pf_noncanonical_json');
        }
        // Exact canonical bytes also reject duplicate keys, alternate escapes and whitespace.
        if (!$value instanceof \stdClass || self::encode($value) !== $bytes) {
            throw new ModelViolation('pf_noncanonical_json');
        }
        return self::arrayObject($value);
    }

    private static function ordered(mixed $value, int $depth): mixed
    {
        if ($depth > 20) {
            throw new ModelViolation('pf_noncanonical_json');
        }
        if (is_string($value)) {
            if (preg_match('/^[\x20-\x7e]*$/D', $value) !== 1) {
                throw new ModelViolation('pf_noncanonical_json');
            }
            return $value;
        }
        if ($value === null || is_bool($value)) {
            return $value;
        }
        if (is_array($value) && array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::ordered($item, $depth + 1), $value);
        }
        if (is_array($value) || $value instanceof \stdClass) {
            $properties = (array) $value;
            ksort($properties, SORT_STRING);
            $result = new \stdClass();
            foreach ($properties as $key => $item) {
                if (!is_string($key) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key) !== 1) {
                    throw new ModelViolation('pf_noncanonical_json');
                }
                $result->{$key} = self::ordered($item, $depth + 1);
            }
            return $result;
        }
        throw new ModelViolation('pf_noncanonical_json');
    }

    /** @return array<string,mixed> */
    private static function arrayObject(\stdClass $object): array
    {
        $result = [];
        foreach (get_object_vars($object) as $key => $value) {
            $result[$key] = self::arrays($value);
        }
        return $result;
    }

    private static function arrays(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            return self::arrayObject($value);
        }
        return is_array($value) ? array_map(self::arrays(...), $value) : $value;
    }
}
