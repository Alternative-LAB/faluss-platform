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
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SignedEnvelope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ProtocolTest extends TestCase
{
    private PeerPolicy $peer;
    private int $now;

    protected function setUp(): void
    {
        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__ . '/');
        }
        require_once dirname(__DIR__, 2) . '/Federation/WordPressStubs.php';
        if (!function_exists('is_wp_error')) {
            eval('namespace { class WP_Error { public function __construct(...$args) {} } function is_wp_error($value): bool { return $value instanceof WP_Error; } }');
        }
        require_once dirname(__DIR__, 3) . '/src/Federation/Legacy/includes/class-faluss-federation-crypto.php';
        $seed = random_bytes(32);
        define('FALUSS_FEDERATION_PRIVATE_SEED', SignedEnvelope::encode($seed));
        $pair = sodium_crypto_sign_seed_keypair($seed);
        $public = SignedEnvelope::encode(sodium_crypto_sign_publickey($pair));
        sodium_memzero($pair);
        sodium_memzero($seed);
        $this->now = 1791194400;
        $this->peer = new PeerPolicy('fixture.fans', 'fixture.hub',
            ['pf.context.delegate', 'pf.reserve', 'pf.confirm', 'pf.release', 'pf.lookup'],
            ['recipe-k1' => ['public_key' => $public, 'state' => 'active',
                'from' => '2026-01-01T00:00:00Z', 'until' => '2027-01-01T00:00:00Z'],
             'recipe-revoked' => ['public_key' => $public, 'state' => 'revoked',
                'from' => '2026-01-01T00:00:00Z', 'until' => '2027-01-01T00:00:00Z']]);
    }

    public function testAsciiJcsVectorAndDuplicateRejection(): void
    {
        $bytes = CanonicalJson::encode(['z' => '9007199254740991', 'a' => ['quote' => '"/', 'list' => [true, null, '2']]]);
        $expected = <<<'JSON'
{"a":{"list":[true,null,"2"],"quote":"\"/"},"z":"9007199254740991"}
JSON;
        self::assertSame($expected, $bytes);
        self::assertSame($bytes, CanonicalJson::encode(CanonicalJson::object($bytes)));
        $this->expectException(ModelViolation::class);
        CanonicalJson::object('{"a":"x","a":"x"}');
    }

    #[DataProvider('nonCanonical')]
    public function testNonCanonicalNumbersUnicodeAndEncodingsRefused(string $bytes): void
    {
        $this->expectException(ModelViolation::class);
        CanonicalJson::object($bytes);
    }

    public static function nonCanonical(): iterable
    {
        foreach (['{"a":1}', '{"a":1.0}', '{"a":"é"}', '{"a":"\\u0061"}',
            '{ "a":"x"}', '{"b":"x","a":"y"}', '{"a":"\\/"}', '[]', '{"a":"\\ud800"}'] as $index => $bytes) {
            yield (string) $index => [$bytes];
        }
    }

    public function testNativeSignatureUsesSeparateDomainsAndCanonicalPayload(): void
    {
        $context = $this->context();
        $envelope = SignedEnvelope::seal(SignedEnvelope::CONTEXT, CanonicalJson::encode($context), 'recipe-k1');
        self::assertSame(CanonicalJson::encode($context),
            CanonicalJson::encode(SignedEnvelope::open(SignedEnvelope::CONTEXT, $envelope, $this->peer, $this->now)));
        $this->expectException(ModelViolation::class);
        SignedEnvelope::open(SignedEnvelope::RECEIPT, $envelope, $this->peer, $this->now);
    }

    #[DataProvider('badEnvelopes')]
    public function testAlteredUnknownRevokedKeysAndSignaturesRefused(string $change): void
    {
        $envelope = SignedEnvelope::seal(SignedEnvelope::CONTEXT, CanonicalJson::encode($this->context()), 'recipe-k1');
        if ($change === 'payload') {
            $envelope['payload_base64url'] = SignedEnvelope::encode(CanonicalJson::encode(['a' => 'tampered']));
            $envelope['payload_sha256'] = hash('sha256', SignedEnvelope::decode($envelope['payload_base64url']));
        } elseif ($change === 'signature') {
            $envelope['signature_base64url'] = SignedEnvelope::encode(random_bytes(64));
        } elseif ($change === 'digest') {
            $envelope['payload_sha256'] = str_repeat('0', 64);
        } elseif ($change === 'unknown' || $change === 'revoked') {
            $envelope = SignedEnvelope::seal(SignedEnvelope::CONTEXT, CanonicalJson::encode($this->context()), 'recipe-' . $change);
        } else {
            $envelope['payload_base64url'] .= '=';
        }
        $this->expectException(ModelViolation::class);
        SignedEnvelope::open(SignedEnvelope::CONTEXT, $envelope, $this->peer, $this->now);
    }

    public static function badEnvelopes(): iterable
    {
        foreach (['payload', 'signature', 'digest', 'unknown', 'revoked', 'padding'] as $change) {
            yield $change => [$change];
        }
    }

    #[DataProvider('badContext')]
    public function testBoundDelegationRejectsFalsification(string $change): void
    {
        $data = $this->context();
        if ($change === 'identity') {
            $data['intent']['member_faluss_id'] = 'forged';
        } elseif ($change === 'self') {
            $data['intent']['creator_faluss_id'] = $data['intent']['member_faluss_id'];
        } elseif ($change === 'expired') {
            $data['expires_at'] = gmdate('Y-m-d\TH:i:s\Z', $this->now);
        } elseif ($change === 'future') {
            $data['issued_at'] = gmdate('Y-m-d\TH:i:s\Z', $this->now + 10);
        } elseif ($change === 'ttl') {
            $data['expires_at'] = gmdate('Y-m-d\TH:i:s\Z', $this->now + 61);
        } else {
            $data[$change] = 'invalid';
        }
        $this->expectException(ModelViolation::class);
        DelegatedContext::validate($data, $this->peer, 'reserve', str_repeat('a', 64), str_repeat('b', 64), '', $this->now);
    }

    public static function badContext(): iterable
    {
        foreach (['audience', 'issuer', 'contract', 'operation', 'nonce', 'key_sha256', 'lookup_operation',
            'identity', 'self', 'expired', 'future', 'ttl'] as $change) {
            yield $change => [$change];
        }
    }

    public function testHistoricalPermissionDoesNotAllowDelegation(): void
    {
        $peer = new PeerPolicy('fixture.fans', 'fixture.hub', ['wallet.read', 'reward.claim', 'event.publish'], []);
        $this->expectException(ModelViolation::class);
        DelegatedContext::validate($this->context(), $peer, 'reserve', str_repeat('a', 64), str_repeat('b', 64), '', $this->now);
    }

    public function testExplicitDelegationPreservesTheExactIntent(): void
    {
        $intent = DelegatedContext::validate($this->context(), $this->peer, 'reserve',
            str_repeat('a', 64), str_repeat('b', 64), '', $this->now);
        self::assertSame(AttributionIntent::fromArray(ModelFixtures::intent())->fingerprint(), $intent->fingerprint());
        $this->expectException(ModelViolation::class);
        $this->peer->allow('wallet.read');
    }

    public function testProductionPeerAndOversizedPayloadAreClosed(): void
    {
        try {
            new PeerPolicy('faluss-fans', 'faluss-hub', ['pf.confirm'], []);
            self::fail('Production peer must not be admitted by the closed policy.');
        } catch (ModelViolation $error) {
            self::assertSame('pf_fixture_peer_required', $error->reason);
        }
        $this->expectException(ModelViolation::class);
        CanonicalJson::object(CanonicalJson::encode(['value' => str_repeat('a', 32768)]));
    }

    public function testReceiptExactAllocationAndHistoricalFact(): void
    {
        $intent = AttributionIntent::fromArray(ModelFixtures::intent());
        $lot = '66666666-6666-4666-8666-666666666666';
        $receipt = ['contract' => DelegatedContext::CONTRACT, 'kind' => SignedEnvelope::RECEIPT,
            'issuer' => 'fixture.hub', 'audience' => 'fixture.fans',
            'receipt_id' => $lot, 'revision' => '1', 'reservation_id' => $lot, 'ledger_entry_uuid' => $lot,
            'confirmed_at' => '2026-10-05T10:00:00Z', 'allocations' => [['allocation_id' => hash('sha256', $intent->values['attribution_id'] . '|' . $lot),
                'lot_id' => $lot, 'evidence_id' => $lot, 'source_revision' => '1', 'purchase_authority' => 'fixture.purchase',
                'purchase_reference' => 'fictional-1', 'purchased_pf' => $intent->values['purchased_pf']]]];
        foreach (['attribution_id', 'member_faluss_id', 'creator_faluss_id', 'purchased_pf', 'policy_version'] as $field) {
            $receipt[$field] = $intent->values[$field];
        }
        PrivateReceipt::validate($receipt, 'fixture.hub', 'fixture.fans', $intent);
        self::assertSame('1', $receipt['revision']);
        $receipt['allocations'][0]['purchased_pf'] = '1';
        $this->expectException(ModelViolation::class);
        PrivateReceipt::validate($receipt, 'fixture.hub', 'fixture.fans', $intent);
    }

    /** @return array<string,mixed> */
    private function context(): array
    {
        return ['contract' => DelegatedContext::CONTRACT, 'kind' => SignedEnvelope::CONTEXT,
            'issuer' => 'fixture.fans', 'audience' => 'fixture.hub', 'operation' => 'reserve', 'nonce' => str_repeat('a', 64),
            'issued_at' => gmdate('Y-m-d\TH:i:s\Z', $this->now), 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $this->now + 60),
            'key_sha256' => ModelValues::keyHash(str_repeat('b', 64)), 'lookup_operation' => '', 'intent' => ModelFixtures::intent()];
    }
}
