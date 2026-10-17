<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\BarrierTransport;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingBarrier;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingValues;

/** Historical primary closure, already verified/applied by the session owner; no inferred suspension. */
final class ParticipationSessionRetirement
{
    /** @param array<string,string> $session
     * @param array<string,string> $version
     * @param array<string,string>|null $closed
     * @return array<string,string> */
    public static function reference(array $session, array $version, string $origin, ?array $closed): array
    {
        $descriptor = RankingBarrier::descriptor(CanonicalJson::object($version['descriptor_json']));
        $content = $descriptor['content'];
        if ($version['descriptor_sha256'] !== hash('sha256',$version['descriptor_json'])
            || $content['kind'] !== 'admission' || $content['origin_id'] !== $origin
            || $content['object_id'] !== $session['session_id'] || $content['subject_id'] !== $version['subject_id']
            || $content['version'] !== $version['barrier_version']
            || $content['session_barrier_version'] !== $version['session_barrier_version']
            || $closed === null || $closed['applied_at'] === '' || $closed['contract'] !== BarrierTransport::CONTRACT
            || $closed['session_id'] !== $session['session_id'] || $closed['barrier_version'] !== $version['session_barrier_version']
            || $closed['operation'] !== 'close' || $closed['fields_sha256'] !== hash('sha256',$closed['fields_json'])) {
            throw new ModelViolation('hof_participation_parent_acknowledgement_required');
        }
        RankingValues::utc($closed['applied_at']);
        $oldParent = array_replace($session,['barrier_version' => $version['session_barrier_version']]);
        $parent = SessionBarrierDescriptor::compose($oldParent,$origin,$content['territory_policy_revision'] === '' ? null : $content['territory_policy_revision']);
        $reference = array_intersect_key(RankingBarrier::reference($parent['content']),array_flip(['barrier_key','version','content_sha256']))
            + ['reason' => 'session_suspended'];
        $fields = CanonicalJson::object($closed['fields_json']); BarrierTransport::fields($fields);
        if ($fields['operation'] !== 'close' || $fields['action_id'] !== $closed['action_id'] || $fields['origin_id'] !== $origin
            || $fields['policy_version'] !== RankingPolicy::VERSION
            || CanonicalJson::encode($fields['object']) !== CanonicalJson::encode($reference)) {
            throw new ModelViolation('hof_participation_parent_acknowledgement_required');
        }
        return array_intersect_key(RankingBarrier::reference($content),array_flip(['barrier_key','version','content_sha256']))
            + ['reason' => 'session_suspended'];
    }
}
