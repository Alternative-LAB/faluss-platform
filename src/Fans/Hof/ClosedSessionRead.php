<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\Fans\PfContract\ClosedBarrierPrimaryRead;
use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\Fans\Profiles\EditorialService;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\BarrierTransport;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingBarrier;

/** Reads the existing local lifecycle binding and primary proof; never registers or closes a barrier. */
final class ClosedSessionRead
{
    public function __construct(private readonly \wpdb $db, private readonly ClosedBarrierPrimaryRead $primary,
        private readonly string $origin) { ModelValues::uuid($origin); }

    /** Called in the delivery's sessions/origin/corpus transaction. No independent COMMIT or HTTP.
     * @return array{row:array<string,string>,content:array<string,mixed>,organizers:list<string>} */
    public function readInTransaction(string $id): array
    {
        ClosedEnvironment::assertIsolated($this->db,'fans');
        $mutex = 'fans_hof_' . substr(hash('sha256',$this->db->prefix . '|sessions'),0,48);
        if ((string) $this->db->get_var('SELECT @@in_transaction') !== '1'
            || (string) $this->db->get_var($this->db->prepare('SELECT IS_USED_LOCK(%s)=CONNECTION_ID()',$mutex)) !== '1'
            || $this->db->last_error !== '') { throw new ModelViolation('hof_primary_transaction_required'); }
        if (!ClosedSessionLifecycleSchema::ready($this->db)) { throw new ModelViolation('hof_lifecycle_schema_unavailable'); }
        $store = new SessionStore($this->db); $session = $store->row($id);
        if (!in_array($session['state'],['open','closed','cancelled'],true)) { throw new ModelViolation('hof_session_unavailable'); }
        (new SessionModeration($this->db))->requireApproval($session);
        // An inactive/withdrawn presentation cannot publish the organizer's identity or its session.
        if (CreatorProfileService::publicById($session['owner_creator_id'],true) === null
            || EditorialService::publicNameInTransaction($session['owner_creator_id']) === null) { throw new ModelViolation('hof_session_unavailable'); }
        $policy = $session['scope'] === 'international' ? null : (new TerritoryService($this->db))->boundPolicy($id);
        $descriptor = SessionBarrierDescriptor::compose($session,$this->origin,$policy);
        $operation = $session['state'] === 'open' ? 'register' : 'close';
        $binding = $store->storage->row($this->db->prepare('SELECT * FROM %i WHERE session_id=%s AND barrier_version=%s AND operation=%s FOR UPDATE',
            ClosedSessionLifecycleSchema::tables($this->db)['bindings'],$id,$session['barrier_version'],$operation));
        if ($binding === null || $binding['applied_at'] === '') { throw new ModelViolation('hof_session_primary_read_required'); }
        $reference = RankingBarrier::reference($descriptor['content']);
        $expectedObject = $descriptor;
        $reason = $session['state'] === 'closed' ? 'session_completed' : 'session_cancelled';
        if ($operation === 'close') { $expectedObject = array_intersect_key($reference,array_flip(['barrier_key','version','content_sha256'])) + ['reason' => $reason]; }
        $expected = ['operation' => $operation,'lookup_operation' => '','action_id' => $binding['action_id'],
            'origin_id' => $this->origin,'policy_version' => RankingPolicy::VERSION,'object' => $expectedObject];
        $contract = $session['state'] === 'closed' ? BarrierTransport::COMPLETION_CONTRACT : BarrierTransport::CONTRACT;
        $bytes = CanonicalJson::encode($expected);
        if ($binding['fields_json'] !== $bytes || $binding['fields_sha256'] !== hash('sha256',$bytes) || $binding['contract'] !== $contract) {
            throw new ModelViolation('hof_lifecycle_binding_conflict');
        }
        $this->primary->withCurrentAcknowledgementInTransaction($binding['action_id'],function (array $ack) use ($expected,$contract,$reference,$binding,$session,$operation,$reason,$store): void {
            if ($ack['contract'] !== $contract || CanonicalJson::encode($ack['fields']) !== CanonicalJson::encode($expected)
                || $ack['result']['effective_at'] !== $binding['applied_at']) { throw new ModelViolation('hof_lifecycle_binding_conflict'); }
            foreach (['barrier_key','version','content_sha256'] as $field) {
                if ($ack['result'][$field] !== $reference[$field]) { throw new ModelViolation('hof_lifecycle_binding_conflict'); }
            }
            if ($operation === 'register') {
                if ($ack['local_state'] !== 'active' || $ack['result']['state'] !== 'active' || $store->now() >= $session['ends_at']) {
                    throw new ModelViolation('hof_session_unavailable');
                }
            } elseif ($ack['local_state'] !== 'closed' || $ack['result']['state'] !== 'closed' || $ack['result']['reason'] !== $reason
                || ($reason === 'session_completed' && $binding['applied_at'] < $session['ends_at'])) { throw new ModelViolation('hof_session_unavailable'); }
        });
        $organizers = [];
        foreach ($store->roles($id) as $role) {
            if ($role['role'] !== 'organizer' || $role['state'] !== 'accepted') { continue; }
            $name = EditorialService::publicNameInTransaction($role['creator_id']);
            if (CreatorProfileService::publicById($role['creator_id'],true) !== null && $name !== null) { $organizers[] = $name; }
        }
        return ['row' => $session,'content' => $descriptor['content'],'organizers' => $organizers];
    }
}
