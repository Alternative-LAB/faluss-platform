<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Fans\Hof;

use Faluss\Platform\Fans\Hof\RankingCorpusProjection;
use Faluss\Platform\Fans\Hof\RankingSessionProjection;
use Faluss\Platform\Tests\TokenEngine\PurchasedPf\RankedFixtures;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingContext;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingCorpusDocument;
use PHPUnit\Framework\TestCase;

final class RankingSessionProjectionTest extends TestCase
{
    private static function id(int $id): string { return sprintf('%08x-1111-4111-8111-111111111111',$id); }

    /** @return array<string,mixed> */
    private static function session(int $id, string $scope = 'international'): array
    {
        return array_replace(RankedFixtures::session(),['session_id' => self::id($id),'scope' => $scope,
            'territory_policy_revision' => $scope === 'international' ? '' : self::id(990),
            'territory_admission_revision' => $scope === 'international' ? '0' : '1',
            'country' => $scope === 'international' ? '' : 'FR','territory_ref' => $scope === 'local' ? 'fixture.city' : '']);
    }

    /** @param list<array<string,mixed>> $sessions
     * @return array<string,mixed> */
    private static function fact(int $order, int $fan, int $creator, array $sessions, int $net = 10): array
    {
        $context = array_replace(RankedFixtures::context(),['sessions' => $sessions]);
        return ['attribution_id' => self::id($order),'consumption_id' => self::id($order+1000),'ledger_entry_uuid' => self::id($order+2000),
            'ledger_fact_sha256' => str_repeat('b',64),'member_faluss_id' => self::id($fan),'creator_faluss_id' => self::id($creator),
            'client_authority' => 'fixture.fans','purchased_pf' => '10','cancelled_pf' => (string) (10-$net),'suspended_pf' => '0','net_pf' => (string) $net,
            'confirmed_at' => '2026-10-05 10:00:00.123456','consumption_order' => (string) $order,'ordering_epoch' => RankedFixtures::EPOCH,
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

    public function testOneFactFeedsTenChosenSessionsAndThePersistentRankingsOncePerDimension(): void
    {
        $choices = []; for ($id = 100; $id < 110; $id++) { $choices[] = self::session($id); }
        $source = self::source([self::fact(1,10,20,$choices)]); $before = $source;
        $result = RankingSessionProjection::build($source); $persistent = RankingCorpusProjection::build($source);
        self::assertCount(10,$result['sessions']); self::assertSame($before,$source);
        foreach ($result['sessions'] as $session) {
            self::assertSame('10',$session['fans'][0]['points']); self::assertSame('10',$session['creators'][0]['points']);
            self::assertSame('1',$session['fans'][0]['position']); self::assertArrayNotHasKey('winner',$session);
        }
        self::assertSame('10',$persistent['general']['fans'][0]['points']);
        self::assertSame($persistent['source'],$result['source']);
    }

    public function testScopesRemainDistinctWithoutRestrictingFansBySessionTerritory(): void
    {
        $result = RankingSessionProjection::build(self::source([self::fact(1,10,20,[
            self::session(100,'local'),self::session(101,'national'),self::session(102)])]));
        self::assertSame(['local','national','international'],array_column($result['sessions'],'scope'));
        foreach ($result['sessions'] as $session) { self::assertSame(self::id(10),$session['fans'][0]['faluss_id']); }
        self::assertSame('fixture.city',$result['sessions'][0]['territory_ref']);
    }

    public function testUnselectedFactsDoNotRetroactivelyFeedSessionsOrInventEmptySessions(): void
    {
        $source = self::source([self::fact(1,10,20,[]),self::fact(2,11,21,[self::session(100)])]);
        $result = RankingSessionProjection::build($source);
        self::assertSame([self::id(11)],array_column($result['sessions'][0]['fans'],'faluss_id'));
        self::assertSame(['source' => RankingCorpusProjection::build(self::source([]))['source'],'sessions' => []],
            RankingSessionProjection::build(self::source([])));
    }

    public function testReadmissionVersionChangesKeepEarlierConfirmedContributionsInTheSameSession(): void
    {
        $first = self::session(100); $second = array_replace($first,['barrier_version' => '2','admission_revision' => '4',
            'admitted_at' => '2026-10-05 10:00:00.000000']);
        $result = RankingSessionProjection::build(self::source([
            self::fact(1,10,20,[$first]),self::fact(2,10,20,[$second])]));
        self::assertCount(1,$result['sessions']); self::assertSame('20',$result['sessions'][0]['creators'][0]['points']);
        self::assertSame('2',$result['sessions'][0]['creators'][0]['consumption_order']);
        self::assertArrayNotHasKey('barrier_version',$result['sessions'][0]);
    }

    public function testIdenticalTimestampsAndCorrectedNetUseTheHubOrderInBothFamilies(): void
    {
        $choices = [self::session(100)]; $source = self::source([
            self::fact(1,10,20,$choices,0),self::fact(2,11,21,$choices,5),self::fact(3,10,20,$choices,5)],4);
        $result = RankingSessionProjection::build($source)['sessions'][0];
        foreach (['fans' => [self::id(11),self::id(10)],'creators' => [self::id(21),self::id(20)]] as $family => $expected) {
            self::assertSame($expected,array_column($result[$family],'faluss_id'));
            self::assertSame(['2','3'],array_column($result[$family],'consumption_order'));
            self::assertSame(['1','2'],array_column($result[$family],'position'));
        }
        self::assertSame(RankingSessionProjection::build($source),RankingSessionProjection::build($source));
    }

    public function testDisputeAndResolutionAfterClosureUseTheLatestNetAndOriginalChronology(): void
    {
        $fact = self::fact(7,10,20,[self::session(100)]); $disputed = $fact;
        $disputed['net_pf'] = '0'; $disputed['suspended_pf'] = '10';
        $disputed['allocations'][0] = array_replace($disputed['allocations'][0],[
            'source_revision' => '2','source_state' => 'disputed','net_pf' => '0','suspended_pf' => '10']);
        $hidden = RankingSessionProjection::build(self::source([$disputed],2))['sessions'][0];
        self::assertSame([],$hidden['fans']); self::assertSame([],$hidden['creators']);
        $fact['allocations'][0]['source_revision'] = '3';
        $restored = RankingSessionProjection::build(self::source([$fact],3))['sessions'][0];
        self::assertSame('10',$restored['creators'][0]['points']);
        self::assertSame($fact['confirmed_at'],$restored['creators'][0]['reached_at']);
        self::assertSame('7',$restored['creators'][0]['consumption_order']);
        self::assertArrayNotHasKey('state',$restored); self::assertArrayNotHasKey('winner',$restored);
    }

    public function testConflictingFrozenMetadataCannotProduceExactSessionResults(): void
    {
        foreach (['rules_sha256' => str_repeat('a',64),'rules_revision' => '3',
            'ends_at' => '2026-11-02 00:00:00.000000','scope' => 'national'] as $field => $replacement) {
            $session = self::session(100); $changed = $session; $changed[$field] = $replacement;
            if ($field === 'scope') { $changed = self::session(100,'national'); }
            $source = self::source([self::fact(1,10,20,[$session]),self::fact(2,11,21,[$changed])]);
            try { RankingSessionProjection::build($source); self::fail('Opened rules must stay frozen.'); }
            catch (ModelViolation $error) { self::assertSame('hof_conflicting_frozen_session',$error->reason); }
        }
    }

    public function testIncompleteOrDuplicateCorpusCannotBeReplacedByTheSelectedSubset(): void
    {
        $source = self::source([self::fact(1,10,20,[]),self::fact(2,11,21,[self::session(100)])]);
        foreach (['omit','duplicate','changed-net','extra-pc'] as $change) {
            $bad = $source;
            if ($change === 'omit') { array_shift($bad['facts']); }
            elseif ($change === 'duplicate') { $bad['facts'][] = $bad['facts'][1]; }
            elseif ($change === 'changed-net') { $bad['facts'][1]['net_pf'] = '9'; }
            else { $bad['facts'][1]['pc'] = '10'; }
            try { RankingSessionProjection::build($bad); self::fail('The entire source must be complete.'); }
            catch (ModelViolation $error) { self::assertNotSame('',$error->reason); }
        }
    }

    public function testLateAdmissionCannotAttachEarlierConfirmations(): void
    {
        $session = self::session(100); $session['admitted_at'] = '2026-10-06 00:00:00.000000';
        $this->expectException(ModelViolation::class);
        self::source([self::fact(1,10,20,[$session])]);
    }
}
