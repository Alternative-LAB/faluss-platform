<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\ClosedRankedSnapshotSchema;
use Faluss\Platform\TokenEngine\PurchasedPf\ClosedRankedSnapshotStore;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedSnapshotDocument;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SnapshotDocument;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class RankedSnapshotDocumentTest extends TestCase
{
    /** @return list<array<string,mixed>> */
    private function rows(): array
    {
        $fact = RankedFixtures::receipt(); $lot = $fact['allocations'][0]['lot_id'];
        return [['kind' => 'lot','lot_id' => $lot,'source_revision' => '1','original_pf' => '100','cancelled_pf' => '0',
            'state' => 'confirmed','available_cancelled_pf' => '0','allocated_original_pf' => '40','allocated_cancelled_pf' => '0',
            'available_pf' => '60','source_evidence_id' => $lot,'source_evidence_sha256' => str_repeat('a',64)],
            ['kind' => 'allocation','lot_id' => $lot,'source_revision' => '1','original_pf' => '40','cancelled_pf' => '0',
                'attribution_id' => $fact['attribution_id'],'consumption_id' => $fact['receipt_id'],'member_faluss_id' => $fact['member_faluss_id'],
                'creator_faluss_id' => $fact['creator_faluss_id'],'client_authority' => 'fixture.fans','attribution_original_pf' => '40',
                'suspended_pf' => '0','net_pf' => '40','confirmed_at' => $fact['confirmed_at'],'ledger_entry_uuid' => $fact['ledger_entry_uuid'],
                'ledger_fact_sha256' => str_repeat('b',64),'ranking' => array_intersect_key($fact,array_flip(['ordering_epoch','consumption_order','ranking_context','context_sha256']))]];
    }

    /** @param list<array<string,mixed>> $rows
     * @return array<string,mixed> */
    private function manifest(array $rows): array
    {
        return ['contract' => RankedSnapshotDocument::CONTRACT,'snapshot_id' => RankedFixtures::SESSION,'issuer' => 'fixture.hub','audience' => 'fixture.fans',
            'member_faluss_id' => ModelFixtures::intent()['member_faluss_id'],'epoch' => RankedFixtures::ORIGIN,'revision' => '1',
            'ordering_epoch' => RankedFixtures::EPOCH,'full_sha256' => hash('sha256',CanonicalJson::encode($rows)),'created_at' => '2026-10-05T10:00:00Z']
            + RankedSnapshotDocument::summary($rows);
    }

    public function testNewVersionAttestsOriginalOrderContextAndLatestNetWithoutASecondConsumption(): void
    {
        $rows = $this->rows(); $manifest = $this->manifest($rows); RankedSnapshotDocument::complete($manifest,$rows);
        self::assertSame('40',$manifest['net_pf']); self::assertSame(RankedFixtures::EPOCH,$manifest['ordering_epoch']);
        self::assertSame('9007199254740991',$rows[1]['ranking']['consumption_order']);
        self::assertArrayNotHasKey('score',$manifest); self::assertArrayNotHasKey('pc',$manifest);
        $rows[0] = array_replace($rows[0],['state' => 'partially_cancelled','cancelled_pf' => '80','allocated_cancelled_pf' => '20','available_cancelled_pf' => '60','available_pf' => '0']);
        $rows[1] = array_replace($rows[1],['cancelled_pf' => '20','net_pf' => '20']);
        RankedSnapshotDocument::complete($this->manifest($rows),$rows);
        self::assertSame('20',RankedSnapshotDocument::summary($rows)['net_pf']);
        self::assertSame('9007199254740991',$rows[1]['ranking']['consumption_order']);
    }

    public function testHistoricalAllocationRemainsExplicitlyUnrankedAndOldValidatorRejectsNewVersion(): void
    {
        $rows = $this->rows(); $rows[1]['ranking'] = null;
        RankedSnapshotDocument::complete($this->manifest($rows),$rows); self::assertNull($rows[1]['ranking']);
        foreach ([fn () => SnapshotDocument::manifest($this->manifest($rows),$rows[1]['member_faluss_id'],RankedFixtures::ORIGIN),
            fn () => SnapshotDocument::row($rows[1])] as $oldConsumer) {
            try { $oldConsumer(); self::fail('Old validators must not silently accept new fields.'); }
            catch (ModelViolation) { self::assertTrue(true); }
        }
    }

    public function testMissingAlteredOrForeignRankingAuthorityIsRejected(): void
    {
        $rows = $this->rows(); $manifest = $this->manifest($rows); $cases = [];
        $bad = $rows; unset($bad[1]['ranking']); $cases[] = $bad;
        foreach (['consumption_order' => '0','ordering_epoch' => RankedFixtures::ORIGIN,'context_sha256' => str_repeat('f',64)] as $field => $value) {
            $bad = $rows; $bad[1]['ranking'][$field] = $value; $cases[] = $bad;
        }
        $bad = $rows; $bad[1]['ranking']['ranking_context']['creator_category'] = 'music'; $cases[] = $bad;
        $bad = $rows; $bad[1]['confirmed_at'] = '2026-11-01 00:00:00.000000'; $cases[] = $bad;
        $bad = $rows; $bad[1]['member_faluss_id'] = RankedFixtures::SESSION; $cases[] = $bad;
        foreach ($cases as $bad) {
            try { RankedSnapshotDocument::complete($manifest,$bad); self::fail('No missing or substituted authority.'); }
            catch (ModelViolation) { self::assertTrue(true); }
        }
    }

    public function testMultiLotContextMustRemainIdenticalIncludingExplicitNull(): void
    {
        [$lot,$allocation] = $this->rows();
        $allocation['original_pf'] = $allocation['net_pf'] = '25'; $lot['allocated_original_pf'] = '25'; $lot['available_pf'] = '75';
        $otherLot = array_replace($lot,['lot_id' => RankedFixtures::SESSION,'allocated_original_pf' => '15','available_pf' => '85']);
        $otherAllocation = array_replace($allocation,['lot_id' => RankedFixtures::SESSION,'original_pf' => '15','net_pf' => '15']);
        $rows = [$lot,$otherLot,$allocation,$otherAllocation];
        self::assertSame('40',RankedSnapshotDocument::summary($rows)['net_pf']);
        foreach ([null,array_replace($otherAllocation['ranking'],['consumption_order' => '1'])] as $substituted) {
            $bad = $rows; $bad[3]['ranking'] = $substituted;
            try { RankedSnapshotDocument::summary($bad); self::fail('One fact cannot have two ranking authorities.'); }
            catch (ModelViolation) { self::assertTrue(true); }
        }
    }

    public function testDifferentAttributionsCannotShareAnOrderOrReversePrimaryChronology(): void
    {
        [$lot,$allocation] = $this->rows(); $lot['allocated_original_pf'] = '80'; $lot['available_pf'] = '20';
        $other = array_replace($allocation,['attribution_id' => RankedFixtures::SESSION,'consumption_id' => RankedFixtures::SESSION,'ledger_entry_uuid' => RankedFixtures::SESSION]);
        $rows = [$lot,$allocation,$other];
        try { RankedSnapshotDocument::summary($rows); self::fail('One order belongs to one consumption.'); }
        catch (ModelViolation $error) { self::assertSame('pf_snapshot_ranking_duplicate_order',$error->reason); }
        $rows[1]['ranking']['consumption_order'] = '1'; $rows[2]['ranking']['consumption_order'] = '2';
        $rows[1]['confirmed_at'] = '2026-10-05 11:00:00.000000'; $rows[2]['confirmed_at'] = '2026-10-05 10:00:00.000000';
        try { RankedSnapshotDocument::summary($rows); self::fail('Network ordering cannot replace the primary chronology.'); }
        catch (ModelViolation $error) { self::assertSame('pf_snapshot_ranking_clock',$error->reason); }
    }

    public function testPageContextBoundsAndCompleteDigestMustBeVerifiedSeparately(): void
    {
        $rows = $this->rows(); $manifest = $this->manifest($rows);
        $page = ['manifest' => $manifest,'page_index' => '0','cursor' => RankedFixtures::SESSION,'next_cursor' => null,'rows' => $rows];
        RankedSnapshotDocument::page($page,$manifest); self::assertTrue(true);
        foreach ([array_replace($page,['page_index' => '1']),array_replace($page,['next_cursor' => RankedFixtures::ORIGIN]),array_replace($page,['rows' => [$rows[0]]]),
            array_replace($page,['rows' => ['substituted' => $rows[0], 'other' => $rows[1]]])] as $bad) {
            try { RankedSnapshotDocument::page($bad,$manifest); self::fail('No inferred pagination or complete page.'); }
            catch (ModelViolation) { self::assertTrue(true); }
        }
        $manifest['full_sha256'] = str_repeat('0',64);
        try { RankedSnapshotDocument::complete($manifest,$rows); self::fail('Hash must cover the enriched rows.'); }
        catch (ModelViolation $error) { self::assertSame('pf_snapshot_digest_mismatch',$error->reason); }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNoNormalSiteCanInstallOrReadTheNewSnapshotSchema(): void
    {
        eval('namespace { class wpdb { public int $queries=0; public function get_var($q) { $this->queries++; return null; } } }');
        $db = new \wpdb();
        foreach ([fn () => ClosedRankedSnapshotSchema::installForRecipe($db),fn () => new ClosedRankedSnapshotStore($db,['fixture.purchase'],'synthetic')] as $operation) {
            try { $operation(); self::fail('The closed physical gate must run first.'); }
            catch (ModelViolation $error) { self::assertSame('isolated_h3_recipe_required',$error->reason); }
        }
        self::assertSame(0,$db->queries);
    }
}
