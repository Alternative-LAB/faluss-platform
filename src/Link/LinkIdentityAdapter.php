<?php

declare(strict_types=1);

namespace Faluss\Platform\Link;

use Faluss\Platform\Identity\IdentityContract;

final class LinkIdentityAdapter
{
    public static function ready(): bool
    {
        return IdentityContract::ready();
    }

    public static function currentFalussId(): string
    {
        return IdentityContract::currentFalussId();
    }

    public static function studioAvailable(): bool
    {
        return IdentityContract::studioAvailable();
    }

    /** @return array<string, mixed> */
    public static function studioProfile(string $falussId): array
    {
        return IdentityContract::studioProfile($falussId);
    }

    /** @return array<string, mixed>|false */
    public static function lockStudioProfileInTransaction(string $falussId): array|false
    {
        return IdentityContract::lockStudioProfileInTransaction($falussId);
    }

    /** @param array<string, mixed> $fields */
    public static function persistStudioProfileInTransaction(string $falussId, array $fields): bool
    {
        return IdentityContract::persistStudioProfileInTransaction($falussId, $fields);
    }

    /** @param list<array<string, mixed>> $links */
    public static function persistExternalLinksInTransaction(string $falussId, array $links): bool
    {
        return IdentityContract::persistExternalLinksInTransaction($falussId, $links);
    }

    /** @return array<string, mixed>|null */
    public static function publishedProfileBySlug(string $slug): ?array
    {
        return IdentityContract::publishedProfileBySlug($slug);
    }

    /**
     * @param list<string> $falussIds
     * @return array<string, array{faluss_id:string,public_slug:string,display_name:string,avatar_attachment_id:int}>
     */
    public static function publishedProfilesByFalussIds(array $falussIds): array
    {
        return IdentityContract::publishedProfilesByFalussIds($falussIds);
    }

    /** @return list<array<string, mixed>> */
    public static function navigationActions(): array
    {
        return IdentityContract::navigationActions();
    }

    public static function onboardingAvailable(): bool
    {
        return IdentityContract::onboardingAvailable();
    }

    /** @return array<string, mixed> */
    public static function onboardingContext(): array
    {
        return IdentityContract::onboardingContext();
    }

    public static function advanceCardWizard(string $step): bool
    {
        return IdentityContract::advanceCardWizard($step);
    }

    public static function completeCardWizard(): bool
    {
        return IdentityContract::completeCardWizard();
    }

    public static function onboardingCompletionDestination(): string
    {
        return IdentityContract::onboardingCompletionDestination();
    }

    public static function onboardingUrl(): string
    {
        return IdentityContract::onboardingUrl();
    }

    public static function loginUrl(string $intent, string $returnTo): string
    {
        return IdentityContract::loginUrl($intent, $returnTo);
    }
}
