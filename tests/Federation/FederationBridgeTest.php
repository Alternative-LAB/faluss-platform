<?php

declare(strict_types=1);

namespace Faluss\Platform\Federation;

use PHPUnit\Framework\TestCase;

final class FederationBridgeFixture
{
    public static function diagnostic(string $value): string
    {
        return 'signed:' . $value;
    }
}

final class FederationBridgeTest extends TestCase
{
    public function testExposesOnlyCheckedDynamicCalls(): void
    {
        self::assertFalse(FederationBridge::isCallable('Missing_Federation_Runtime', 'diagnostic'));
        self::assertTrue(FederationBridge::isCallable(FederationBridgeFixture::class, 'diagnostic'));
        self::assertSame(
            'signed:both-directions',
            FederationBridge::invoke(FederationBridgeFixture::class, 'diagnostic', 'both-directions')
        );
    }
}
