<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\TokenEngine\PurchasedPf;

use Faluss\Platform\Fans\PfContract\ClosedSnapshotClient;
use Faluss\Platform\Fans\PfContract\ClosedSnapshotInbox;
use Faluss\Platform\Fans\PfContract\ClosedSnapshotInboxSchema;
use Faluss\Platform\TokenEngine\PurchasedPf\ClosedSnapshotGateway;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SignedEnvelope;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SnapshotTransport;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SnapshotTransportTest extends TestCase
{
    private const ID = '11111111-1111-4111-8111-111111111111';
    private const MEMBER = '22222222-2222-4222-8222-222222222222';
    private PeerPolicy $peer;

    protected function setUp(): void
    {
        define('ABSPATH', __DIR__ . '/');
        require_once dirname(__DIR__, 2) . '/Federation/WordPressStubs.php';
        if (!function_exists('is_wp_error')) {
            eval('namespace { class WP_Error { public function __construct(...$args) {} } function is_wp_error($v): bool { return $v instanceof WP_Error; } }');
        }
        require_once dirname(__DIR__, 3) . '/src/Federation/Legacy/includes/class-faluss-federation-crypto.php';
        $seed = random_bytes(32);
        define('FALUSS_FEDERATION_PRIVATE_SEED', SignedEnvelope::encode($seed));
        $pair = sodium_crypto_sign_seed_keypair($seed);
        $public = SignedEnvelope::encode(sodium_crypto_sign_publickey($pair));
        sodium_memzero($pair); sodium_memzero($seed);
        $this->peer = new PeerPolicy('fixture.fans', 'fixture.hub', ['pf.snapshot', 'pf.context.delegate'],
            ['recipe-key' => ['public_key' => $public, 'state' => 'active', 'from' => '2026-01-01T00:00:00Z', 'until' => '2027-01-01T00:00:00Z']]);
    }

    /** @return array<string,string> */
    private function fields(): array
    {
        return ['operation' => 'start', 'read_id' => self::ID, 'member_faluss_id' => self::MEMBER, 'snapshot_id' => '', 'cursor' => ''];
    }

    public function testSnapshotDelegationBindsExactMemberReadKeyAndFreshRequest(): void
    {
        $request = SnapshotTransport::sealRequest($this->fields(), str_repeat('a', 64), 'recipe-key', time() + 60);
        $payload = SignedEnvelope::open(SignedEnvelope::SNAPSHOT_REQUEST, CanonicalJson::object($request['wire']), $this->peer, time());
        $verified = SnapshotTransport::request($payload, $this->peer, time());
        self::assertSame(self::MEMBER, $verified['member_faluss_id']);
        self::assertSame(self::ID, $verified['read_id']);
        self::assertSame(str_repeat('a', 64), $verified['read_key']);
        $payload['member_faluss_id'] = self::ID;
        $this->expectException(ModelViolation::class);
        SnapshotTransport::request($payload, $this->peer, time());
    }

    public function testOldPfRightsCannotConferSnapshotReadPermission(): void
    {
        $peer = new PeerPolicy('fixture.fans', 'fixture.hub', ['pf.lookup', 'pf.context.delegate', 'wallet.read'], []);
        $this->expectException(ModelViolation::class);
        $peer->allow('pf.snapshot');
    }

    public function testDomainsAndLegacySizeBoundsRemainDistinct(): void
    {
        $bytes = CanonicalJson::encode(['body' => str_repeat('a', 70000)]);
        $proof = SignedEnvelope::seal(SignedEnvelope::SNAPSHOT_RESPONSE, $bytes, 'recipe-key');
        self::assertSame($bytes, CanonicalJson::encode(SignedEnvelope::open(SignedEnvelope::SNAPSHOT_RESPONSE, $proof, $this->peer, time())));
        foreach ([SignedEnvelope::RESPONSE, SignedEnvelope::RECEIPT, SignedEnvelope::CONTEXT] as $old) {
            try { SignedEnvelope::seal($old, $bytes, 'recipe-key'); self::fail('Legacy bounds must remain unchanged.'); }
            catch (ModelViolation) { self::assertTrue(true); }
        }
        $this->expectException(ModelViolation::class);
        SignedEnvelope::open(SignedEnvelope::RESPONSE, $proof, $this->peer, time());
    }

    public function testReadOperationsCannotDispatchEconomicsOrGuessCursors(): void
    {
        foreach ([['operation' => 'confirm'], ['cursor' => self::ID], ['snapshot_id' => self::ID],
            ['operation' => 'page', 'snapshot_id' => self::ID, 'cursor' => '1']] as $bad) {
            try { SnapshotTransport::fields(array_replace($this->fields(), $bad)); self::fail('No economic or offset operation admitted.'); }
            catch (ModelViolation) { self::assertTrue(true); }
        }
        $this->expectException(ModelViolation::class);
        SnapshotTransport::sealRequest($this->fields(), str_repeat('a', 64), 'recipe-key', time() - 1);
    }

    public function testMarkerAloneRefusesAllEntryPointsBeforeAnyDatabaseQuery(): void
    {
        define('FALUSS_PF_H4_RECIPE', true);
        define('FALUSS_PF_H3_RECIPE_ONLY', true);
        eval('namespace { class wpdb { public int $queries=0; public function get_row($q,$a=null) { $this->queries++; return null; } } }');
        $database = new \wpdb();
        foreach ([static fn () => ClosedSnapshotInboxSchema::installForRecipe($database),
            static fn () => new ClosedSnapshotInbox($database, self::ID),
            static fn () => new ClosedSnapshotClient($database, new PeerPolicy('fixture.hub', 'fixture.fans', [], []), 'recipe-key', '', self::ID),
            static fn () => new ClosedSnapshotGateway($database, new PeerPolicy('fixture.fans', 'fixture.hub', [], []), 'recipe-key')] as $attempt) {
            try { $attempt(); self::fail('A copied marker cannot open recipe operations.'); }
            catch (ModelViolation) { self::assertSame(0, $database->queries); }
        }
    }
}
