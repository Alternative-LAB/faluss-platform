<?php

declare(strict_types=1);

namespace Faluss\Platform\Catalog;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class CatalogEntitlementProviderTest extends TestCase
{
    public function testReportsUnavailableWhenTheConnectorIsAbsent(): void
    {
        self::assertFalse((new CatalogEntitlementProvider())->available());
    }

    public function testKeepsOnlyValidThemeEntitlements(): void
    {
        $definitions = CatalogEntitlementProvider::filter([
            ['code' => 'THEME.GOLD', 'type' => 'theme', 'label' => '<b>Gold</b>'],
            ['code' => 'subscription.pro', 'type' => 'subscription', 'label' => 'Pro'],
            ['code' => 'bad code', 'type' => 'theme', 'label' => 'Invalid'],
            ['code' => 'theme.basic', 'type' => 'theme', 'label' => ''],
            'malformed',
        ]);

        self::assertSame(['theme.gold' => 'Gold', 'theme.basic' => 'theme.basic'], $definitions);
    }
}
