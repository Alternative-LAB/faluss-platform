<?php

declare(strict_types=1);

namespace Faluss\Platform\Catalog;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class CatalogConnectorStub
{
    /** @return list<array{code:string,type:string,label:string}> */
    public static function entitlement_definitions(): array
    {
        return [['code' => 'theme.gold', 'type' => 'theme', 'label' => 'Gold']];
    }
}

final class CatalogEntitlementProviderTest extends TestCase
{
    public function testReportsUnavailableWhenTheConnectorIsAbsent(): void
    {
        self::assertFalse((new CatalogEntitlementProvider())->available());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReadsTheAlreadyLoadedOptionalConnector(): void
    {
        class_alias(CatalogConnectorStub::class, 'Token_Engine_Connector_Service');

        self::assertSame(['theme.gold' => 'Gold'], (new CatalogEntitlementProvider())->available());
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
