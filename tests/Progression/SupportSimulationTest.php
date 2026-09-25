<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Progression;

use Faluss\Platform\Progression\SupportSimulation;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SupportSimulationTest extends TestCase
{
    public function testUnitsPackAndConsumedAllocationNeverDoubleCount(): void
    {
        $gift = $this->gift();
        $pack = array_replace($gift, ['reference' => $this->id(4), 'source' => 'pack_purchase', 'allocation_reference' => null]);
        $this->assertTotals([$pack], 0, 0);
        $this->assertTotals([$gift], 30000, 725);
        $this->assertTotals([$pack, $gift, $pack, $gift], 30000, 725);
        $this->assertTotals([array_replace($gift, ['amount_cents' => 999])], 30000, 999);
        $result = SupportSimulation::project([$gift]);
        self::assertSame(['simulation', 'policy', 'donor_consumed_eur_cents', 'creator_score_centipoints'], array_keys($result));
        self::assertTrue($result['simulation']);
        self::assertSame('fans.support-simulation/2.0.0', $result['policy']);
    }

    public function testDirectEuroSupportAndPartialRefundUseIntegerHundredths(): void
    {
        $paid = $this->direct();
        $this->assertTotals([$paid], 125, 125);
        $refund = array_replace($paid, ['revision' => 2, 'refunded_cents' => 25]);
        $this->assertTotals([$paid, $refund], 100, 100);
        self::assertSame(SupportSimulation::project([$paid, $refund]), SupportSimulation::project([$refund, array_reverse($paid, true), $refund]));
        $this->assertTotals([array_replace($refund, ['revision' => 3, 'refunded_cents' => 125])], 0, 0);
    }

    public function testGiftRefundsHaveIndependentCoinAndEuroAllocations(): void
    {
        $gift = $this->gift();
        $refund = array_replace($gift, ['revision' => 2, 'refunded_coins' => 100, 'refunded_cents' => 200]);
        $this->assertTotals([$refund, $gift, $refund], 20000, 525);
        $this->assertTotals([array_replace($refund, ['revision' => 3, 'refunded_coins' => 300, 'refunded_cents' => 725])], 0, 0);
    }

    public function testClosedSessionRecalculatesCorrectionsAndDisputes(): void
    {
        $paid = $this->gift();
        $session = ['start' => 1799999900, 'end' => 1800000100, 'closed' => true];
        self::assertSame(30000, array_sum(SupportSimulation::project([$paid], $session)['creator_score_centipoints']));
        $refund = array_replace($paid, ['revision' => 2, 'refunded_coins' => 100, 'refunded_cents' => 200]);
        $dispute = array_replace($refund, ['revision' => 3, 'status' => 'disputed']);
        $this->assertTotals([$paid, $refund], 20000, 525, $session);
        $this->assertTotals([$dispute, $paid, $refund], 0, 0, $session);
        $restored = array_replace($refund, ['revision' => 4]);
        $this->assertTotals([$restored, $paid, $dispute, $refund], 20000, 525, $session);
        self::assertSame(SupportSimulation::project([$restored, $paid], $session), SupportSimulation::project([$paid, $restored], array_replace($session, ['closed' => false])));
        $this->assertTotals([$paid], 0, 725, ['start' => 1799999900, 'end' => 1800000000, 'closed' => true]);
        $this->assertTotals([$paid], 30000, 725, ['start' => 1800000000, 'end' => 1800000001, 'closed' => false]);
    }

    public function testFreeGiftsAndClosedStatesNeverProduceMonetizableScore(): void
    {
        foreach (['none', 'earned', 'promotional'] as $class) {
            $this->assertTotals([array_replace($this->gift(), ['source' => 'free_gift', 'funding' => 'none', 'economic_class' => $class, 'amount_cents' => 0, 'allocation_reference' => null])], 0, 0);
        }
        foreach (['pending', 'failed', 'refunded', 'disputed'] as $status) {
            $this->assertTotals([array_replace($this->gift(), ['status' => $status])], 0, 0);
        }
        $this->assertTotals([array_replace($this->gift(), ['category' => 'external_adult_delivery_right'])], 0, 0);
    }

    public function testFalussFinancingRemainsExplicitlyUnsupportedWithoutAllocationPolicy(): void
    {
        $this->expectExceptionMessage('Faluss funded gift allocation policy remains undefined.');
        SupportSimulation::project([array_replace($this->gift(), ['source' => 'free_gift', 'funding' => 'faluss', 'economic_class' => 'promotional', 'amount_cents' => 0, 'allocation_reference' => null])]);
    }

    public function testInvalidShapesAndEconomicClaimsFailClosed(): void
    {
        foreach ([['version' => '1.0.0'], ['verified' => true], ['coins' => 1.5], ['amount_cents' => -1],
            ['refunded_coins' => 301], ['refunded_cents' => 726], ['allocation_reference' => null],
            ['economic_class' => 'earned'], ['economic_class' => 'promotional'], ['funding' => 'none'],
            ['source' => 'cosmetic'], ['owner' => 'unknown'], ['creator' => 'invalid'],
        ] as $change) {
            $this->assertInvalid([array_replace($this->gift(), $change)]);
        }
    }

    public function testConflictsImmutableFieldsAndRefundRegressionsFailClosed(): void
    {
        $gift = $this->gift();
        $this->assertInvalid([$gift, array_replace($gift, ['status' => 'failed'])]);
        foreach (['creator' => $this->id(8), 'amount_cents' => 800, 'coins' => 400, 'occurred_at' => 1800000001, 'allocation_reference' => $this->id(9)] as $field => $value) {
            $this->assertInvalid([$gift, array_replace($gift, ['revision' => 2, $field => $value])]);
        }
        foreach (['refunded_coins' => 10, 'refunded_cents' => 20] as $field => $value) {
            $this->assertInvalid([array_replace($gift, ['revision' => 3]), array_replace($gift, ['revision' => 2, $field => $value])]);
        }
    }

    public function testConsumedSliceCannotBeReusedUnderAnotherBusinessReference(): void
    {
        $this->assertInvalid([$this->gift(), array_replace($this->gift(), ['reference' => $this->id(9)])]);
    }

    /** @param list<array<string,mixed>> $facts */
    private function assertInvalid(array $facts): void
    {
        try {
            SupportSimulation::project($facts);
            self::fail('Invalid fixture accepted.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    /** @param list<array<string,mixed>> $facts
     * @param array{start:int,end:int,closed:bool}|null $session
     */
    private function assertTotals(array $facts, int $score, int $spent, ?array $session = null): void
    {
        $result = SupportSimulation::project($facts, $session);
        self::assertSame($score, array_sum($result['creator_score_centipoints']));
        self::assertSame($spent, array_sum($result['donor_consumed_eur_cents']));
    }

    private function id(int $n): string { return sprintf('11111111-1111-4111-8111-%012d', $n); }

    /** @return array<string,mixed> */
    private function direct(): array
    {
        return array_replace($this->gift(), ['source' => 'direct_eur_support', 'economic_class' => 'none', 'coins' => 0, 'amount_cents' => 125, 'allocation_reference' => null]);
    }

    /** @return array<string,mixed> */
    private function gift(): array
    {
        return [
            'version' => '2.0.0', 'owner' => 'faluss-fans', 'reference' => $this->id(1), 'revision' => 1,
            'member' => $this->id(2), 'creator' => $this->id(3), 'category' => 'hosted_allowed_content',
            'source' => 'funded_coin_gift', 'economic_class' => 'funded', 'funding' => 'donor',
            'allocation_reference' => $this->id(5), 'coins' => 300, 'amount_cents' => 725,
            'refunded_coins' => 0, 'refunded_cents' => 0, 'status' => 'confirmed', 'occurred_at' => 1800000000,
        ];
    }
}
