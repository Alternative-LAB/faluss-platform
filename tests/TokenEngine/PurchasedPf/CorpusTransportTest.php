<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CorpusTransport;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingCorpusDocument;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SignedEnvelope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CorpusTransportTest extends TestCase
{
    private const MEMBER = '22222222-2222-4222-8222-222222222222';
    private PeerPolicy $fans;
    private PeerPolicy $hub;
    private string $public;

    protected function setUp(): void
    {
        define('ABSPATH',__DIR__ . '/');
        require_once dirname(__DIR__,2) . '/Federation/WordPressStubs.php';
        if (!function_exists('is_wp_error')) { eval('namespace { class WP_Error { public function __construct(...$args) {} } function is_wp_error($v): bool { return $v instanceof WP_Error; } }'); }
        require_once dirname(__DIR__,3) . '/src/Federation/Legacy/includes/class-faluss-federation-crypto.php';
        $seed = random_bytes(32); define('FALUSS_FEDERATION_PRIVATE_SEED',SignedEnvelope::encode($seed));
        $pair = sodium_crypto_sign_seed_keypair($seed); $this->public = SignedEnvelope::encode(sodium_crypto_sign_publickey($pair));
        sodium_memzero($pair); sodium_memzero($seed);
        // Pure codec examples only. Distinct node keys and network are exercised by the following disposable HTTP lot.
        $keys = ['recipe-key' => ['public_key' => $this->public,'state' => 'active',
            'from' => gmdate('Y-m-d\TH:i:s\Z',time()-3600),'until' => gmdate('Y-m-d\TH:i:s\Z',time()+3600)]];
        $this->fans = new PeerPolicy('fixture.fans','fixture.hub',['pf.ranking.corpus'],$keys);
        $this->hub = new PeerPolicy('fixture.hub','fixture.fans',['pf.ranking.corpus'],$keys);
    }

    /** @return array<string,string> */
    private function fields(string $op = 'start'): array
    {
        return ['operation' => $op,'read_id' => RankedFixtures::EPOCH,'origin_id' => RankedFixtures::ORIGIN,'policy_version' => '1.0.0',
            'corpus_id' => in_array($op,['page','finish'],true) ? RankedFixtures::SESSION : '',
            'cursor' => $op === 'page' ? RankedFixtures::ORIGIN : ''];
    }

    /** @return array<string,mixed> */
    private function request(string $op = 'start'): array
    {
        $sealed = CorpusTransport::sealRequest($this->fields($op),str_repeat('a',64),'recipe-key',time()+60);
        return SignedEnvelope::open(SignedEnvelope::CORPUS_REQUEST,CanonicalJson::object($sealed['wire'],CorpusTransport::MAX_REQUEST_WIRE),$this->fans,time());
    }

    /** @return array<string,mixed> */
    private function manifest(): array
    {
        return ['contract' => RankingCorpusDocument::CONTRACT,'corpus_id' => RankedFixtures::SESSION,'issuer' => 'fixture.hub','audience' => 'fixture.fans',
            'origin_id' => RankedFixtures::ORIGIN,'policy_version' => '1.0.0','ordering_epoch' => RankedFixtures::EPOCH,'epoch' => RankedFixtures::SESSION,
            'revision' => '1','last_order' => '0','created_at' => '2026-10-01 10:00:00.000000','scope' => RankingCorpusDocument::SCOPE,
            'full_sha256' => hash('sha256',CanonicalJson::encode([]))] + RankingCorpusDocument::summary([]);
    }

    /** @return array<string,mixed> */
    private function response(string $op = 'start'): array
    {
        $manifest = $this->manifest();
        $page = ['manifest' => $manifest,'page_index' => '0','cursor' => RankedFixtures::ORIGIN,'next_cursor' => null,'facts' => []];
        $result = ['state' => $op === 'page' ? 'page' : 'materialized','page' => $page];
        if ($op === 'lookup') { $result = ['state' => 'absent']; }
        if ($op === 'finish') { $result = ['state' => 'current','manifest' => $manifest,
            'manifest_sha256' => hash('sha256',CanonicalJson::encode($manifest)),'verified_at' => '2026-10-01 10:00:00.000001']; }
        return ['contract' => RankingCorpusDocument::CONTRACT,'kind' => SignedEnvelope::CORPUS_RESPONSE,'issuer' => 'fixture.hub','audience' => 'fixture.fans',
            'nonce' => str_repeat('b',64),'request_sha256' => str_repeat('c',64),'issued_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z',time()+60),'outcome' => 'ok','result' => $result] + $this->fields($op);
    }

    public function testOneDedicatedPermissionBindsAllReadOperationsWithoutARecipient(): void
    {
        foreach (['start','lookup','page','finish'] as $op) {
            $request = CorpusTransport::request($this->request($op),$this->fans,time());
            self::assertSame($op,$request['operation']); self::assertSame(RankedFixtures::ORIGIN,$request['origin_id']);
            self::assertSame(str_repeat('a',64),$request['read_key']); self::assertArrayNotHasKey('member_faluss_id',$request);
            $response = CorpusTransport::response($this->response($op),$this->hub,$this->fields($op),str_repeat('b',64),str_repeat('c',64),time());
            self::assertSame('ok',$response['outcome']);
        }
    }

    #[DataProvider('changedRequests')]
    public function testChangedDelegationNeverExpandsARead(string $field, mixed $value): void
    {
        $request = array_replace($this->request(),[$field => $value]);
        $this->expectException(ModelViolation::class); CorpusTransport::request($request,$this->fans,time());
    }

    public static function changedRequests(): iterable
    {
        foreach (['operation' => 'lookup','read_id' => RankedFixtures::SESSION,'origin_id' => RankedFixtures::SESSION,'policy_version' => '2.0.0',
            'read_key' => str_repeat('b',64),'nonce' => str_repeat('f',64),'audience' => 'fixture.other','issuer' => 'fixture.other',
            'contract' => 'hub.purchased-pf.snapshot/2.0.0','kind' => SignedEnvelope::SNAPSHOT_REQUEST,
            'member_faluss_id' => self::MEMBER,'cursor' => RankedFixtures::SESSION,'corpus_id' => RankedFixtures::SESSION] as $field => $value) { yield $field => [$field,$value]; }
    }

    public function testExpiredOrFutureContextsAndInvalidOperationsAreRefused(): void
    {
        foreach ([['operation' => 'confirm'],['operation' => 'reserve'],['operation' => 'release'],['operation' => 'page','corpus_id' => '1'],
            ['operation' => 'page','corpus_id' => RankedFixtures::SESSION,'cursor' => '0'],['member_faluss_id' => self::MEMBER],
            ['operation' => 'finish','corpus_id' => RankedFixtures::SESSION,'cursor' => RankedFixtures::ORIGIN]] as $bad) {
            try { CorpusTransport::fields(array_replace($this->fields(),$bad)); self::fail('Economic, recipient or offset operations must be refused.'); }
            catch (ModelViolation) { self::assertTrue(true); }
        }
        $request = $this->request(); $issued = strtotime($request['issued_at']);
        foreach ([$issued-6,$issued+61] as $now) {
            try { CorpusTransport::request($request,$this->fans,$now); self::fail('Future or expired context must be refused.'); }
            catch (ModelViolation) { self::assertTrue(true); }
        }
        $this->expectException(ModelViolation::class); CorpusTransport::sealRequest($this->fields(),str_repeat('a',64),'recipe-key',time()-1);
    }

    public function testOtherPermissionsOrOwnersCannotReadTheCorpus(): void
    {
        $payload = $this->request();
        foreach ([new PeerPolicy('fixture.fans','fixture.hub',['pf.snapshot','pf.context.delegate','wallet.read'],[]),
            new PeerPolicy('fixture.other','fixture.hub',['pf.ranking.corpus'],[])] as $peer) {
            try { CorpusTransport::request($payload,$peer,time()); self::fail('Explicit owner permission is required.'); }
            catch (ModelViolation) { self::assertTrue(true); }
        }
    }

    #[DataProvider('changedResponses')]
    public function testResponseCannotCrossRequestOriginCursorOrRead(string $field, mixed $value): void
    {
        $response = array_replace($this->response(),[$field => $value]);
        $this->expectException(ModelViolation::class); CorpusTransport::response($response,$this->hub,$this->fields(),str_repeat('b',64),str_repeat('c',64),time());
    }

    public static function changedResponses(): iterable
    {
        foreach (['origin_id' => RankedFixtures::SESSION,'read_id' => RankedFixtures::SESSION,'policy_version' => '2.0.0','operation' => 'lookup',
            'nonce' => str_repeat('a',64),'request_sha256' => str_repeat('d',64),'issuer' => 'fixture.other','audience' => 'fixture.other',
            'cursor' => RankedFixtures::SESSION,'corpus_id' => RankedFixtures::SESSION,'member_faluss_id' => self::MEMBER,
            'outcome' => 'opened','result' => ['state' => 'absent']] as $field => $value) { yield $field => [$field,$value]; }
    }

    public function testPageAndFenceCannotPretendToBeAnotherGenerationOrValidFactSet(): void
    {
        foreach (['page','finish'] as $op) {
            $response = $this->response($op);
            if ($op === 'page') { $response['result']['page']['cursor'] = RankedFixtures::SESSION; }
            else { $response['result']['manifest_sha256'] = str_repeat('f',64); }
            try { CorpusTransport::response($response,$this->hub,$this->fields($op),str_repeat('b',64),str_repeat('c',64),time()); self::fail('Wrong page/fence binding must fail.'); }
            catch (ModelViolation) { self::assertTrue(true); }
        }
        $response = $this->response(); $response['result']['page']['manifest']['origin_id'] = RankedFixtures::SESSION;
        $this->expectException(ModelViolation::class); CorpusTransport::response($response,$this->hub,$this->fields(),str_repeat('b',64),str_repeat('c',64),time());
    }

    public function testSignedRefusalAndUnknownDoNotContainAManufacturedResult(): void
    {
        foreach (['refused','unknown'] as $outcome) {
            $response = array_replace($this->response(),['outcome' => $outcome,'result' => ['reason' => 'pf_corpus_superseded']]);
            self::assertSame($outcome,CorpusTransport::response($response,$this->hub,$this->fields(),str_repeat('b',64),str_repeat('c',64),time())['outcome']);
            $response['result']['page'] = [];
            try { CorpusTransport::response($response,$this->hub,$this->fields(),str_repeat('b',64),str_repeat('c',64),time()); self::fail('Refusal cannot contain a page.'); }
            catch (ModelViolation) { self::assertTrue(true); }
        }
    }

    public function testSignaturesDomainsAndOuterWireBoundsAreSeparateFromLegacy(): void
    {
        $request = CorpusTransport::sealRequest($this->fields(),str_repeat('a',64),'recipe-key',time()+60);
        $envelope = CanonicalJson::object($request['wire'],CorpusTransport::MAX_REQUEST_WIRE);
        foreach ([SignedEnvelope::REQUEST,SignedEnvelope::SNAPSHOT_REQUEST,SignedEnvelope::RANKING_REQUEST] as $old) {
            try { SignedEnvelope::open($old,$envelope,$this->fans,time()); self::fail('No cross-domain signature.'); }
            catch (ModelViolation) { self::assertTrue(true); }
        }
        $bytes = CanonicalJson::encode(['body' => str_repeat('a',4194280)]);
        $wire = CanonicalJson::encode(SignedEnvelope::seal(SignedEnvelope::CORPUS_RESPONSE,$bytes,'recipe-key'));
        self::assertGreaterThan(4194304,strlen($wire)); self::assertLessThan(CorpusTransport::MAX_RESPONSE_WIRE,strlen($wire));
        self::assertSame($bytes,CanonicalJson::encode(SignedEnvelope::open(SignedEnvelope::CORPUS_RESPONSE,CanonicalJson::object($wire,CorpusTransport::MAX_RESPONSE_WIRE),$this->hub,time())));
        $envelope['signature_base64url'] = str_repeat('A',86);
        $this->expectException(ModelViolation::class); SignedEnvelope::open(SignedEnvelope::CORPUS_REQUEST,$envelope,$this->fans,time());
    }

    public function testRevokedUnknownAndWrongKeysAreRefused(): void
    {
        $request = CorpusTransport::sealRequest($this->fields(),str_repeat('a',64),'recipe-key',time()+60);
        $envelope = CanonicalJson::object($request['wire']);
        foreach ([[],['recipe-key' => ['public_key' => $this->public,'state' => 'revoked','from' => gmdate('Y-m-d\TH:i:s\Z',time()-3600),'until' => gmdate('Y-m-d\TH:i:s\Z',time()+3600)]],
            ['recipe-key' => ['public_key' => SignedEnvelope::encode(random_bytes(32)),'state' => 'active','from' => gmdate('Y-m-d\TH:i:s\Z',time()-3600),'until' => gmdate('Y-m-d\TH:i:s\Z',time()+3600)]]] as $keys) {
            $peer = new PeerPolicy('fixture.fans','fixture.hub',['pf.ranking.corpus'],$keys);
            try { SignedEnvelope::open(SignedEnvelope::CORPUS_REQUEST,$envelope,$peer,time()); self::fail('Unknown/revoked key must fail.'); }
            catch (ModelViolation) { self::assertTrue(true); }
        }
    }
}
