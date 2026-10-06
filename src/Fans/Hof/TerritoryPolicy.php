<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Immutable published criteria and named territorial references; no implicit global policy. */
final class TerritoryPolicy
{
    /** @param array<string,mixed> $input
     * @return array{criteria_url:string,criteria_text:string,countries:list<string>,territories:array<string,array{country:string,name:string}>} */
    public static function validate(array $input): array
    {
        ModelValues::exactKeys($input, ['criteria_url','criteria_text','countries','territories']);
        $url = $input['criteria_url']; $criteria = self::text($input['criteria_text'], 4000);
        if (!is_string($url) || strlen($url) > 512 || filter_var($url, FILTER_VALIDATE_URL) === false
            || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) { throw new ModelViolation('hof_invalid_territory_policy'); }
        $countries = $input['countries']; $territories = $input['territories'];
        if (!is_array($countries) || !array_is_list($countries) || count($countries) < 1 || count($countries) > 250
            || !is_array($territories) || array_is_list($territories) || count($territories) > 2000) { throw new ModelViolation('hof_invalid_territory_policy'); }
        $canonicalCountries = [];
        foreach ($countries as $country) {
            if (!is_string($country) || preg_match('/^[A-Z]{2}$/D', $country) !== 1 || in_array($country, $canonicalCountries, true)) { throw new ModelViolation('hof_invalid_territory_policy'); }
            $canonicalCountries[] = $country;
        }
        sort($canonicalCountries, SORT_STRING); $canonicalTerritories = [];
        foreach ($territories as $ref => $territory) {
            if (!is_string($ref) || preg_match('/^[A-Za-z0-9._-]{1,80}$/D', $ref) !== 1 || !is_array($territory)) { throw new ModelViolation('hof_invalid_territory_policy'); }
            ModelValues::exactKeys($territory, ['country','name']);
            if (!is_string($territory['country']) || !in_array($territory['country'], $canonicalCountries, true)) { throw new ModelViolation('hof_invalid_territory_policy'); }
            $canonicalTerritories[$ref] = ['country' => $territory['country'], 'name' => self::text($territory['name'], 120)];
        }
        ksort($canonicalTerritories, SORT_STRING);
        return ['criteria_url' => $url, 'criteria_text' => $criteria, 'countries' => $canonicalCountries, 'territories' => $canonicalTerritories];
    }

    public static function text(mixed $value, int $maximum): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1) { throw new ModelViolation('hof_invalid_territory_text'); }
        $value = trim(str_replace("\r\n", "\n", $value));
        if (mb_strlen($value, 'UTF-8') < 2 || mb_strlen($value, 'UTF-8') > $maximum || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F<>]/u', $value) === 1) { throw new ModelViolation('hof_invalid_territory_text'); }
        return $value;
    }

    /** @param array<string,mixed> $input */
    public static function digest(array $input): string
    {
        $policy = self::validate($input); $territories = [];
        foreach ($policy['territories'] as $ref => $territory) { $territories[] = ['territory_ref' => $ref] + $territory; }
        // Opaque reference values are data, never protocol object property names.
        $policy['territories'] = $territories;
        ksort($policy, SORT_STRING);
        return hash('sha256', json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
