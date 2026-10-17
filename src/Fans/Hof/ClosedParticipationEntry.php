<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;

/** Voluntary entry through an actually publishable session, without relaxing private inspection. */
final class ClosedParticipationEntry
{
    /** @return array{session:array<string,string>,roles:list<array<string,string>>} */
    public static function preview(\wpdb $db, string $id): array
    {
        ClosedEnvironment::assertIsolated($db,'fans'); ClosedSessionManagement::services($db);
        $creator = SessionStore::creator()['creator_id'];
        $provider = apply_filters('faluss_fans_hof_closed_delivery',null);
        if (!$provider instanceof ClosedRankingDelivery) { throw new ModelViolation('hof_session_unavailable'); }
        // Public delivery already requires a complete current corpus and an authorized session.
        $public = $provider->session($id);
        $store = new SessionStore($db);
        return $store->transaction(function () use ($store,$db,$id,$creator,$public): array {
            $session = $store->row($id); SessionStore::activeOwner($creator);
            (new SessionModeration($db))->requireApproval($session);
            if ($session['state'] !== 'open' || ($public['state'] ?? '') !== 'open' || ($public['id'] ?? '') !== $id
                || $session['ends_at'] <= $store->now() || $session['frozen_sha256'] !== SessionRules::digest($session)) {
                throw new ModelViolation('hof_invalid_session_transition');
            }
            foreach (['title','rules_text','starts_at','ends_at','scope','category'] as $field) {
                if (($public[$field] ?? null) !== $session[$field]) { throw new ModelViolation('hof_session_unavailable'); }
            }
            $role = $store->role($id,$creator,'participant');
            return ['session' => $session,'roles' => $role === null ? [] : [$role]];
        });
    }
}
