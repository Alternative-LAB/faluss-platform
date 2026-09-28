<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Progression;

use Faluss\Platform\Progression\HofSimulationV3;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class HofSimulationV3Test extends TestCase
{
    public function testPackScoresZeroAndOnePurchasedAllocatedPfScoresOnePoint(): void
    {
        $pack = $this->pack();
        self::assertSame([], $this->calculate([$pack], [$this->projection([$pack['reference']])])['creator_points'][$this->id(10)]);
        foreach ([1, 300] as $pf) {
            $gift = array_replace($this->gift(), ['pf' => $pf]);
            $result = $this->calculate([$pack, $gift], [$this->projection([$pack['reference'], $gift['reference']])]);
            self::assertSame($pf, $result['creator_points'][$this->id(10)][$this->id(3)]);
            self::assertSame(1, $result['fixture_receipt_count']);
        }
    }

    public function testMultipleProjectionsAndDerivedReplaysDoNotConsumeAgainOrRewardPc(): void
    {
        $gift = $this->gift();
        $first = $this->projection([$gift['reference'], $gift['reference']]);
        $second = array_replace($first, ['reference' => $this->id(11), 'dimension' => $this->id(21)]);
        $events = [];
        foreach (['badge_notice', 'ranking_notice', 'refund_notice'] as $i => $kind) {
            $events[] = ['reference' => $this->id(30 + $i), 'fact_reference' => $gift['reference'], 'kind' => $kind];
        }
        $expected = $this->calculate([$gift], [$first, $second]);
        self::assertSame($expected, $this->calculate([$gift, array_reverse($gift, true)], [$second, $first, $second], [...$events, ...$events]));
        self::assertSame(1, $expected['fixture_receipt_count']);
        self::assertSame(300, $expected['creator_points'][$this->id(10)][$this->id(3)]);
        self::assertSame(300, $expected['creator_points'][$this->id(11)][$this->id(3)]);
        self::assertSame(['simulation', 'policy', 'creator_points', 'pc_delta', 'fixture_receipt_count', 'revisions'], array_keys($expected));
        self::assertSame('fans.hof-simulation/3.0.0', $expected['policy']);
    }

    public function testPartialAndTotalCorrectionsReprojectEveryClosedSession(): void
    {
        $gift = $this->gift();
        $partial = array_replace($gift, ['revision' => 2, 'cancelled_pf' => 100]);
        $projections = [$this->projection([$gift['reference']]), array_replace($this->projection([$gift['reference']]), [
            'reference' => $this->id(11), 'session' => $this->id(22), 'closed' => true,
        ])];
        $result = $this->calculate([$partial, $gift, $partial], $projections);
        foreach ($result['creator_points'] as $points) {
            self::assertSame([$this->id(3) => 200], $points);
        }
        $total = array_replace($partial, ['revision' => 3, 'status' => 'refunded', 'cancelled_pf' => 300]);
        foreach ($this->calculate([$total, $gift, $partial], $projections)['creator_points'] as $points) {
            self::assertSame([$this->id(3) => 0], $points);
        }
        // A later resolution cannot undo a cumulative total cancellation.
        $resolved = array_replace($total, ['revision' => 4, 'status' => 'confirmed']);
        self::assertSame(0, $this->calculate([$resolved, $total], $projections)['creator_points'][$this->id(10)][$this->id(3)]);
    }

    public function testDisputeAndLateResolutionUseLatestRevisionNotArrivalOrder(): void
    {
        $gift = $this->gift();
        $disputed = array_replace($gift, ['revision' => 2, 'status' => 'disputed', 'cancelled_pf' => 50]);
        $projection = [$this->projection([$gift['reference']])];
        self::assertSame(0, $this->calculate([$disputed, $gift], $projection)['creator_points'][$this->id(10)][$this->id(3)]);
        $resolved = array_replace($disputed, ['revision' => 3, 'status' => 'confirmed']);
        $result = $this->calculate([$resolved, $gift, $disputed, $gift], $projection);
        self::assertSame(250, $result['creator_points'][$this->id(10)][$this->id(3)]);
        self::assertSame([$gift['reference'] => 3], $result['revisions']);
        self::assertSame($result, $this->calculate([$gift, $disputed, $resolved], $projection));
        foreach (['pending', 'failed'] as $status) {
            self::assertSame(0, $this->calculate([array_replace($gift, ['status' => $status])], $projection)['creator_points'][$this->id(10)][$this->id(3)]);
        }
    }

    public function testPackRefundDoesNotInventAllocationCorrections(): void
    {
        $pack = array_replace($this->pack(), ['status' => 'refunded', 'cancelled_pf' => 300]);
        $gift = $this->gift();
        $result = $this->calculate([$pack, $gift], [$this->projection([$pack['reference'], $gift['reference']])]);
        self::assertSame(300, $result['creator_points'][$this->id(10)][$this->id(3)]);
        self::assertSame(1, $result['fixture_receipt_count']);
    }

    public function testIneligibleSourcesMissingProofsAndPrivateFieldsAreRejected(): void
    {
        foreach ([
            ['source' => 'direct_eur_support'], ['source' => 'free_gift'],
            ['economic_class' => 'earned'], ['economic_class' => 'promotional'],
            ['purchase_attestation' => null], ['consumption_receipt' => null],
            ['creator' => $this->gift()['member']], ['version' => '2.0.0'],
            ['pf' => 1.5], ['pf' => 0], ['pf' => 1000001], ['cancelled_pf' => 301],
            ['status' => 'refunded'], ['revision' => 0], ['creator' => 'invalid'],
            ['revenue' => 12], ['amount_cents' => 12], ['pc_delta' => 5], ['suspended' => true],
        ] as $change) {
            $this->invalid([array_replace($this->gift(), $change)]);
        }
        $this->invalid([array_replace($this->pack(), ['creator' => $this->id(3)])]);
    }

    public function testConflictsReceiptReuseAndDecreasingCorrectionsAreRejected(): void
    {
        $gift = $this->gift();
        $this->invalid([$gift, array_replace($gift, ['status' => 'disputed'])]);
        foreach (['member', 'creator', 'purchase_attestation', 'consumption_receipt'] as $field) {
            $this->invalid([$gift, array_replace($gift, ['revision' => 2, $field => $this->id(90)])]);
        }
        $this->invalid([$gift, array_replace($gift, ['revision' => 2, 'pf' => 400])]);
        $this->invalid([array_replace($gift, ['revision' => 2, 'cancelled_pf' => 10]), array_replace($gift, ['revision' => 3])]);
        $this->invalid([$gift, array_replace($gift, ['reference' => $this->id(90)])]);
    }

    public function testProjectionAndEventInjectionFailClosed(): void
    {
        $gift = $this->gift();
        $projection = $this->projection([$gift['reference']]);
        foreach ([['facts' => [$this->id(99)]], ['winner' => true], ['closed' => 'yes']] as $change) {
            $this->invalid([$gift], [array_replace($projection, $change)]);
        }
        $this->invalid([$gift], [$projection, array_replace($projection, ['dimension' => $this->id(88)])]);
        $event = ['reference' => $this->id(30), 'fact_reference' => $gift['reference'], 'kind' => 'badge_notice'];
        foreach ([['kind' => 'pc_reward'], ['pc_delta' => 100], ['fact_reference' => $this->id(99)]] as $change) {
            $this->invalid([$gift], [], [array_replace($event, $change)]);
        }
        $this->invalid([$gift], [], [$event, array_replace($event, ['kind' => 'refund_notice'])]);
        $this->invalid(array_fill(0, 1001, $gift));
    }

    /** @param array<mixed> $facts @param array<mixed> $projections @param array<mixed> $events */
    private function invalid(array $facts, array $projections = [], array $events = []): void
    {
        try {
            HofSimulationV3::project($facts, $projections, $events);
            self::fail('Invalid fixture accepted.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    /**
     * @param array<mixed> $facts
     * @param array<mixed> $projections
     * @param array<mixed> $events
     * @return array<string,mixed>
     */
    private function calculate(array $facts, array $projections, array $events = []): array
    {
        $result = HofSimulationV3::project($facts, $projections, $events);
        self::assertSame(0, $result['pc_delta']);
        return $result;
    }

    /** @return array<string,mixed> */
    private function gift(): array
    {
        return [
            'version' => '3.0.0', 'reference' => $this->id(1), 'revision' => 1,
            'member' => $this->id(2), 'creator' => $this->id(3), 'source' => 'purchased_pf_allocation',
            'economic_class' => 'funded', 'purchase_attestation' => $this->id(4),
            'consumption_receipt' => $this->id(5), 'pf' => 300, 'cancelled_pf' => 0, 'status' => 'confirmed',
        ];
    }

    /** @return array<string,mixed> */
    private function pack(): array
    {
        return array_replace($this->gift(), ['reference' => $this->id(6), 'source' => 'purchased_pf_pack', 'creator' => null, 'consumption_receipt' => null]);
    }

    /** @param list<string> $facts @return array<string,mixed> */
    private function projection(array $facts): array
    {
        return ['reference' => $this->id(10), 'session' => $this->id(20), 'dimension' => $this->id(21), 'closed' => false, 'facts' => $facts];
    }

    private function id(int $n): string
    {
        return sprintf('11111111-1111-4111-8111-%012d', $n);
    }
}
