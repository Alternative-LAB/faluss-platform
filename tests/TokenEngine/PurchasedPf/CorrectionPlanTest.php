<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\CorrectionPlan;
use Faluss\Platform\TokenEngine\PurchasedPf\ClosedCorrectionSchema;
use Faluss\Platform\TokenEngine\PurchasedPf\ClosedCorrectionStore;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class CorrectionPlanTest extends TestCase
{
    private const LOT = '11111111-1111-4111-8111-111111111111';
    private const A = '22222222-2222-4222-8222-222222222222';
    private const B = '33333333-3333-4333-8333-333333333333';

    /** @return list<array{attribution_id:string,lot_id:string,purchased_pf:string,confirmed_at:string,cancelled_pf:string}> */
    private function allocations(): array
    {
        return [['attribution_id' => self::A, 'lot_id' => self::LOT, 'purchased_pf' => '25',
            'confirmed_at' => '2026-10-05 10:00:00.000000', 'cancelled_pf' => '0'],
            ['attribution_id' => self::B, 'lot_id' => self::LOT, 'purchased_pf' => '15',
                'confirmed_at' => '2026-10-05 10:00:01.000000', 'cancelled_pf' => '0']];
    }

    public function testValidatedProductExampleAndPartialLatestAllocation(): void
    {
        $available = CorrectionPlan::build('100', '30', false, $this->allocations());
        self::assertSame('30', $available['available_cancelled_pf']);
        self::assertSame(['15', '25'], array_column($available['rows'], 'net_pf'));
        $partial = CorrectionPlan::build('100', '70', false, $this->allocations());
        self::assertSame(['5', '25'], array_column($partial['rows'], 'net_pf'));
        $eighty = CorrectionPlan::build('100', '80', false, $this->allocations());
        self::assertSame('60', $eighty['available_cancelled_pf']);
        self::assertSame(['0', '20'], array_column($eighty['rows'], 'net_pf'));
        $ninety = CorrectionPlan::build('100', '90', false, $this->allocations());
        self::assertSame(['0', '10'], array_column($ninety['rows'], 'net_pf'));
    }

    public function testDisputeSuspendsOnlyNetAndResolutionRestoresOnlyNet(): void
    {
        $plan = CorrectionPlan::build('100', '80', true, $this->allocations());
        self::assertSame(['0', '0'], array_column($plan['rows'], 'net_pf'));
        self::assertSame(['0', '20'], array_column($plan['rows'], 'suspended_pf'));
        self::assertSame(['15', '5'], array_column($plan['rows'], 'cancelled_pf'));
    }

    public function testEqualConfirmationDatesUseStableDescendingBinaryAttribution(): void
    {
        $rows = $this->allocations();
        $rows[1]['confirmed_at'] = $rows[0]['confirmed_at'];
        self::assertSame([self::B, self::A], array_column(CorrectionPlan::build('100', '70', false, $rows)['rows'], 'attribution_id'));
        self::assertSame(CorrectionPlan::build('100', '70', false, $rows), CorrectionPlan::build('100', '70', false, array_reverse($rows)));
    }

    public function testOriginalBoundsAndDuplicateOrRegressingFiliationFailClosed(): void
    {
        $cases = [['39', '0', $this->allocations()], ['100', '101', []], ['100', '10', [$this->allocations()[0], $this->allocations()[0]]]];
        $regressing = $this->allocations();
        $regressing[1]['cancelled_pf'] = '15';
        $cases[] = ['100', '60', $regressing];
        foreach ($cases as [$original, $cancelled, $rows]) {
            try {
                CorrectionPlan::build($original, $cancelled, false, $rows);
                self::fail('An invalid plan must not produce corrections.');
            } catch (ModelViolation) {
                self::assertTrue(true);
            }
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNormalSiteCannotConstructOrInstallBeforeQuery(): void
    {
        eval('namespace { class wpdb { public int $queries=0; public function get_var($q) { $this->queries++; return null; } } }');
        $database = new \wpdb();
        foreach ([static fn () => ClosedCorrectionSchema::installForRecipe($database),
            static fn () => new ClosedCorrectionStore($database, ['fixture.purchase'])] as $action) {
            try {
                $action();
                self::fail('H4 must remain unreachable on normal sites.');
            } catch (ModelViolation $error) {
                self::assertSame('closed_h4_recipe_required', $error->reason);
            }
        }
        self::assertSame(0, $database->queries);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCopiedH4MarkerCannotBypassPhysicalH1Isolation(): void
    {
        define('FALUSS_PF_H4_RECIPE', true);
        eval('namespace { class wpdb { public int $queries=0; public function get_var($q) { $this->queries++; return null; } } }');
        $database = new \wpdb();
        try {
            ClosedCorrectionSchema::installForRecipe($database);
            self::fail('A marker does not authorize an ordinary site migration.');
        } catch (ModelViolation $error) {
            self::assertSame('isolated_h1_recipe_required', $error->reason);
        }
        self::assertSame(0, $database->queries);
    }
}
