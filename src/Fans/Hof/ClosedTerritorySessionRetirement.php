<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\Fans\PfContract\ClosedBarrierClient;
use Faluss\Platform\Fans\PfContract\ClosedBarrierInbox;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\BarrierTransport;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingBarrier;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingValues;

/** Exact territorial organizer checkpoint, including a coorganizer; no general owner bypass. */
final class ClosedTerritorySessionRetirement
{
    private readonly SessionStore $store;
    private readonly string $bindings;

    public function __construct(private readonly \wpdb $db, private readonly ClosedBarrierInbox $inbox,
        private readonly ClosedBarrierClient $client, private readonly string $origin)
    {
        ClosedEnvironment::assertIsolated($db,'fans'); ModelValues::uuid($origin);
        if (!$inbox->usesConnection($db)) { throw new ModelViolation('hof_primary_connection_required'); }
        $this->store = new SessionStore($db); $this->bindings = ClosedSessionLifecycleSchema::tables($db)['bindings'];
    }

    /** Only explicit recovery, never GET/cron. The invalidated creator comes from its private journal.
     * @return array<string,string> */
    public function advance(string $change, string $id, string $version, int $steps = 4): array
    {
        ClosedEnvironment::assertIsolated($this->db,'fans');
        if ($steps < 1 || $steps > 16) { throw new ModelViolation('pf_local_barrier_budget'); }
        if (!ClosedSessionLifecycleSchema::ready($this->db)) { throw new ModelViolation('hof_session_lifecycle_schema_unavailable'); }
        $plan = $this->store->transaction(function () use ($change,$id,$version): array {
            $target = ClosedTerritoryChanges::target($this->db,$change,'session',$id,$version);
            $row = $this->store->row($id); $historical = array_replace($row,['barrier_version' => $version]);
            if ($row['scope'] === 'international' || (new TerritoryService($this->db))->boundPolicy($id) !== $target['change']['policy_id']) {
                throw new ModelViolation('hof_territory_checkpoint_conflict');
            }
            $descriptor = SessionBarrierDescriptor::compose($historical,$this->origin,$target['change']['policy_id']);
            if (hash('sha256',CanonicalJson::encode($descriptor)) !== $target['item']['descriptor_sha256']) { throw new ModelViolation('hof_territory_checkpoint_conflict'); }
            $opening = $this->binding($id,$version,'register') ?? throw new ModelViolation('hof_session_binding_conflict');
            $this->validate($opening,'register',$descriptor);
            $closing = $this->binding($id,$version,'close');
            if ($closing === null) {
                if ($row['barrier_version'] !== $version || $row['state'] !== 'closing') { throw new ModelViolation('hof_territory_checkpoint_conflict'); }
                $reason = self::reason($row['closure_action']);
                $fields = ['operation' => 'close','lookup_operation' => '','action_id' => wp_generate_uuid4(),
                    'origin_id' => $this->origin,'policy_version' => RankingPolicy::VERSION,
                    'object' => array_intersect_key(RankingBarrier::reference($descriptor['content']),array_flip(['barrier_key','version','content_sha256'])) + ['reason' => $reason]];
                $contract = $reason === 'session_completed' ? BarrierTransport::COMPLETION_CONTRACT : BarrierTransport::CONTRACT;
                BarrierTransport::fields($fields,$contract); $bytes = CanonicalJson::encode($fields);
                if ($this->db->insert($this->bindings,['session_id' => $id,'barrier_version' => $version,'operation' => 'close',
                    'action_id' => $fields['action_id'],'contract' => $contract,'fields_json' => $bytes,'fields_sha256' => hash('sha256',$bytes),'applied_at' => '']) !== 1) {
                    throw new ModelViolation('hof_storage_unavailable');
                }
                $closing = $this->binding($id,$version,'close') ?? throw new ModelViolation('hof_session_binding_conflict');
            }
            $this->validate($closing,'close',$descriptor);
            ClosedTerritoryChanges::attach($this->db,$change,'session',$id,$version,$closing['action_id']);
            return ['opening' => $opening,'closing' => $closing,'descriptor' => $descriptor];
        });
        foreach (['opening','closing'] as $operation) {
            $binding = $plan[$operation]; $this->inbox->prepare(CanonicalJson::object($binding['fields_json']),$binding['contract']);
        }
        $result = $this->client->advance($plan['closing']['action_id'],$steps);
        if ($result['state'] !== 'acknowledged') { return $result; }
        return $this->store->withGovernanceMutex(fn (): array => $this->inbox->withAcknowledgement($plan['closing']['action_id'],
            function (array $ack) use ($change,$id,$version,$plan,$result): array {
                $target = ClosedTerritoryChanges::target($this->db,$change,'session',$id,$version);
                $binding = $this->binding($id,$version,'close') ?? throw new ModelViolation('hof_session_binding_conflict');
                $this->validate($binding,'close',$plan['descriptor']); $bytes = CanonicalJson::encode($ack['fields']);
                $instant = RankingValues::utc($ack['result']['effective_at']); $ref = RankingBarrier::reference($plan['descriptor']['content']);
                foreach (['barrier_key','version','content_sha256'] as $field) {
                    if ($ack['result'][$field] !== $ref[$field]) { throw new ModelViolation('hof_territory_checkpoint_conflict'); }
                }
                if ($binding['action_id'] !== $target['item']['action_id'] || $binding['fields_json'] !== $bytes || $binding['contract'] !== $ack['contract']
                    || $ack['local_state'] !== 'closed' || $ack['result']['state'] !== 'closed'
                    || $ack['result']['reason'] !== $ack['fields']['object']['reason']) { throw new ModelViolation('hof_territory_checkpoint_conflict'); }
                $row = $this->store->row($id);
                if ($binding['applied_at'] !== '') {
                    if ($binding['applied_at'] !== $instant) { throw new ModelViolation('hof_territory_checkpoint_conflict'); }
                } else {
                    if ($row['barrier_version'] !== $version || $row['state'] !== 'closing' || self::reason($row['closure_action']) !== $ack['result']['reason']
                        || ($row['closure_action'] === 'closed' && $instant < $row['ends_at'])) { throw new ModelViolation('hof_territory_checkpoint_conflict'); }
                    $row = $this->store->change($row,['state' => $row['closure_action']],'hub_close_ack','',$ack['result']['reason']);
                    if ($this->store->storage->query($this->db->prepare('UPDATE %i SET applied_at=%s WHERE action_id=%s AND applied_at=%s',
                        $this->bindings,$instant,$binding['action_id'],'')) !== 1) { throw new ModelViolation('hof_storage_unavailable'); }
                }
                ClosedTerritoryChanges::settle($this->db,$change,'session',$id,$version,$binding['action_id'],$instant);
                return $result + ['session_state' => $row['state'],'territory_state' => 'applied'];
            }));
    }

    /** @return array<string,string>|null */
    private function binding(string $id, string $version, string $operation): ?array
    { return $this->store->storage->row($this->db->prepare('SELECT * FROM %i WHERE session_id=%s AND barrier_version=%s AND operation=%s FOR UPDATE',$this->bindings,$id,$version,$operation)); }

    /** @param array<string,string> $binding
     * @param array<string,mixed> $descriptor */
    private function validate(array $binding, string $operation, array $descriptor): void
    {
        $fields = CanonicalJson::object($binding['fields_json']); BarrierTransport::fields($fields,$binding['contract']);
        $expected = $descriptor; $contract = BarrierTransport::CONTRACT;
        if ($operation === 'close') {
            $reason = $fields['object']['reason'] ?? '';
            if (!in_array($reason,['session_suspended','session_cancelled','session_completed'],true)) { throw new ModelViolation('hof_territory_checkpoint_conflict'); }
            $expected = array_intersect_key(RankingBarrier::reference($descriptor['content']),array_flip(['barrier_key','version','content_sha256'])) + ['reason' => $reason];
            if ($reason === 'session_completed') { $contract = BarrierTransport::COMPLETION_CONTRACT; }
        }
        if ($binding['contract'] !== $contract || $binding['fields_sha256'] !== hash('sha256',$binding['fields_json'])
            || $fields['operation'] !== $operation || $fields['action_id'] !== $binding['action_id'] || $fields['origin_id'] !== $this->origin
            || $fields['policy_version'] !== RankingPolicy::VERSION
            || CanonicalJson::encode($fields['object']) !== CanonicalJson::encode($expected)) { throw new ModelViolation('hof_session_binding_conflict'); }
    }

    private static function reason(string $state): string
    { return match ($state) { 'suspended' => 'session_suspended','cancelled' => 'session_cancelled','closed' => 'session_completed',default => throw new ModelViolation('hof_territory_checkpoint_conflict') }; }
}
