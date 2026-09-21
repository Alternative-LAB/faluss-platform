<?php

declare(strict_types=1);

namespace Faluss\Platform\Portal;

final class PortalIdentityAdapter
{
    /** @return array{faluss_id:string,created_at:string,last_proved_at:string}|null */
    public static function currentLinkedSubject(): ?array
    {
        if (!class_exists('Faluss_Identity_Client')) {
            return null;
        }
        $subject = \Faluss_Identity_Client::current_linked_subject();
        if (!is_array($subject)
            || !self::uuid($subject['faluss_id'])
            || !self::date($subject['created_at'], true)
            || !self::date($subject['last_proved_at'], true)
        ) {
            return null;
        }

        return [
            'faluss_id' => strtolower((string) $subject['faluss_id']),
            'created_at' => (string) $subject['created_at'],
            'last_proved_at' => (string) $subject['last_proved_at'],
        ];
    }

    /** @param array<string, mixed> $attributes */
    public static function button(array $attributes): string
    {
        return class_exists('Faluss_Identity_Client')
            ? \Faluss_Identity_Client::button($attributes, false)
            : '';
    }

    public static function canonicalDestination(string $falussId): ?string
    {
        if (!self::uuid($falussId)
            || !class_exists('Faluss_Identity_Client_Apps_Registry_Adapter')
        ) {
            return null;
        }
        $destination = \Faluss_Identity_Client_Apps_Registry_Adapter::canonical_destination($falussId);

        return is_string($destination) && $destination !== '' ? $destination : null;
    }

    private static function uuid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value) === 1;
    }

    private static function date(mixed $value, bool $allowEmpty): bool
    {
        return ($allowEmpty && $value === '')
            || (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) === 1);
    }
}
