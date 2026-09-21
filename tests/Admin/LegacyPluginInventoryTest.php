<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Admin;

use Faluss\Platform\Admin\LegacyPluginInventory;
use Faluss\Platform\Core\SiteRole;
use PHPUnit\Framework\TestCase;

final class LegacyPluginInventoryTest extends TestCase
{
    public function testMeInventoryTracksOnlyKnownLocalPlugins(): void
    {
        $inventory = new LegacyPluginInventory(SiteRole::Me, [
            'faluss-theme/faluss-theme.php',
            'faluss-identity/faluss-identity.php',
            'faluss-portal/faluss-portal.php',
            'faluss-platform/faluss-platform.php',
        ]);

        $rows = $inventory->rows();
        self::assertCount(8, $rows);
        self::assertSame(2, $inventory->activeCount());
        self::assertContains('Faluss Theme', array_column($rows, 'name'));
        self::assertNotContains('Faluss Portal', array_column($rows, 'name'));
    }

    public function testHubInventoryTracksOnlyKnownLocalPlugins(): void
    {
        $inventory = new LegacyPluginInventory(SiteRole::Hub, [
            'faluss-portal/faluss-portal.php',
            'token-engine/token-engine.php',
        ]);

        $rows = $inventory->rows();
        self::assertCount(8, $rows);
        self::assertSame(2, $inventory->activeCount());
        self::assertContains('Faluss Portal', array_column($rows, 'name'));
        self::assertNotContains('Faluss Theme', array_column($rows, 'name'));
    }
}
