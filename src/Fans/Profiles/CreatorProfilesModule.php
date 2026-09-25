<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Profiles;

use Faluss\Platform\Core\Module;
use Faluss\Platform\Core\SiteRole;
use Faluss\Platform\Fans\Sso\FansSsoSchema;
use Faluss\Platform\Fans\Sso\FansSsoService;

final class CreatorProfilesModule implements Module
{
    public function id(): string { return 'fans-creator-profiles'; }

    public function roles(): array { return [SiteRole::Fans]; }

    public function dependencies(): array { return ['fans-sso']; }

    public function boot(): void
    {
        if (self::enabledForFans() && CreatorProfileSchema::ready() && FansSsoSchema::ready()) {
            CreatorProfileRest::register();
        }
    }

    public static function activate(): void
    {
        if (self::enabledForFans() && FansSsoService::configured() && FansSsoSchema::ready()) {
            CreatorProfileSchema::installOrVerify();
        }
    }

    private static function enabledForFans(): bool
    {
        return SiteRole::fromValue(
            defined('FALUSS_PLATFORM_ROLE') ? constant('FALUSS_PLATFORM_ROLE') : null
        ) === SiteRole::Fans
            && defined('FALUSS_PLATFORM_FANS_SSO')
            && constant('FALUSS_PLATFORM_FANS_SSO') === true
            && defined('FALUSS_PLATFORM_FANS_CREATOR_PROFILES')
            && constant('FALUSS_PLATFORM_FANS_CREATOR_PROFILES') === true;
    }
}
