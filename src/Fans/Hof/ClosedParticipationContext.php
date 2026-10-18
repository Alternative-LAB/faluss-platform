<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\Fans\PfContract\ClosedBarrierPrimaryRead;
use Faluss\Platform\Fans\Sso\FansLinkedAccounts;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\BarrierTransport;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingBarrier;

/** Read the current native voluntary admission and its primary proof, not a reconstructed browser context. */
final class ClosedParticipationContext
{
    public function __construct(private readonly \wpdb $db,private readonly ClosedBarrierPrimaryRead $primary,private readonly string $origin)
    {
        ClosedEnvironment::assertIsolated($db,'fans'); ModelValues::uuid($origin);
        if ($db !== ($GLOBALS['wpdb'] ?? null)) { throw new ModelViolation('hof_primary_connection_required'); }
    }

    /** @return array{session:array<string,string>,context:array<string,mixed>} */
    public function readInTransaction(string $id,string $creator): array
    {
        ClosedEnvironment::assertIsolated($this->db,'fans');
        if (!ClosedParticipationSchema::ready($this->db)) { throw new ModelViolation('hof_participation_schema_unavailable'); }
        $read = (new ClosedSessionRead($this->db,$this->primary,$this->origin))->readInTransaction($id);
        $session = $read['row']; $store = new SessionStore($this->db); $now = $store->now();
        $role = $store->role($id,$creator,'participant');
        if ($session['state'] !== 'open' || $now < $session['starts_at'] || $now >= $session['ends_at'] || $role === null
            || $role['state'] !== 'admitted' || $role['rules_sha256'] !== $session['frozen_sha256'] || $now < $role['admitted_at']) {
            throw new ModelViolation('hof_attribution_session_unavailable');
        }
        $owner = SessionStore::activeOwner($creator);
        $subject = FansLinkedAccounts::identityInTransaction($owner) ?? throw new ModelViolation('hof_linked_creator_required');
        $territoryRevision = $session['scope'] === 'international' ? '0'
            : (new TerritoryService($this->db))->eligible($creator,$read['territory_policy'] ?? throw new ModelViolation('hof_reviewed_territory_required'),$session)['approved_revision'];
        $tables = ClosedParticipationSchema::tables($this->db);
        $version = $store->storage->row($this->db->prepare('SELECT * FROM %i WHERE session_id=%s AND creator_id=%s ORDER BY barrier_version DESC LIMIT 1 FOR UPDATE',
            $tables['versions'],$id,$creator)) ?? throw new ModelViolation('hof_participation_binding_required');
        $descriptor = ParticipationBarrierDescriptor::compose($session,$role,$this->origin,$subject,$version['barrier_version'],$read['territory_policy'],$territoryRevision);
        $bytes = CanonicalJson::encode($descriptor);
        if ($version['descriptor_json'] !== $bytes || $version['descriptor_sha256'] !== hash('sha256',$bytes)
            || $version['subject_id'] !== $subject || $version['role_revision'] !== $role['revision']
            || $version['session_barrier_version'] !== $session['barrier_version']) { throw new ModelViolation('hof_participation_binding_conflict'); }
        $binding = $store->storage->row($this->db->prepare('SELECT * FROM %i WHERE session_id=%s AND creator_id=%s AND barrier_version=%s AND operation=%s FOR UPDATE',
            $tables['bindings'],$id,$creator,$version['barrier_version'],'register')) ?? throw new ModelViolation('hof_participation_binding_required');
        $expected = ['operation' => 'register','lookup_operation' => '','action_id' => $binding['action_id'],
            'origin_id' => $this->origin,'policy_version' => RankingPolicy::VERSION,'object' => $descriptor];
        $fields = CanonicalJson::encode($expected);
        if ($binding['applied_at'] === '' || $binding['contract'] !== BarrierTransport::CONTRACT
            || $binding['fields_json'] !== $fields || $binding['fields_sha256'] !== hash('sha256',$fields)) { throw new ModelViolation('hof_participation_binding_conflict'); }
        $this->primary->withCurrentAcknowledgementInTransaction($binding['action_id'],static function (array $ack) use ($expected,$descriptor,$binding): void {
            if ($ack['contract'] !== BarrierTransport::CONTRACT || CanonicalJson::encode($ack['fields']) !== CanonicalJson::encode($expected)
                || $ack['local_state'] !== 'active' || $ack['result']['state'] !== 'active' || $ack['result']['effective_at'] !== $binding['applied_at']) {
                throw new ModelViolation('hof_participation_binding_conflict');
            }
            $reference = RankingBarrier::reference($descriptor['content']);
            foreach (['barrier_key','version','content_sha256'] as $field) {
                if ($ack['result'][$field] !== $reference[$field]) { throw new ModelViolation('hof_participation_binding_conflict'); }
            }
        });
        return ['session' => $session,'context' => ['session_id' => $id,'admission_revision' => $version['barrier_version'],
            'barrier_version' => $session['barrier_version'],'admitted_at' => $role['admitted_at'],'territory_admission_revision' => $territoryRevision]
            + array_intersect_key($read['content'],array_flip(['rules_revision','rules_sha256','starts_at','ends_at','scope','territory_policy_revision','country','territory_ref']))];
    }
}
