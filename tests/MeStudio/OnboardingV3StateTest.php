<?php

declare(strict_types=1);

namespace Faluss\Platform\MeStudio;

use Faluss\Platform\Link\StudioProvider;
use Faluss\Platform\Link\StudioProviderRegistry;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OnboardingV3StateTest extends TestCase
{
    public function testEveryHistoricalDraftCursorLandsOnAValidV3Screen(): void
    {
        $cursors = [
            'choice', 'identifier', 'studio', 'wizard_structure', 'wizard_name', 'wizard_avatar',
            'wizard_header', 'wizard_style', 'wizard_socials', 'wizard_links', 'wizard_finish',
            'wizard_atomic_warning', 'wizard_atomic_colors', 'wizard_atomic_buttons',
            'wizard_atomic_avatar_upload', 'wizard_atomic_avatar', 'wizard_atomic_wallpaper_upload',
            'wizard_atomic_wallpaper', 'wizard_atomic_networks',
        ];
        foreach (['simple', 'atomic'] as $mode) {
            $valid = OnboardingV3::sequence($mode);
            foreach ($cursors as $cursor) {
                self::assertContains(OnboardingV3::step($cursor, $mode), $valid, "$mode / $cursor");
            }
        }
        self::assertSame('v3_identity', OnboardingV3::step('v3_identity_atomic', 'atomic'));
        self::assertSame('v3_mode', OnboardingV3::step('v3_mode_simple', 'simple'));
        self::assertSame('v3_success', OnboardingV3::step('wizard_finish', 'atomic', ['publication_status' => 'published']));
        self::assertSame('v3_review', OnboardingV3::sequence('simple')[4]);
        self::assertSame('v3_name', OnboardingV3::sequence('atomic')[8]);
    }

    #[RunInSeparateProcess]
    public function testActiveV3NeverCallsAnOldOnboardingFallback(): void
    {
        define('FALUSS_PLATFORM_ONBOARDING_V3', true);
        $calls = 0;
        $fallback = static function () use (&$calls): string {
            ++$calls;
            return '<section class="faluss-link-onboarding">V1</section>';
        };
        self::assertStringContainsString('faluss-onboarding-v3__unavailable', StudioProviderRegistry::renderOnboarding($fallback));
        StudioProviderRegistry::register(new class implements StudioProvider {
            public function id(): string { return 'failing-provider'; }
            public function renderStudio(callable $fallback): string { return ''; }
            public function renderOnboarding(callable $fallback): string { throw new RuntimeException('unavailable'); }
            public function renderStudioExtension(array $state): string { return ''; }
            public function enqueueCardAssets(): void {}
        });
        $result = StudioProviderRegistry::renderOnboarding($fallback);
        self::assertStringContainsString('faluss-onboarding-v3__unavailable', $result);
        self::assertStringNotContainsString('faluss-link-onboarding', $result);
        self::assertSame(0, $calls);
    }

    #[RunInSeparateProcess]
    public function testV3AloneNeverFallsBackToTheHistoricalStudio(): void
    {
        define('FALUSS_PLATFORM_ONBOARDING_V3', true);
        $provider = new MeStudioProvider(StudioBlockProviderRegistry::shared());
        StudioProviderRegistry::register($provider);
        self::assertStringContainsString('faluss-onboarding-v3__unavailable', StudioProviderRegistry::renderStudio(static fn (): string => '<main>Studio Link</main>'));
        self::assertSame('', $provider->renderStudioExtension([]));
    }
}
