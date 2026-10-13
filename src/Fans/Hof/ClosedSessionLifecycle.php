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

/** Closed B2/Hub lifecycle bridge. No routes, cron, choices, economic calls or real-site entry. */
final class ClosedSessionLifecycle
{
    private readonly SessionStore $sessions;
    private readonly string $bindings;

    public function __construct(private readonly \wpdb $db, private readonly ClosedBarrierInbox $inbox,
        private readonly ClosedBarrierClient $client, private readonly string $origin)
    {
        ClosedEnvironment::assertIsolated($db,'fans'); ModelValues::uuid($origin);
        $this->sessions = new SessionStore($db); $this->bindings = ClosedSessionLifecycleSchema::tables($db)['bindings'];
    }

    /** Same immutable local binding survives the gap between B2 COMMIT and inbox preparation.
     * @return array<string,string> */
    public function prepare(string $sessionId, int $revision): array
    {
        $this->available();
        $prepared = $this->sessions->transaction(function () use ($sessionId,$revision): array {
            $session = $this->sessions->row($sessionId); $this->authorize($session); SessionStore::revision($session,$revision);
            $terminal = in_array($session['state'],['closed','cancelled','suspended'],true);
            if (!in_array($session['state'],['opening','open','closing'],true) && !$terminal) { throw new ModelViolation('hof_invalid_session_transition'); }
            if ($terminal && $this->binding($sessionId,$session['barrier_version'],'close') === null) { throw new ModelViolation('hof_invalid_session_transition'); }
            $origin = $this->sessions->storage->row($this->db->prepare('SELECT * FROM %i WHERE id=1 FOR UPDATE',RankingSchema::tables($this->db)['origin']));
            if ($origin === null || $origin['origin_id'] !== $this->origin || $origin['policy_version'] !== RankingPolicy::VERSION) { throw new ModelViolation('hof_origin_conflict'); }
            if ($session['state'] === 'opening') { $this->approval($session); }
            $descriptor = $this->descriptor($session); $operation = $session['state'] === 'closing' || $terminal ? 'close' : 'register';
            $opening = $this->bind($session,$descriptor,'register');
            return ['opening' => $opening,'target' => $operation === 'register' ? $opening : $this->bind($session,$descriptor,'close')];
        });
        // A close may arrive before the initial inbox checkpoint; retain both actions across that crash gap.
        $opening = $prepared['opening']; $binding = $prepared['target'];
        $this->inbox->prepare(CanonicalJson::object($opening['fields_json']),$opening['contract']);
        $progress = $this->inbox->prepare(CanonicalJson::object($binding['fields_json']),$binding['contract']);
        return ['action_id' => $binding['action_id'],'phase' => $progress['phase'],'local_state' => $progress['state']];
    }

    /** HTTP starts only after both durable checkpoints have committed; uncertain completion stays closing.
     * @return array<string,string> */
    public function advance(string $sessionId, int $revision, int $steps = 4): array
    {
        if ($steps < 1 || $steps > 16) { throw new ModelViolation('pf_local_barrier_budget'); }
        $prepared = $this->prepare($sessionId,$revision); $result = $this->client->advance($prepared['action_id'],$steps);
        if ($result['state'] !== 'acknowledged') { return $result; }
        return $result + ['session_state' => $this->apply($sessionId,$prepared['action_id'])['state']];
    }

    /** Apply under the existing B2 domain mutex, then inbox origin mutex/TX, never in reverse order.
     * @return array<string,string> */
    public function apply(string $sessionId, string $action): array
    {
        $this->available(); ModelValues::uuid($sessionId); ModelValues::uuid($action);
        $lock = 'fans_hof_' . substr(hash('sha256',$this->db->prefix . '|sessions'),0,48);
        if ((string) $this->db->get_var('SELECT @@in_transaction') !== '0' || $this->failed()) { throw new ModelViolation('hof_nested_transaction'); }
        if ((string) $this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s,10)',$lock)) !== '1' || $this->failed()) { throw new ModelViolation('hof_busy'); }
        try {
            return $this->inbox->withAcknowledgement($action,function (array $ack) use ($sessionId,$action): array {
                $session = $this->sessions->row($sessionId); $this->authorize($session);
                $operation = $ack['fields']['operation']; $binding = $this->binding($sessionId,$session['barrier_version'],$operation);
                $bytes = CanonicalJson::encode($ack['fields']);
                if ($binding === null || $binding['action_id'] !== $action || $binding['contract'] !== $ack['contract']
                    || $binding['fields_json'] !== $bytes || $binding['fields_sha256'] !== hash('sha256',$bytes)) {
                    throw new ModelViolation('hof_lifecycle_binding_conflict');
                }
                $descriptor = $this->descriptor($session); $ref = RankingBarrier::reference($descriptor['content']);
                foreach (['barrier_key','version','content_sha256'] as $field) {
                    if ($ack['result'][$field] !== $ref[$field]) { throw new ModelViolation('hof_lifecycle_binding_conflict'); }
                }
                $instant = RankingValues::utc($ack['result']['effective_at']);
                if ($binding['applied_at'] !== '') {
                    if ($binding['applied_at'] !== $instant) { throw new ModelViolation('hof_lifecycle_binding_conflict'); }
                    return $session;
                }
                if ($operation === 'register') {
                    if ($session['state'] === 'opening' && $ack['local_state'] === 'active' && $ack['result']['state'] === 'active') {
                        $this->approval($session);
                        $session = $this->sessions->change($session,['state' => 'open'],'hub_open_ack','','primary_acknowledgement');
                    } elseif (!in_array($session['state'],['closing','closed','cancelled','suspended'],true)) { throw new ModelViolation('hof_invalid_session_transition'); }
                } else {
                    $expected = match ($session['closure_action']) {'closed' => 'session_completed','cancelled' => 'session_cancelled','suspended' => 'session_suspended',
                        default => throw new ModelViolation('hof_invalid_session_transition')};
                    if ($session['state'] !== 'closing' || $ack['local_state'] !== 'closed' || $ack['result']['state'] !== 'closed'
                        || $ack['result']['reason'] !== $expected || ($expected === 'session_completed' && $instant < $session['ends_at'])) {
                        throw new ModelViolation('hof_invalid_session_transition');
                    }
                    $session = $this->sessions->change($session,['state' => $session['closure_action']],'hub_close_ack','',$expected);
                }
                if ($this->db->query($this->db->prepare('UPDATE %i SET applied_at=%s WHERE action_id=%s AND applied_at=%s',
                    $this->bindings,$instant,$action,'')) !== 1 || $this->failed()) { throw new ModelViolation('hof_storage_unavailable'); }
                return $session;
            });
        } finally {
            if ((string) $this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)',$lock)) !== '1') { throw new ModelViolation('hof_commit_unknown'); }
        }
    }

    private function available(): void
    {
        ClosedEnvironment::assertIsolated($this->db,'fans');
        if (!ClosedSessionLifecycleSchema::ready($this->db)) { throw new ModelViolation('hof_lifecycle_schema_unavailable'); }
    }
    /** @param array<string,string> $session */
    private function authorize(array $session): void
    {
        if (current_user_can('manage_options')) { return; }
        $owner = SessionStore::ownerCreator()['creator_id'];
        if ($session['owner_creator_id'] !== $owner) { throw new ModelViolation('hof_session_forbidden'); }
    }
    /** @param array<string,string> $session */
    private function approval(array $session): void
    {
        if ($session['ends_at'] <= $this->sessions->now()) { throw new ModelViolation('hof_session_expired'); }
        (new SessionModeration($this->db))->requireApproval($session);
        $policy = $session['scope'] === 'international' ? null : (new TerritoryService($this->db))->boundPolicy($session['session_id']);
        foreach ($this->sessions->roles($session['session_id']) as $role) {
            if ($role['role'] !== 'organizer') { continue; }
            if ($role['state'] === 'invited') { throw new ModelViolation('hof_coorganizer_acceptance_required'); }
            if ($role['state'] !== 'accepted') { continue; }
            SessionStore::activeOwner($role['creator_id']); $this->sessions->quota($role['creator_id'],$session['session_id']);
            if ($session['scope'] !== 'international') {
                if ($policy === null) { throw new ModelViolation('hof_reviewed_territory_required'); }
                (new TerritoryService($this->db))->eligible($role['creator_id'],$policy,$session);
            }
        }
    }
    /** @param array<string,string> $session
     * @return array<string,mixed> */
    private function descriptor(array $session): array
    {
        $policy = $session['scope'] === 'international' ? null : (new TerritoryService($this->db))->boundPolicy($session['session_id']);
        return SessionBarrierDescriptor::compose($session,$this->origin,$policy);
    }
    /** @return array<string,string>|null */
    private function binding(string $session, string $version, string $operation): ?array
    {
        return $this->sessions->storage->row($this->db->prepare('SELECT * FROM %i WHERE session_id=%s AND barrier_version=%s AND operation=%s FOR UPDATE',
            $this->bindings,$session,$version,$operation));
    }

    /** @phpstan-impure Reads the most recent SQL operation's error. */
    private function failed(): bool { global $wpdb; return $wpdb->last_error !== ''; }

    /** @param array<string,string> $session
     * @param array<string,mixed> $descriptor
     * @return array<string,string> */
    private function bind(array $session, array $descriptor, string $operation): array
    {
        $contract = $operation === 'close' && $session['closure_action'] === 'closed' ? BarrierTransport::COMPLETION_CONTRACT : BarrierTransport::CONTRACT;
        $old = $this->binding($session['session_id'],$session['barrier_version'],$operation);
        $action = $old['action_id'] ?? wp_generate_uuid4(); $object = $descriptor;
        if ($operation === 'close') {
            $ref = RankingBarrier::reference($descriptor['content']);
            $object = array_intersect_key($ref,array_flip(['barrier_key','version','content_sha256'])) + ['reason' => match ($session['closure_action']) {
                'closed' => 'session_completed','cancelled' => 'session_cancelled','suspended' => 'session_suspended',
                default => throw new ModelViolation('hof_invalid_session_transition'),
            }];
        }
        $fields = ['operation' => $operation,'lookup_operation' => '', 'action_id' => $action,'origin_id' => $this->origin,
            'policy_version' => RankingPolicy::VERSION,'object' => $object];
        BarrierTransport::fields($fields,$contract); $bytes = CanonicalJson::encode($fields);
        if ($old !== null) {
            if ($old['contract'] !== $contract || $old['fields_json'] !== $bytes || $old['fields_sha256'] !== hash('sha256',$bytes)) { throw new ModelViolation('hof_lifecycle_binding_conflict'); }
            return $old;
        }
        if ($this->db->insert($this->bindings,['session_id' => $session['session_id'],'barrier_version' => $session['barrier_version'],'operation' => $operation,
            'action_id' => $action,'contract' => $contract,'fields_json' => $bytes,'fields_sha256' => hash('sha256',$bytes),'applied_at' => '']) !== 1
            || $this->failed()) { throw new ModelViolation('hof_storage_unavailable'); }
        return $this->binding($session['session_id'],$session['barrier_version'],$operation) ?? throw new ModelViolation('hof_storage_unavailable');
    }
}
