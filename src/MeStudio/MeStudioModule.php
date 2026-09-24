<?php

declare(strict_types=1);

namespace Faluss\Platform\MeStudio;

use Faluss\Platform\AppsRegistry\ManifestValidator;
use Faluss\Platform\Core\Module;
use Faluss\Platform\Core\SiteRole;
use Faluss\Platform\Link\LinkStudioContract;
use Faluss\Platform\Link\StudioProviderRegistry;

final class MeStudioModule implements Module
{
    public const VERSION = '2.0.0';

    public function id(): string
    {
        return 'me-studio-v2';
    }

    public function roles(): array
    {
        return [SiteRole::Me];
    }

    public function dependencies(): array
    {
        return ['admin-dashboard', 'identity', 'catalog', 'link', 'apps-registry'];
    }

    public function boot(): void
    {
        if (!LinkStudioContract::available() || !class_exists(ManifestValidator::class)) {
            return;
        }

        StudioProviderRegistry::register(new MeStudioProvider(StudioBlockProviderRegistry::shared()));
        if (defined('FALUSS_PLATFORM_ME_STUDIO_V2') && constant('FALUSS_PLATFORM_ME_STUDIO_V2') === true) {
            StudioActions::register();
        }
        if (OnboardingV3::enabled()) {
            OnboardingV3::register();
        }
    }
}
