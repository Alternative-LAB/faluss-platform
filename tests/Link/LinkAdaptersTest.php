<?php

declare(strict_types=1);

namespace Faluss\Platform\Link;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class LinkAdaptersTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUnavailableAuthoritiesFailClosed(): void
    {
        class_alias(LinkWpErrorFixture::class, 'WP_Error');
        link_test_reset();

        self::assertFalse(LinkIdentityAdapter::ready());
        self::assertSame('', LinkIdentityAdapter::currentFalussId());
        self::assertNull(LinkIdentityAdapter::publishedProfileBySlug('member'));
        self::assertSame([], LinkIdentityAdapter::publishedProfilesByFalussIds([]));
        self::assertFalse(LinkCatalogAdapter::available());
        self::assertFalse(LinkCatalogAdapter::activeTheme('faluss-default'));
        self::assertSame([], LinkCatalogAdapter::themes(true));
        self::assertFalse(LinkTokenEngineConnectorAdapter::available());
        self::assertInstanceOf('WP_Error', LinkTokenEngineConnectorAdapter::dailyRewardOffer());
        self::assertFalse(LinkTokenEngineConnectorAdapter::subjectHasEntitlement(
            '11111111-1111-4111-8111-111111111111',
            'studio.theme.plus'
        ));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAdaptersUseOnlyThePublishedAuthorityContracts(): void
    {
        class_alias(LinkWpErrorFixture::class, 'WP_Error');
        class_alias(LinkIdentitySchemaFixture::class, 'Faluss_Identity_Schema');
        class_alias(LinkIdentityRegistryFixture::class, 'Faluss_Identity_Registry');
        class_alias(LinkIdentityProfileFixture::class, 'Faluss_Identity_Public_Profile');
        class_alias(LinkIdentityOnboardingFixture::class, 'Faluss_Identity_Onboarding');
        class_alias(LinkIdentityNavigationFixture::class, 'Faluss_Identity_Navigation');
        class_alias(LinkCatalogFixture::class, 'Faluss_Catalog_Themes');
        class_alias(LinkConnectorFixture::class, 'Token_Engine_Connector_Service');
        link_test_reset();
        $GLOBALS['link_test_logged_in'] = true;
        $GLOBALS['link_test_user_id'] = 17;
        $falussId = '11111111-1111-4111-8111-111111111111';

        self::assertTrue(LinkIdentityAdapter::ready());
        self::assertSame($falussId, LinkIdentityAdapter::currentFalussId());
        self::assertSame('member', LinkIdentityAdapter::publishedProfileBySlug('member')['public_slug']);
        self::assertSame([], LinkIdentityAdapter::publishedProfilesByFalussIds([$falussId]));
        self::assertTrue(LinkIdentityAdapter::studioAvailable());
        self::assertTrue(LinkIdentityAdapter::persistStudioProfileInTransaction($falussId, ['display_name' => 'Membre']));
        self::assertTrue(LinkIdentityAdapter::persistExternalLinksInTransaction($falussId, []));
        self::assertSame('https://faluss.me/onboarding/', LinkIdentityAdapter::onboardingUrl());
        self::assertSame([['key' => 'home']], LinkIdentityAdapter::navigationActions());

        self::assertTrue(LinkCatalogAdapter::available());
        self::assertSame('faluss-default', LinkCatalogAdapter::activeTheme('faluss-default')['slug']);
        self::assertCount(1, LinkCatalogAdapter::themes(true));

        self::assertTrue(LinkTokenEngineConnectorAdapter::available());
        self::assertSame('available', LinkTokenEngineConnectorAdapter::dailyRewardOffer()['state']);
        self::assertSame('available', LinkTokenEngineConnectorAdapter::dailyRewardStatusForCurrentSubject()['state']);
        self::assertSame('granted', LinkTokenEngineConnectorAdapter::claimDailyRewardForCurrentSubject()['state']);
        self::assertTrue(LinkTokenEngineConnectorAdapter::subjectHasEntitlement($falussId, 'studio.theme.plus'));
        self::assertSame('studio.theme.plus', LinkTokenEngineConnectorAdapter::entitlementDefinitions()[0]['code']);
    }
}

final class LinkWpErrorFixture
{
    public function __construct(public string $code = '')
    {
    }
}

final class LinkIdentitySchemaFixture
{
    /** @return array{ready:true} */
    public static function get_status(): array
    {
        return ['ready' => true];
    }

    public static function get_public_profiles_table(): string
    {
        return 'wp_faluss_identity_public_profiles';
    }
}

final class LinkIdentityRegistryFixture
{
    public static function get_active_for_wp_user(int $userId): string
    {
        return $userId === 17 ? '11111111-1111-4111-8111-111111111111' : '';
    }
}

final class LinkIdentityProfileFixture
{
    /** @return array<string, mixed> */
    public static function studio_profile(string $falussId): array
    {
        return ['faluss_id' => $falussId, 'public_slug' => 'member', 'links' => []];
    }

    /** @return array<string, mixed> */
    public static function lock_studio_profile_in_transaction(string $falussId): array
    {
        return self::studio_profile($falussId);
    }

    /** @param array<string, mixed> $fields */
    public static function persist_studio_profile_in_transaction(string $falussId, array $fields): bool
    {
        return $falussId !== '' && $fields !== [];
    }

    /** @param list<array<string, mixed>> $links */
    public static function persist_external_links_in_transaction(string $falussId, array $links): bool
    {
        unset($links);

        return $falussId !== '';
    }

    /** @return array<string, mixed> */
    public static function find_published_by_slug(string $slug): array
    {
        return ['public_slug' => $slug, 'faluss_id' => '11111111-1111-4111-8111-111111111111', 'links' => []];
    }
}

final class LinkIdentityOnboardingFixture
{
    /** @return array{required:true,step:string} */
    public static function card_wizard_context(): array
    {
        return ['required' => true, 'step' => 'profile'];
    }

    public static function advance_card_wizard(string $step): bool
    {
        return $step !== '';
    }

    public static function complete_card_wizard(): bool
    {
        return true;
    }

    public static function onboarding_completion_destination(): string
    {
        return 'https://faluss.me/mon-faluss/';
    }

    public static function onboarding_url(): string
    {
        return 'https://faluss.me/onboarding/';
    }

    public static function login_url(string $intent, string $returnTo): string
    {
        return 'https://faluss.me/login/?intent=' . rawurlencode($intent) . '&return_to=' . rawurlencode($returnTo);
    }
}

final class LinkIdentityNavigationFixture
{
    /** @return list<array{key:string}> */
    public static function actions(): array
    {
        return [['key' => 'home']];
    }
}

final class LinkCatalogFixture
{
    /** @return array<string, mixed> */
    public static function get_active_theme(string $slug, string $scope): array
    {
        return ['slug' => $slug, 'scope' => $scope];
    }

    /** @return array<string, array<string, mixed>> */
    public static function active_for_scope(string $scope): array
    {
        return ['faluss-default' => ['slug' => 'faluss-default', 'scope' => $scope]];
    }

    /** @return array<string, array<string, mixed>> */
    public static function all_for_scope(string $scope): array
    {
        return self::active_for_scope($scope);
    }
}

final class LinkConnectorFixture
{
    /** @return array{state:string,amount:int,unit:string} */
    public static function daily_reward_offer(): array
    {
        return ['state' => 'available', 'amount' => 20, 'unit' => 'PF'];
    }

    /** @return array{state:string} */
    public static function daily_reward_status_for_current_subject(): array
    {
        return ['state' => 'available'];
    }

    /** @return array{state:string} */
    public static function claim_daily_reward_for_current_subject(): array
    {
        return ['state' => 'granted'];
    }

    public static function subject_has_entitlement(string $falussId, string $code): bool
    {
        return $falussId !== '' && $code === 'studio.theme.plus';
    }

    /** @return list<array{code:string,label:string}> */
    public static function entitlement_definitions(): array
    {
        return [['code' => 'studio.theme.plus', 'label' => 'Plus']];
    }
}
