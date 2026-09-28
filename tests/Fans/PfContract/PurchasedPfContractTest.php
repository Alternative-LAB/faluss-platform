<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Fans\PfContract;

use Faluss\Platform\TokenEngine\TokenEngineContract;
use Fiber;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class PurchasedPfContractTest extends TestCase
{
    public function testRealFacadeHasNoPurchasedPfProtocol(): void
    {
        $methods = array_map(static fn ($method) => $method->name, (new ReflectionClass(TokenEngineContract::class))->getMethods(\ReflectionMethod::IS_PUBLIC));
        sort($methods);
        self::assertSame(['claimHubDaily', 'hubDailyStatus'], $methods);
    }

    public function testReserveConfirmLookupAndReplayHaveOneReceipt(): void
    {
        $hub = new FakeHub();
        $request = $this->request();
        $reserved = $hub->reserve($request);
        self::assertNull($reserved['receipt']);
        self::assertSame($reserved, $hub->reserve(array_reverse($request, true)));
        $receipt = $hub->confirm('attribution-a');
        self::assertTrue(FakeHub::verify($receipt));
        self::assertSame($receipt, $hub->confirm('attribution-a'));
        self::assertSame($receipt, $hub->lookup('key-a')['receipt']);
        self::assertSame($receipt, $hub->reserve($request)['receipt']);
        self::assertSame('lot-a', $receipt['payload']['lot']);
        self::assertSame('proof-a', $receipt['payload']['purchase_proof']);
        $this->rejects('already_consumed', fn () => $hub->release('attribution-a'));
    }

    public function testRejectsUnauthorizedUnfundedAndConflictingRequests(): void
    {
        foreach ([['version' => '0.0.0'], ['caller' => 'me'], ['audience' => 'fans']] as $change) {
            $this->rejects('unauthorized_contract', fn () => (new FakeHub())->reserve(array_replace($this->request(), $change)));
        }
        foreach ([['member' => 'fan-b'], ['creator' => 'fan-a'], ['pf' => 0], ['pf' => 1.5]] as $change) {
            $this->rejects('ineligible', fn () => (new FakeHub())->reserve(array_replace($this->request(), $change)));
        }
        $this->rejects('invalid_shape', fn () => (new FakeHub())->reserve($this->request() + ['verified' => true]));
        foreach (['earned', 'promotional'] as $class) {
            $this->rejects('purchase_unavailable', fn () => (new FakeHub(lotClass: $class))->reserve($this->request()));
        }
        $this->rejects('purchase_unavailable', fn () => (new FakeHub(purchaseAttested: false))->reserve($this->request()));
        $hub = new FakeHub();
        $hub->reserve($this->request());
        $this->rejects('idempotency_conflict', fn () => $hub->reserve(array_replace($this->request(), ['pf' => 4])));
        $this->rejects('attribution_conflict', fn () => $hub->reserve(array_replace($this->request(), ['key' => 'new-key'])));
        $this->rejects('unauthorized_contract', fn () => $hub->lookup('key-a', 'other'));
        $this->rejects('unauthorized_contract', fn () => $hub->confirm('attribution-a', 'other'));
    }

    public function testOverlappingReservationsCannotOverspendTheFixtureLot(): void
    {
        $hub = new FakeHub();
        $first = new Fiber(fn () => $hub->reserve($this->request(), static fn () => Fiber::suspend()));
        $first->start();
        $second = array_replace($this->request(), ['key' => 'key-b', 'attribution' => 'attribution-b', 'pf' => 6]);
        $this->rejects('busy', fn () => $hub->reserve($second));
        $first->resume();
        self::assertSame('reserved', $first->getReturn()['state']);
        $this->rejects('insufficient_purchased_pf', fn () => $hub->reserve($second));
        $hub->confirm('attribution-a');
        $this->rejects('insufficient_purchased_pf', fn () => $hub->reserve($second));
        // The model serializes confirms; both callers obtain the same receipt.
        self::assertSame($hub->confirm('attribution-a'), $hub->confirm('attribution-a'));
    }

    public function testTimeoutBeforeAndAfterCommitRecoverWithoutNewBusinessKey(): void
    {
        $hub = new FakeHub();
        $hub->unavailable = true;
        $this->rejects('unavailable', fn () => $hub->reserve($this->request()));
        $hub->unavailable = false;
        self::assertNull($hub->lookup('key-a'));
        $this->rejects('timeout_after_commit', fn () => $hub->reserve($this->request(), loseResponse: true));
        self::assertSame('reserved', $hub->lookup('key-a')['state']);
        self::assertSame($hub->lookup('key-a'), $hub->reserve($this->request()));
        $this->rejects('timeout_after_commit', fn () => $hub->confirm('attribution-a', loseResponse: true));
        $hub->unavailable = true;
        $this->rejects('unavailable', fn () => $hub->lookup('key-a'));
        $hub->unavailable = false;
        self::assertSame($hub->lookup('key-a')['receipt'], $hub->confirm('attribution-a'));
    }

    public function testFailureBeforeFixtureCommitLeavesNoReservationAndReleasesLock(): void
    {
        $hub = new FakeHub();
        $this->rejects('storage_failure', fn () => $hub->reserve($this->request(), static function (): void {
            throw new RuntimeException('storage_failure');
        }));
        self::assertNull($hub->lookup('key-a'));
        self::assertSame('reserved', $hub->reserve($this->request())['state']);
    }

    public function testExpiryReleaseAndConfirmationOrderAreExplicit(): void
    {
        $hub = new FakeHub();
        $hub->reserve($this->request());
        $hub->now = 130;
        self::assertSame('expired', $hub->lookup('key-a')['state']);
        $this->rejects('reservation_closed', fn () => $hub->confirm('attribution-a'));
        $other = array_replace($this->request(), ['key' => 'key-b', 'attribution' => 'attribution-b', 'pf' => 10]);
        self::assertSame('reserved', $hub->reserve($other)['state']);
        $hub->release('attribution-b');
        $hub->release('attribution-b');
        $this->rejects('reservation_closed', fn () => $hub->confirm('attribution-b'));
    }

    public function testTamperedAndWrongAudienceReceiptsAreRejected(): void
    {
        $hub = new FakeHub();
        $hub->reserve($this->request());
        $receipt = $hub->confirm('attribution-a');
        foreach (['pf' => 999, 'audience' => 'me', 'issuer' => 'other', 'creator' => 'other', 'lot' => 'lot-b'] as $field => $value) {
            $changed = $receipt;
            $changed['payload'][$field] = $value;
            self::assertFalse(FakeHub::verify($changed));
        }
        self::assertFalse(FakeHub::verify(array_replace($receipt, ['signature' => 'forged'])));
    }

    public function testLotCorrectionMapsAllAttributionsAndCannotReplayOutOfOrder(): void
    {
        $hub = new FakeHub();
        $hub->reserve($this->request());
        $hub->confirm('attribution-a');
        $hub->reserve(array_replace($this->request(), ['key' => 'key-b', 'attribution' => 'attribution-b', 'pf' => 3]));
        $hub->confirm('attribution-b');
        $dispute = $hub->correctLot(2, 'disputed');
        self::assertTrue(FakeHub::verify($dispute));
        self::assertSame(['attribution-a', 'attribution-b'], array_keys($dispute['payload']['affected']));
        self::assertSame(8, array_sum(array_column($dispute['payload']['affected'], 'pf')));
        self::assertSame($dispute, $hub->correctLot(2, 'disputed'));
        $this->rejects('revision_conflict', fn () => $hub->correctLot(2, 'confirmed'));
        $resolved = $hub->correctLot(4, 'confirmed');
        self::assertTrue(FakeHub::verify($resolved));
        $this->rejects('stale_or_terminal', fn () => $hub->correctLot(3, 'disputed'));
        // Old replay returns the old envelope; consumer must retain revision 4.
        self::assertSame(2, $hub->correctLot(2, 'disputed')['payload']['revision']);
        $refunded = $hub->correctLot(5, 'refunded');
        self::assertSame($refunded, $hub->correctLot(5, 'refunded'));
        $this->rejects('stale_or_terminal', fn () => $hub->correctLot(6, 'confirmed'));
    }

    public function testDisputeBeforeConfirmClosesReservation(): void
    {
        $hub = new FakeHub();
        $hub->reserve($this->request());
        self::assertSame([], $hub->correctLot(2, 'disputed')['payload']['affected']);
        $this->rejects('reservation_closed', fn () => $hub->confirm('attribution-a'));
        $this->rejects('purchase_unavailable', fn () => $hub->reserve(array_replace($this->request(), ['key' => 'b', 'attribution' => 'b'])));
    }

    public function testMissingMappingAndPartialCapabilityBlockAutomaticCorrection(): void
    {
        $hub = new FakeHub();
        $hub->reserve($this->request());
        $hub->confirm('attribution-a');
        $this->rejects('hub_partial_compensation_missing', fn () => $hub->correctLot(2, 'refunded', partial: true));
        $hub->breakMapping();
        $this->rejects('mapping_incomplete_manual_review', fn () => $hub->correctLot(2, 'disputed'));
        self::assertSame('confirmed', $hub->lookup('key-a')['state']);
        $this->rejects('purchase_unavailable', fn () => $hub->reserve(array_replace($this->request(), ['key' => 'b', 'attribution' => 'b'])));
    }

    private function request(): array
    {
        return ['version' => FakeHub::VERSION, 'audience' => 'hub', 'caller' => 'fans', 'key' => 'key-a',
            'attribution' => 'attribution-a', 'member' => 'fan-a', 'creator' => 'creator-a', 'pf' => 5];
    }

    private function rejects(string $error, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected contract rejection.');
        } catch (RuntimeException $exception) {
            self::assertSame($error, $exception->getMessage());
        }
    }
}
