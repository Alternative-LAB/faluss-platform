<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Sso;

use Faluss\Platform\Core\Module;
use Faluss\Platform\Core\SiteRole;

final class FansSsoModule implements Module
{
    public function id(): string
    {
        return 'fans-sso';
    }

    public function roles(): array
    {
        return [SiteRole::Fans];
    }

    public function dependencies(): array
    {
        return ['admin-dashboard'];
    }

    public function boot(): void
    {
        if (!self::enabledForFans() || !FansSsoSchema::ready() || !FansSsoService::configured()) {
            return;
        }

        FansSsoService::register();
    }

    public static function activate(): bool
    {
        if (!self::enabledForFans() || !FansSsoService::configured() || !FansSsoSchema::installOrVerify()) {
            return false;
        }

        FansSsoService::rewrite();
        flush_rewrite_rules(false);

        return true;
    }

    public static function deactivate(): void
    {
        if (SiteRole::fromValue(
            defined('FALUSS_PLATFORM_ROLE') ? constant('FALUSS_PLATFORM_ROLE') : null
        ) === SiteRole::Fans) {
            flush_rewrite_rules(false);
        }
    }

    private static function enabledForFans(): bool
    {
        return SiteRole::fromValue(
            defined('FALUSS_PLATFORM_ROLE') ? constant('FALUSS_PLATFORM_ROLE') : null
        ) === SiteRole::Fans
            && defined('FALUSS_PLATFORM_FANS_SSO')
            && constant('FALUSS_PLATFORM_FANS_SSO') === true;
    }
}
