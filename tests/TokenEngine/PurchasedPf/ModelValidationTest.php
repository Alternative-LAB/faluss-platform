<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\AllocationPlan;
use Faluss\Platform\TokenEngine\PurchasedPf\AttributionIntent;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\PurchaseEvidence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModelValidationTest extends TestCase
{
    public function testShapeValidationIsNotAnAttestationAndIgnoresInputKeyOrder(): void
    {
        $first = PurchaseEvidence::fromArray(ModelFixtures::evidence());
        $second = PurchaseEvidence::fromArray(array_reverse(ModelFixtures::evidence(), true));
        self::assertSame($first->fingerprint(), $second->fingerprint());
        self::assertSame('100', $first->values['purchased_pf']);
        self::assertSame('50', $first->values['bonus_pf']);
        self::assertSame('hub.purchased-pf.h1-model/1.0.0', $first->values['contract']);
        self::assertArrayNotHasKey('receipt', $first->values);
    }

    #[DataProvider('invalidEvidence')]
    public function testEvidenceRejectsUnprovedClassesAndInconsistentData(array $changes): void
    {
        $this->expectException(ModelViolation::class);
        PurchaseEvidence::fromArray(array_replace(ModelFixtures::evidence(), $changes));
    }

    public static function invalidEvidence(): iterable
    {
        foreach (['0', '00', '01', '-1', '+1', '1.0', '1e3', '9007199254740992',
            '999999999999999999999', 1, 1.0, true, null] as $i => $value) {
            yield 'quantity-' . $i => [['purchased_pf' => $value]];
        }
        yield 'earned' => [['economic_class' => 'earned']];
        yield 'funded' => [['economic_class' => 'funded']];
        yield 'promotional' => [['economic_class' => 'promotional']];
        yield 'bonus-only' => [['purchased_pf' => '0', 'bonus_pf' => '100']];
        yield 'old-contract' => [['contract' => 'fans.hub-purchased-pf/0.1.0']];
        yield 'email-reference' => [['purchase_id' => 'member@example.invalid']];
        yield 'upper-case-id' => [['member_faluss_id' => 'ABCDEF01-1111-4111-8111-111111111111']];
        yield 'wordpress-id' => [['member_faluss_id' => '123']];
        yield 'over-cancellation' => [['cancelled_purchased_pf_cumulative' => '101']];
        yield 'partial-zero' => [['state' => 'partially_cancelled']];
        yield 'cancelled-not-total' => [['state' => 'cancelled', 'cancelled_purchased_pf_cumulative' => '99']];
        yield 'pending-with-date' => [['state' => 'pending']];
        yield 'confirmed-without-date' => [['confirmed_at' => null]];
        yield 'invalid-date' => [['observed_at' => '2026-02-31T10:00:00Z']];
        yield 'date-with-offset' => [['observed_at' => '2026-10-05T10:00:00+00:00']];
        yield 'observation-before-confirmation' => [['observed_at' => '2026-09-01T10:00:00Z']];
        yield 'zero-revision' => [['source_revision' => '0']];
        yield 'negative-bonus' => [['bonus_pf' => '-1']];
        yield 'floating-cancellation' => [['cancelled_purchased_pf_cumulative' => 1.0]];
        yield 'url-source' => [['authority_id' => 'https://example.invalid']];
        yield 'invalid-policy' => [['policy_version' => 'latest']];
    }

    public function testMissingFieldsAreNotImplicitlyFilled(): void
    {
        $data = ModelFixtures::evidence();
        unset($data['authority_id']);
        $this->expectException(ModelViolation::class);
        PurchaseEvidence::fromArray($data);
    }

    public function testRevisionChangesFingerprintButNotPurchaseIdentity(): void
    {
        $first = PurchaseEvidence::fromArray(ModelFixtures::evidence());
        $next = PurchaseEvidence::fromArray(array_replace(ModelFixtures::evidence(), [
            'source_revision' => '2', 'evidence_id' => '55555555-5555-4555-8555-555555555555', 'state' => 'disputed',
        ]));
        $next->assertSuccessorOf($first);
        self::assertSame($first->immutableFingerprint(), $next->immutableFingerprint());
        self::assertNotSame($first->fingerprint(), $next->fingerprint());
    }

    #[DataProvider('immutableChanges')]
    public function testRevisionCannotChangeOriginalPurchaseOrRegress(array $changes): void
    {
        $first = PurchaseEvidence::fromArray(ModelFixtures::evidence());
        $next = PurchaseEvidence::fromArray(array_replace(ModelFixtures::evidence(), ['source_revision' => '2'], $changes));
        $this->expectException(ModelViolation::class);
        $next->assertSuccessorOf($first);
    }

    public static function immutableChanges(): iterable
    {
        yield 'quantity' => [['purchased_pf' => '101']];
        yield 'bonus' => [['bonus_pf' => '51']];
        yield 'holder' => [['member_faluss_id' => ModelFixtures::CREATOR]];
        yield 'source' => [['authority_id' => 'fixture.other']];
        yield 'purchase' => [['purchase_id' => 'other.pack']];
        yield 'policy' => [['policy_version' => '2.0.0']];
        yield 'date' => [['confirmed_at' => '2026-10-02T10:00:00Z']];
        yield 'old-revision' => [['source_revision' => '1']];
        yield 'old-observation' => [['observed_at' => '2026-10-04T10:00:00Z']];
    }

    public function testOldEventCannotRestoreCancelledUnitsOrTerminalState(): void
    {
        $cancelled = PurchaseEvidence::fromArray(array_replace(ModelFixtures::evidence(), [
            'state' => 'cancelled', 'cancelled_purchased_pf_cumulative' => '100', 'source_revision' => '2',
        ]));
        $this->expectException(ModelViolation::class);
        PurchaseEvidence::fromArray(array_replace(ModelFixtures::evidence(), ['source_revision' => '3']))->assertSuccessorOf($cancelled);
    }

    public function testSelfAttributionIsRefused(): void
    {
        $this->expectException(ModelViolation::class);
        AttributionIntent::fromArray(array_replace(ModelFixtures::intent(), ['creator_faluss_id' => ModelFixtures::MEMBER]));
    }

    public function testKeysAreCanonical256BitAndOnlyTheirFingerprintIsStored(): void
    {
        $key = str_repeat('a', 64);
        self::assertSame(hash('sha256', $key), ModelValues::keyHash($key));
        self::assertNotSame($key, ModelValues::keyHash($key));
        $this->expectException(ModelViolation::class);
        ModelValues::keyHash(strtoupper($key));
    }

    public function testCumulativeCancellationCannotRegressEvenOnANewerDisputedRevision(): void
    {
        $first = PurchaseEvidence::fromArray(array_replace(ModelFixtures::evidence(), [
            'state' => 'partially_cancelled', 'cancelled_purchased_pf_cumulative' => '20', 'source_revision' => '2',
        ]));
        $next = PurchaseEvidence::fromArray(array_replace(ModelFixtures::evidence(), [
            'state' => 'disputed', 'cancelled_purchased_pf_cumulative' => '10', 'source_revision' => '3',
        ]));
        $this->expectException(ModelViolation::class);
        $next->assertSuccessorOf($first);
    }

    public function testIntentFingerprintBindsQuantityCreatorAndAuthority(): void
    {
        $intent = AttributionIntent::fromArray(ModelFixtures::intent());
        foreach ([['purchased_pf' => '41'], ['client_authority' => 'fixture.other'],
            ['creator_faluss_id' => '66666666-6666-4666-8666-666666666666']] as $change) {
            self::assertNotSame($intent->fingerprint(), AttributionIntent::fromArray(array_replace(ModelFixtures::intent(), $change))->fingerprint());
        }
    }

    public function testFifoUsesHubAdmissionDateThenLotId(): void
    {
        $intent = AttributionIntent::fromArray(array_replace(ModelFixtures::intent(), ['purchased_pf' => '75']));
        $lots = [self::lot(3, '20', '2026-10-05 10:00:01.000000'), self::lot(2, '50'), self::lot(1, '30')];
        $plan = AllocationPlan::forIntent($intent, $lots);
        self::assertSame([self::lotId(1), self::lotId(2)], array_column($plan, 'lot_id'));
        self::assertSame(['30', '45'], array_column($plan, 'purchased_pf'));
        self::assertSame($lots[2]['evidence_id'], $plan[0]['evidence_id']);
    }

    public function testLargestQuantityDoesNotUseFloatingPoint(): void
    {
        $max = (string) ModelValues::MAX_INTEGER;
        $intent = AttributionIntent::fromArray(array_replace(ModelFixtures::intent(), ['purchased_pf' => $max]));
        self::assertSame($max, AllocationPlan::forIntent($intent, [self::lot(1, $max)])[0]['purchased_pf']);
    }

    public function testInsufficientPlanIsNotReturnedPartially(): void
    {
        $this->expectException(ModelViolation::class);
        AllocationPlan::forIntent(AttributionIntent::fromArray(ModelFixtures::intent()), [self::lot(1, '39')]);
    }

    public function testForeignLotsAreRefused(): void
    {
        $lot = self::lot(1, '100');
        $lot['member_faluss_id'] = ModelFixtures::CREATOR;
        $this->expectException(ModelViolation::class);
        AllocationPlan::forIntent(AttributionIntent::fromArray(ModelFixtures::intent()), [$lot]);
    }

    public function testDuplicateLotsAreNotCountedTwice(): void
    {
        $lot = self::lot(1, '20');
        $this->expectException(ModelViolation::class);
        AllocationPlan::forIntent(AttributionIntent::fromArray(ModelFixtures::intent()), [$lot, $lot]);
    }

    public function testIntentionIsLimitedTo32Allocations(): void
    {
        $lots = array_map(static fn (int $id): array => self::lot($id, '1'), range(1, 33));
        $intent = AttributionIntent::fromArray(array_replace(ModelFixtures::intent(), ['purchased_pf' => '32']));
        self::assertCount(32, AllocationPlan::forIntent($intent, $lots));
        $this->expectException(ModelViolation::class);
        AllocationPlan::forIntent(AttributionIntent::fromArray(array_replace(ModelFixtures::intent(), ['purchased_pf' => '33'])), $lots);
    }

    private static function lot(int $id, string $quantity, string $date = '2026-10-05 10:00:00.000000'): array
    {
        return ['lot_id' => self::lotId($id), 'member_faluss_id' => ModelFixtures::MEMBER,
            'purchased_pf' => $quantity, 'evidence_id' => '33333333-3333-4333-8333-333333333333',
            'source_revision' => '1', 'accepted_at' => $date];
    }

    private static function lotId(int $id): string
    {
        return sprintf('%08x-1111-4111-8111-111111111111', $id);
    }
}
