<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Ui;

use Faluss\Platform\Core\Module;
use Faluss\Platform\Core\SiteRole;
use Faluss\Platform\Fans\Sso\FansSsoSchema;
use Faluss\Platform\Fans\Sso\FansSsoService;

final class FansUiModule implements Module
{
    public function id(): string { return 'fans-ui'; }
    public function roles(): array { return [SiteRole::Fans]; }
    public function dependencies(): array { return ['fans-sso']; }

    public function boot(): void
    {
        if (self::available()) {
            FansUiRoutes::register();
        }
    }

    public static function activate(): void
    {
        if (!self::available()) {
            return;
        }
        FansUiRoutes::rewrite();
        flush_rewrite_rules(false);
    }

    public static function available(): bool
    {
        return SiteRole::fromValue(defined('FALUSS_PLATFORM_ROLE') ? constant('FALUSS_PLATFORM_ROLE') : null) === SiteRole::Fans
            && defined('FALUSS_PLATFORM_FANS_UI')
            && constant('FALUSS_PLATFORM_FANS_UI') === true
            && defined('FALUSS_PLATFORM_FANS_SSO')
            && constant('FALUSS_PLATFORM_FANS_SSO') === true
            && FansSsoSchema::ready()
            && FansSsoService::configured();
    }
}
