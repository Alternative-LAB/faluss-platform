<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Profiles;

use Faluss\Platform\Core\SiteRole;
use Faluss\Platform\Fans\Sso\FansSsoSchema;
use Faluss\Platform\Fans\Sso\FansSsoService;

/** Optional part of CreatorProfilesModule, with its own additive schema. */
final class EditorialModule
{
    public static function enabled(): bool
    {
        if (SiteRole::fromValue(defined('FALUSS_PLATFORM_ROLE') ? constant('FALUSS_PLATFORM_ROLE') : null) !== SiteRole::Fans) { return false; }
        foreach (['FALUSS_PLATFORM_FANS_SSO', 'FALUSS_PLATFORM_FANS_CREATOR_PROFILES', 'FALUSS_PLATFORM_FANS_EDITORIAL'] as $flag) {
            if (!defined($flag) || constant($flag) !== true) { return false; }
        }
        return true;
    }

    public static function available(): bool
    {
        return self::enabled() && FansSsoService::configured() && FansSsoSchema::ready()
            && CreatorProfileSchema::ready() && EditorialSchema::ready();
    }

    public static function activate(): void
    {
        if (self::enabled() && FansSsoService::configured() && FansSsoSchema::ready() && CreatorProfileSchema::ready()) {
            EditorialSchema::installOrVerify();
        }
    }
}
