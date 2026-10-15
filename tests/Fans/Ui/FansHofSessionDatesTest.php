<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Fans\Ui;

use Faluss\Platform\Fans\Ui\FansUiSessionDates;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use PHPUnit\Framework\TestCase;

final class FansHofSessionDatesTest extends TestCase
{
    public function testNativeMinuteAndSecondFieldsUseTheirExplicitTimezone(): void
    {
        self::assertSame('2026-01-10 09:30:00.000000', FansUiSessionDates::utc('2026-01-10T10:30', 'Europe/Paris'));
        self::assertSame('2026-07-10 08:30:45.000000', FansUiSessionDates::utc('2026-07-10T10:30:45', 'Europe/Paris'));
        self::assertSame('2026-07-10T10:30:45', FansUiSessionDates::localValue('2026-07-10 08:30:45.123456', 'Europe/Paris'));
        self::assertSame('+02:00', FansUiSessionDates::offsetValue('2026-07-10 08:30:45.123456', 'Europe/Paris'));
    }

    public function testAnUnchangedNativeFieldPreservesThePreviousSubsecondInstant(): void
    {
        $previous = '2026-07-10 08:30:45.123456';
        self::assertSame($previous, FansUiSessionDates::utc('2026-07-10T10:30:45', 'Europe/Paris', '', $previous, 'Europe/Paris'));
        self::assertSame('2026-07-10 08:31:45.000000', FansUiSessionDates::utc('2026-07-10T10:31:45', 'Europe/Paris', '', $previous, 'Europe/Paris'));
    }

    public function testTheAutumnAmbiguityRequiresAChoiceForANewInstant(): void
    {
        self::assertSame(['+02:00', '+01:00'], FansUiSessionDates::choices('2026-10-25T02:30', 'Europe/Paris'));
        self::assertSame('2026-10-25 00:30:00.000000', FansUiSessionDates::utc('2026-10-25T02:30', 'Europe/Paris', '+02:00'));
        self::assertSame('2026-10-25 01:30:00.000000', FansUiSessionDates::utc('2026-10-25T02:30', 'Europe/Paris', '+01:00'));
        $this->expectException(ModelViolation::class);
        FansUiSessionDates::utc('2026-10-25T02:30', 'Europe/Paris');
    }

    public function testTheOwnerCanExplicitlyChangeAnExistingAmbiguousOffset(): void
    {
        self::assertSame('2026-10-25 00:30:00.000000', FansUiSessionDates::utc('2026-10-25T02:30', 'Europe/Paris', '+02:00', '2026-10-25 01:30:00.123456', 'Europe/Paris'));
    }

    public function testGapsInvalidDatesOffsetsAndZonesNeverNormalizeSilently(): void
    {
        foreach ([['2026-03-29T02:30', 'Europe/Paris', ''], ['2026-02-30T10:00', 'Europe/Paris', ''],
            ['2026-07-10T10:30', 'Europe/Paris', '+01:00'], ['2026-07-10T10:30', 'Europe/Paris', '+99:00'],
            ['2026-07-10T10:30', 'Unknown/Zone', ''], ['2026-07-10T10:30Z', 'UTC', '']] as [$local, $zone, $offset]) {
            try { FansUiSessionDates::utc($local, $zone, $offset); self::fail('Invalid native date accepted'); }
            catch (ModelViolation) { self::assertTrue(true); }
        }
        self::assertSame([], FansUiSessionDates::choices('2026-03-29T02:30', 'Europe/Paris'));
    }
}
