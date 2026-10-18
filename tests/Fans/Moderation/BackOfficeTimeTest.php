<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Fans\Moderation;

use Faluss\Platform\Fans\Moderation\BackOfficeTime;
use PHPUnit\Framework\TestCase;

final class BackOfficeTimeTest extends TestCase
{
    public function testUtcInstantsFollowTheActualSummerAndWinterOffset(): void
    {
        $zone = new \DateTimeZone('Europe/Paris');
        self::assertSame('06/10/2026 à 10:00:01 +02:00 · Europe/Paris', BackOfficeTime::utc('2026-10-06 08:00:01', $zone));
        self::assertSame('06/12/2026 à 09:00:01 +01:00 · Europe/Paris', BackOfficeTime::utc('2026-12-06 08:00:01.123456', $zone));
        self::assertSame('29/03/2026 à 01:59:59 +01:00 · Europe/Paris', BackOfficeTime::utc('2026-03-29 00:59:59', $zone));
        self::assertSame('29/03/2026 à 03:00:00 +02:00 · Europe/Paris', BackOfficeTime::utc('2026-03-29 01:00:00', $zone));
        self::assertSame('25/10/2026 à 02:30:00 +02:00 · Europe/Paris', BackOfficeTime::utc('2026-10-25 00:30:00', $zone));
        self::assertSame('25/10/2026 à 02:30:00 +01:00 · Europe/Paris', BackOfficeTime::utc('2026-10-25 01:30:00', $zone));
    }

    public function testConfiguredFixedOffsetAndEpochUseTheSameInstant(): void
    {
        $instant = new \DateTimeImmutable('2026-10-06 08:00:01', new \DateTimeZone('UTC'));
        $zone = new \DateTimeZone('+05:30');
        $expected = '06/10/2026 à 13:30:01 +05:30 · +05:30';
        self::assertSame($expected, BackOfficeTime::utc('2026-10-06 08:00:01', $zone));
        self::assertSame($expected, BackOfficeTime::timestamp($instant->getTimestamp(), $zone));
        self::assertSame('06/10/2026 à 08:00:01 +00:00 · UTC', BackOfficeTime::utc('2026-10-06 08:00:01', new \DateTimeZone('UTC')));
    }

    public function testMissingAndInvalidValuesNeverInventAValidDeadline(): void
    {
        $zone = new \DateTimeZone('Europe/Paris');
        self::assertSame('Non renseigné', BackOfficeTime::utc(null, $zone));
        self::assertSame('Non renseigné', BackOfficeTime::timestamp(null, $zone));
        foreach (['', '0000-00-00 00:00:00', '2026-02-30 12:00:00', '2026-10-06 25:00:00', '2026-10-06T08:00:01Z', '2026-10-06 08:00:01+02:00'] as $invalid) {
            self::assertSame('Date indisponible', BackOfficeTime::utc($invalid, $zone));
        }
    }

    public function testJournalFormattingDoesNotChangeItsSourceOrNonDateValues(): void
    {
        $row = ['actor_id' => '42', 'reason' => '2026-10-06 08:00:01', 'created_at' => '2026-10-06 08:00:01', 'until_at' => null];
        $before = $row;
        $display = BackOfficeTime::journal($row, new \DateTimeZone('Europe/Paris'));
        self::assertSame($before, $row);
        self::assertSame(array_keys($before), array_keys($display));
        self::assertSame($row['actor_id'], $display['actor_id']);
        self::assertSame($row['reason'], $display['reason']);
        self::assertSame('06/10/2026 à 10:00:01 +02:00 · Europe/Paris', $display['created_at']);
        self::assertSame('Non renseigné', $display['until_at']);
    }
}
