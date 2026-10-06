<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedIntent;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingBarrier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RankingBarrierTest extends TestCase
{
    public function testEveryChosenDimensionHasAnExactCanonicalBarrier(): void
    {
        $refs = RankingBarrier::references(RankedIntent::fromArray(RankedFixtures::intent()));
        self::assertCount(5,$refs);
        $keys = array_column($refs,'barrier_key'); $ordered = $keys; sort($ordered,SORT_STRING);
        self::assertSame($ordered,$keys); self::assertCount(5,array_unique($keys));
        self::assertSame(['admission','country','creator','origin','session'],(static function (array $refs): array { $kinds = array_column(array_column($refs,'content'),'kind'); sort($kinds); return $kinds; })($refs));
        foreach ($refs as $ref) {
            $data = $ref['content']; $start = $data['kind'] === 'admission' ? $data['admitted_at'] : ($data['starts_at'] ?? '2026-01-01 00:00:00.000000');
            $end = $data['ends_at'] ?? '2027-01-01 00:00:00.000000';
            self::assertSame($ref,RankingBarrier::reference(RankingBarrier::descriptor(['content' => $data,'valid_from' => $start,'valid_until' => $end])['content']));
        }
    }

    public function testChangedCategoryKeepsIdentityButRequiresANewVersionAndContent(): void
    {
        $intent = RankedFixtures::intent(); $a = RankingBarrier::references(RankedIntent::fromArray($intent));
        $intent['ranking_context']['creator_category'] = 'music'; $intent['ranking_context']['category_revision'] = '3';
        $intent['context_sha256'] = \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingContext::fingerprint($intent['ranking_context']);
        $b = RankingBarrier::references(RankedIntent::fromArray($intent));
        self::assertSame(array_column($a,'barrier_key'),array_column($b,'barrier_key'));
        $creator = static fn (array $refs): array => array_values(array_filter($refs,static fn (array $ref): bool => $ref['content']['kind'] === 'creator'))[0];
        self::assertNotSame($creator($a)['content_sha256'],$creator($b)['content_sha256']); self::assertSame('3',$creator($b)['version']);
    }

    #[DataProvider('invalidDescriptor')]
    public function testDescriptorsRejectIncorrectIdentityFieldsAndBounds(string $field): void
    {
        $refs = RankingBarrier::references(RankedIntent::fromArray(RankedFixtures::intent()));
        $session = array_values(array_filter($refs,static fn (array $ref): bool => $ref['content']['kind'] === 'session'))[0]['content'];
        $input = ['content' => $session,'valid_from' => $session['starts_at'],'valid_until' => $session['ends_at']];
        switch ($field) {
            case 'subject': $input['content']['subject_id'] = ModelFixtures::MEMBER; break;
            case 'number': $input['content']['version'] = 1; break;
            case 'kind': $input['content']['kind'] = 'wallet'; break;
            case 'extra': $input['content']['score'] = '100'; break;
            case 'bounds': $input['valid_from'] = '2026-10-01 00:00:00.000001'; break;
            case 'expired_interval': $input['valid_until'] = $input['valid_from']; break;
            case 'territory': $input['content']['country'] = 'FR'; break;
            case 'date': $input['content']['starts_at'] = '2026-02-30 00:00:00.000000'; break;
        }
        $this->expectException(ModelViolation::class); RankingBarrier::descriptor($input);
    }

    public static function invalidDescriptor(): iterable
    { foreach (['subject','number','kind','extra','bounds','expired_interval','territory','date'] as $field) { yield $field => [$field]; } }

    public function testCloseReferenceCannotSupplyAnEconomicAdjustment(): void
    {
        $ref = RankingBarrier::references(RankedIntent::fromArray(RankedFixtures::intent()))[0]; unset($ref['content']); $ref['reason'] = 'session_suspended';
        self::assertSame($ref,RankingBarrier::closeReference($ref));
        $ref['purchased_pf'] = '10'; $this->expectException(ModelViolation::class); RankingBarrier::closeReference($ref);
    }
}
