<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\ClosedReservationDatabase;
use Faluss\Platform\TokenEngine\PurchasedPf\ClosedReservationSchema;
use Faluss\Platform\TokenEngine\PurchasedPf\ClosedReservationStore;
use Faluss\Platform\TokenEngine\PurchasedPf\ClosedConsumptionSchema;
use Faluss\Platform\TokenEngine\PurchasedPf\ClosedConsumptionStore;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class ClosedReservationGuardTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNormalWordPressCannotInstallOrConstructEvenWithSyntheticAuthority(): void
    {
        eval('namespace { class wpdb { public int $queries=0; public function get_var($q) { $this->queries++; return null; } } }');
        $database = new \wpdb();
        foreach ([static fn () => ClosedReservationSchema::installForRecipe($database),
            static fn () => new ClosedReservationStore($database, ['fixture.fans'], ['fixture.purchase']),
            static fn () => new ClosedReservationDatabase($database),
            static fn () => ClosedConsumptionSchema::installForRecipe($database),
            static fn () => new ClosedConsumptionStore($database, ['fixture.fans'], ['fixture.purchase'])] as $action) {
            try {
                $action();
                self::fail('An ordinary site must fail before its first query.');
            } catch (ModelViolation $error) {
                self::assertSame('isolated_h1_recipe_required', $error->reason);
            }
        }
        self::assertSame(0, $database->queries);
    }

    public function testLockCoordinatesWithHistoricalFundedWriterWithoutChangingEarnedClaims(): void
    {
        $member = '11111111-1111-4111-8111-111111111111';
        self::assertSame('token_engine_pf_' . substr(hash('sha256', $member . '|funded'), 0, 32), ClosedReservationDatabase::subjectLock($member));
        self::assertNotSame('token_engine_pf_' . substr(hash('sha256', $member . '|earned'), 0, 32), ClosedReservationDatabase::subjectLock($member));
    }
}
