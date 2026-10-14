<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Fans\Hof;

use Faluss\Platform\Fans\Hof\SessionBarrierDescriptor;
use Faluss\Platform\Fans\Hof\SessionRules;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use PHPUnit\Framework\TestCase;

final class SessionBarrierDescriptorTest extends TestCase
{
    private const ORIGIN = '22222222-2222-4222-8222-222222222222';
    private const SESSION = '33333333-3333-4333-8333-333333333333';

    /** @return array<string,string> */
    private function session(): array
    {
        $row = ['title' => 'Lumières et création','rules_text' => 'Participation volontaire et individuelle.',
            'category' => 'arts','scope' => 'international','country' => '', 'territory_ref' => '', 'timezone' => 'Europe/Paris',
            'starts_at' => '2026-10-09 10:00:00.000000','ends_at' => '2026-10-10 10:00:00.000000',
            'session_id' => self::SESSION,'state' => 'opening','barrier_version' => '1','rules_version' => '2','policy_version' => '1.0.0'];
        $row['frozen_sha256'] = SessionRules::digest($row); return $row;
    }

    public function testFrozenEditorialFingerprintIsPreservedWithoutSendingTextOrScores(): void
    {
        $row = $this->session(); $descriptor = SessionBarrierDescriptor::compose($row,self::ORIGIN,null);
        self::assertSame($row['frozen_sha256'],$descriptor['content']['rules_sha256']);
        self::assertSame('2',$descriptor['content']['rules_revision']);
        self::assertSame($row['ends_at'],$descriptor['valid_until']);
        foreach (['title','rules_text','category','owner_creator_id','score','price'] as $field) { self::assertArrayNotHasKey($field,$descriptor['content']); }
    }

    public function testReadmissionChangesBarrierVersionWithoutRewritingFrozenRules(): void
    {
        $row = $this->session(); $first = SessionBarrierDescriptor::compose($row,self::ORIGIN,null);
        $row['barrier_version'] = '2'; $again = SessionBarrierDescriptor::compose($row,self::ORIGIN,null);
        self::assertSame('2',$again['content']['version']); self::assertSame($first['content']['rules_sha256'],$again['content']['rules_sha256']);
    }

    public function testEveryNonInternationalScopeNeedsTheReviewedPolicyBinding(): void
    {
        foreach (['national','local'] as $scope) {
            $row = array_replace($this->session(),['scope' => $scope,'country' => 'FR','territory_ref' => $scope === 'local' ? 'fixture.locality' : '']);
            $row['frozen_sha256'] = SessionRules::digest($row);
            try { SessionBarrierDescriptor::compose($row,self::ORIGIN,null); self::fail('Missing policy admitted'); }
            catch (ModelViolation $error) { self::assertSame('hof_reviewed_territory_required',$error->reason); }
            self::assertSame(self::ORIGIN,SessionBarrierDescriptor::compose($row,self::ORIGIN,self::ORIGIN)['content']['territory_policy_revision']);
        }
    }

    public function testDraftTamperedRulesAndUnknownPolicyNeverComposeAdmission(): void
    {
        foreach ([['state' => 'draft'],['title' => 'Changed title'],['policy_version' => '2.0.0'],['frozen_sha256' => ''],['barrier_version' => '0']] as $change) {
            try { SessionBarrierDescriptor::compose(array_replace($this->session(),$change),self::ORIGIN,null); self::fail('Invalid frozen context admitted'); }
            catch (ModelViolation $error) { self::assertNotSame('',$error->reason); }
        }
        $this->expectException(ModelViolation::class); SessionBarrierDescriptor::compose($this->session(),self::ORIGIN,self::ORIGIN);
    }
}
