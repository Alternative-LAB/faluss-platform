<?php

declare(strict_types=1);

namespace Faluss\Platform\Link;

use Throwable;

final class LinkIdentityAdapter
{
    public static function ready(): bool
    {
        if (!self::hasMethod('Faluss_Identity_Schema', 'get_status')
            || !class_exists('Faluss_Identity_Registry')
        ) {
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
            || !self::hasMethod('Faluss_Identity_Registry', 'get_active_for_wp_user')
        ) {
            return '';
        }
        $falussId = self::call('Faluss_Identity_Registry', 'get_active_for_wp_user', [get_current_user_id()]);

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

        $row = self::call('Faluss_Identity_Public_Profile', 'lock_studio_profile_in_transaction', [$falussId]);

        return is_array($row) ? $row : false;
    }

    /** @param array<string, mixed> $fields */
    public static function persistStudioProfileInTransaction(string $falussId, array $fields): bool
    {
        if (!self::uuid($falussId) || !self::studioAvailable()) {
            return false;
        }

        return self::call('Faluss_Identity_Public_Profile', 'persist_studio_profile_in_transaction', [$falussId, $fields]) === true;
    }

    /** @param list<array<string, mixed>> $links */
    public static function persistExternalLinksInTransaction(string $falussId, array $links): bool
    {
        if (!self::uuid($falussId) || !self::studioAvailable()) {
            return false;
        }

        return self::call('Faluss_Identity_Public_Profile', 'persist_external_links_in_transaction', [$falussId, $links]) === true;
    }

    /** @return array<string, mixed>|null */
    public static function publishedProfileBySlug(string $slug): ?array
    {
        if (!self::ready()
            || !self::hasMethod('Faluss_Identity_Public_Profile', 'find_published_by_slug')
        ) {
            return null;
        }
        $profile = self::call('Faluss_Identity_Public_Profile', 'find_published_by_slug', [$slug]);

        return is_array($profile) ? $profile : null;
    }

    /**
     * Transitional public schema contract needed for Link's owner-private
     * discovery join until Identity itself is absorbed by the platform.
     */
    public static function publicProfilesTable(): string
    {
        if (!self::ready() || !self::hasMethod('Faluss_Identity_Schema', 'get_public_profiles_table')) {
            return '';
        }
        $table = self::call('Faluss_Identity_Schema', 'get_public_profiles_table');

        return is_string($table) && preg_match('/^[A-Za-z0-9_]+$/D', $table) === 1 ? $table : '';
    }

    /** @return array<int, array<string, mixed>> */
    public static function navigationActions(): array
    {
        if (!self::hasMethod('Faluss_Identity_Navigation', 'actions')) {
            return [];
        }
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
        if (!self::onboardingAvailable()) {
            return [];
        }

        $context = self::call('Faluss_Identity_Onboarding', 'card_wizard_context');

        return is_array($context) ? $context : [];
    }

    public static function advanceCardWizard(string $step): bool
    {
        if (!self::onboardingAvailable()) {
            return false;
        }

        return self::call('Faluss_Identity_Onboarding', 'advance_card_wizard', [$step]) === true;
    }

    public static function completeCardWizard(): bool
    {
        if (!self::onboardingAvailable()) {
            return false;
        }

        return self::call('Faluss_Identity_Onboarding', 'complete_card_wizard') === true;
    }

    public static function onboardingCompletionDestination(): string
    {
        if (!self::onboardingAvailable()) {
            return '';
        }

        $destination = self::call('Faluss_Identity_Onboarding', 'onboarding_completion_destination');

        return is_string($destination) ? $destination : '';
    }

    public static function onboardingUrl(): string
    {
        if (!self::onboardingAvailable()) {
            return '';
        }

        $url = self::call('Faluss_Identity_Onboarding', 'onboarding_url');

        return is_string($url) ? $url : '';
    }

    public static function loginUrl(string $intent, string $returnTo): string
    {
        if (!self::onboardingAvailable()) {
            return '';
        }

        $url = self::call('Faluss_Identity_Onboarding', 'login_url', [$intent, $returnTo]);

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
