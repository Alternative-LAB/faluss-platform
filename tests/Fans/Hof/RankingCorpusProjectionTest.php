<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Fans\Hof;

use Faluss\Platform\Fans\Hof\RankingCorpusProjection;
use Faluss\Platform\Tests\TokenEngine\PurchasedPf\RankedFixtures;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingContext;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingCorpusDocument;
use PHPUnit\Framework\TestCase;

final class RankingCorpusProjectionTest extends TestCase
{
    private static function id(int $id): string { return sprintf('%08x-1111-4111-8111-111111111111',$id); }

    /** @return array<string,mixed> */
    private static function fact(int $order, int $fan, int $creator, string $category, string $date, int $net = 10): array
    {
        $context = array_replace(RankedFixtures::context(),['creator_category' => $category,'sessions' => []]);
        return ['attribution_id' => self::id($order),'consumption_id' => self::id($order+1000),'ledger_entry_uuid' => self::id($order+2000),
            'ledger_fact_sha256' => str_repeat('b',64),'member_faluss_id' => self::id($fan),'creator_faluss_id' => self::id($creator),
            'client_authority' => 'fixture.fans','purchased_pf' => '10','cancelled_pf' => (string) (10-$net),'suspended_pf' => '0','net_pf' => (string) $net,
            'confirmed_at' => $date,'consumption_order' => (string) $order,'ordering_epoch' => RankedFixtures::EPOCH,
            'ranking_context' => $context,'context_sha256' => RankingContext::fingerprint($context),
            'allocations' => [['lot_id' => self::id($order+3000),'source_revision' => $net === 10 ? '1' : '2',
                'source_state' => $net === 10 ? 'confirmed' : ($net === 0 ? 'cancelled' : 'partially_cancelled'),
                'source_evidence_id' => self::id($order+4000),'source_evidence_sha256' => str_repeat('a',64),
                'original_pf' => '10','cancelled_pf' => (string) (10-$net),'suspended_pf' => '0','net_pf' => (string) $net]]];
    }

    /** @param list<array<string,mixed>> $facts
     * @return array{manifest:array<string,mixed>,facts:list<array<string,mixed>>,verified_at:string} */
    private static function source(array $facts, int $revision = 1): array
    {
        $manifest = ['contract' => RankingCorpusDocument::CONTRACT,'corpus_id' => self::id($revision+5000),'issuer' => 'fixture.hub',
            'audience' => 'fixture.fans','origin_id' => RankedFixtures::ORIGIN,'policy_version' => '1.0.0',
            'ordering_epoch' => RankedFixtures::EPOCH,'epoch' => RankedFixtures::SESSION,'revision' => (string) $revision,
            'last_order' => $facts === [] ? '0' : $facts[array_key_last($facts)]['consumption_order'],'created_at' => '2026-12-31 23:00:00.000000',
            'scope' => RankingCorpusDocument::SCOPE,'full_sha256' => hash('sha256',CanonicalJson::encode($facts))] + RankingCorpusDocument::summary($facts);
        return ['manifest' => $manifest,'facts' => $facts,'verified_at' => '2026-12-31 23:00:01.000000'];
    }

    public function testOneConsumptionFeedsTwoFamiliesAndOnlyItsOriginalCategoryWithoutMonthlyReset(): void
    {
        $result = RankingCorpusProjection::build(self::source([
            self::fact(1,10,20,'arts','2026-03-31 21:59:59.999999'),
            self::fact(2,10,21,'music','2026-03-31 22:00:00.000000')]));
        self::assertSame('20',$result['general']['fans'][0]['points']);
        self::assertSame(['10','10'],array_column($result['general']['creators'],'points'));
        self::assertSame('10',$result['categories']['arts']['fans'][0]['points']);
        self::assertSame('10',$result['categories']['music']['fans'][0]['points']);
        self::assertSame(['2026-03','2026-04'],array_keys($result['months']));
        foreach ($result['months'] as $statement) {
            self::assertSame('Europe/Paris',$statement['timezone']);
            self::assertSame('10',$statement['fans'][0]['points']);
            self::assertArrayNotHasKey('position',$statement['fans'][0]);
        }
        self::assertSame(['fans' => [],'creators' => []],$result['categories']['games']);
    }

    public function testDstMonthBoundariesUseParisAndCorrectionsStayInTheirOriginalMonth(): void
    {
        $facts = [self::fact(1,10,20,'arts','2026-10-31 22:59:59.999999'),
            self::fact(2,10,20,'arts','2026-10-31 23:00:00.000000',3)];
        $result = RankingCorpusProjection::build(self::source($facts,2));
        self::assertSame(['2026-10','2026-11'],array_keys($result['months']));
        self::assertSame('2026-09-30 22:00:00.000000',$result['months']['2026-10']['start']);
        self::assertSame('2026-10-31 23:00:00.000000',$result['months']['2026-10']['end']);
        self::assertSame('3',$result['months']['2026-11']['fans'][0]['points']);
        self::assertSame('13',$result['general']['fans'][0]['points']);
        self::assertSame('2026-10-31 23:00:00.000000',$result['general']['fans'][0]['reached_at']);
    }

    public function testLatestCancellationRemovesPlacesAndAncienneteButKeepsZeroMonthlyActivity(): void
    {
        $facts = [self::fact(1,10,20,'arts','2026-10-01 09:00:00.000000'),
            self::fact(2,11,21,'arts','2026-10-01 09:05:00.000000'),
            self::fact(3,10,20,'arts','2026-11-01 09:00:00.000000',0)];
        $result = RankingCorpusProjection::build(self::source($facts,3));
        self::assertSame([self::id(10),self::id(11)],array_column($result['general']['fans'],'faluss_id'));
        self::assertSame('2026-10-01 09:00:00.000000',$result['general']['fans'][0]['reached_at']);
        self::assertSame('0',$result['months']['2026-11']['fans'][0]['points']);
        self::assertArrayNotHasKey('position',$result['months']['2026-11']['fans'][0]);
    }

    public function testIdenticalOwnerTimestampsUseHubOrderInEachFamily(): void
    {
        $result = RankingCorpusProjection::build(self::source([
            self::fact(41,11,21,'arts','2026-10-01 09:00:00.123456'),
            self::fact(42,10,20,'arts','2026-10-01 09:00:00.123456')]));
        self::assertSame([self::id(11),self::id(10)],array_column($result['general']['fans'],'faluss_id'));
        self::assertSame([self::id(21),self::id(20)],array_column($result['categories']['arts']['creators'],'faluss_id'));
    }

    public function testPartialAndTotalCorrectionsRebuildAttainmentFromSurvivingContributions(): void
    {
        $facts = [self::fact(1,10,20,'arts','2026-10-01 09:00:00.000000',0),
            self::fact(2,11,21,'arts','2026-10-02 09:00:00.000000',5),
            self::fact(3,10,20,'arts','2026-10-03 09:00:00.000000',5)];
        $source = self::source($facts,4); $result = RankingCorpusProjection::build($source);
        foreach ([$result['general'],$result['categories']['arts']] as $ranking) {
            self::assertSame([self::id(11),self::id(10)],array_column($ranking['fans'],'faluss_id'));
            self::assertSame(['1','2'],array_column($ranking['fans'],'position'));
            self::assertSame('2026-10-03 09:00:00.000000',$ranking['fans'][1]['reached_at']);
            self::assertSame([self::id(21),self::id(20)],array_column($ranking['creators'],'faluss_id'));
        }
        self::assertSame($result,RankingCorpusProjection::build($source));
        self::assertSame('4',$result['source']['revision']);
    }

    public function testDisputeAndResolutionRebuildBothFamiliesWithoutInventingNewOrder(): void
    {
        $fact = self::fact(7,10,20,'arts','2026-10-01 09:00:00.000000');
        $disputed = $fact; $disputed['net_pf'] = '0'; $disputed['suspended_pf'] = '10';
        $disputed['allocations'][0] = array_replace($disputed['allocations'][0],[
            'source_revision' => '2','source_state' => 'disputed','net_pf' => '0','suspended_pf' => '10']);
        $hidden = RankingCorpusProjection::build(self::source([$disputed],2));
        self::assertSame(['fans' => [],'creators' => []],$hidden['general']);
        self::assertSame('0',$hidden['months']['2026-10']['fans'][0]['points']);
        $fact['allocations'][0]['source_revision'] = '3';
        $resolved = RankingCorpusProjection::build(self::source([$fact],3));
        foreach (['fans','creators'] as $family) {
            self::assertSame('10',$resolved['general'][$family][0]['points']);
            self::assertSame('7',$resolved['general'][$family][0]['consumption_order']);
            self::assertSame($fact['confirmed_at'],$resolved['general'][$family][0]['reached_at']);
        }
    }

    public function testEmptyOwnerCorpusCreatesNoMonthlyActivityOrImaginaryPlaces(): void
    {
        $result = RankingCorpusProjection::build(self::source([]));
        self::assertSame(['fans' => [],'creators' => []],$result['general']); self::assertSame([],$result['months']);
        self::assertArrayNotHasKey('available_pf',$result); self::assertArrayNotHasKey('pc',$result);
    }

    public function testMissingDuplicateOrChangedFactsCannotProduceAnExactProjection(): void
    {
        $source = self::source([self::fact(1,10,20,'arts','2026-10-01 09:00:00.000000')]);
        foreach (['missing','duplicate','altered','unattested-pc','earlier-fence'] as $change) {
            $bad = $source;
            if ($change === 'missing') { $bad['facts'] = []; }
            elseif ($change === 'duplicate') { $bad['facts'][] = $bad['facts'][0]; }
            elseif ($change === 'altered') { $bad['facts'][0]['net_pf'] = '9'; }
            elseif ($change === 'unattested-pc') { $bad['facts'][0]['pc'] = '100'; }
            else { $bad['verified_at'] = '2026-12-30 23:00:00.000000'; }
            try { RankingCorpusProjection::build($bad); self::fail('Only a complete attested generation can be projected.'); }
            catch (ModelViolation $error) { self::assertNotSame('',$error->reason); }
        }
    }
}
