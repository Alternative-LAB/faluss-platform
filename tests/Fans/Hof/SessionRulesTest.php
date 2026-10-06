<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Fans\Hof;

use Faluss\Platform\Fans\Hof\SessionRules;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use PHPUnit\Framework\TestCase;

final class SessionRulesTest extends TestCase
{
    /** @return array<string,string> */
    private function rules(): array
    {
        return ['title' => 'Fixture session', 'rules_text' => 'Voluntary individual participation.', 'category' => 'arts', 'scope' => 'international',
            'country' => '', 'territory_ref' => '', 'timezone' => 'Europe/Paris', 'starts_at' => '2026-10-07 08:00:00.000000', 'ends_at' => '2026-10-09 08:00:00.000000'];
    }

    public function testInputKeyOrderCannotChangePersistedFieldOrderOrTheImmutableDigest(): void
    {
        $first = $this->rules(); $shuffled = array_reverse($first, true);
        self::assertSame($first, SessionRules::validate($shuffled));
        self::assertSame(SessionRules::digest($first), SessionRules::digest($shuffled));
    }

    public function testThreeScopesHaveDistinctTerritorialShapesWithoutInferringAnAuthorizedCountry(): void
    {
        $national = array_replace($this->rules(), ['scope' => 'national', 'country' => 'FR']);
        $local = array_replace($national, ['scope' => 'local', 'territory_ref' => 'fixture-locality']);
        self::assertSame('national', SessionRules::validate($national)['scope']);
        self::assertSame('local', SessionRules::validate($local)['scope']);
        self::assertNotSame(SessionRules::digest($local), SessionRules::digest($national));
    }

    public function testInvalidTerritoryShapeAndForeignEconomicFieldsFailClosed(): void
    {
        foreach ([['country' => 'FR'], ['scope' => 'national', 'country' => ''], ['scope' => 'local', 'country' => 'FR'], ['price' => '20'], ['score' => '10']] as $change) {
            try { SessionRules::validate(array_replace($this->rules(), $change)); self::fail('Invalid structural rule accepted'); }
            catch (ModelViolation $error) { self::assertNotSame('', $error->getMessage()); }
        }
    }

    public function testRulesArePlainTextAndDurationCannotEscapeTheApprovedLimit(): void
    {
        foreach ([['title' => '<script>'], ['category' => 'unknown'], ['rules_text' => "bad\x00text"], ['ends_at' => '2027-01-05 08:00:00.000001']] as $change) {
            try { SessionRules::validate(array_replace($this->rules(), $change)); self::fail('Invalid rule accepted'); }
            catch (ModelViolation $error) { self::assertNotSame('', $error->getMessage()); }
        }
    }
}
