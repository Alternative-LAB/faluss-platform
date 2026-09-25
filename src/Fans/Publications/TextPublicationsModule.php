<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Publications;

use Faluss\Platform\Core\Module;
use Faluss\Platform\Core\SiteRole;
use Faluss\Platform\Fans\Profiles\CreatorProfileSchema;
use Faluss\Platform\Fans\Sso\FansSsoSchema;
use Faluss\Platform\Fans\Sso\FansSsoService;

final class TextPublicationsModule implements Module
{
    public function id(): string { return 'fans-text-publications'; }
    public function roles(): array { return [SiteRole::Fans]; }
    public function dependencies(): array { return ['fans-creator-profiles']; }
    public static function enabled(): bool
    {
        if (SiteRole::fromValue(defined('FALUSS_PLATFORM_ROLE') ? constant('FALUSS_PLATFORM_ROLE') : null) !== SiteRole::Fans) { return false; }
        foreach (['FALUSS_PLATFORM_FANS_SSO', 'FALUSS_PLATFORM_FANS_CREATOR_PROFILES', 'FALUSS_PLATFORM_FANS_TEXT_PUBLICATIONS'] as $flag) {
            if (!defined($flag) || constant($flag) !== true) { return false; }
        }
        return true;
    }
    public static function available(): bool
    {
        return self::enabled() && FansSsoService::configured() && FansSsoSchema::ready()
            && CreatorProfileSchema::ready() && TextPublicationSchema::ready();
    }
    public static function activate(): void
    {
        if (self::enabled() && FansSsoService::configured() && FansSsoSchema::ready() && CreatorProfileSchema::ready()) {
            TextPublicationSchema::installOrVerify();
        }
    }
    public function boot(): void
    {
        if (self::available()) { TextPublicationRest::register(); }
    }
}
