<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\BarrierCompletion;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\BarrierTerritoryRevocation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\BarrierTransport;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedIntent;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingBarrier;
use PHPUnit\Framework\TestCase;

final class BarrierTerritoryRevocationTest extends TestCase
{
    /** @return array<string,mixed> */
    private function descriptor(string $kind): array
    {
        $references = RankingBarrier::references(RankedIntent::fromArray(RankedFixtures::intent()));
        $content = array_values(array_filter($references,static fn (array $row): bool => $row['content']['kind'] === $kind))[0]['content'];
        return ['content' => $content,'valid_from' => $kind === 'admission' ? max($content['starts_at'],$content['admitted_at'])
            : ($content['starts_at'] ?? '2026-01-01 00:00:00.000000'),'valid_until' => $content['ends_at'] ?? '2027-01-01 00:00:00.000000'];
    }

    public function testTheNewReasonNeverChangesTheHistoricalValidators(): void
    {
        $ref = RankingBarrier::reference($this->descriptor('admission')['content']);
        $object = array_intersect_key($ref,array_flip(['barrier_key','version','content_sha256'])) + ['reason' => BarrierTerritoryRevocation::REASON];
        $fields = ['operation' => 'close','lookup_operation' => '','action_id' => ModelFixtures::intent()['attribution_id'],
            'origin_id' => RankedFixtures::ORIGIN,'policy_version' => '1.0.0','object' => $object];
        foreach ([BarrierTransport::CONTRACT,BarrierTransport::COMPLETION_CONTRACT] as $contract) {
            foreach (['close','lookup'] as $operation) {
                $candidate = array_replace($fields,['operation' => $operation,'lookup_operation' => $operation === 'lookup' ? 'close' : '']);
                try { BarrierTransport::fields($candidate,$contract); self::fail('Historical version accepted a new reason.'); }
                catch (ModelViolation $error) { self::assertSame('pf_barrier_invalid_reason',$error->reason); }
                BarrierTransport::fields($candidate,BarrierTransport::TERRITORY_CONTRACT);
            }
        }
        self::assertSame($object,BarrierTerritoryRevocation::reference($object));
        foreach ([RankingBarrier::closeReference(...),BarrierCompletion::reference(...)] as $validator) {
            try { $validator($object); self::fail('Historical syntax changed.'); }
            catch (ModelViolation $error) { self::assertSame('pf_barrier_invalid_reason',$error->reason); }
        }
    }

    public function testOnlyStoredTerritorialAdmissionsAreEligibleForRevocation(): void
    {
        $admission = $this->descriptor('admission');
        $admission['content']['territory_policy_revision'] = '99999999-9999-4999-8999-999999999999';
        $admission['content']['territory_admission_revision'] = '7';
        BarrierTerritoryRevocation::requireTerritorialAdmission($admission);
        self::assertSame('7',$admission['content']['territory_admission_revision']);
        foreach (['origin','country','creator','session','admission'] as $kind) {
            try { BarrierTerritoryRevocation::requireTerritorialAdmission($this->descriptor($kind)); self::fail('Nonterritorial object admitted.'); }
            catch (ModelViolation $error) { self::assertSame('pf_barrier_territory_requires_admission',$error->reason); }
        }
        foreach (['pf_barrier_invalid_territory' => ['territory_policy_revision' => ''],
            'invalid_quantity_or_revision' => ['territory_admission_revision' => '0']] as $expected => $change) {
            $bad = $admission; $bad['content'] = array_replace($bad['content'],$change);
            try { BarrierTerritoryRevocation::requireTerritorialAdmission($bad); self::fail('Partial territory evidence admitted.'); }
            catch (ModelViolation $error) { self::assertSame($expected,$error->reason); }
        }
    }

    public function testTheSparseReferenceRemainsExactAndDoesNotClaimItsOwnKind(): void
    {
        $object = ['barrier_key' => str_repeat('a',64),'version' => '2','content_sha256' => str_repeat('b',64),'reason' => BarrierTerritoryRevocation::REASON];
        self::assertSame($object,BarrierTerritoryRevocation::reference($object));
        $this->expectException(ModelViolation::class);
        BarrierTerritoryRevocation::reference($object + ['kind' => 'admission']);
    }
}
