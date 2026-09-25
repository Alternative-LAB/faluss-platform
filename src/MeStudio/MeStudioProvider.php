<?php

declare(strict_types=1);

namespace Faluss\Platform\MeStudio;

use Faluss\Platform\Identity\IdentityContract;
use Faluss\Platform\Link\LinkStudioContract;
use Faluss\Platform\Link\StudioProvider;

final class MeStudioProvider implements StudioProvider
{
    public function __construct(private readonly StudioBlockProviderRegistry $extensions)
    {
    }

    public function id(): string
    {
        return 'me-studio-v2';
    }

    public function renderStudio(callable $fallback): string
    {
        if (OnboardingV3::enabled()) { return StudioV3::render(); }
        if (!self::studioV2Enabled()) {
            return $fallback();
        }
        $state = LinkStudioContract::state();
        if (is_wp_error($state)) {
            return $fallback();
        }
        MeStudioAssets::enqueueStudio();
        $markup = $fallback();

        return str_replace(
            'data-faluss-studio="v1"',
            'data-faluss-studio="v2" data-faluss-studio-provider="me-studio-v2"',
            $markup
        );
    }

    public function renderOnboarding(callable $fallback): string
    {
        if (OnboardingV3::enabled()) {
            return OnboardingV3::render();
        }
        $context = IdentityContract::onboardingContext();
        $step = is_string($context['step'] ?? null) ? $context['step'] : '';
        if ($step !== 'wizard_structure' && !str_starts_with($step, 'wizard_atomic_')) {
            return $fallback();
        }
        $state = LinkStudioContract::state();
        if (is_wp_error($state)) {
            return $fallback();
        }
        $onboardingPreview = LinkStudioContract::preview([], true);
        if (!is_wp_error($onboardingPreview) && is_string($onboardingPreview['preview_html'] ?? null)) {
            $state['preview_html'] = $onboardingPreview['preview_html'];
        }
        if ($step === 'wizard_structure') {
            $state['structure_previews'] = [];
            foreach (['simple', 'atomic'] as $structure) {
                $preview = LinkStudioContract::preview(['structure' => $structure], true);
                $state['structure_previews'][$structure] = !is_wp_error($preview) && is_string($preview['preview_html'] ?? null)
                    ? $preview['preview_html']
                    : (string) ($state['preview_html'] ?? '');
            }
        }
        MeStudioAssets::enqueueOnboarding($step);

        return StudioRenderer::onboarding($step, $state);
    }

    public function renderStudioExtension(array $state): string
    {
        return self::studioV2Enabled() ? StudioRenderer::studioExtension($state, $this->extensions->descriptors()) : '';
    }

    public function enqueueCardAssets(): void
    {
        MeStudioAssets::enqueueCard();
    }

    private static function studioV2Enabled(): bool
    {
        return defined('FALUSS_PLATFORM_ME_STUDIO_V2') && constant('FALUSS_PLATFORM_ME_STUDIO_V2') === true;
    }
}
