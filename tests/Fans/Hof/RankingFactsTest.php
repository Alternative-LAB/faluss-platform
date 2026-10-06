<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Fans\Hof;

use Faluss\Platform\Fans\Hof\RankingFacts;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use PHPUnit\Framework\TestCase;

final class RankingFactsTest extends TestCase
{
    private function id(int $id): string { return sprintf('%08x-1111-4111-8111-111111111111', $id); }

    /** @return array<string,string> */
    private function fact(int $id, int $creator, int $original, int $net, string $time, int $order): array
    {
        return ['attribution_id' => $this->id($id), 'creator_faluss_id' => $this->id($creator),
            'consumption_id' => $this->id($id + 100), 'ledger_entry_uuid' => $this->id($id + 200),
            'original_pf' => (string) $original, 'net_pf' => (string) $net,
            'confirmed_at' => '2026-10-06 ' . $time . '.000000', 'consumption_order' => (string) $order];
    }

    /** @param list<array<string,string>> $facts
     * @return array{member_faluss_id:string,revision:string,ordering_epoch:string,attributions:list<array<string,string>>} */
    private function source(int $member, array $facts, int $revision = 1): array
    {
        return ['member_faluss_id' => $this->id($member), 'revision' => (string) $revision,
            'ordering_epoch' => $this->id(999), 'attributions' => $facts];
    }

    public function testSameScoreUsesUniquePlacesAndOwnerConfirmationForBothFamilies(): void
    {
        $early = $this->source(10, [$this->fact(21, 30, 10, 10, '09:00:00', 1)]);
        $late = $this->source(11, [$this->fact(22, 31, 10, 10, '09:05:00', 2)]);
        $result = RankingFacts::build([$late, $early]);
        self::assertSame([$this->id(10), $this->id(11)], array_column($result['fans'], 'faluss_id'));
        self::assertSame([$this->id(30), $this->id(31)], array_column($result['creators'], 'faluss_id'));
        self::assertSame(['1', '2'], array_column($result['fans'], 'position'));
        self::assertSame($result, RankingFacts::build([$early, $late, $early]));
    }

    public function testPartialRefundReconstructsTheDateFromOnlyTheRemainingContributions(): void
    {
        $a = $this->source(10, [$this->fact(21, 30, 10, 10, '09:00:00', 1), $this->fact(22, 30, 5, 3, '10:00:00', 3)], 2);
        $b = $this->source(11, [$this->fact(23, 31, 13, 13, '09:30:00', 2)]);
        $result = RankingFacts::build([$a, $b]);
        self::assertSame([$this->id(11), $this->id(10)], array_column($result['fans'], 'faluss_id'));
        self::assertSame(['13', '13'], array_column($result['fans'], 'points'));
        self::assertSame('2026-10-06 10:00:00.000000', $result['fans'][1]['reached_at']);
        self::assertSame('3', $result['fans'][1]['consumption_order']);
        self::assertSame([$this->id(31), $this->id(30)], array_column($result['creators'], 'faluss_id'));
    }

    public function testCancelledEarlyContributionCannotPreserveAFormerAttainmentAdvantage(): void
    {
        $a = $this->source(10, [$this->fact(21, 30, 10, 0, '08:00:00', 1), $this->fact(22, 30, 10, 10, '10:00:00', 3)], 2);
        $b = $this->source(11, [$this->fact(23, 31, 10, 10, '09:00:00', 2)]);
        $result = RankingFacts::build([$a, $b]);
        self::assertSame($this->id(11), $result['fans'][0]['faluss_id']);
        self::assertSame('2026-10-06 10:00:00.000000', $result['fans'][1]['reached_at']);
    }

    public function testCancelledLastContributionMovesTheAttainmentBackToItsSurvivingPredecessor(): void
    {
        $a = $this->source(10, [$this->fact(21, 30, 10, 10, '08:00:00', 1), $this->fact(22, 30, 5, 0, '10:00:00', 3)], 3);
        $b = $this->source(11, [$this->fact(23, 31, 10, 10, '09:00:00', 2)]);
        $result = RankingFacts::build([$a, $b]);
        self::assertSame($this->id(10), $result['fans'][0]['faluss_id']);
        self::assertSame('2026-10-06 08:00:00.000000', $result['fans'][0]['reached_at']);
    }

    public function testDisputeAndResolutionRebuildWithoutInventingAConsumptionOrRestoringCancelledPf(): void
    {
        $first = $this->fact(21, 30, 10, 10, '08:00:00', 1);
        $original = $this->source(10, [$first, $this->fact(22, 30, 5, 5, '10:00:00', 2)]);
        $disputed = $this->source(10, [$first, $this->fact(22, 30, 5, 0, '10:00:00', 2)], 2);
        $resolved = $this->source(10, [$first, $this->fact(22, 30, 5, 3, '10:00:00', 2)], 3);
        self::assertSame('10', RankingFacts::build([$disputed, $original])['fans'][0]['points']);
        $result = RankingFacts::build([$resolved, $original, $disputed]);
        self::assertSame('13', $result['fans'][0]['points']);
        self::assertSame('2026-10-06 10:00:00.000000', $result['fans'][0]['reached_at']);
        self::assertSame('2', $result['fans'][0]['consumption_order']);
        self::assertSame($result, RankingFacts::build([$disputed, $resolved, $original, $resolved]));
    }

    public function testOlderSnapshotCannotRestorePointsOrDateAfterCancellation(): void
    {
        $first = $this->fact(21, 30, 10, 10, '09:00:00', 1);
        $snapshots = [];
        foreach ([5, 3, 0] as $revision => $net) { $snapshots[] = $this->source(10, [$first, $this->fact(22, 30, 5, $net, '10:00:00', 2)], $revision + 1); }
        $result = RankingFacts::build([$snapshots[2], $snapshots[0], $snapshots[1]]);
        self::assertSame('10', $result['fans'][0]['points']);
        self::assertSame('2026-10-06 09:00:00.000000', $result['fans'][0]['reached_at']);
    }

    public function testIdenticalTimestampsUseOwnerOrderNotArrivalOrLexicalAttributionId(): void
    {
        $laterOrder = $this->source(10, [$this->fact(20, 30, 12, 12, '09:00:00', 42)]);
        $earlierOrder = $this->source(11, [$this->fact(99, 31, 12, 12, '09:00:00', 41)]);
        $result = RankingFacts::build([$laterOrder, $earlierOrder]);
        self::assertSame($this->id(11), $result['fans'][0]['faluss_id']);
        self::assertSame('41', $result['fans'][0]['consumption_order']);
        self::assertSame($result, RankingFacts::build([$earlierOrder, $laterOrder]));
    }

    public function testPackAloneZeroNetAndPcFieldsCannotCreatePlaces(): void
    {
        self::assertSame(['fans' => [], 'creators' => []], RankingFacts::build([$this->source(10, [])]));
        self::assertSame(['fans' => [], 'creators' => []], RankingFacts::build([$this->source(10, [$this->fact(21, 30, 10, 0, '09:00:00', 1)])]));
        $row = $this->fact(21, 30, 10, 10, '09:00:00', 1); $row['pc'] = '50';
        $this->expectException(ModelViolation::class);
        RankingFacts::build([$this->source(10, [$row])]);
    }

    public function testMissingOwnerOrderHasNoFallbackToAUuidOrNetworkSequence(): void
    {
        $row = $this->fact(21, 30, 10, 10, '09:00:00', 1); unset($row['consumption_order']);
        $this->expectException(ModelViolation::class);
        RankingFacts::build([$this->source(10, [$row])]);
    }

    public function testSameRevisionDifferentContentFailsEvenWhenAFormerRevisionArrivesLate(): void
    {
        $a = $this->source(10, [$this->fact(21, 30, 10, 10, '09:00:00', 1)]);
        $b = $this->source(10, [$this->fact(21, 30, 10, 5, '09:00:00', 1)]);
        $current = $this->source(10, [$this->fact(21, 30, 10, 3, '09:00:00', 1)], 2);
        $this->expectException(ModelViolation::class);
        RankingFacts::build([$current, $a, $b]);
    }

    public function testNewerSnapshotCannotDropAKnownAttributionToFalsifyTheHistory(): void
    {
        $this->expectException(ModelViolation::class);
        RankingFacts::build([$this->source(10, [$this->fact(21, 30, 10, 10, '09:00:00', 1)]), $this->source(10, [], 2)]);
    }

    public function testChangedConfirmationAndChangedOrderAreNotCorrections(): void
    {
        foreach (['confirmed_at' => '2026-10-06 10:00:00.000000', 'consumption_order' => '2', 'creator_faluss_id' => $this->id(31)] as $field => $changed) {
            $row = $this->fact(21, 30, 10, 10, '09:00:00', 1); $other = $row; $other[$field] = $changed;
            try { RankingFacts::build([$this->source(10, [$row]), $this->source(10, [$other], 2)]); self::fail('Mutable owner identity accepted'); }
            catch (ModelViolation $error) { self::assertSame('hof_changed_consumption_identity', $error->getMessage()); }
        }
    }

    public function testDuplicatedConsumptionLedgerOrderAndAttributionCannotMultiplyPoints(): void
    {
        foreach (['consumption_id', 'ledger_entry_uuid', 'consumption_order', 'attribution_id'] as $field) {
            $first = $this->fact(21, 30, 10, 10, '09:00:00', 1); $other = $this->fact(22, 30, 10, 10, '09:05:00', 2); $other[$field] = $first[$field];
            try { RankingFacts::build([$this->source(10, [$first, $other])]); self::fail('Duplicate owner fact accepted'); }
            catch (ModelViolation $error) { self::assertContains($error->getMessage(), ['hof_duplicate_owner_fact', 'hof_duplicate_attribution']); }
        }
    }

    public function testMixedOrderingAuthoritiesCannotBeMergedAsACompleteRanking(): void
    {
        $source = $this->source(11, [$this->fact(22, 31, 10, 10, '09:00:00', 2)]); $source['ordering_epoch'] = $this->id(998);
        $this->expectException(ModelViolation::class);
        RankingFacts::build([$this->source(10, [$this->fact(21, 30, 10, 10, '09:00:00', 1)]), $source]);
    }

    public function testNegativeNetNetAboveOriginalAndSelfAttributionFailClosed(): void
    {
        foreach ([['net_pf', '-1'], ['net_pf', '11'], ['creator_faluss_id', $this->id(10)]] as [$field, $value]) {
            $row = $this->fact(21, 30, 10, 10, '09:00:00', 1); $row[$field] = $value;
            try { RankingFacts::build([$this->source(10, [$row])]); self::fail('Invalid contribution accepted'); }
            catch (ModelViolation $error) { self::assertNotSame('', $error->getMessage()); }
        }
    }

    public function testSummingIndividuallyValidContributionsCannotOverflowTheCanonicalRange(): void
    {
        $row = $this->fact(21, 30, 9007199254740991, 9007199254740991, '09:00:00', 1);
        $this->expectExceptionMessage('hof_points_overflow');
        RankingFacts::build([$this->source(10, [$row, $this->fact(22, 30, 1, 1, '09:05:00', 2)])]);
    }

    public function testAnAttributionDictionaryIsNotAnExhaustiveCanonicalSourceList(): void
    {
        $row = $this->fact(21, 30, 10, 10, '09:00:00', 1);
        $source = $this->source(10, []);
        $source['attributions'] = [$row['attribution_id'] => $row];
        $this->expectExceptionMessage('hof_invalid_source');
        RankingFacts::build([$source]);
    }
}
