<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf\Protocol;

use DateTimeImmutable;
use DateTimeZone;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Owner-provided dimensions, not proof of profile/admission or permission to consume. */
final class RankingContext
{
    /** @param array<array-key,mixed> $input
     * @return array<string,mixed> */
    public static function validate(array $input): array
    {
        ModelValues::exactKeys($input,['origin_id','policy_version','creator_category','category_revision','country_policy_revision','sessions']);
        ModelValues::uuid($input['origin_id']); ModelValues::version($input['policy_version']);
        RankingValues::category($input['creator_category']); ModelValues::integer($input['category_revision'],true);
        ModelValues::uuid($input['country_policy_revision']);
        if (!is_array($input['sessions']) || !array_is_list($input['sessions']) || count($input['sessions']) > 10) { throw new ModelViolation('pf_ranking_invalid_sessions'); }
        $previous = '';
        foreach ($input['sessions'] as $session) {
            if (!is_array($session)) { throw new ModelViolation('pf_ranking_invalid_sessions'); }
            self::session($session);
            if (strcmp($session['session_id'],$previous) <= 0) { throw new ModelViolation('pf_ranking_duplicate_or_order'); }
            $previous = $session['session_id'];
        }
        // No editorial Unicode/numbers enter this ASCII canonical wire format.
        CanonicalJson::object(CanonicalJson::encode($input)); return $input;
    }

    /** @param array<array-key,mixed> $input */
    public static function fingerprint(array $input): string
    { return hash('sha256',CanonicalJson::encode(self::validate($input))); }

    /** Every explicitly chosen session is checked, never silently removed from the selection.
     * @param array<array-key,mixed> $input */
    public static function atConfirmation(array $input, string $confirmedAt): void
    {
        $context = self::validate($input); RankingValues::utc($confirmedAt);
        foreach ($context['sessions'] as $session) {
            if ($confirmedAt < $session['starts_at'] || $confirmedAt < $session['admitted_at'] || $confirmedAt >= $session['ends_at']) { throw new ModelViolation('pf_ranking_session_not_current'); }
        }
    }

    /** @param array<array-key,mixed> $session */
    private static function session(array $session): void
    {
        ModelValues::exactKeys($session,['session_id','rules_revision','rules_sha256','admission_revision','barrier_version',
            'starts_at','ends_at','admitted_at','scope','territory_policy_revision','territory_admission_revision','country','territory_ref']);
        ModelValues::uuid($session['session_id']); RankingValues::digest($session['rules_sha256']);
        foreach (['rules_revision','admission_revision','barrier_version'] as $field) { ModelValues::integer($session[$field],true); }
        foreach (['starts_at','ends_at','admitted_at'] as $field) { RankingValues::utc($session[$field]); }
        $start = new DateTimeImmutable($session['starts_at'],new DateTimeZone('UTC'));
        $end = new DateTimeImmutable($session['ends_at'],new DateTimeZone('UTC'));
        if ($end <= $start || $end > $start->modify('+90 days') || $session['admitted_at'] >= $session['ends_at']) { throw new ModelViolation('pf_ranking_invalid_session_interval'); }
        if ($session['scope'] === 'international') {
            if ($session['country'] !== '' || $session['territory_ref'] !== '' || $session['territory_policy_revision'] !== '' || $session['territory_admission_revision'] !== '0') { throw new ModelViolation('pf_ranking_invalid_territory'); }
        } elseif (in_array($session['scope'],['local','national'],true)) {
            ModelValues::uuid($session['territory_policy_revision']); ModelValues::integer($session['territory_admission_revision'],true);
            if (!is_string($session['country']) || preg_match('/^[A-Z]{2}$/D',$session['country']) !== 1
                || !is_string($session['territory_ref']) || ($session['scope'] === 'local'
                    ? preg_match('/^[A-Za-z0-9._-]{1,80}$/D',$session['territory_ref']) !== 1 : $session['territory_ref'] !== '')) { throw new ModelViolation('pf_ranking_invalid_territory'); }
        } else { throw new ModelViolation('pf_ranking_invalid_scope'); }
    }
}
