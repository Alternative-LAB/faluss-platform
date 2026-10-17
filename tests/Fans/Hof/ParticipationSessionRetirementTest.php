<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Fans\Hof;

use Faluss\Platform\Fans\Hof\ParticipationBarrierDescriptor;
use Faluss\Platform\Fans\Hof\ParticipationSessionRetirement;
use Faluss\Platform\Fans\Hof\SessionBarrierDescriptor;
use Faluss\Platform\Fans\Hof\SessionRules;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\BarrierTransport;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingBarrier;
use PHPUnit\Framework\TestCase;

final class ParticipationSessionRetirementTest extends TestCase
{
    private const ORIGIN = '22222222-2222-4222-8222-222222222222';
    private const SUBJECT = '44444444-4444-4444-8444-444444444444';
    private const ACTION = '55555555-5555-4555-8555-555555555555';

    /** @return array{array<string,string>,array<string,string>,array<string,string>} */
    private function fixture(): array
    {
        $row = ['title' => 'Session fictive','rules_text' => 'Participation individuelle et volontaire.',
            'category' => 'arts','scope' => 'international','country' => '', 'territory_ref' => '', 'timezone' => 'Europe/Paris',
            'starts_at' => '2026-10-17 10:00:00.000000','ends_at' => '2026-10-18 10:00:00.000000',
            'session_id' => '33333333-3333-4333-8333-333333333333','state' => 'open','barrier_version' => '1','rules_version' => '2','policy_version' => '1.0.0'];
        $row['frozen_sha256'] = SessionRules::digest($row);
        $role = ['session_id' => $row['session_id'],'role' => 'participant','state' => 'admitted',
            'rules_sha256' => $row['frozen_sha256'],'admitted_at' => '2026-10-17 10:03:00.000000'];
        $descriptor = ParticipationBarrierDescriptor::compose($row,$role,self::ORIGIN,self::SUBJECT,'1',null,'0');
        $version = ['descriptor_json' => CanonicalJson::encode($descriptor),'subject_id' => self::SUBJECT,
            'session_barrier_version' => '1','barrier_version' => '1'];
        $version['descriptor_sha256'] = hash('sha256',$version['descriptor_json']);
        $object = array_intersect_key(RankingBarrier::reference(SessionBarrierDescriptor::compose($row,self::ORIGIN,null)['content']),
            array_flip(['barrier_key','version','content_sha256'])) + ['reason' => 'session_suspended'];
        $fields = ['operation' => 'close','action_id' => self::ACTION,'origin_id' => self::ORIGIN,
            'policy_version' => '1.0.0','lookup_operation' => '','object' => $object];
        $closed = ['session_id' => $row['session_id'],'barrier_version' => '1','operation' => 'close',
            'action_id' => self::ACTION,'contract' => BarrierTransport::CONTRACT,'fields_json' => CanonicalJson::encode($fields),
            'applied_at' => '2026-10-17 10:10:00.000000'];
        $closed['fields_sha256'] = hash('sha256',$closed['fields_json']);
        $row['barrier_version'] = '2';
        return [$row,$version,$closed];
    }

    public function testOnlyThePreviouslyAppliedParentSuspensionSelectsItsExactAdmission(): void
    {
        [$session,$version,$closed] = $this->fixture();
        $reference = ParticipationSessionRetirement::reference($session,$version,self::ORIGIN,$closed);
        self::assertSame('session_suspended',$reference['reason']);
        self::assertSame('1',$reference['version']);
        $fields = json_decode($closed['fields_json'],true,20,JSON_THROW_ON_ERROR);
        self::assertNotSame($fields['object']['barrier_key'],$reference['barrier_key']);
        RankingBarrier::closeReference($reference);
        self::assertSame($reference,ParticipationSessionRetirement::reference(array_replace($session,['barrier_version' => '3']),$version,self::ORIGIN,$closed));
    }

    public function testAnUnconfirmedTamperedOrDifferentParentCannotAuthorizeRetirement(): void
    {
        [$session,$version,$closed] = $this->fixture();
        foreach ([null,array_replace($closed,['applied_at' => '']),array_replace($closed,['fields_sha256' => str_repeat('0',64)]),
            array_replace($closed,['barrier_version' => '2']),array_replace($closed,['operation' => 'register']),
            array_replace($closed,['action_id' => self::ORIGIN]),array_replace($closed,['contract' => BarrierTransport::COMPLETION_CONTRACT])] as $bad) {
            try { ParticipationSessionRetirement::reference($session,$version,self::ORIGIN,$bad); self::fail('Unapplied or unrelated parent accepted.'); }
            catch (ModelViolation $error) { self::assertSame('hof_participation_parent_acknowledgement_required',$error->reason); }
        }
    }

    public function testAValidCancellationIsNeverInterpretedAsTemporarySuspension(): void
    {
        [$session,$version,$closed] = $this->fixture();
        foreach (['session_cancelled','participant_withdrawn','creator_suspended'] as $reason) {
            $fields = CanonicalJson::object($closed['fields_json']); $fields['object']['reason'] = $reason;
            $closed['fields_json'] = CanonicalJson::encode($fields); $closed['fields_sha256'] = hash('sha256',$closed['fields_json']);
            try { ParticipationSessionRetirement::reference($session,$version,self::ORIGIN,$closed); self::fail('Different reason admitted.'); }
            catch (ModelViolation $error) { self::assertSame('hof_participation_parent_acknowledgement_required',$error->reason); }
        }
    }
}
