<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Fans\Hof;

use Faluss\Platform\Fans\Hof\RankingCalendar;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RankingCalendarTest extends TestCase
{
    public function testCivilMonthsSpanTheirActualSummerAndWinterTransitions(): void
    {
        $march = RankingCalendar::month('2026-03');
        self::assertSame(['timezone' => 'Europe/Paris', 'start' => '2026-02-28 23:00:00.000000', 'end' => '2026-03-31 22:00:00.000000'], $march);
        self::assertSame(743 * 3600, RankingCalendar::utc($march['end'])->getTimestamp() - RankingCalendar::utc($march['start'])->getTimestamp());
        $october = RankingCalendar::month('2026-10');
        self::assertSame('2026-09-30 22:00:00.000000', $october['start']);
        self::assertSame('2026-10-31 23:00:00.000000', $october['end']);
        self::assertSame(745 * 3600, RankingCalendar::utc($october['end'])->getTimestamp() - RankingCalendar::utc($october['start'])->getTimestamp());
        self::assertSame('2026-03', RankingCalendar::monthOf('2026-03-31 21:59:59.999999'));
        self::assertSame('2026-04', RankingCalendar::monthOf('2026-03-31 22:00:00.000000'));
        self::assertSame('2028-02-29 23:00:00.000000', RankingCalendar::month('2028-02')['end']);
    }

    public function testSessionDatesAreIndependentAndEndExclusiveAtMicrosecondPrecision(): void
    {
        $range = RankingCalendar::session('2026-03-17 15:00:00.123456', '2026-06-15 15:00:00.123456', 'Asia/Tokyo');
        self::assertTrue(RankingCalendar::contains($range['start'], $range['end'], $range['start']));
        self::assertTrue(RankingCalendar::contains($range['start'], $range['end'], '2026-06-15 15:00:00.123455'));
        self::assertFalse(RankingCalendar::contains($range['start'], $range['end'], $range['end']));
        self::assertFalse(RankingCalendar::contains($range['start'], $range['end'], '2026-03-17 15:00:00.123455'));
        $this->expectException(ModelViolation::class);
        RankingCalendar::session($range['start'], '2026-06-15 15:00:00.123457', 'Asia/Tokyo');
    }

    public function testAmbiguousAutumnTimeRequiresItsExplicitOccurrence(): void
    {
        self::assertSame('2026-10-25 00:30:00.123456', RankingCalendar::local('2026-10-25 02:30:00.123456', 'Europe/Paris', 7200));
        self::assertSame('2026-10-25 01:30:00.123456', RankingCalendar::local('2026-10-25 02:30:00.123456', 'Europe/Paris', 3600));
        self::assertSame('2026-10-25 02:30:00.123456', RankingCalendar::local('2026-10-25 02:30:00.123456', 'UTC'));
        $this->expectException(ModelViolation::class);
        $this->expectExceptionMessage('hof_ambiguous_local_time');
        RankingCalendar::local('2026-10-25 02:30:00.123456', 'Europe/Paris');
    }

    public function testSpringGapCannotBeSilentlyShiftedByTheDateParser(): void
    {
        $this->expectException(ModelViolation::class);
        $this->expectExceptionMessage('hof_nonexistent_local_time');
        RankingCalendar::local('2026-03-29 02:30:00.000000', 'Europe/Paris', 3600);
    }

    public function testFalseUtcOffsetDoesNotSelectAnInventedOccurrence(): void
    {
        $this->expectException(ModelViolation::class);
        RankingCalendar::local('2026-10-25 02:30:00.000000', 'Europe/Paris', 0);
    }

    /** @return list<array{string}> */
    public static function invalidMonths(): array { return [['2026-00'], ['2026-13'], ['2026-1'], ['1969-12'], ['9999-12'], ['2026-10 extra']]; }

    #[DataProvider('invalidMonths')]
    public function testInvalidMonthlyBoundariesFailClosed(string $month): void
    {
        $this->expectException(ModelViolation::class);
        RankingCalendar::month($month);
    }

    /** @return list<array{string}> */
    public static function invalidInstants(): array
    {
        return [['2026-02-30 12:00:00.000000'], ['2026-10-06 25:00:00.000000'], ['2026-10-06 12:00:60.000000'],
            ['2026-10-06T12:00:00Z'], ['2026-10-06 12:00:00.000001+02:00'], ['1969-12-31 23:59:59.000000']];
    }

    #[DataProvider('invalidInstants')]
    public function testInvalidOrNoncanonicalInstantsCannotEnterTheCalculation(string $instant): void
    {
        $this->expectException(ModelViolation::class);
        RankingCalendar::utc($instant);
    }

    public function testReverseIntervalAndTimezoneAliasAreRejected(): void
    {
        $this->expectException(ModelViolation::class);
        RankingCalendar::session('2026-10-06 12:00:00.000000', '2026-10-06 13:00:00.000000', '+02:00');
    }

    public function testZeroDurationSessionIsRejected(): void
    {
        $this->expectException(ModelViolation::class);
        RankingCalendar::session('2026-10-06 12:00:00.000000', '2026-10-06 12:00:00.000000', 'Europe/Paris');
    }
}
