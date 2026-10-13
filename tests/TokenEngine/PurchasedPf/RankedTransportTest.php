<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\DelegatedContext;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedIntent;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedTransport;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingContext;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SignedEnvelope;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\TransportMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RankedTransportTest extends TestCase
{
    private PeerPolicy $fans;
    private PeerPolicy $hub;
    private string $public;

    protected function setUp(): void
    {
        define('ABSPATH',__DIR__ . '/'); require_once dirname(__DIR__,2) . '/Federation/WordPressStubs.php';
        if (!function_exists('is_wp_error')) { eval('namespace { class WP_Error {} function is_wp_error($v): bool { return $v instanceof WP_Error; } }'); }
        require_once dirname(__DIR__,3) . '/src/Federation/Legacy/includes/class-faluss-federation-crypto.php';
        $seed = random_bytes(32); define('FALUSS_FEDERATION_PRIVATE_SEED',SignedEnvelope::encode($seed));
        $pair = sodium_crypto_sign_seed_keypair($seed); $this->public = SignedEnvelope::encode(sodium_crypto_sign_publickey($pair));
        sodium_memzero($pair); sodium_memzero($seed);
        $keys = ['recipe-key' => ['public_key' => $this->public,'state' => 'active','from' => gmdate('Y-m-d\TH:i:s\Z',time()-3600),'until' => gmdate('Y-m-d\TH:i:s\Z',time()+3600)]];
        $permissions = ['pf.context.delegate','pf.reserve','pf.confirm','pf.release','pf.lookup'];
        $this->fans = new PeerPolicy('fixture.fans','fixture.hub',$permissions,$keys);
        $this->hub = new PeerPolicy('fixture.hub','fixture.fans',$permissions,$keys);
    }

    /** @return array<string,mixed> */
    private function request(string $op = 'confirm', string $lookup = ''): array
    {
        $sealed = RankedTransport::sealRequest(RankedIntent::fromArray(RankedFixtures::intent()),$op,str_repeat('a',64),$lookup,'recipe-key',time()+60);
        return SignedEnvelope::open(SignedEnvelope::RANKING_REQUEST,CanonicalJson::object($sealed['wire'],RankedTransport::MAX_WIRE),$this->fans,time());
    }

    /** @return array<string,mixed> */
    private function reply(string $state = 'confirmed'): array
    {
        $result = ['state' => $state];
        if ($state === 'confirmed') { $result['receipt'] = SignedEnvelope::seal(SignedEnvelope::RANKING_RECEIPT,CanonicalJson::encode(RankedFixtures::receipt()),'recipe-key'); }
        return ['contract' => RankedIntent::CONTRACT,'kind' => SignedEnvelope::RANKING_RESPONSE,'issuer' => 'fixture.hub','audience' => 'fixture.fans',
            'operation' => 'confirm','nonce' => str_repeat('b',64),'request_sha256' => str_repeat('c',64),'issued_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z',time()+60),'outcome' => 'ok','result' => $result];
    }

    public function testFourOperationsBindExactFullIntentKeyAndDedicatedContext(): void
    {
        foreach (['reserve','confirm','release','lookup'] as $op) {
            $request = RankedTransport::request($this->request($op,$op === 'lookup' ? 'confirm' : ''),$this->fans,time());
            self::assertSame($op,$request['operation']); self::assertSame(str_repeat('a',64),$request['key']);
            self::assertSame(CanonicalJson::encode(RankedFixtures::intent()),CanonicalJson::encode($request['intent']->values));
        }
    }

    #[DataProvider('changedRequests')]
    public function testAlteredRequestNeverDelegatesAnotherOperation(string $field, mixed $value): void
    {
        $request = array_replace($this->request(),[$field => $value]);
        $this->expectException(ModelViolation::class); RankedTransport::request($request,$this->fans,time());
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function changedRequests(): iterable
    {
        foreach (['contract' => DelegatedContext::CONTRACT,'kind' => SignedEnvelope::REQUEST,'issuer' => 'fixture.other','audience' => 'fixture.other',
            'operation' => 'reserve','nonce' => str_repeat('f',64),'operation_key' => str_repeat('e',64),'lookup_operation' => 'confirm',
            'intent' => [],'member_faluss_id' => RankedFixtures::ORIGIN,'context' => [],'operation2' => 'confirm'] as $field => $value) { yield $field => [$field,$value]; }
    }

    public function testSignedContextCannotChangeCategorySessionIdentityOrLifetime(): void
    {
        foreach (['category','member','creator','lifetime','old-domain'] as $change) {
            $request = $this->request(); $context = SignedEnvelope::open(SignedEnvelope::RANKING_CONTEXT,$request['context'],$this->fans,time());
            $domain = SignedEnvelope::RANKING_CONTEXT;
            if ($change === 'category') { $context['intent']['ranking_context']['creator_category'] = 'music'; }
            elseif ($change === 'member') { $context['intent']['member_faluss_id'] = $context['intent']['creator_faluss_id']; }
            elseif ($change === 'creator') { $context['intent']['creator_faluss_id'] = RankedFixtures::ORIGIN; }
            elseif ($change === 'lifetime') { $context['expires_at'] = gmdate('Y-m-d\TH:i:s\Z',strtotime($context['expires_at'])-1); }
            else { $domain = SignedEnvelope::CONTEXT; }
            $request['context'] = SignedEnvelope::seal($domain,CanonicalJson::encode($context),'recipe-key');
            if ($change === 'creator') {
                // A fully authenticated explicit identity change is a distinct intent, not an impossible syntactic intent.
                $changed = RankedTransport::request($request,$this->fans,time());
                self::assertNotSame(RankedIntent::fromArray(RankedFixtures::intent())->fingerprint(),$changed['intent']->fingerprint());
                continue;
            }
            try { RankedTransport::request($request,$this->fans,time()); self::fail('Changed context must be rejected.'); }
            catch (ModelViolation $error) { self::assertNotSame('',$error->reason); }
        }
    }

    public function testHistoricalPermissionsExpiryAndLegacyWireNeverAdmitRankedMessage(): void
    {
        $request = $this->request();
        foreach ([new PeerPolicy('fixture.fans','fixture.hub',['wallet.read','pf.snapshot'],[]),new PeerPolicy('fixture.other','fixture.hub',['pf.context.delegate','pf.confirm'],[])] as $peer) {
            try { RankedTransport::request($request,$peer,time()); self::fail('Explicit exact peer permissions required.'); }
            catch (ModelViolation $error) { self::assertNotSame('',$error->reason); }
        }
        foreach ([time()-10,time()+61] as $now) {
            try { RankedTransport::request($request,$this->fans,$now); self::fail('Expired or future context required.'); }
            catch (ModelViolation $error) { self::assertNotSame('',$error->reason); }
        }
        try { TransportMessage::request($request,$this->fans,time()); self::fail('Old wire must reject new format.'); }
        catch (ModelViolation $error) { self::assertNotSame('',$error->reason); }
        $this->expectException(ModelViolation::class);
        RankedTransport::sealRequest(RankedIntent::fromArray(RankedFixtures::intent()),'confirm',str_repeat('a',64),'','recipe-key',time()-1);
    }

    public function testAllReplyStatesAndSignedFailureKeepTheirMeaning(): void
    {
        $intent = RankedIntent::fromArray(RankedFixtures::intent());
        foreach (['reserved','released','expired','not_found','confirmed'] as $state) {
            $answer = RankedTransport::response($this->reply($state),$this->hub,$intent,'confirm',str_repeat('b',64),str_repeat('c',64),time());
            self::assertSame($state,$answer['result']['state']);
        }
        foreach (['unknown','refused'] as $outcome) {
            $reply = array_replace($this->reply(),['outcome' => $outcome,'result' => ['reason' => 'pf_commit_unknown']]);
            self::assertSame($outcome,RankedTransport::response($reply,$this->hub,$intent,'confirm',str_repeat('b',64),str_repeat('c',64),time())['outcome']);
        }
    }

    #[DataProvider('changedResponses')]
    public function testReplyCannotCrossRequestOrContract(string $field, mixed $value): void
    {
        $reply = array_replace($this->reply(),[$field => $value]);
        $this->expectException(ModelViolation::class);
        RankedTransport::response($reply,$this->hub,RankedIntent::fromArray(RankedFixtures::intent()),'confirm',str_repeat('b',64),str_repeat('c',64),time());
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function changedResponses(): iterable
    {
        foreach (['contract' => DelegatedContext::CONTRACT,'kind' => SignedEnvelope::RESPONSE,'issuer' => 'fixture.other','audience' => 'fixture.other',
            'operation' => 'reserve','nonce' => str_repeat('e',64),'request_sha256' => str_repeat('d',64),'result' => ['state' => 'credited'],
            'outcome' => 'applied','score' => '40'] as $field => $value) { yield $field => [$field,$value]; }
    }

    public function testReceiptAuthenticatesFullIntentAndOriginalHubOrderOnly(): void
    {
        $intent = RankedIntent::fromArray(RankedFixtures::intent());
        foreach (['intent','signature','legacy-domain','unknown-key'] as $change) {
            $reply = $this->reply(); $receipt = RankedFixtures::receipt();
            if ($change === 'intent') {
                $receipt['ranking_context']['category_revision'] = '3'; $receipt['context_sha256'] = RankingContext::fingerprint($receipt['ranking_context']);
                $reply['result']['receipt'] = SignedEnvelope::seal(SignedEnvelope::RANKING_RECEIPT,CanonicalJson::encode($receipt),'recipe-key');
            } elseif ($change === 'signature') { $reply['result']['receipt']['signature_base64url'] = str_repeat('A',86); }
            elseif ($change === 'legacy-domain') { $reply['result']['receipt'] = SignedEnvelope::seal(SignedEnvelope::RECEIPT,CanonicalJson::encode($receipt),'recipe-key'); }
            else { $reply['result']['receipt']['key_id'] = 'unknown-key'; }
            try { RankedTransport::response($reply,$this->hub,$intent,'confirm',str_repeat('b',64),str_repeat('c',64),time()); self::fail('Receipt authentication required.'); }
            catch (ModelViolation $error) { self::assertNotSame('',$error->reason); }
        }
        $reply = $this->reply();
        $this->expectException(ModelViolation::class); TransportMessage::response($reply,$this->hub,'confirm',str_repeat('b',64),str_repeat('c',64),time());
    }
}
