<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;

/** Local model validation/fingerprints, not a signed receipt codec. */
final class ModelValues
{
    public const MAX_INTEGER = 9007199254740991;

    /**
     * @param array<array-key, mixed> $input
     * @param list<string> $keys
     */
    public static function exactKeys(array $input, array $keys): void
    {
        $actual = array_keys($input);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new ModelViolation('invalid_shape');
        }
    }

    public static function integer(mixed $value, bool $positive = false): string
    {
        if (PHP_INT_SIZE < 8) {
            throw new ModelViolation('model_requires_64_bit');
        }
        if (!is_string($value) || preg_match('/^(0|[1-9][0-9]{0,15})$/D', $value) !== 1
            || strlen($value) > 16 || (strlen($value) === 16 && strcmp($value, '9007199254740991') > 0)
            || ($positive && $value === '0')
        ) {
            throw new ModelViolation('invalid_quantity_or_revision');
        }

        return $value;
    }

    public static function uuid(mixed $value): string
    {
        if (!is_string($value)
            || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value) !== 1
        ) {
            throw new ModelViolation('invalid_identity_or_reference');
        }

        return $value;
    }

    public static function authority(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $value) !== 1) {
            throw new ModelViolation('invalid_authority');
        }

        return $value;
    }

    public static function reference(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $value) !== 1) {
            throw new ModelViolation('invalid_purchase_reference');
        }

        return $value;
    }

    public static function version(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 32
            || preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $value) !== 1
        ) {
            throw new ModelViolation('invalid_policy_version');
        }

        return $value;
    }

    public static function utc(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D', $value) !== 1) {
            throw new ModelViolation('invalid_date');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i:s\Z') !== $value || substr($value, 0, 4) < '1970') {
            throw new ModelViolation('invalid_date');
        }

        return $value;
    }

    public static function keyHash(string $key): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) {
            throw new ModelViolation('invalid_idempotency_key');
        }

        return hash('sha256', $key);
    }

    /** @param array<array-key, mixed> $values */
    public static function encode(array $values): string
    {
        try {
            return json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new ModelViolation('invalid_model_encoding');
        }
    }

    /** @param array<array-key, mixed> $values */
    public static function fingerprint(array $values): string
    {
        return hash('sha256', self::encode($values));
    }
}
