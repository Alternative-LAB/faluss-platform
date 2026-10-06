<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingCorpusDocument;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingContext;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SignedEnvelope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RankingCorpusDocumentTest extends TestCase
{
    /** @return array<string,mixed> */
    private function fact(int $order = 1): array
    {
        $receipt = RankedFixtures::receipt();
        $id = sprintf('%08x-6666-4666-8666-666666666666',$order);
        return ['attribution_id' => $id,'consumption_id' => $id,'ledger_entry_uuid' => $id,'ledger_fact_sha256' => str_repeat('b',64),
            'member_faluss_id' => $receipt['member_faluss_id'],'creator_faluss_id' => $receipt['creator_faluss_id'],
            'client_authority' => 'fixture.fans','purchased_pf' => '40','cancelled_pf' => '0','suspended_pf' => '0','net_pf' => '40',
            'confirmed_at' => $receipt['confirmed_at'],'consumption_order' => (string) $order,'ordering_epoch' => $receipt['ordering_epoch'],
            'ranking_context' => $receipt['ranking_context'],'context_sha256' => $receipt['context_sha256'],
            'allocations' => [['lot_id' => $id,'source_revision' => '1','source_state' => 'confirmed','source_evidence_id' => $id,
                'source_evidence_sha256' => str_repeat('a',64),'original_pf' => '40','cancelled_pf' => '0','suspended_pf' => '0','net_pf' => '40']]];
    }

    /** @param list<array<string,mixed>> $facts
     * @return array<string,mixed> */
    private function manifest(array $facts): array
    {
        return ['contract' => RankingCorpusDocument::CONTRACT,'corpus_id' => RankedFixtures::SESSION,'issuer' => 'fixture.hub',
            'audience' => 'fixture.fans','origin_id' => RankedFixtures::ORIGIN,'policy_version' => '1.0.0','ordering_epoch' => RankedFixtures::EPOCH,
            'epoch' => RankedFixtures::SESSION,'revision' => '1','last_order' => (string) count($facts),'created_at' => '2026-10-05 10:01:00.000000',
            'scope' => RankingCorpusDocument::SCOPE,'full_sha256' => hash('sha256',CanonicalJson::encode($facts))]
            + RankingCorpusDocument::summary($facts);
    }

    public function testExplicitlyEmptyCorpusIsCompleteButNotAnInventoryInferredFromFansMembers(): void
    {
        $manifest = $this->manifest([]); RankingCorpusDocument::complete($manifest,[]);
        RankingCorpusDocument::page(['manifest' => $manifest,'page_index' => '0','cursor' => RankedFixtures::ORIGIN,
            'next_cursor' => null,'facts' => []],$manifest);
        self::assertSame('0',$manifest['fact_count']); self::assertSame('1',$manifest['page_count']);
        self::assertSame('all_confirmed_ranked_0.3_including_zero',$manifest['scope']);
        self::assertArrayNotHasKey('members',$manifest); self::assertArrayNotHasKey('available_pf',$manifest);
    }

    public function testCompleteCorpusIncludesUnknownMembersZeroNetAndAllPages(): void
    {
        $facts = array_map($this->fact(...),range(1,101));
        $facts[100]['member_faluss_id'] = RankedFixtures::EPOCH;
        $facts[0]['cancelled_pf'] = '40'; $facts[0]['net_pf'] = '0';
        $facts[0]['allocations'][0] = array_replace($facts[0]['allocations'][0],['source_revision' => '2',
            'source_state' => 'cancelled','cancelled_pf' => '40','net_pf' => '0']);
        $manifest = $this->manifest($facts); RankingCorpusDocument::complete($manifest,$facts);
        self::assertSame('101',$manifest['fact_count']); self::assertSame('4000',$manifest['net_pf']);
        $page = ['manifest' => $manifest,'page_index' => '0','cursor' => RankedFixtures::ORIGIN,
            'next_cursor' => RankedFixtures::SESSION,'facts' => array_slice($facts,0,100)];
        RankingCorpusDocument::page($page,$manifest);
        $page['page_index'] = '1'; $page['cursor'] = RankedFixtures::SESSION; $page['next_cursor'] = null;
        $page['facts'] = array_slice($facts,100); RankingCorpusDocument::page($page,$manifest);
        self::assertCount(1,$page['facts']); self::assertSame(RankedFixtures::EPOCH,$page['facts'][0]['member_faluss_id']);
        $this->expectException(ModelViolation::class); RankingCorpusDocument::complete($manifest,array_slice($facts,0,100));
    }

    public function testPartialCorrectionDisputeAndResolutionRetainOriginalOrderAndContext(): void
    {
        $fact = $this->fact(); $context = $fact['context_sha256'];
        $fact['cancelled_pf'] = '10'; $fact['net_pf'] = '30';
        $fact['allocations'][0] = array_replace($fact['allocations'][0],['source_revision' => '2',
            'source_state' => 'partially_cancelled','cancelled_pf' => '10','net_pf' => '30']);
        RankingCorpusDocument::complete($this->manifest([$fact]),[$fact]);
        $fact['suspended_pf'] = '30'; $fact['net_pf'] = '0';
        $fact['allocations'][0] = array_replace($fact['allocations'][0],['source_revision' => '3',
            'source_state' => 'disputed','suspended_pf' => '30','net_pf' => '0']);
        RankingCorpusDocument::complete($this->manifest([$fact]),[$fact]);
        $fact['suspended_pf'] = '0'; $fact['net_pf'] = '30';
        $fact['allocations'][0] = array_replace($fact['allocations'][0],['source_revision' => '4',
            'source_state' => 'partially_cancelled','suspended_pf' => '0','net_pf' => '30']);
        RankingCorpusDocument::complete($this->manifest([$fact]),[$fact]);
        self::assertSame('1',$fact['consumption_order']); self::assertSame($context,$fact['context_sha256']);
    }

    #[DataProvider('invalidFact')]
    public function testUnknownFinancialHistoricalOrContradictoryFactsAreRejected(string $change): void
    {
        $fact = $this->fact();
        switch ($change) {
            case 'purchase_reference': $fact['purchase_reference'] = 'synthetic.pack'; break;
            case 'available': $fact['available_pf'] = '60'; break;
            case 'pc': $fact['pc'] = '1'; break;
            case 'email': $fact['email'] = 'synthetic@example.test'; break;
            case 'old': unset($fact['ranking_context'],$fact['context_sha256'],$fact['ordering_epoch'],$fact['consumption_order']); break;
            case 'context': $fact['context_sha256'] = str_repeat('f',64); break;
            case 'unselected': $fact['ranking_context']['sessions'] = []; break;
            case 'self': $fact['creator_faluss_id'] = $fact['member_faluss_id']; break;
            case 'foreign_pair': $fact['client_authority'] = 'fixture.other'; break;
            case 'quantity': $fact['net_pf'] = '39'; break;
            case 'source': $fact['allocations'][0]['source_state'] = 'pending'; break;
            case 'source_net': $fact['allocations'][0]['net_pf'] = '39'; break;
            case 'source_confirmed_cancelled': $fact['cancelled_pf'] = '1'; $fact['net_pf'] = '39';
                $fact['allocations'][0]['cancelled_pf'] = '1'; $fact['allocations'][0]['net_pf'] = '39'; break;
            case 'source_cancelled_live': $fact['allocations'][0]['source_state'] = 'cancelled'; break;
            case 'disputed_live': $fact['allocations'][0]['source_state'] = 'disputed'; break;
            case 'allocations_map': $fact['allocations'] = ['private' => $fact['allocations'][0]]; break;
            case 'duplicate_lot': $fact['allocations'][] = $fact['allocations'][0]; break;
            case 'too_many_lots': $fact['allocations'] = array_fill(0,33,$fact['allocations'][0]); break;
            case 'source_payment': $fact['allocations'][0]['payment_reference'] = 'synthetic.only'; break;
        }
        $this->expectException(ModelViolation::class); RankingCorpusDocument::fact($fact);
    }

    public static function invalidFact(): iterable
    {
        foreach (['purchase_reference','available','pc','email','old','context','unselected','self','foreign_pair','quantity',
            'source','source_net','source_confirmed_cancelled','source_cancelled_live','disputed_live','allocations_map',
            'duplicate_lot','too_many_lots','source_payment'] as $change) { yield $change => [$change]; }
    }

    public function testSharedLotMustRetainTheSameOwnerAndLatestCompleteRevisionAcrossFacts(): void
    {
        $first = $this->fact(); $second = $this->fact(2); $second['allocations'] = $first['allocations'];
        $second['allocations'][0] = array_reverse($second['allocations'][0],true);
        self::assertSame('80',RankingCorpusDocument::summary([$first,$second])['net_pf']);
        foreach (['source_revision' => '2','source_state' => 'partially_cancelled','source_evidence_id' => RankedFixtures::ORIGIN,
            'source_evidence_sha256' => str_repeat('c',64),'owner' => RankedFixtures::EPOCH] as $field => $value) {
            $bad = $second;
            if ($field === 'owner') { $bad['member_faluss_id'] = $value; } else { $bad['allocations'][0][$field] = $value; }
            try { RankingCorpusDocument::summary([$first,$bad]); self::fail('Mixed source revisions/owners must fail.'); }
            catch (ModelViolation) { self::assertTrue(true); }
        }
    }

    public function testDuplicateFactsOrdersEpochsAndBackwardPrimaryInstantsFailAcrossPageBoundaries(): void
    {
        $first = $this->fact(); $second = $this->fact(2);
        self::assertSame('2',RankingCorpusDocument::summary([$first,$second])['fact_count']);
        foreach (['attribution_id','consumption_id','ledger_entry_uuid','consumption_order','ordering_epoch','confirmed_at'] as $field) {
            $bad = $second;
            $bad[$field] = match ($field) {
                'ordering_epoch' => RankedFixtures::SESSION,
                'confirmed_at' => '2026-10-05 10:00:00.123455',
                default => $first[$field],
            };
            try { RankingCorpusDocument::summary([$first,$bad]); self::fail('Contradictory authority must fail.'); }
            catch (ModelViolation) { self::assertTrue(true); }
        }
        $this->expectException(ModelViolation::class); RankingCorpusDocument::summary([$second,$first]);
    }

    public function testOriginAndPolicyCannotBeMixedOrDerivedFromReceiptDelivery(): void
    {
        $facts = [$this->fact()]; $manifest = $this->manifest($facts);
        foreach (['origin_id' => RankedFixtures::SESSION,'policy_version' => '2.0.0',
            'ordering_epoch' => RankedFixtures::SESSION,'last_order' => '0','created_at' => '2026-10-05 10:00:00.123455',
            'scope' => 'known_members','contract' => 'hub.purchased-pf.snapshot/2.0.0','audience' => 'fixture.other',
            'issuer' => 'fixture.fans','net_pf' => '41','full_sha256' => str_repeat('f',64)] as $field => $value) {
            $bad = array_replace($manifest,[$field => $value]);
            try { RankingCorpusDocument::complete($bad,$facts); self::fail('Manifest mismatch must fail.'); }
            catch (ModelViolation) { self::assertTrue(true); }
        }
    }

    public function testMissingPageAlteredManifestAndNonCanonicalListCannotClaimCompleteness(): void
    {
        $facts = array_map($this->fact(...),range(1,101)); $manifest = $this->manifest($facts);
        $page = ['manifest' => $manifest,'page_index' => '0','cursor' => RankedFixtures::ORIGIN,
            'next_cursor' => RankedFixtures::SESSION,'facts' => array_slice($facts,0,100)];
        $cases = [array_replace($page,['next_cursor' => null]),array_replace($page,['page_index' => '2']),
            array_replace($page,['facts' => array_slice($facts,0,99)]),array_replace($page,['manifest' => array_replace($manifest,['revision' => '2'])]),
            array_replace($page,['facts' => ['one' => $facts[0]]])];
        foreach ($cases as $case) {
            try { RankingCorpusDocument::page($case,$manifest); self::fail('A partial page is not exhaustive.'); }
            catch (ModelViolation) { self::assertTrue(true); }
        }
        $this->expectException(ModelViolation::class); RankingCorpusDocument::summary(['first' => $facts[0]]);
    }

    public function testAggregateOverflowIsRejectedInsteadOfRounding(): void
    {
        $facts = [$this->fact(),$this->fact(2)];
        foreach ($facts as &$fact) {
            $fact['purchased_pf'] = $fact['net_pf'] = (string) ModelValues::MAX_INTEGER;
            $fact['allocations'][0]['original_pf'] = $fact['allocations'][0]['net_pf'] = (string) ModelValues::MAX_INTEGER;
        }
        unset($fact);
        $this->expectException(ModelViolation::class); RankingCorpusDocument::summary($facts);
    }

    public function testMaximumSessionAndAllocationPageFitsOnlyTheDedicatedResponseBudget(): void
    {
        $context = RankedFixtures::context(); $context['sessions'] = [];
        for ($number = 1; $number <= 10; $number++) {
            $session = RankedFixtures::session(); $session['session_id'] = sprintf('%08x-9999-4999-8999-999999999999',$number);
            $session['scope'] = 'local'; $session['country'] = 'FR'; $session['territory_ref'] = str_repeat('a',80);
            $session['territory_policy_revision'] = RankedFixtures::ORIGIN; $session['territory_admission_revision'] = (string) ModelValues::MAX_INTEGER;
            $context['sessions'][] = $session;
        }
        $facts = [];
        foreach (range(1,100) as $order) {
            $fact = $this->fact($order); $fact['ranking_context'] = $context; $fact['context_sha256'] = RankingContext::fingerprint($context);
            $fact['purchased_pf'] = $fact['net_pf'] = '32'; $fact['allocations'] = [];
            foreach (range(1,32) as $number) {
                $id = sprintf('%08x-6666-4666-8666-666666666666',$order * 32 + $number);
                $fact['allocations'][] = ['lot_id' => $id,'source_revision' => (string) ModelValues::MAX_INTEGER,'source_state' => 'confirmed',
                    'source_evidence_id' => $id,'source_evidence_sha256' => str_repeat('a',64),'original_pf' => '1',
                    'cancelled_pf' => '0','suspended_pf' => '0','net_pf' => '1'];
            }
            $facts[] = $fact;
        }
        $manifest = $this->manifest($facts); RankingCorpusDocument::complete($manifest,$facts);
        $page = ['manifest' => $manifest,'page_index' => '0','cursor' => RankedFixtures::ORIGIN,'next_cursor' => null,'facts' => $facts];
        RankingCorpusDocument::page($page,$manifest); $length = strlen(CanonicalJson::encode(['page' => $page]));
        self::assertGreaterThan(1048576,$length); self::assertLessThan(4194304,$length);
    }

    public function testCorpusPermissionMustBeExplicitAndCannotGrantSnapshotOrEconomicPermissions(): void
    {
        $dedicated = new PeerPolicy('fixture.fans','fixture.hub',['pf.ranking.corpus'],[]); $dedicated->allow('pf.ranking.corpus');
        foreach (['pf.snapshot','pf.confirm','pf.ranking.context.register'] as $permission) {
            try { $dedicated->allow($permission); self::fail('Dedicated read permission is not admission.'); }
            catch (ModelViolation) { self::assertTrue(true); }
        }
        $existing = new PeerPolicy('fixture.fans','fixture.hub',['pf.snapshot','wallet.read','pf.context.delegate'],[]);
        $this->expectException(ModelViolation::class); $existing->allow('pf.ranking.corpus');
    }
}
