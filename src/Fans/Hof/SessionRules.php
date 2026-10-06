<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;

/** Structural rules only; territory/country admissibility is a separate reviewed policy. */
final class SessionRules
{
    /** @param array<string,mixed> $input
     * @return array<string,string> */
    public static function validate(array $input): array
    {
        $keys = ['title', 'rules_text', 'category', 'scope', 'country', 'territory_ref', 'timezone', 'starts_at', 'ends_at'];
        ModelValues::exactKeys($input, $keys);
        $strings = [];
        foreach ($input as $key => $value) {
            if (!is_string($value)) { throw new ModelViolation('hof_invalid_session_rules'); }
            $strings[$key] = $value;
        }
        $input = $strings;
        foreach (['title' => 120, 'rules_text' => 3000] as $field => $maximum) {
            $input[$field] = trim(str_replace("\r\n", "\n", $input[$field]));
            if (mb_strlen($input[$field], 'UTF-8') < 2 || mb_strlen($input[$field], 'UTF-8') > $maximum
                || preg_match('//u', $input[$field]) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F<>]/u', $input[$field]) === 1) {
                throw new ModelViolation('hof_invalid_session_rules');
            }
        }
        RankingPolicy::category($input['category']); RankingPolicy::scope($input['scope']);
        RankingCalendar::session($input['starts_at'], $input['ends_at'], $input['timezone']);
        if ($input['scope'] === 'international') {
            if ($input['country'] !== '' || $input['territory_ref'] !== '') { throw new ModelViolation('hof_invalid_session_territory'); }
        } elseif (preg_match('/^[A-Z]{2}$/D', $input['country']) !== 1
            || ($input['scope'] === 'local' ? preg_match('/^[A-Za-z0-9._-]{1,80}$/D', $input['territory_ref']) !== 1 : $input['territory_ref'] !== '')) {
            throw new ModelViolation('hof_invalid_session_territory');
        }
        return array_replace(array_fill_keys($keys, ''), $input);
    }

    /** @param array<string,string> $row */
    public static function digest(array $row): string
    {
        $fields = array_intersect_key($row, array_flip(['title', 'rules_text', 'category', 'scope', 'country', 'territory_ref', 'timezone', 'starts_at', 'ends_at']));
        return hash('sha256', CanonicalJson::encode(self::validate($fields)));
    }
}
