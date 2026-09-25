<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Progression;

use Faluss\Platform\Progression\SupportSimulation;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SupportSimulationTest extends TestCase
{
    public function testReplayCorrectionsAndDeliveryOrderDoNotDuplicateScore(): void
    {
        $paid = $this->fact();
        $refund = array_replace($paid, ['revision' => 2, 'refunded_cents' => 300]);
        $disputed = array_replace($refund, ['revision' => 3, 'status' => 'disputed']);
        $net = SupportSimulation::project([$paid, $paid, $refund]);
        self::assertSame(700, $net['member_contributions'][$paid['member']]);
        self::assertSame(700, $net['creator_scores'][$paid['creator']]);
        self::assertSame($net, SupportSimulation::project([$refund, $paid, $refund]));
        self::assertSame(0, SupportSimulation::project([$disputed, $paid, $refund])['creator_scores'][$paid['creator']]);
        $restored = array_replace($refund, ['revision' => 4]);
        self::assertSame(700, SupportSimulation::project([$restored, $paid, $disputed])['creator_scores'][$paid['creator']]);
        self::assertTrue($net['simulation']);
    }

    public function testInvalidEconomicSourcesNeverScore(): void
    {
        foreach (['pf_purchase', 'earned_pf', 'promotional_pf', 'cosmetic'] as $source) {
            self::assertSame(0, array_sum(SupportSimulation::project([array_replace($this->fact(), ['source' => $source])])['creator_scores']));
        }
        foreach (['pending', 'failed', 'refunded', 'disputed'] as $status) {
            self::assertSame(0, array_sum(SupportSimulation::project([array_replace($this->fact(), ['status' => $status])])['member_contributions']));
        }
        self::assertSame(0, array_sum(SupportSimulation::project([array_replace($this->fact(), ['category' => 'external_adult_delivery_right'])])['creator_scores']));
        self::assertSame(0, array_sum(SupportSimulation::project([array_replace($this->fact(), ['refunded_cents' => 1000])])['creator_scores']));
    }

    public function testConflictingRevisionFailsClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SupportSimulation::project([$this->fact(), array_replace($this->fact(), ['status' => 'failed'])]);
    }

    public function testCorrectionCannotChangeOriginalBeneficiary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SupportSimulation::project([$this->fact(), array_replace($this->fact(), ['revision' => 2, 'creator' => $this->fact()['member']])]);
    }

    public function testExtraProofClaimDoesNotAuthenticateAFact(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SupportSimulation::project([array_replace($this->fact(), ['verified' => true])]);
    }

    public function testInvalidRefundFailsClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SupportSimulation::project([array_replace($this->fact(), ['refunded_cents' => 1001])]);
    }

    public function testNewRevisionCannotEraseAnEarlierRefund(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SupportSimulation::project([
            array_replace($this->fact(), ['revision' => 3]),
            array_replace($this->fact(), ['revision' => 2, 'refunded_cents' => 300]),
        ]);
    }

    /** @return array<string,mixed> */
    private function fact(): array
    {
        return [
            'version' => '1.0.0', 'owner' => 'faluss-fans',
            'reference' => '11111111-1111-4111-8111-111111111111', 'revision' => 1,
            'member' => '22222222-2222-4222-8222-222222222222',
            'creator' => '33333333-3333-4333-8333-333333333333',
            'category' => 'hosted_allowed_content', 'source' => 'eur_support',
            'amount_cents' => 1000, 'refunded_cents' => 0, 'status' => 'confirmed',
            'occurred_at' => 1800000000,
        ];
    }
}
