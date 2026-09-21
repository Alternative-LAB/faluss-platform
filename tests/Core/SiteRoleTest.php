<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Core;

use Faluss\Platform\Core\SiteRole;
use PHPUnit\Framework\TestCase;

final class SiteRoleTest extends TestCase
{
    public function testAcceptsOnlyExplicitRoles(): void
    {
        self::assertSame(SiteRole::Me, SiteRole::fromValue('me'));
        self::assertSame(SiteRole::Hub, SiteRole::fromValue('hub'));
        self::assertNull(SiteRole::fromValue('com'));
        self::assertNull(SiteRole::fromValue(null));
        self::assertNull(SiteRole::fromValue(['me']));
    }
}
