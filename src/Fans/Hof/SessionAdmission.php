<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** One server admission guard for organizers and moderator recourse, always inside their audited transaction. */
final class SessionAdmission
{
    /** @param array<string,string> $session
     * @return array<string,string> */
    public static function decide(SessionStore $store, array $session, string $target, bool $allow, bool $appeal = false): array
    {
        ModelValues::uuid($target);
        if ((string) $store->db->get_var('SELECT @@in_transaction') !== '1' || $store->db->last_error !== '') { throw new ModelViolation('hof_admission_transaction_required'); }
        if ($appeal) { RankingRegistry::administrator(); }
        else { $store->manager($session,SessionStore::ownerCreator()['creator_id']); }
        if (!in_array($session['state'],['opening','open'],true) || $session['ends_at'] <= $store->now()) { throw new ModelViolation('hof_invalid_session_transition'); }
        $role = $store->role($session['session_id'],$target,'participant');
        if ($role === null || $role['state'] !== ($appeal ? 'refused' : 'requested') || $role['rules_sha256'] !== $session['frozen_sha256']) { throw new ModelViolation('hof_participation_conflict'); }
        if ($allow) {
            SessionStore::activeOwner($target); (new SessionModeration($store->db))->requireApproval($session);
            if ($session['scope'] !== 'international') {
                $territories = new TerritoryService($store->db); $policy = $territories->boundPolicy($session['session_id']) ?? throw new ModelViolation('hof_reviewed_territory_required');
                $territories->eligible($target,$policy,$session);
            }
        }
        if ($store->storage->query($store->db->prepare('UPDATE %i SET state=%s,revision=revision+1,admitted_at=%s,reason=%s,updated_at=UTC_TIMESTAMP(6) WHERE session_id=%s AND creator_id=%s AND role=%s AND revision=%d',
            $store->tables['roles'],$allow ? 'admitted' : 'refused',$allow ? $store->now() : '',$allow ? 'allowed_participation' : 'criteria_not_met',$session['session_id'],$target,'participant',$role['revision'])) !== 1) { throw new ModelViolation('hof_participation_conflict'); }
        return $store->change($session,[],$appeal ? 'appeal_admission' : 'decide_participation',$target,$allow ? 'allowed' : 'refused');
    }
}
