<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Theme;

use Faluss\Platform\Core\SiteRole;
use Faluss\Platform\Theme\ThemeTokensModule;
use PHPUnit\Framework\TestCase;

final class ThemeTokensModuleTest extends TestCase
{
    public function testModuleIsLimitedToMeAndDependsOnAdmin(): void
    {
        $module = new ThemeTokensModule();

        self::assertSame('theme-tokens', $module->id());
        self::assertSame([SiteRole::Me], $module->roles());
        self::assertSame(['admin-dashboard'], $module->dependencies());
    }
}
