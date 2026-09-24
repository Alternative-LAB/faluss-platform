<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Followers;

use Faluss\Platform\Core\Module;
use Faluss\Platform\Core\SiteRole;
use Faluss\Platform\Fans\Profiles\CreatorProfileSchema;

final class FollowersModule implements Module
{
    public function id(): string { return 'fans-followers'; }

    public function roles(): array { return [SiteRole::Fans]; }

    public function dependencies(): array { return ['fans-creator-profiles']; }

    public function boot(): void
    {
        if (self::enabledForFans() && CreatorProfileSchema::ready() && FollowersSchema::ready()) {
            FollowersRest::register();
        }
    }

    public static function activate(): void
    {
        if (self::enabledForFans() && CreatorProfileSchema::ready()) {
            FollowersSchema::installOrVerify();
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
            && constant('FALUSS_PLATFORM_FANS_CREATOR_PROFILES') === true
            && defined('FALUSS_PLATFORM_FANS_FOLLOWERS')
            && constant('FALUSS_PLATFORM_FANS_FOLLOWERS') === true;
    }
}
