<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Fans\Hof;

use Faluss\Platform\Fans\Hof\TerritoryPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TerritoryPolicyTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function fixture(): array
    { return ['criteria_url' => 'https://policy.example.test/fixture', 'criteria_text' => 'Critères fictifs, activité déclarée uniquement.', 'countries' => ['FR','BE'], 'territories' => ['fixture.paris' => ['country' => 'FR', 'name' => 'Recette Île-de-France'], 'fixture.belgium' => ['country' => 'BE', 'name' => 'Recette Belgique']]]; }

    public function testCanonicalPolicyIsIndependentOfInputOrdering(): void
    {
        $a = self::fixture(); $b = array_reverse($a, true);
        $b['countries'] = ['BE','FR']; $b['territories'] = array_reverse($a['territories'], true);
        self::assertSame(TerritoryPolicy::digest($a), TerritoryPolicy::digest($b));
        self::assertSame(['BE','FR'], TerritoryPolicy::validate($a)['countries']);
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function invalid(): iterable
    {
        yield 'No insecure public criteria' => ['criteria_url','http://policy.example.test'];
        yield 'No credentials in reference' => ['criteria_url','https://member:fixture@policy.example.test'];
        yield 'No markup' => ['criteria_text','<script>fixture</script>'];
        yield 'No duplicated countries' => ['countries',['FR','FR']];
        yield 'No default all countries' => ['countries',[]];
        yield 'No guessed lowercase country' => ['countries',['fr']];
        yield 'No unregistered territory country' => ['territories',['fixture' => ['country' => 'ZZ', 'name' => 'Unknown']]];
        yield 'No unreviewed financial condition' => ['territories',['fixture' => ['country' => 'FR', 'name' => 'Fixture', 'purchase' => true]]];
        yield 'No unnamed locality' => ['territories',['fixture' => ['country' => 'FR', 'name' => '']]];
    }

    #[DataProvider('invalid')]
    public function testUnusablePolicyIsRejected(string $key, mixed $value): void
    { $fixture = self::fixture(); $fixture[$key] = $value; $this->expectException(ModelViolation::class); TerritoryPolicy::validate($fixture); }
}
