<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Fans\PfProjection;

use Faluss\Platform\Fans\PfProjection\ClosedProjectionSchema;
use Faluss\Platform\Fans\PfProjection\ClosedProjectionStore;
use Faluss\Platform\Fans\PfProjection\ProjectionFacts;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SnapshotDocument;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class ProjectionFactsTest extends TestCase
{
    private const EPOCH = '99999999-9999-4999-8999-999999999999';

    private function id(int $value): string { return sprintf('%08x-1111-4111-8111-111111111111', $value); }

    /** @param list<array<string,string>> $rows
     * @return array{manifest:array<string,mixed>,rows:list<array<string,string>>} */
    private function snapshot(string $member, array $rows, string $revision = '1'): array
    {
        usort($rows, static fn (array $left, array $right): int => strcmp(SnapshotDocument::order($left), SnapshotDocument::order($right)));
        $manifest = ['contract' => SnapshotDocument::CONTRACT, 'snapshot_id' => $this->id(90), 'issuer' => 'fixture.hub',
            'audience' => 'fixture.fans', 'member_faluss_id' => $member, 'epoch' => self::EPOCH, 'revision' => $revision,
            'full_sha256' => hash('sha256', CanonicalJson::encode($rows)), 'created_at' => '2026-10-06T10:00:00Z'] + SnapshotDocument::summary($rows);
        return ['manifest' => $manifest, 'rows' => $rows];
    }

    /** @return list<array<string,string>> */
    private function allocation(int $lot, int $attribution, int $member, int $creator, int $original, int $cancelled = 0,
        bool $disputed = false, ?int $attributionOriginal = null): array
    {
        $lotId = $this->id($lot);
        $net = $original - $cancelled;
        return [['kind' => 'lot', 'lot_id' => $lotId, 'source_revision' => '2', 'original_pf' => (string) $original,
            'cancelled_pf' => (string) $cancelled, 'state' => $disputed ? 'disputed' : ($cancelled === 0 ? 'confirmed' : ($net === 0 ? 'cancelled' : 'partially_cancelled')),
            'available_cancelled_pf' => '0', 'allocated_original_pf' => (string) $original,
            'allocated_cancelled_pf' => (string) $cancelled, 'available_pf' => '0',
            'source_evidence_id' => $lotId, 'source_evidence_sha256' => str_repeat('a', 64)],
            ['kind' => 'allocation', 'lot_id' => $lotId, 'source_revision' => '2', 'original_pf' => (string) $original,
                'cancelled_pf' => (string) $cancelled, 'attribution_id' => $this->id($attribution), 'consumption_id' => $this->id($attribution + 100),
                'member_faluss_id' => $this->id($member), 'creator_faluss_id' => $this->id($creator),
                'client_authority' => 'fixture.fans', 'attribution_original_pf' => (string) ($attributionOriginal ?? $original),
                'suspended_pf' => $disputed ? (string) $net : '0', 'net_pf' => $disputed ? '0' : (string) $net,
                'confirmed_at' => '2026-10-06 10:00:00.000000', 'ledger_entry_uuid' => $this->id($attribution + 200),
                'ledger_fact_sha256' => str_repeat('b', 64)]];
    }

    public function testFanAndCreatorAreSeparateViewsOfOneCanonicalAttribution(): void
    {
        $first = $this->snapshot($this->id(10), array_merge($this->allocation(1, 21, 10, 30, 40, 15), $this->allocation(2, 22, 10, 31, 12)));
        $second = $this->snapshot($this->id(11), $this->allocation(3, 23, 11, 30, 20));
        $result = ProjectionFacts::build([$second, $first], self::EPOCH);
        self::assertCount(3, $result['attributions']);
        self::assertSame([['faluss_id' => $this->id(10), 'points' => '37'], ['faluss_id' => $this->id(11), 'points' => '20']], $result['fans']);
        self::assertSame([['faluss_id' => $this->id(30), 'points' => '45'], ['faluss_id' => $this->id(31), 'points' => '12']], $result['creators']);
        self::assertSame($result, ProjectionFacts::build([$first, $second], self::EPOCH));
        foreach (['amount', 'eur', 'pc', 'rank', 'session', 'balance'] as $forbidden) { self::assertArrayNotHasKey($forbidden, $result); }
    }

    public function testMultiLotConsumptionIsGroupedOnceWithoutLosingACorrectedLeg(): void
    {
        $rows = array_merge($this->allocation(1, 21, 10, 30, 25, 10, false, 40), $this->allocation(2, 21, 10, 30, 15, 0, false, 40));
        $result = ProjectionFacts::build([$this->snapshot($this->id(10), $rows)], self::EPOCH);
        self::assertCount(1, $result['attributions']);
        self::assertSame('30', $result['attributions'][0]['points']);
        self::assertSame('30', $result['fans'][0]['points']);
        self::assertSame('30', $result['creators'][0]['points']);
        $this->expectException(ModelViolation::class);
        ProjectionFacts::build([$this->snapshot($this->id(10), [$rows[0], $rows[1]])], self::EPOCH);
    }

    public function testPackAloneAndItsAvailablePfGenerateZeroPoints(): void
    {
        $lot = $this->allocation(1, 21, 10, 30, 300)[0];
        $lot['allocated_original_pf'] = '0'; $lot['available_pf'] = '300';
        $result = ProjectionFacts::build([$this->snapshot($this->id(10), [$lot])], self::EPOCH);
        self::assertSame([['faluss_id' => $this->id(10), 'points' => '0']], $result['fans']);
        self::assertSame([], $result['attributions']);
        self::assertSame([], $result['creators']);
    }

    public function testPartialTotalDisputeAndResolutionUseOnlyTheAttestedNet(): void
    {
        foreach ([[15, false, '25'], [40, false, '0'], [15, true, '0'], [15, false, '25']] as [$cancelled, $disputed, $expected]) {
            $result = ProjectionFacts::build([$this->snapshot($this->id(10), $this->allocation(1, 21, 10, 30, 40, $cancelled, $disputed))], self::EPOCH);
            self::assertSame($expected, $result['fans'][0]['points']);
            self::assertSame($expected, $result['creators'][0]['points']);
        }
    }

    public function testIncompleteDuplicateForeignAndNonPurchasedFieldsCannotBeAccepted(): void
    {
        $good = $this->snapshot($this->id(10), $this->allocation(1, 21, 10, 30, 40));
        $cases = [];
        $bad = $good; array_pop($bad['rows']); $cases[] = [$bad];
        $cases[] = [$good, $good];
        foreach ([['pc' => '10'], ['economic_class' => 'earned'], ['economic_class' => 'promotional']] as $extra) {
            $bad = $good; $bad['rows'][1] += $extra; $cases[] = [$bad];
        }
        $bad = $good; $bad['manifest']['epoch'] = $this->id(1); $cases[] = [$bad];
        foreach ($cases as $input) {
            try { ProjectionFacts::build($input, self::EPOCH); self::fail('Only complete admitted facts can form a projection.'); }
            catch (ModelViolation) { self::assertTrue(true); }
        }
    }

    public function testOneConsumptionOrDebitCannotBeReusedAsTwoAttributions(): void
    {
        $left = $this->allocation(1, 21, 10, 30, 10);
        $right = $this->allocation(2, 22, 10, 30, 10);
        foreach (['consumption_id', 'ledger_entry_uuid'] as $field) {
            $bad = $right; $bad[1][$field] = $left[1][$field];
            $input = $this->snapshot($this->id(10), array_merge($left, $bad));
            try { ProjectionFacts::build([$input], self::EPOCH); self::fail('Consumption must remain unique independently of the dimensions.'); }
            catch (ModelViolation $error) { self::assertSame('pf_projection_duplicate_consumption', $error->reason); }
        }
    }

    public function testDifferentMemberTotalsCannotOverflowTheCreatorIntegerDomain(): void
    {
        $first = $this->snapshot($this->id(10), $this->allocation(1, 21, 10, 30, ModelValues::MAX_INTEGER));
        $second = $this->snapshot($this->id(11), $this->allocation(2, 22, 11, 30, 1));
        $this->expectException(ModelViolation::class);
        $this->expectExceptionMessage('pf_projection_overflow');
        ProjectionFacts::build([$first, $second], self::EPOCH);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNormalSiteAndCopiedMarkersCannotInstallOrReadProjections(): void
    {
        eval('namespace { class wpdb { public int $queries=0; public function get_var($q) { $this->queries++; return null; } } }');
        $database = new \wpdb();
        foreach ([static fn () => ClosedProjectionSchema::installForRecipe($database), static fn () => new ClosedProjectionStore($database, self::EPOCH)] as $operation) {
            try { $operation(); self::fail('No ordinary site can admit F1a.'); }
            catch (ModelViolation $error) { self::assertSame('isolated_f1a_recipe_required', $error->reason); }
        }
        define('FALUSS_FANS_F1A_RECIPE', true);
        define('FALUSS_PF_H4_RECIPE', true);
        define('FALUSS_PF_H3_RECIPE_ONLY', true);
        try { new ClosedProjectionStore($database, self::EPOCH); self::fail('Markers alone cannot open physical isolation.'); }
        catch (ModelViolation) { self::assertSame(0, $database->queries); }
    }
}
