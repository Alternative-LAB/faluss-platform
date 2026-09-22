<?php

declare(strict_types=1);

namespace Faluss\Platform\Identity;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class IdentityContractTest extends TestCase
{
    public function testUnavailableAuthorityFailsClosed(): void
    {
        identity_test_reset();

        self::assertFalse(IdentityContract::ready());
        self::assertSame('', IdentityContract::currentFalussId());
        self::assertNull(IdentityContract::publishedProfileBySlug('member'));
        self::assertSame([], IdentityContract::publishedProfilesByFalussIds([]));
        self::assertFalse(IdentityContract::studioAvailable());
        self::assertFalse(IdentityContract::onboardingAvailable());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPublishesOnlyTheBoundedIdentitySurface(): void
    {
        identity_test_reset();
        eval(<<<'PHP'
            namespace {
                final class Faluss_Identity_Schema {
                    public static function get_status(): array { return ['ready' => true]; }
                    public static function get_public_profiles_table(): string { return 'wp_faluss_identity_public_profiles'; }
                }
                final class Faluss_Identity_Registry {
                    public static function get_active_for_wp_user(int $id): string { return $id === 17 ? '11111111-1111-4111-8111-111111111111' : ''; }
                }
                final class Faluss_Identity_Public_Profile {
                    public static function studio_profile(string $id): array { return ['faluss_id' => $id, 'public_slug' => 'member']; }
                    public static function lock_studio_profile_in_transaction(string $id): array { return self::studio_profile($id); }
                    public static function persist_studio_profile_in_transaction(string $id, array $fields): bool { return $id !== '' && $fields !== []; }
                    public static function persist_external_links_in_transaction(string $id, array $links): bool { return $id !== ''; }
                    public static function find_published_by_slug(string $slug): array { return ['faluss_id' => '11111111-1111-4111-8111-111111111111', 'public_slug' => $slug]; }
                }
                final class Faluss_Identity_Navigation {
                    public static function actions(): array { return [['key' => 'home']]; }
                }
                final class Faluss_Identity_Onboarding {
                    public static function card_wizard_context(): array { return ['required' => true, 'step' => 'profile']; }
                    public static function advance_card_wizard(string $step): bool { return $step !== ''; }
                    public static function complete_card_wizard(): bool { return true; }
                    public static function onboarding_completion_destination(): string { return 'https://faluss.me/mon-faluss/'; }
                    public static function onboarding_url(): string { return 'https://faluss.me/commencer/'; }
                    public static function login_url(string $intent, string $returnTo): string { return 'https://faluss.me/login/?intent=' . $intent . '&return_to=' . rawurlencode($returnTo); }
                }
            }
            PHP);
        $GLOBALS['identity_test_logged_in'] = true;
        $GLOBALS['identity_test_user_id'] = 17;
        $GLOBALS['wpdb'] = new IdentityContractWpdbFixture();
        $falussId = '11111111-1111-4111-8111-111111111111';

        self::assertTrue(IdentityContract::ready());
        self::assertSame($falussId, IdentityContract::currentFalussId());
        self::assertSame('member', IdentityContract::studioProfile($falussId)['public_slug']);
        self::assertSame('member', IdentityContract::publishedProfileBySlug('member')['public_slug']);
        self::assertTrue(IdentityContract::persistStudioProfileInTransaction($falussId, ['display_name' => 'Membre']));
        self::assertTrue(IdentityContract::persistExternalLinksInTransaction($falussId, []));
        self::assertSame([['key' => 'home']], IdentityContract::navigationActions());
        self::assertSame('https://faluss.me/commencer/', IdentityContract::onboardingUrl());

        $profiles = IdentityContract::publishedProfilesByFalussIds([$falussId, 'invalid']);
        self::assertSame([$falussId], array_keys($profiles));
        self::assertSame(
            ['faluss_id', 'public_slug', 'display_name', 'avatar_attachment_id'],
            array_keys($profiles[$falussId])
        );
        self::assertStringContainsString('publication_status=%s', $GLOBALS['wpdb']->query);
        self::assertSame(['published', $falussId], $GLOBALS['wpdb']->arguments);
    }
}

final class IdentityContractWpdbFixture
{
    public string $query = '';

    /** @var list<mixed> */
    public array $arguments = [];

    public function prepare(string $query, mixed ...$arguments): string
    {
        $this->query = $query;
        $this->arguments = $arguments;

        return $query;
    }

    /** @return list<array<string, mixed>> */
    public function get_results(string $query, string $format): array
    {
        unset($query, $format);

        return [[
            'faluss_id' => '11111111-1111-4111-8111-111111111111',
            'public_slug' => 'member',
            'display_name' => 'Membre',
            'avatar_attachment_id' => '42',
        ]];
    }
}
