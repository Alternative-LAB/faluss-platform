<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngineConnector;

use Faluss\Platform\Identity\IdentityContract;

final class ConnectorSubjectResolver
{
    public static function current(): string
    {
        $user = self::currentUserAfterPluginsLoaded();
        if ($user === null) {
            return '';
        }

        $identity = self::identityResolution($user);

        return self::filteredSubject($identity['faluss_id'], $user);
    }

    /** @return array{signed_in:bool,identity_adapter_available:bool,active_identity_profile:bool,subject_available:bool,subject_fingerprint:string} */
    public static function diagnostic(): array
    {
        $user = self::currentUserAfterPluginsLoaded();
        $identity = $user === null ? self::emptyResolution() : self::identityResolution($user);
        $subject = $user === null ? '' : self::filteredSubject($identity['faluss_id'], $user);

        return [
            'signed_in' => $user !== null,
            'identity_adapter_available' => $identity['identity_detected'],
            'active_identity_profile' => $identity['active'],
            'subject_available' => $subject !== '',
            'subject_fingerprint' => $subject !== '' ? substr(hash('sha256', $subject), 0, 12) : '',
        ];
    }

    /** @return array{identity_detected:bool,active:bool,faluss_id:string} */
    private static function identityResolution(object $user): array
    {
        $userId = self::userId($user);
        if (!IdentityContract::ready()) {
            return self::emptyResolution();
        }
        $falussId = IdentityContract::activeFalussIdForWpUser($userId);
        $active = self::validFalussId($falussId);

        return [
            'identity_detected' => true,
            'active' => $active,
            'faluss_id' => $active ? self::normalise($falussId) : '',
        ];
    }

    private static function currentUserAfterPluginsLoaded(): ?object
    {
        if (!did_action('plugins_loaded')) {
            return null;
        }

        $user = wp_get_current_user();

        return $user->exists() ? $user : null;
    }

    private static function userId(object $user): int
    {
        $properties = get_object_vars($user);

        return is_int($properties['ID'] ?? null) ? $properties['ID'] : (int) ($properties['ID'] ?? 0);
    }

    private static function filteredSubject(string $subject, object $user): string
    {
        return self::normalise(apply_filters('token_engine_connector_subject_id', $subject, $user));
    }

    private static function validFalussId(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }

    /** @return array{identity_detected:bool,active:bool,faluss_id:string} */
    private static function emptyResolution(): array
    {
        return ['identity_detected' => false, 'active' => false, 'faluss_id' => ''];
    }

    private static function normalise(mixed $value): string
    {
        $value = is_string($value) ? trim(sanitize_text_field($value)) : '';

        return function_exists('mb_substr') ? mb_substr($value, 0, 191) : substr($value, 0, 191);
    }
}
