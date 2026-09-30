<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Messaging;

use Faluss\Platform\Core\SiteRole;
use Faluss\Platform\Fans\Profiles\CreatorProfileSchema;
use Faluss\Platform\Fans\Sso\FansSsoSchema;
use Faluss\Platform\Fans\Sso\FansSsoService;

final class MessageModule
{
    public static function enabled(): bool
    {
        if (SiteRole::fromValue(defined('FALUSS_PLATFORM_ROLE')?constant('FALUSS_PLATFORM_ROLE'):null)!==SiteRole::Fans) { return false; }
        foreach (['SSO','CREATOR_PROFILES','MESSAGING'] as $flag) {
            if (!defined('FALUSS_PLATFORM_FANS_'.$flag) || constant('FALUSS_PLATFORM_FANS_'.$flag)!==true) { return false; }
        }
        return true;
    }
    public static function available(): bool
    { return self::enabled() && FansSsoService::configured() && FansSsoSchema::ready() && CreatorProfileSchema::ready() && MessageSchema::ready(); }
}
