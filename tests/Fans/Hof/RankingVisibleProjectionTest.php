<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Fans\Hof;

use Faluss\Platform\Fans\Hof\RankingVisibleProjection;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use PHPUnit\Framework\TestCase;

final class RankingVisibleProjectionTest extends TestCase
{
    private static function id(int $value): string { return sprintf('%08x-1111-4111-8111-111111111111',$value); }
    /** @return array<string,string> */
    private static function row(int $id, int $position, int $points, int $order): array
    {
        return ['faluss_id' => self::id($id),'position' => (string) $position,'points' => (string) $points,
            'reached_at' => '2026-10-05 10:00:00.123456','consumption_order' => (string) $order];
    }
    public function testHiddenRowsDoNotRevealTheirIdentityScoreOrPrivateRank(): void
    {
        $rows = [self::row(1,1,20,1),self::row(2,2,12,2),self::row(3,3,12,3)]; $before = $rows;
        $result = RankingVisibleProjection::build($rows,static fn (string $id): ?string => $id === self::id(1) ? null : 'Approved name');
        self::assertSame([['name' => 'Approved name','points' => '12','position' => '1'],
            ['name' => 'Approved name','points' => '12','position' => '2']],$result);
        self::assertSame($before,$rows);
        foreach ($result as $row) { self::assertSame(['name','points','position'],array_keys($row)); }
    }
    public function testCurrentVisibilityChangesRecomputePlacesWithoutChangingEconomicFacts(): void
    {
        $rows = [self::row(1,1,10,1),self::row(2,2,10,2)]; $allowed = [self::id(1) => 'First',self::id(2) => 'Second'];
        $names = static function (string $id) use (&$allowed): ?string { return $allowed[$id] ?? null; };
        self::assertSame(['1','2'],array_column(RankingVisibleProjection::build($rows,$names),'position'));
        unset($allowed[self::id(1)]);
        self::assertSame([['name' => 'Second','points' => '10','position' => '1']],RankingVisibleProjection::build($rows,$names));
        $allowed[self::id(1)] = 'First';
        self::assertSame(['First','Second'],array_column(RankingVisibleProjection::build($rows,$names),'name'));
        self::assertSame('10',$rows[0]['points']);
    }
    public function testEmptyOrFullyPrivateSourceCreatesNoParticipant(): void
    {
        self::assertSame([],RankingVisibleProjection::build([],static fn (): ?string => null));
        self::assertSame([],RankingVisibleProjection::build([self::row(1,1,8,1)],static fn (): ?string => null));
    }
    public function testMalformedOrReorderedSourceIsRefusedInsteadOfRepairingAFalseRanking(): void
    {
        $first = self::row(1,1,10,1); $second = self::row(2,2,10,2);
        $cases = [[$second,$first],[$first,$first],[$first,array_replace($second,['position' => '3'])],
            [$first,array_replace($second,['points' => '20'])],[$first + ['email' => 'forbidden@example.invalid']],
            [array_replace($first,['points' => '0'])],[$first,array_replace($second,['consumption_order' => '0'])]];
        foreach ($cases as $rows) {
            try { RankingVisibleProjection::build($rows,static fn (): string => 'Approved');self::fail('Invalid ranking was displayed.'); }
            catch (ModelViolation $error) { self::assertNotSame('',$error->reason); }
        }
    }
    public function testSameHubInstantAndOrderRetainsTheImmutableLastTieBreakWithoutDisplayingIt(): void
    {
        $rows = [self::row(1,1,10,7),self::row(2,2,10,7)];
        $names = [self::id(1) => 'First',self::id(2) => 'Second'];
        self::assertSame(['First','Second'],array_column(RankingVisibleProjection::build($rows,static fn (string $id): string => $names[$id]),'name'));
    }
    public function testVisibilityFailureDoesNotReturnAPartialRanking(): void
    {
        $this->expectException(ModelViolation::class);
        RankingVisibleProjection::build([self::row(1,1,10,1),self::row(2,2,5,2)],static function (string $id): string {
            if ($id === self::id(2)) { throw new ModelViolation('hof_storage_unavailable'); }return 'Approved';
        });
    }
    public function testEmptyApprovedNameNeverFallsBackToTechnicalIdentity(): void
    {
        $this->expectException(ModelViolation::class);
        RankingVisibleProjection::build([self::row(1,1,10,1)],static fn (): string => '');
    }
}
