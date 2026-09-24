<?php

declare(strict_types=1);

namespace Faluss\Platform\Identity;

use Throwable;

final class IdentityContract
{
    public static function ready(): bool
    {
        if (!self::hasMethod('Faluss_Identity_Schema', 'get_status')) {
            return false;
        }

        $status = self::call('Faluss_Identity_Schema', 'get_status');

        return is_array($status) && !empty($status['ready']);
    }

    public static function currentFalussId(): string
    {
        if (!self::ready()
            || !function_exists('is_user_logged_in')
            || !is_user_logged_in()
        ) {
            return '';
        }

        return self::activeFalussIdForWpUser(get_current_user_id());
    }

    public static function activeFalussIdForWpUser(int $userId): string
    {
        if ($userId < 1
            || !self::ready()
            || !self::hasMethod('Faluss_Identity_Registry', 'get_active_for_wp_user')
        ) {
            return '';
        }

        $falussId = self::call('Faluss_Identity_Registry', 'get_active_for_wp_user', [$userId]);

        return self::uuid($falussId) ? strtolower((string) $falussId) : '';
    }

    public static function studioAvailable(): bool
    {
        return self::ready()
            && self::hasMethod('Faluss_Identity_Public_Profile', 'studio_profile')
            && self::hasMethod('Faluss_Identity_Public_Profile', 'lock_studio_profile_in_transaction')
            && self::hasMethod('Faluss_Identity_Public_Profile', 'persist_studio_profile_in_transaction')
            && self::hasMethod('Faluss_Identity_Public_Profile', 'persist_external_links_in_transaction');
    }

    /** @return array<string, mixed> */
    public static function studioProfile(string $falussId): array
    {
        if (!self::uuid($falussId) || !self::studioAvailable()) {
            return self::emptyProfile($falussId);
        }

        $profile = self::call('Faluss_Identity_Public_Profile', 'studio_profile', [$falussId]);

        return is_array($profile) ? $profile : self::emptyProfile($falussId);
    }

    /** @return array<string, mixed>|false */
    public static function lockStudioProfileInTransaction(string $falussId): array|false
    {
        if (!self::uuid($falussId) || !self::studioAvailable()) {
            return false;
        }

        $profile = self::call('Faluss_Identity_Public_Profile', 'lock_studio_profile_in_transaction', [$falussId]);

        return is_array($profile) ? $profile : false;
    }

    /** @param array<string, mixed> $fields */
    public static function persistStudioProfileInTransaction(string $falussId, array $fields): bool
    {
        return self::uuid($falussId)
            && self::studioAvailable()
            && self::call('Faluss_Identity_Public_Profile', 'persist_studio_profile_in_transaction', [$falussId, $fields]) === true;
    }

    /** @param list<array<string, mixed>> $links */
    public static function persistExternalLinksInTransaction(string $falussId, array $links): bool
    {
        return self::uuid($falussId)
            && self::studioAvailable()
            && self::call('Faluss_Identity_Public_Profile', 'persist_external_links_in_transaction', [$falussId, $links]) === true;
    }

    /** @return array<string, mixed>|null */
    public static function publishedProfileBySlug(string $slug): ?array
    {
        if (!self::ready() || !self::hasMethod('Faluss_Identity_Public_Profile', 'find_published_by_slug')) {
            return null;
        }

        $profile = self::call('Faluss_Identity_Public_Profile', 'find_published_by_slug', [$slug]);

        return is_array($profile) ? $profile : null;
    }

    /**
     * Returns only the public fields needed by Link's private discovery list.
     *
     * @param list<string> $falussIds
     * @return array<string, array{faluss_id:string,public_slug:string,display_name:string,avatar_attachment_id:int}>
     */
    public static function publishedProfilesByFalussIds(array $falussIds): array
    {
        global $wpdb;

        if (!self::ready()
            || !is_object($wpdb)
            || !method_exists($wpdb, 'prepare')
            || !method_exists($wpdb, 'get_results')
            || !self::hasMethod('Faluss_Identity_Schema', 'get_public_profiles_table')
        ) {
            return [];
        }

        $ids = [];
        foreach ($falussIds as $falussId) {
            if (self::uuid($falussId)) {
                $ids[strtolower($falussId)] = strtolower($falussId);
            }
            if (count($ids) === 250) {
                break;
            }
        }
        if ($ids === []) {
            return [];
        }

        $table = self::call('Faluss_Identity_Schema', 'get_public_profiles_table');
        if (!is_string($table) || preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1) {
            return [];
        }

        $values = array_values($ids);
        $placeholders = implode(',', array_fill(0, count($values), '%s'));
        try {
            $query = $wpdb->prepare(
                'SELECT faluss_id,public_slug,display_name,avatar_attachment_id FROM ' . $table
                    . ' WHERE publication_status=%s AND faluss_id IN (' . $placeholders . ')',
                ...array_merge(['published'], $values)
            );
            $rows = $wpdb->get_results($query, defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
        } catch (Throwable) {
            return [];
        }
        $profiles = [];

        foreach ((array) $rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $falussId = strtolower((string) ($row['faluss_id'] ?? ''));
            $slug = (string) ($row['public_slug'] ?? '');
            if (!isset($ids[$falussId]) || preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/D', $slug) !== 1) {
                continue;
            }
            $profiles[$falussId] = [
                'faluss_id' => $falussId,
                'public_slug' => $slug,
                'display_name' => (string) ($row['display_name'] ?? ''),
                'avatar_attachment_id' => max(0, (int) ($row['avatar_attachment_id'] ?? 0)),
            ];
        }

        return $profiles;
    }

    /** @return list<array<string, mixed>> */
    public static function navigationActions(): array
    {
        $actions = self::call('Faluss_Identity_Navigation', 'actions');

        return is_array($actions) ? array_values(array_filter($actions, 'is_array')) : [];
    }

    public static function onboardingAvailable(): bool
    {
        return self::ready()
            && self::hasMethod('Faluss_Identity_Onboarding', 'card_wizard_context')
            && self::hasMethod('Faluss_Identity_Onboarding', 'advance_card_wizard')
            && self::hasMethod('Faluss_Identity_Onboarding', 'complete_card_wizard')
            && self::hasMethod('Faluss_Identity_Onboarding', 'onboarding_completion_destination')
            && self::hasMethod('Faluss_Identity_Onboarding', 'onboarding_url')
            && self::hasMethod('Faluss_Identity_Onboarding', 'login_url');
    }

    /** @return array<string, mixed> */
    public static function onboardingContext(): array
    {
        $context = self::onboardingAvailable()
            ? self::call('Faluss_Identity_Onboarding', 'card_wizard_context')
            : null;

        return is_array($context) ? $context : [];
    }

    /** @return array<string, mixed> */
    public static function onboardingV3Context(): array
    {
        $context = self::call('Faluss_Identity_Onboarding', 'v3_context');
        return is_array($context) ? $context : [];
    }

    public static function beginOnboardingV3(string $mode): bool
    {
        return self::call('Faluss_Identity_Onboarding', 'begin_v3', [$mode]) === true;
    }

    public static function reserveOnboardingV3Slug(string $slug): string
    {
        $result = self::call('Faluss_Identity_Onboarding', 'reserve_v3_slug', [$slug]);
        return is_string($result) ? $result : 'invalid';
    }

    public static function setOnboardingV3Cursor(string $step): bool
    {
        return self::call('Faluss_Identity_Onboarding', 'set_v3_cursor', [$step]) === true;
    }

    public static function advanceCardWizard(string $step): bool
    {
        return self::onboardingAvailable()
            && self::call('Faluss_Identity_Onboarding', 'advance_card_wizard', [$step]) === true;
    }

    public static function completeCardWizard(): bool
    {
        return self::onboardingAvailable()
            && self::call('Faluss_Identity_Onboarding', 'complete_card_wizard') === true;
    }

    public static function onboardingCompletionDestination(): string
    {
        $url = self::onboardingAvailable()
            ? self::call('Faluss_Identity_Onboarding', 'onboarding_completion_destination')
            : null;

        return is_string($url) ? $url : '';
    }

    public static function onboardingUrl(): string
    {
        $url = self::onboardingAvailable()
            ? self::call('Faluss_Identity_Onboarding', 'onboarding_url')
            : null;

        return is_string($url) ? $url : '';
    }

    public static function loginUrl(string $intent, string $returnTo): string
    {
        $url = self::onboardingAvailable()
            ? self::call('Faluss_Identity_Onboarding', 'login_url', [$intent, $returnTo])
            : null;

        return is_string($url) ? $url : '';
    }

    private static function uuid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/Di', $value) === 1;
    }

    private static function hasMethod(string $class, string $method): bool
    {
        return class_exists($class) && method_exists($class, $method);
    }

    /** @param list<mixed> $arguments */
    private static function call(string $class, string $method, array $arguments = []): mixed
    {
        if (!self::hasMethod($class, $method)) {
            return null;
        }

        try {
            return $class::$method(...$arguments);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private static function emptyProfile(string $falussId): array
    {
        return [
            'faluss_id' => $falussId,
            'public_slug' => '',
            'display_name' => '',
            'bio' => '',
            'avatar_attachment_id' => 0,
            'publication_status' => 'draft',
            'links' => [],
        ];
    }
}
