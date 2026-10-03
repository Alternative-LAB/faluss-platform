<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Profiles;

use PHPUnit\Framework\TestCase;

final class DiscoveryCursorTest extends TestCase
{
    private const ID = '11111111-1111-4111-8111-111111111111';

    public function testInitialAndLeapDayPositions(): void
    {
        self::assertNull(DiscoveryCursor::decode(null, 'd1.all.'));
        self::assertSame(['date' => '2024-02-29 23:59:59', 'id' => self::ID],
            DiscoveryCursor::decode('d1.arts.2024-02-29T23:59:59.' . self::ID, 'd1.arts.'));
    }

    public function testInvalidDatesCategoryCrossingAndUntrustedValuesFailClosed(): void
    {
        foreach ([false, 0, [], '', str_repeat('x', 129),
            'd1.music.2026-09-01T00:00:00.' . self::ID,
            'd1.arts.2026-02-29T00:00:00.' . self::ID,
            'd1.arts.2026-09-01T24:00:00.' . self::ID,
            'd1.arts.2026-09-01T00:00:00.' . self::ID . "\n",
            "d1.arts.2026-09-01T00:00:00.' OR 1=1 --",
            'd1.arts.2026-09-01T00:00:00.11111111-1111-1111-1111-111111111111'] as $value) {
            self::assertFalse(DiscoveryCursor::decode($value, 'd1.arts.'));
        }
    }
}
