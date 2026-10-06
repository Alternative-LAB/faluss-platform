<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\AttributionIntent;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\DelegatedContext;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PrivateReceipt;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedDelegation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedIntent;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedReceipt;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingContext;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingValues;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SignedEnvelope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RankedProtocolTest extends TestCase
{
    private PeerPolicy $peer;
    private int $now = 1791194400;

    protected function setUp(): void
    {
        if (!defined('ABSPATH')) { define('ABSPATH',__DIR__ . '/'); }
        require_once dirname(__DIR__,2) . '/Federation/WordPressStubs.php';
        if (!function_exists('is_wp_error')) { eval('namespace { class WP_Error { public function __construct(...$args) {} } function is_wp_error($value): bool { return $value instanceof WP_Error; } }'); }
        require_once dirname(__DIR__,3) . '/src/Federation/Legacy/includes/class-faluss-federation-crypto.php';
        $seed = random_bytes(32); define('FALUSS_FEDERATION_PRIVATE_SEED',SignedEnvelope::encode($seed));
        $pair = sodium_crypto_sign_seed_keypair($seed); $public = SignedEnvelope::encode(sodium_crypto_sign_publickey($pair)); sodium_memzero($pair); sodium_memzero($seed);
        $this->peer = new PeerPolicy('fixture.fans','fixture.hub',['pf.context.delegate','pf.confirm','pf.lookup'],
            ['recipe-k1' => ['public_key' => $public,'state' => 'active','from' => '2026-01-01T00:00:00Z','until' => '2027-01-01T00:00:00Z'],
             'recipe-revoked' => ['public_key' => $public,'state' => 'revoked','from' => '2026-01-01T00:00:00Z','until' => '2027-01-01T00:00:00Z']]);
    }

    public function testContextChangesTheIntentionAndRemainsBoundOnReplay(): void
    {
        $values = RankedFixtures::intent(); $intent = RankedIntent::fromArray($values);
        self::assertSame(ModelFixtures::intent(),$intent->base->values);
        self::assertNotSame($intent->base->fingerprint(),$intent->fingerprint());
        $values['ranking_context']['category_revision'] = '3'; $values['context_sha256'] = RankingContext::fingerprint($values['ranking_context']);
        self::assertNotSame($intent->fingerprint(),RankedIntent::fromArray($values)->fingerprint());
        $this->expectException(ModelViolation::class); AttributionIntent::fromArray($intent->values);
    }

    public function testZeroSessionsAndTenExplicitSessionsHaveNoImplicitSelection(): void
    {
        $context = RankedFixtures::context(); $context['sessions'] = [];
        self::assertSame([],RankingContext::validate($context)['sessions']);
        for ($n=1; $n<=10; $n++) { $session = RankedFixtures::session(); $session['session_id'] = sprintf('%08d-9999-4999-8999-999999999999',$n); $context['sessions'][] = $session; }
        self::assertCount(10,RankingContext::validate($context)['sessions']);
        $context['sessions'][] = RankedFixtures::session(); $this->expectException(ModelViolation::class); RankingContext::validate($context);
    }

    #[DataProvider('invalidContext')]
    public function testStrictContextsRefuseAmbiguousOrUnattestableFields(string $change): void
    {
        $context = RankedFixtures::context();
        switch ($change) {
            case 'unknown': $context['extra'] = ''; break;
            case 'origin': $context['origin_id'] = ''; break;
            case 'category': $context['creator_category'] = 'unknown'; break;
            case 'country_policy': $context['country_policy_revision'] = ''; break;
            case 'category_revision': $context['category_revision'] = '02'; break;
            case 'number': $context['category_revision'] = 2; break;
            case 'duplicate': $context['sessions'][] = $context['sessions'][0]; break;
            case 'unordered': $context['sessions'][] = array_replace($context['sessions'][0],['session_id' => RankedFixtures::ORIGIN]); break;
            case 'scope': $context['sessions'][0]['scope'] = 'fiction'; break;
            case 'international_territory': $context['sessions'][0]['territory_ref'] = 'fixture'; break;
            case 'local_no_review': $context['sessions'][0]['scope'] = 'local'; break;
            case 'invalid_date': $context['sessions'][0]['starts_at'] = '2026-02-30 00:00:00.000000'; break;
            case 'too_long': $context['sessions'][0]['ends_at'] = '2027-10-01 00:00:00.000000'; break;
            case 'admitted_too_late': $context['sessions'][0]['admitted_at'] = $context['sessions'][0]['ends_at']; break;
            case 'rules_digest': $context['sessions'][0]['rules_sha256'] = 'wrong'; break;
            case 'zero_barrier': $context['sessions'][0]['barrier_version'] = '0'; break;
            case 'unicode': $context['sessions'][0]['territory_ref'] = 'é'; break;
        }
        $this->expectException(ModelViolation::class); RankingContext::validate($context);
    }

    public static function invalidContext(): iterable
    {
        foreach (['unknown','origin','category','country_policy','category_revision','number','duplicate','unordered','scope',
            'international_territory','local_no_review','invalid_date','too_long','admitted_too_late','rules_digest','zero_barrier','unicode'] as $change) { yield $change => [$change]; }
    }

    public function testLocalAndNationalRequireExplicitTerritorialVersions(): void
    {
        $context = RankedFixtures::context(); $session = &$context['sessions'][0];
        $session['scope'] = 'local'; $session['country'] = 'FR'; $session['territory_ref'] = 'fictional.area';
        $session['territory_policy_revision'] = RankedFixtures::ORIGIN; $session['territory_admission_revision'] = '4';
        self::assertSame('local',RankingContext::validate($context)['sessions'][0]['scope']);
        $session['scope'] = 'national'; $session['territory_ref'] = '';
        self::assertSame('national',RankingContext::validate($context)['sessions'][0]['scope']);
    }

    public function testReceiptRetainsMicrosecondsAndExactContextWithOldAllocationChecks(): void
    {
        $receipt = RankedFixtures::receipt(); RankedReceipt::validate($receipt,'fixture.hub','fixture.fans',RankedIntent::fromArray(RankedFixtures::intent()));
        self::assertSame('2026-10-05 10:00:00.123456',RankingValues::utc($receipt['confirmed_at']));
        $this->expectException(ModelViolation::class); PrivateReceipt::validate($receipt,'fixture.hub','fixture.fans',AttributionIntent::fromArray(ModelFixtures::intent()));
    }

    #[DataProvider('badReceipt')]
    public function testForgedAndOutOfBoundsReceiptsAreRejected(string $change): void
    {
        $payload = RankedFixtures::receipt();
        switch ($change) {
            case 'epoch': $payload['ordering_epoch'] = ''; break;
            case 'order': $payload['consumption_order'] = '0'; break;
            case 'overflow': $payload['consumption_order'] = '9007199254740992'; break;
            case 'context': $payload['ranking_context']['category_revision'] = '4'; break;
            case 'digest': $payload['context_sha256'] = str_repeat('b',64); break;
            case 'quantity': $payload['allocations'][0]['purchased_pf'] = '39'; break;
            case 'after_end': $payload['confirmed_at'] = '2026-11-01 00:00:00.000000'; break;
            case 'before_admission': $payload['confirmed_at'] = '2026-10-02 10:00:00.000000'; break;
            case 'audience': $payload['audience'] = 'fixture.other'; break;
            case 'old_contract': $payload['contract'] = DelegatedContext::CONTRACT; break;
            case 'extra': $payload['extra'] = ''; break;
        }
        $this->expectException(ModelViolation::class); RankedReceipt::validate($payload,'fixture.hub','fixture.fans',RankedIntent::fromArray(RankedFixtures::intent()));
    }

    public static function badReceipt(): iterable
    { foreach (['epoch','order','overflow','context','digest','quantity','after_end','before_admission','audience','old_contract','extra'] as $change) { yield $change => [$change]; } }

    public function testDelegationRetainsExistingSessionIdentityAndKeyProtections(): void
    {
        $data = $this->delegation();
        self::assertSame(RankedIntent::fromArray(RankedFixtures::intent())->fingerprint(),
            RankedDelegation::validate($data,$this->peer,'confirm',str_repeat('a',64),str_repeat('b',64),'',$this->now)->fingerprint());
        $data['intent']['creator_faluss_id'] = $data['intent']['member_faluss_id'];
        $this->expectException(ModelViolation::class); RankedDelegation::validate($data,$this->peer,'confirm',str_repeat('a',64),str_repeat('b',64),'',$this->now);
    }

    #[DataProvider('badDelegation')]
    public function testWrongAudienceExpiryAndChangedKeyAreNotAccepted(string $field): void
    {
        $data = $this->delegation(); $data[$field] = $field === 'expires_at' ? gmdate('Y-m-d\TH:i:s\Z',$this->now) : 'wrong';
        $this->expectException(ModelViolation::class); RankedDelegation::validate($data,$this->peer,'confirm',str_repeat('a',64),str_repeat('b',64),'',$this->now);
    }

    public static function badDelegation(): iterable
    { foreach (['audience','contract','kind','key_sha256','expires_at'] as $field) { yield $field => [$field]; } }

    public function testContextPermissionsAreDedicatedAndExplicit(): void
    {
        $dedicated = new PeerPolicy('fixture.fans','fixture.hub',['pf.ranking.context.register','pf.ranking.context.close'],[]);
        $dedicated->allow('pf.ranking.context.register'); $dedicated->allow('pf.ranking.context.close');
        $this->expectException(ModelViolation::class); $this->peer->allow('pf.ranking.context.close');
    }

    public function testNewEnvelopeDomainsRoundTripWithoutChangingLegacyDomains(): void
    {
        $bytes = CanonicalJson::encode($this->delegation());
        foreach ([SignedEnvelope::RANKING_CONTEXT,SignedEnvelope::RANKING_RECEIPT,SignedEnvelope::RANKING_REQUEST,SignedEnvelope::RANKING_RESPONSE] as $domain) {
            $sealed = SignedEnvelope::seal($domain,$bytes,'recipe-k1');
            self::assertSame($bytes,CanonicalJson::encode(SignedEnvelope::open($domain,$sealed,$this->peer,$this->now)));
        }
        self::assertSame('fans.hub-purchased-pf/0.2.0',DelegatedContext::CONTRACT);
        self::assertSame('faluss.hub-purchased-pf.receipt/1',SignedEnvelope::RECEIPT);
    }

    #[DataProvider('badSignature')]
    public function testNewDomainsRetainSignatureAndKeyControls(string $change): void
    {
        $payload = CanonicalJson::encode($this->delegation()); $domain = SignedEnvelope::RANKING_CONTEXT;
        $envelope = SignedEnvelope::seal($domain,$payload,$change === 'revoked' ? 'recipe-revoked' : 'recipe-k1');
        if ($change === 'old_domain') { $domain = SignedEnvelope::CONTEXT; }
        if ($change === 'unknown') { $envelope['key_id'] = 'unknown'; }
        if ($change === 'tamper') { $envelope['payload_base64url'] = SignedEnvelope::encode(CanonicalJson::encode(['tampered' => true])); }
        $this->expectException(ModelViolation::class); SignedEnvelope::open($domain,$envelope,$this->peer,$this->now);
    }

    public static function badSignature(): iterable
    { foreach (['old_domain','unknown','revoked','tamper'] as $change) { yield $change => [$change]; } }

    /** @return array<string,mixed> */
    private function delegation(): array
    { return ['contract' => RankedIntent::CONTRACT,'kind' => SignedEnvelope::RANKING_CONTEXT,'issuer' => 'fixture.fans','audience' => 'fixture.hub',
        'operation' => 'confirm','nonce' => str_repeat('a',64),'issued_at' => gmdate('Y-m-d\TH:i:s\Z',$this->now),
        'expires_at' => gmdate('Y-m-d\TH:i:s\Z',$this->now+60),'key_sha256' => ModelValues::keyHash(str_repeat('b',64)),
        'lookup_operation' => '','intent' => RankedFixtures::intent()]; }
}
