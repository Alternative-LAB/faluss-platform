<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Fans\Hof;

use Faluss\Platform\Fans\Hof\ParticipationBarrierDescriptor;
use Faluss\Platform\Fans\Hof\SessionRules;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use PHPUnit\Framework\TestCase;

final class ParticipationBarrierDescriptorTest extends TestCase
{
    private const ORIGIN = '22222222-2222-4222-8222-222222222222';
    private const SESSION = '33333333-3333-4333-8333-333333333333';
    private const IDENTITY = '44444444-4444-4444-8444-444444444444';

    /** @return array<string,string> */
    private function session(): array
    {
        $row = ['title' => 'Fixture','rules_text' => 'Voluntary individual participation.',
            'category' => 'arts','scope' => 'international','country' => '','territory_ref' => '','timezone' => 'Europe/Paris',
            'starts_at' => '2026-10-09 10:00:00.000000','ends_at' => '2026-10-10 10:00:00.000000',
            'session_id' => self::SESSION,'state' => 'open','barrier_version' => '3','rules_version' => '2','policy_version' => '1.0.0'];
        $row['frozen_sha256'] = SessionRules::digest($row); return $row;
    }

    /** @param array<string,string> $session
     * @return array<string,string> */
    private function role(array $session): array
    {
        return ['session_id' => self::SESSION,'role' => 'participant','state' => 'admitted',
            'revision' => '12','admitted_at' => '2026-10-09 13:00:00.000000','rules_sha256' => $session['frozen_sha256']];
    }

    public function testLateAdmissionStartsInTheFutureAndUsesIndependentBarrierVersions(): void
    {
        $session = $this->session(); $role = $this->role($session);
        $value = ParticipationBarrierDescriptor::compose($session,$role,self::ORIGIN,self::IDENTITY,'2',null,'0');
        self::assertSame($role['admitted_at'],$value['valid_from']);
        self::assertSame('2',$value['content']['version']);
        self::assertSame('3',$value['content']['session_barrier_version']);
        self::assertSame(self::IDENTITY,$value['content']['subject_id']);
        foreach (['revision','creator_id','title','quantity','score'] as $field) { self::assertArrayNotHasKey($field,$value['content']); }
        $role['admitted_at'] = '2026-10-09 09:00:00.000000';
        self::assertSame($session['starts_at'],ParticipationBarrierDescriptor::compose($session,$role,self::ORIGIN,self::IDENTITY,'2',null,'0')['valid_from']);
    }

    public function testReviewedTerritoryRevisionIsNotTheRoleOrPolicyVersion(): void
    {
        $session = array_replace($this->session(),['scope' => 'local','country' => 'FR','territory_ref' => 'fixture.locality']);
        $session['frozen_sha256'] = SessionRules::digest($session); $role = $this->role($session);
        $value = ParticipationBarrierDescriptor::compose($session,$role,self::ORIGIN,self::IDENTITY,'1',self::ORIGIN,'7');
        self::assertSame('7',$value['content']['territory_admission_revision']);
        $this->expectException(ModelViolation::class);
        ParticipationBarrierDescriptor::compose($session,$role,self::ORIGIN,self::IDENTITY,'1',self::ORIGIN,'0');
    }

    public function testUnacceptedRulesWrongSessionAndExpiredAdmissionCannotCompose(): void
    {
        $session = $this->session(); $role = $this->role($session);
        foreach ([['role' => 'organizer'],['state' => 'requested'],['session_id' => self::ORIGIN],
            ['rules_sha256' => str_repeat('0',64)],['admitted_at' => $session['ends_at']]] as $change) {
            try { ParticipationBarrierDescriptor::compose($session,array_replace($role,$change),self::ORIGIN,self::IDENTITY,'1',null,'0'); self::fail('Invalid admission accepted'); }
            catch (ModelViolation $error) { self::assertNotSame('',$error->reason); }
        }
        $this->expectException(ModelViolation::class);
        ParticipationBarrierDescriptor::compose($session,$role,self::ORIGIN,'wordpress-user-12','1',null,'0');
    }
}
