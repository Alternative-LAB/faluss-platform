<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Fans\Hof;

use Faluss\Platform\Fans\Hof\OriginOpening;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingBarrier;
use PHPUnit\Framework\TestCase;

final class OriginOpeningTest extends TestCase
{
    private const ORIGIN='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    /** @return array<string,mixed> */
    private function ack(): array
    {
        $descriptor=['content'=>['kind'=>'origin','origin_id'=>self::ORIGIN,'object_id'=>self::ORIGIN,'subject_id'=>'','version'=>'1','policy_version'=>'1.0.0'],
            'valid_from'=>'2026-10-01 00:00:00.000000','valid_until'=>'2026-11-01 00:00:00.000000'];
        $ref=RankingBarrier::reference($descriptor['content']);
        return ['contract'=>'hub.purchased-pf.ranking-barriers/1.0.0','fields'=>['operation'=>'register','origin_id'=>self::ORIGIN,'policy_version'=>'1.0.0','object'=>$descriptor],
            'local_state'=>'active','result'=>array_intersect_key($ref,array_flip(['barrier_key','version','content_sha256']))
                + ['operation'=>'register','state'=>'active','effective_at'=>'2026-10-03 10:00:00.123456']];
    }
    public function testPrimaryInstantRatherThanPreparedAccountPurchaseOrNetworkDate(): void
    {
        self::assertSame(['primary_ack_at'=>'2026-10-03 10:00:00.123456','admissible_from'=>'2026-10-03 10:00:00.123456'],OriginOpening::fromAcknowledgement($this->ack(),self::ORIGIN));
    }
    public function testScheduledIntervalNeverAdmitsBeforeItsFrozenStart(): void
    {
        $ack=$this->ack();$ack['fields']['object']['valid_from']='2026-10-04 00:00:00.000000';
        self::assertSame('2026-10-04 00:00:00.000000',OriginOpening::fromAcknowledgement($ack,self::ORIGIN)['admissible_from']);
        self::assertSame('2026-10-03 10:00:00.123456',OriginOpening::fromAcknowledgement($ack,self::ORIGIN)['primary_ack_at']);
    }
    public function testOldActiveProofCannotOverrideCurrentClosingOrClosedState(): void
    {
        foreach (['opening','closing','closed','superseded'] as $state) {
            $ack=$this->ack();$ack['local_state']=$state;
            try { OriginOpening::fromAcknowledgement($ack,self::ORIGIN);self::fail('Closed origin became active.'); }
            catch (ModelViolation $error) { self::assertSame('hof_origin_acknowledgement_required',$error->reason); }
        }
    }
    public function testMismatchedReferenceVersionPolicyAndDeadlineFailClosed(): void
    {
        foreach (['content_sha256'=>'b','version'=>'2','effective_at'=>'2026-11-01 00:00:00.000000'] as $field=>$value) {
            $ack=$this->ack();$ack['result'][$field]=$value;
            try { OriginOpening::fromAcknowledgement($ack,self::ORIGIN);self::fail('Invalid origin became active.'); }
            catch (ModelViolation $error) { self::assertSame('hof_origin_conflict',$error->reason); }
        }
        $ack=$this->ack();$ack['fields']['policy_version']='2.0.0';
        $this->expectException(ModelViolation::class);OriginOpening::fromAcknowledgement($ack,self::ORIGIN);
    }
}
