<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf\Protocol;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Content-addressed admission metadata. No editorial text, PF quantity or account credential. */
final class RankingBarrier
{
    /** @return list<array<string,mixed>> */
    public static function references(RankedIntent $intent): array
    {
        $context = $intent->values['ranking_context']; $origin = $context['origin_id']; $creator = $intent->base->values['creator_faluss_id'];
        $common = ['origin_id' => $origin,'policy_version' => $context['policy_version']];
        $items = [self::reference($common + ['kind' => 'origin','object_id' => $origin,'subject_id' => '', 'version' => '1']),
            self::reference($common + ['kind' => 'country','object_id' => $context['country_policy_revision'],'subject_id' => '', 'version' => '1']),
            self::reference($common + ['kind' => 'creator','object_id' => $creator,'subject_id' => '', 'version' => $context['category_revision'],'creator_category' => $context['creator_category']])];
        foreach ($context['sessions'] as $session) {
            $identity = ['object_id' => $session['session_id']];
            $fields = array_intersect_key($session,array_flip(['rules_revision','rules_sha256','starts_at','ends_at','scope','territory_policy_revision','country','territory_ref']));
            $items[] = self::reference($common + $identity + $fields + ['kind' => 'session','subject_id' => '', 'version' => $session['barrier_version']]);
            $fields = array_intersect_key($session,array_flip(['rules_sha256','starts_at','ends_at','admitted_at','territory_policy_revision','territory_admission_revision']));
            $items[] = self::reference($common + $identity + $fields + ['kind' => 'admission','subject_id' => $creator,
                'version' => $session['admission_revision'],'session_barrier_version' => $session['barrier_version']]);
        }
        usort($items,static fn (array $a,array $b): int => strcmp($a['barrier_key'],$b['barrier_key']));
        return $items;
    }

    /** @param array<array-key,mixed> $input
     * @return array<string,mixed> */
    public static function descriptor(array $input): array
    {
        ModelValues::exactKeys($input,['content','valid_from','valid_until']);
        if (!is_array($input['content'])) { throw new ModelViolation('pf_barrier_invalid_descriptor'); }
        $reference = self::reference($input['content']); $data = $reference['content'];
        RankingValues::utc($input['valid_from']); RankingValues::utc($input['valid_until']);
        if ($input['valid_until'] <= $input['valid_from']) { throw new ModelViolation('pf_barrier_invalid_interval'); }
        if (in_array($data['kind'],['session','admission'],true)) {
            $first = $data['kind'] === 'session' ? $data['starts_at'] : max($data['starts_at'],$data['admitted_at']);
            if ($input['valid_from'] !== $first || $input['valid_until'] !== $data['ends_at']) { throw new ModelViolation('pf_barrier_invalid_interval'); }
        }
        CanonicalJson::object(CanonicalJson::encode($input)); return $input;
    }

    /** @param array<array-key,mixed> $data
     * @return array{barrier_key:string,version:string,content_sha256:string,content:array<array-key,mixed>} */
    public static function reference(array $data): array
    {
        $kind = $data['kind'] ?? '';
        $extra = match ($kind) {
            'origin','country' => [], 'creator' => ['creator_category'],
            'session' => ['rules_revision','rules_sha256','starts_at','ends_at','scope','territory_policy_revision','country','territory_ref'],
            'admission' => ['rules_sha256','starts_at','ends_at','admitted_at','territory_policy_revision','territory_admission_revision','session_barrier_version'],
            default => throw new ModelViolation('pf_barrier_invalid_kind'),
        };
        ModelValues::exactKeys($data,array_merge(['kind','origin_id','object_id','subject_id','version','policy_version'],$extra));
        ModelValues::uuid($data['origin_id']); ModelValues::uuid($data['object_id']); ModelValues::version($data['policy_version']);
        $version = ModelValues::integer($data['version'],true);
        if ($kind === 'admission') { ModelValues::uuid($data['subject_id']); }
        elseif ($data['subject_id'] !== '') { throw new ModelViolation('pf_barrier_invalid_subject'); }
        if (in_array($kind,['origin','country'],true) && ($version !== '1' || ($kind === 'origin' && $data['object_id'] !== $data['origin_id']))) { throw new ModelViolation('pf_barrier_invalid_version'); }
        if ($kind === 'creator') { RankingValues::category($data['creator_category']); }
        if (in_array($kind,['session','admission'],true)) {
            RankingValues::digest($data['rules_sha256']); RankingValues::utc($data['starts_at']); RankingValues::utc($data['ends_at']);
            $start = new \DateTimeImmutable($data['starts_at'],new \DateTimeZone('UTC'));
            $end = new \DateTimeImmutable($data['ends_at'],new \DateTimeZone('UTC'));
            if ($end <= $start || $end > $start->modify('+90 days')) { throw new ModelViolation('pf_barrier_invalid_interval'); }
            if ($kind === 'admission') {
                RankingValues::utc($data['admitted_at']); ModelValues::integer($data['session_barrier_version'],true);
                ModelValues::integer($data['territory_admission_revision']);
                if ($data['admitted_at'] >= $data['ends_at']) { throw new ModelViolation('pf_barrier_invalid_interval'); }
                if ($data['territory_policy_revision'] === '') {
                    if ($data['territory_admission_revision'] !== '0') { throw new ModelViolation('pf_barrier_invalid_territory'); }
                } else { ModelValues::uuid($data['territory_policy_revision']); ModelValues::integer($data['territory_admission_revision'],true); }
            } else {
                ModelValues::integer($data['rules_revision'],true);
                if ($data['scope'] === 'international') {
                    if ($data['country'] !== '' || $data['territory_ref'] !== '' || $data['territory_policy_revision'] !== '') { throw new ModelViolation('pf_barrier_invalid_territory'); }
                } elseif (in_array($data['scope'],['local','national'],true)) {
                    ModelValues::uuid($data['territory_policy_revision']);
                    if (!is_string($data['country']) || preg_match('/^[A-Z]{2}$/D',$data['country']) !== 1 || !is_string($data['territory_ref'])
                        || ($data['scope'] === 'national' ? $data['territory_ref'] !== '' : preg_match('/^[A-Za-z0-9._-]{1,80}$/D',$data['territory_ref']) !== 1)) { throw new ModelViolation('pf_barrier_invalid_territory'); }
                } else { throw new ModelViolation('pf_barrier_invalid_scope'); }
            }
        }
        $key = hash('sha256',CanonicalJson::encode(array_intersect_key($data,array_flip(['origin_id','kind','object_id','subject_id']))));
        return ['barrier_key' => $key,'version' => $version,'content_sha256' => hash('sha256',CanonicalJson::encode($data)), 'content' => $data];
    }

    /** @param array<array-key,mixed> $input
     * @return array<string,string> */
    public static function closeReference(array $input): array
    {
        ModelValues::exactKeys($input,['barrier_key','version','content_sha256','reason']);
        $reasons = ['session_cancelled','session_suspended','participant_withdrawn','creator_suspended','country_revoked','origin_closed'];
        if (!is_string($input['reason']) || !in_array($input['reason'],$reasons,true)) { throw new ModelViolation('pf_barrier_invalid_reason'); }
        return ['barrier_key' => RankingValues::digest($input['barrier_key']),'version' => ModelValues::integer($input['version'],true),
            'content_sha256' => RankingValues::digest($input['content_sha256']),'reason' => $input['reason']];
    }
}
