<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\BarrierTransport;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedIntent;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingBarrier;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SignedEnvelope;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\TransportMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BarrierTransportTest extends TestCase
{
    private PeerPolicy $fans;
    private PeerPolicy $hub;
    /** @var array<string,array{public_key:string,state:string,from:string,until:string}> */
    private array $keys;

    protected function setUp(): void
    {
        define('ABSPATH',__DIR__ . '/'); require_once dirname(__DIR__,2) . '/Federation/WordPressStubs.php';
        if (!function_exists('is_wp_error')) { eval('namespace { class WP_Error {} function is_wp_error($v): bool { return $v instanceof WP_Error; } }'); }
        require_once dirname(__DIR__,3) . '/src/Federation/Legacy/includes/class-faluss-federation-crypto.php';
        $seed = random_bytes(32); define('FALUSS_FEDERATION_PRIVATE_SEED',SignedEnvelope::encode($seed));
        $pair = sodium_crypto_sign_seed_keypair($seed); $public = SignedEnvelope::encode(sodium_crypto_sign_publickey($pair));
        sodium_memzero($pair); sodium_memzero($seed);
        $this->keys = ['recipe-key' => ['public_key' => $public,'state' => 'active','from' => gmdate('Y-m-d\TH:i:s\Z',time()-3600),'until' => gmdate('Y-m-d\TH:i:s\Z',time()+3600)]];
        $permissions = ['pf.ranking.context.register','pf.ranking.context.close','pf.lookup'];
        $this->fans = new PeerPolicy('fixture.fans','fixture.hub',$permissions,$this->keys);
        $this->hub = new PeerPolicy('fixture.hub','fixture.fans',$permissions,$this->keys);
    }

    /** @return array<string,mixed> */
    private function fields(string $operation = 'register', string $lookup = ''): array
    {
        $refs = RankingBarrier::references(RankedIntent::fromArray(RankedFixtures::intent()));
        $ref = array_values(array_filter($refs,static fn (array $ref): bool => $ref['content']['kind'] === 'origin'))[0];
        $object = ($operation === 'lookup' ? $lookup : $operation) === 'register'
            ? ['content' => $ref['content'],'valid_from' => '2026-01-01 00:00:00.000000','valid_until' => '2027-01-01 00:00:00.000000']
            : array_intersect_key($ref,array_flip(['barrier_key','version','content_sha256'])) + ['reason' => 'origin_closed'];
        return ['operation' => $operation,'action_id' => ModelFixtures::intent()['attribution_id'],'origin_id' => RankedFixtures::ORIGIN,
            'policy_version' => '1.0.0','lookup_operation' => $lookup,'object' => $object];
    }

    /** @return array<string,mixed> */
    private function request(string $operation = 'register', string $lookup = ''): array
    {
        $sealed = BarrierTransport::sealRequest($this->fields($operation,$lookup),str_repeat('a',64),'recipe-key',time()+60);
        return SignedEnvelope::open(SignedEnvelope::BARRIER_REQUEST,CanonicalJson::object($sealed['wire'],BarrierTransport::MAX_WIRE),$this->fans,time());
    }

    /** @param array<string,mixed> $fields
     * @return array<string,mixed> */
    private function reply(array $fields, string $state): array
    {
        $operation = $fields['operation'] === 'lookup' ? $fields['lookup_operation'] : $fields['operation'];
        $result = ['state' => $state];
        if ($state !== 'not_found') {
            $ref = $operation === 'register' ? RankingBarrier::reference($fields['object']['content']) : $fields['object'];
            $result += array_intersect_key($ref,array_flip(['barrier_key','version','content_sha256','reason']))
                + ['operation' => $operation,'effective_at' => '2026-10-06 18:00:00.000001'];
        }
        return ['contract' => BarrierTransport::CONTRACT,'kind' => SignedEnvelope::BARRIER_RESPONSE,'issuer' => 'fixture.hub','audience' => 'fixture.fans',
            'nonce' => str_repeat('b',64),'request_sha256' => str_repeat('c',64),'issued_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z',time()+60),'outcome' => 'ok','result' => $result] + BarrierTransport::responseFields($fields);
    }

    public function testRegisterCloseAndTheirLookupsBindDurableActionObjectAndKey(): void
    {
        foreach ([['register',''],['close',''],['lookup','register'],['lookup','close']] as [$operation,$lookup]) {
            $request = BarrierTransport::request($this->request($operation,$lookup),$this->fans,time());
            self::assertSame(str_repeat('a',64),$request['operation_key']);
            self::assertSame(CanonicalJson::encode($this->fields($operation,$lookup)),CanonicalJson::encode(array_intersect_key($request,$this->fields($operation,$lookup))));
            self::assertArrayNotHasKey('purchased_pf',$request); self::assertArrayNotHasKey('member_faluss_id',$request);
        }
    }

    #[DataProvider('requestChanges')]
    public function testAlteredFieldsNeverDelegateAnotherAction(string $field, mixed $value): void
    {
        $request = array_replace($this->request(),[$field => $value]);
        $this->expectException(ModelViolation::class); BarrierTransport::request($request,$this->fans,time());
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function requestChanges(): iterable
    {
        foreach (['contract' => 'hub.purchased-pf/0.3.0','kind' => SignedEnvelope::RANKING_REQUEST,'issuer' => 'fixture.other','audience' => 'fixture.other',
            'action_id' => RankedFixtures::ORIGIN,'origin_id' => ModelFixtures::MEMBER,'policy_version' => '1.0.1','operation_key' => str_repeat('d',64),
            'lookup_operation' => 'close','operation' => 'close','nonce' => str_repeat('e',64),'object' => [],'context' => [],'score' => '100'] as $field => $value) {
            yield $field => [$field,$value];
        }
    }

    public function testCloseLookupRequiresBothCloseAndLookupPermissions(): void
    {
        foreach (['pf.ranking.context.close','pf.lookup'] as $missing) {
            $permissions = array_values(array_diff(['pf.ranking.context.close','pf.lookup'],[$missing]));
            $peer = new PeerPolicy('fixture.fans','fixture.hub',$permissions,$this->keys);
            try { BarrierTransport::request($this->request('lookup','close'),$peer,time()); self::fail('Both dedicated permissions required.'); }
            catch (ModelViolation $error) { self::assertSame('pf_permission_denied',$error->reason); }
        }
        $request = $this->request();
        foreach ([new PeerPolicy('fixture.fans','fixture.hub',['pf.confirm','pf.context.delegate','wallet.read'],$this->keys),
            new PeerPolicy('fixture.other','fixture.hub',['pf.ranking.context.register'],$this->keys)] as $peer) {
            try { BarrierTransport::request($request,$peer,time()); self::fail('No inherited permission or foreign peer.'); }
            catch (ModelViolation $error) { self::assertNotSame('',$error->reason); }
        }
    }

    public function testUnknownRevokedExpiredAndOldDomainSignaturesAreRejected(): void
    {
        $sealed = BarrierTransport::sealRequest($this->fields(),str_repeat('a',64),'recipe-key',time()+60);
        $envelope = CanonicalJson::object($sealed['wire'],BarrierTransport::MAX_WIRE);
        foreach (['unknown','revoked','legacy'] as $case) {
            $keys = $this->keys; $wire = $envelope; $domain = SignedEnvelope::BARRIER_REQUEST;
            if ($case === 'unknown') { $wire['key_id'] = 'unknown-key'; }
            elseif ($case === 'revoked') { $keys['recipe-key'] = array_replace($this->keys['recipe-key'],['state' => 'revoked']); }
            else { $domain = SignedEnvelope::REQUEST; }
            try { SignedEnvelope::open($domain,$wire,new PeerPolicy('fixture.fans','fixture.hub',['pf.ranking.context.register'],$keys),time()); self::fail('Dedicated active signature required.'); }
            catch (ModelViolation $error) { self::assertNotSame('',$error->reason); }
        }
        foreach ([time()-10,time()+61] as $now) {
            try { BarrierTransport::request($this->request(),$this->fans,$now); self::fail('Fresh context required.'); }
            catch (ModelViolation $error) { self::assertNotSame('',$error->reason); }
        }
        $this->expectException(ModelViolation::class); BarrierTransport::sealRequest($this->fields(),str_repeat('a',64),'recipe-key',time()-1);
    }

    public function testOriginalRegisterAcknowledgementMayReportClosedOrSupersededWithoutReopening(): void
    {
        foreach (['active','closed','superseded'] as $state) {
            $fields = $this->fields(); $reply = $this->reply($fields,$state);
            $answer = BarrierTransport::response($reply,$this->hub,$fields,str_repeat('b',64),str_repeat('c',64),time());
            self::assertSame($state,$answer['result']['state']); self::assertArrayNotHasKey('object',$reply);
        }
        $fields = $this->fields('close');
        foreach (['closed','superseded'] as $state) { self::assertSame($state,BarrierTransport::response($this->reply($fields,$state),$this->hub,$fields,str_repeat('b',64),str_repeat('c',64),time())['result']['state']); }
        $this->expectException(ModelViolation::class); BarrierTransport::response($this->reply($fields,'active'),$this->hub,$fields,str_repeat('b',64),str_repeat('c',64),time());
    }

    public function testCertainAbsenceExistsOnlyAtLookupAndFailuresContainNoObject(): void
    {
        $fields = $this->fields('lookup','register');
        self::assertSame(['state' => 'not_found'],BarrierTransport::response($this->reply($fields,'not_found'),$this->hub,$fields,str_repeat('b',64),str_repeat('c',64),time())['result']);
        foreach (['unknown','refused'] as $outcome) {
            $reply = array_replace($this->reply($fields,'not_found'),['outcome' => $outcome,'result' => ['reason' => 'pf_barrier_commit_unknown']]);
            self::assertSame($outcome,BarrierTransport::response($reply,$this->hub,$fields,str_repeat('b',64),str_repeat('c',64),time())['outcome']);
        }
        $fields = $this->fields(); $this->expectException(ModelViolation::class);
        BarrierTransport::response($this->reply($fields,'not_found'),$this->hub,$fields,str_repeat('b',64),str_repeat('c',64),time());
    }

    #[DataProvider('responseChanges')]
    public function testResponseCannotCrossActionOrExposeAdditionalData(string $field, mixed $value): void
    {
        $fields = $this->fields(); $reply = array_replace($this->reply($fields,'active'),[$field => $value]);
        $this->expectException(ModelViolation::class); BarrierTransport::response($reply,$this->hub,$fields,str_repeat('b',64),str_repeat('c',64),time());
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function responseChanges(): iterable
    {
        foreach (['contract' => 'hub.purchased-pf/0.3.0','kind' => SignedEnvelope::RANKING_RESPONSE,'issuer' => 'fixture.other','audience' => 'fixture.other',
            'action_id' => RankedFixtures::ORIGIN,'origin_id' => ModelFixtures::MEMBER,'policy_version' => '1.0.1','nonce' => str_repeat('d',64),
            'request_sha256' => str_repeat('e',64),'object_sha256' => str_repeat('f',64),'result' => ['state' => 'confirmed'],'score' => '100'] as $field => $value) { yield $field => [$field,$value]; }
    }

    public function testResultRequiresExactReferenceMicrosecondInstantAndCloseReason(): void
    {
        $fields = $this->fields('close');
        foreach (['version' => '2','content_sha256' => str_repeat('f',64),'effective_at' => '2026-10-06T18:00:00Z','reason' => 'session_cancelled'] as $field => $value) {
            $reply = $this->reply($fields,'closed'); $reply['result'][$field] = $value;
            try { BarrierTransport::response($reply,$this->hub,$fields,str_repeat('b',64),str_repeat('c',64),time()); self::fail('Exact owner reference required.'); }
            catch (ModelViolation $error) { self::assertNotSame('',$error->reason); }
        }
        $this->expectException(ModelViolation::class); TransportMessage::request($this->request(),$this->fans,time());
    }

    /** @return array<string,mixed> */
    private function completionFields(string $operation = 'close'): array
    {
        $refs = RankingBarrier::references(RankedIntent::fromArray(RankedFixtures::intent()));
        $ref = array_values(array_filter($refs,static fn (array $ref): bool => $ref['content']['kind'] === 'session'))[0];
        $fields = $this->fields($operation,$operation === 'lookup' ? 'close' : '');
        $fields['object'] = array_intersect_key($ref,array_flip(['barrier_key','version','content_sha256'])) + ['reason' => 'session_completed'];
        return $fields;
    }

    public function testCompletionRequiresExplicitVersionForCloseAndItsSameActionLookup(): void
    {
        foreach (['close','lookup'] as $operation) {
            $fields = $this->completionFields($operation);
            $sealed = BarrierTransport::sealRequest($fields,str_repeat('a',64),'recipe-key',time()+60,BarrierTransport::COMPLETION_CONTRACT);
            $payload = SignedEnvelope::open(SignedEnvelope::BARRIER_REQUEST,CanonicalJson::object($sealed['wire'],BarrierTransport::MAX_WIRE),$this->fans,time());
            $accepted = BarrierTransport::request($payload,$this->fans,time(),BarrierTransport::COMPLETION_CONTRACT);
            self::assertSame('session_completed',$accepted['object']['reason']);
            self::assertSame($fields['action_id'],$accepted['action_id']);
            self::assertSame(str_repeat('a',64),$accepted['operation_key']);
            try { BarrierTransport::request($payload,$this->fans,time()); self::fail('The legacy validator must reject 1.1.'); }
            catch (ModelViolation $error) { self::assertNotSame('',$error->reason); }
        }
    }

    public function testLegacyReferenceAndDefaultSignerStillRefuseCompletion(): void
    {
        $fields = $this->completionFields();
        try { RankingBarrier::closeReference($fields['object']); self::fail('The historical reference must stay unchanged.'); }
        catch (ModelViolation $error) { self::assertSame('pf_barrier_invalid_reason',$error->reason); }
        $this->expectException(ModelViolation::class);
        BarrierTransport::sealRequest($fields,str_repeat('a',64),'recipe-key',time()+60);
    }

    public function testContractCannotBeChangedOutsideItsSignedDelegation(): void
    {
        $payload = $this->request(); $payload['contract'] = BarrierTransport::COMPLETION_CONTRACT;
        $this->expectException(ModelViolation::class);
        BarrierTransport::request($payload,$this->fans,time(),BarrierTransport::COMPLETION_CONTRACT);
    }

    public function testCompletionResponseBindsContractReasonActionAndPrimaryInstant(): void
    {
        $fields = $this->completionFields();
        $result = ['operation' => 'close','state' => 'closed','effective_at' => '2026-11-01 00:00:00.000000'] + $fields['object'];
        $reply = ['contract' => BarrierTransport::COMPLETION_CONTRACT,'kind' => SignedEnvelope::BARRIER_RESPONSE,
            'issuer' => 'fixture.hub','audience' => 'fixture.fans','nonce' => str_repeat('b',64),'request_sha256' => str_repeat('c',64),
            'issued_at' => gmdate('Y-m-d\TH:i:s\Z'),'expires_at' => gmdate('Y-m-d\TH:i:s\Z',time()+60),'outcome' => 'ok','result' => $result]
            + BarrierTransport::responseFields($fields,BarrierTransport::COMPLETION_CONTRACT);
        self::assertSame(['outcome' => 'ok','result' => $result],BarrierTransport::response($reply,$this->hub,$fields,
            str_repeat('b',64),str_repeat('c',64),time(),BarrierTransport::COMPLETION_CONTRACT));
        foreach (['legacy-contract','wrong-reason','wrong-action','extra-field'] as $change) {
            $bad = $reply;
            if ($change === 'legacy-contract') { $bad['contract'] = BarrierTransport::CONTRACT; }
            elseif ($change === 'wrong-reason') { $bad['result']['reason'] = 'session_cancelled'; }
            elseif ($change === 'wrong-action') { $bad['action_id'] = RankedFixtures::ORIGIN; }
            else { $bad['result']['winner'] = ModelFixtures::MEMBER; }
            try { BarrierTransport::response($bad,$this->hub,$fields,str_repeat('b',64),str_repeat('c',64),time(),BarrierTransport::COMPLETION_CONTRACT); self::fail('Exact 1.1 response required.'); }
            catch (ModelViolation $error) { self::assertNotSame('',$error->reason); }
        }
    }

    public function testUnknownVersionAndUnapprovedCompletionReasonRemainClosed(): void
    {
        foreach ([BarrierTransport::CONTRACT,'hub.purchased-pf.ranking-barriers/1.2.0'] as $contract) {
            try { BarrierTransport::fields($this->completionFields(),$contract); self::fail('Unsupported contract must fail.'); }
            catch (ModelViolation $error) { self::assertNotSame('',$error->reason); }
        }
        $fields = $this->completionFields(); $fields['object']['reason'] = 'session_finished_early';
        $this->expectException(ModelViolation::class); BarrierTransport::fields($fields,BarrierTransport::COMPLETION_CONTRACT);
    }
}
