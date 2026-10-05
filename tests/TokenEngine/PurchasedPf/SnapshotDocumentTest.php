<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\ClosedSnapshotSchema;
use Faluss\Platform\TokenEngine\PurchasedPf\ClosedSnapshotStore;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SnapshotDocument;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class SnapshotDocumentTest extends TestCase
{
    private const LOT = '11111111-1111-4111-8111-111111111111';
    private const MEMBER = '22222222-2222-4222-8222-222222222222';
    private const CREATOR = '33333333-3333-4333-8333-333333333333';

    /** @return list<array<string,string>> */
    private function rows(): array
    {
        $digest = str_repeat('a', 64);
        return [['kind' => 'lot', 'lot_id' => self::LOT, 'source_revision' => '2', 'original_pf' => '100', 'cancelled_pf' => '80',
            'state' => 'partially_cancelled', 'available_cancelled_pf' => '60', 'allocated_original_pf' => '40',
            'allocated_cancelled_pf' => '20', 'available_pf' => '0', 'source_evidence_id' => self::LOT, 'source_evidence_sha256' => $digest],
            ['kind' => 'allocation', 'lot_id' => self::LOT, 'source_revision' => '2', 'original_pf' => '40', 'cancelled_pf' => '20',
                'attribution_id' => self::LOT, 'consumption_id' => self::LOT, 'member_faluss_id' => self::MEMBER,
                'creator_faluss_id' => self::CREATOR, 'client_authority' => 'fixture.fans', 'attribution_original_pf' => '40', 'suspended_pf' => '0', 'net_pf' => '20',
                'confirmed_at' => '2026-10-05 10:00:00.000000', 'ledger_entry_uuid' => self::LOT, 'ledger_fact_sha256' => $digest]];
    }

    /**
     * @param list<array<string,string>> $rows
     * @return array<string,string>
     */
    private function manifest(array $rows): array
    {
        return ['contract' => SnapshotDocument::CONTRACT, 'snapshot_id' => self::LOT, 'issuer' => 'fixture.hub',
            'audience' => 'fixture.fans', 'member_faluss_id' => self::MEMBER, 'epoch' => self::CREATOR, 'revision' => '1',
            'full_sha256' => hash('sha256', CanonicalJson::encode($rows)), 'created_at' => '2026-10-05T10:00:00Z']
            + SnapshotDocument::summary($rows);
    }

    public function testCompletePrivateCumulativeFactsHaveExactSumsAndNoRankingPolicy(): void
    {
        $rows = $this->rows();
        $manifest = $this->manifest($rows);
        SnapshotDocument::complete($manifest, $rows);
        self::assertSame('40', $manifest['original_pf']);
        self::assertSame('20', $manifest['cancelled_pf']);
        self::assertSame('20', $manifest['net_pf']);
        self::assertArrayNotHasKey('score', $manifest);
        self::assertArrayNotHasKey('pc', $manifest);
        self::assertArrayNotHasKey('expires_at', $manifest);
    }

    public function testIncompleteDuplicateForeignOrInconsistentRowsAreRefused(): void
    {
        $rows = $this->rows();
        $manifest = $this->manifest($rows);
        $cases = [[$rows[0]], [$rows[1]], [$rows[0], $rows[1], $rows[1]], array_reverse($rows)];
        $wrong = $rows;
        $wrong[1]['net_pf'] = '21';
        $cases[] = $wrong;
        $wrong = $rows;
        $wrong[1]['member_faluss_id'] = self::LOT;
        $cases[] = $wrong;
        foreach ($cases as $bad) {
            try {
                SnapshotDocument::complete($manifest, $bad);
                self::fail('A partial or conflicting snapshot cannot be activated.');
            } catch (ModelViolation) {
                self::assertTrue(true);
            }
        }
    }

    public function testContextEpochDigestAndPageBoundsAreClosed(): void
    {
        $rows = $this->rows();
        $manifest = $this->manifest($rows);
        $page = ['manifest' => $manifest, 'page_index' => '0', 'cursor' => self::LOT, 'next_cursor' => null, 'rows' => $rows];
        SnapshotDocument::page($page, $manifest);
        self::assertTrue(true);
        foreach ([array_replace($page, ['page_index' => '1']), array_replace($page, ['next_cursor' => self::MEMBER]),
            array_replace($page, ['rows' => [$rows[0]]])] as $bad) {
            try {
                SnapshotDocument::page($bad, $manifest);
                self::fail('Page context or boundary cannot be inferred.');
            } catch (ModelViolation) {
                self::assertTrue(true);
            }
        }
        foreach ([array_replace($manifest, ['epoch' => self::LOT]), array_replace($manifest, ['audience' => 'fixture.other'])] as $bad) {
            try {
                SnapshotDocument::manifest($bad, self::MEMBER, self::CREATOR);
                self::fail('A different audience or unapproved epoch is not admissible.');
            } catch (ModelViolation) {
                self::assertTrue(true);
            }
        }
    }

    public function testMultiLotAttributionRequiresOneImmutableIdentityAndEveryAllocation(): void
    {
        [$lot, $allocation] = $this->rows();
        $lot = array_replace($lot, ['cancelled_pf' => '20', 'available_cancelled_pf' => '20',
            'allocated_original_pf' => '25', 'allocated_cancelled_pf' => '0', 'available_pf' => '55']);
        $secondLot = array_replace($lot, ['lot_id' => self::CREATOR, 'source_revision' => '1', 'original_pf' => '50',
            'cancelled_pf' => '0', 'state' => 'confirmed', 'available_cancelled_pf' => '0',
            'allocated_original_pf' => '15', 'available_pf' => '35', 'source_evidence_id' => self::CREATOR]);
        $allocation = array_replace($allocation, ['original_pf' => '25', 'cancelled_pf' => '0', 'net_pf' => '25']);
        $secondAllocation = array_replace($allocation, ['lot_id' => self::CREATOR, 'source_revision' => '1', 'original_pf' => '15', 'net_pf' => '15']);
        $rows = [$lot, $secondLot, $allocation, $secondAllocation];
        self::assertSame('40', SnapshotDocument::summary($rows)['net_pf']);
        $wrong = $rows;
        $wrong[3]['creator_faluss_id'] = self::LOT;
        $missing = [$lot, array_replace($secondLot, ['allocated_original_pf' => '0', 'available_pf' => '50']), $allocation];
        foreach ([$wrong, $missing] as $bad) {
            try {
                SnapshotDocument::summary($bad);
                self::fail('A multi-lot attribution must retain its exact identity and total.');
            } catch (ModelViolation) {
                self::assertTrue(true);
            }
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNormalSiteCannotInstallOrReadSnapshots(): void
    {
        eval('namespace { class wpdb { public int $queries=0; public function get_var($q) { $this->queries++; return null; } } }');
        $database = new \wpdb();
        foreach ([static fn () => ClosedSnapshotSchema::installForRecipe($database),
            static fn () => new ClosedSnapshotStore($database, ['fixture.purchase'])] as $operation) {
            try {
                $operation();
                self::fail('Ordinary sites cannot admit these private proof operations.');
            } catch (ModelViolation $error) {
                self::assertSame('closed_h4_recipe_required', $error->reason);
            }
        }
        self::assertSame(0, $database->queries);
    }
}
