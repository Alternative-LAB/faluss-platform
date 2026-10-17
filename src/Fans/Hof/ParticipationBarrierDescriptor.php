<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingBarrier;

/** Maps server-owned admission facts to the already approved admission barrier grammar. */
final class ParticipationBarrierDescriptor
{
    /** @param array<string,string> $session
     * @param array<string,string> $role
     * @return array<string,mixed> */
    public static function compose(array $session, array $role, string $origin, string $subject,
        string $version, ?string $territoryPolicy, string $territoryRevision): array
    {
        // Reuse the session validator, including the frozen digest and reviewed scope.
        SessionBarrierDescriptor::compose($session,$origin,$territoryPolicy);
        ModelValues::uuid($subject); ModelValues::integer($version,true);
        if (($role['session_id'] ?? '') !== $session['session_id'] || ($role['role'] ?? '') !== 'participant'
            || !in_array($role['state'] ?? '',['admitted','closing'],true)
            || ($role['rules_sha256'] ?? '') !== $session['frozen_sha256']) {
            throw new ModelViolation('hof_participation_binding_conflict');
        }
        $content = ['kind' => 'admission','origin_id' => $origin,'object_id' => $session['session_id'],
            'subject_id' => $subject,'version' => $version,'policy_version' => RankingPolicy::VERSION,
            'rules_sha256' => $session['frozen_sha256'],'starts_at' => $session['starts_at'],'ends_at' => $session['ends_at'],
            'admitted_at' => $role['admitted_at'],'territory_policy_revision' => $territoryPolicy ?? '',
            'territory_admission_revision' => $territoryRevision,'session_barrier_version' => $session['barrier_version']];
        return RankingBarrier::descriptor(['content' => $content,
            'valid_from' => max($session['starts_at'],$role['admitted_at']),'valid_until' => $session['ends_at']]);
    }
}
