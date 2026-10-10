<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\PfContract;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\BarrierTransport;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\DelegatedContext;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingBarrier;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SignedEnvelope;
use Throwable;

/** Trusted Fans governance composition, not a browser action or an economic ledger. */
final class ClosedBarrierInbox
{
    /** @var array<string,string> */
    private readonly array $tables;

    public function __construct(private readonly \wpdb $db, private readonly PeerPolicy $peer,
        private readonly string $origin, private readonly string $policy)
    {
        ClosedEnvironment::assertIsolated($db,'fans'); ModelValues::uuid($origin); ModelValues::version($policy);
        if ($peer->node !== 'fixture.hub' || $peer->audience !== 'fixture.fans') { throw new ModelViolation('pf_fixture_peer_required'); }
        foreach (['pf.ranking.context.register','pf.ranking.context.close','pf.lookup'] as $permission) { $peer->allow($permission); }
        $this->tables = ClosedBarrierInboxSchema::tables($db);
    }

    /** Persist server-composed immutable action/key before HTTP. Close blocks local choices immediately.
     * @param array<string,mixed> $fields
     * @return array<string,mixed> */
    public function prepare(array $fields): array
    {
        $this->scope($fields);
        if ($fields['operation'] === 'lookup') { throw new ModelViolation('pf_local_barrier_action'); }
        $ref = $fields['operation'] === 'register' ? RankingBarrier::reference($fields['object']['content']) : RankingBarrier::closeReference($fields['object']);
        return $this->write(function () use ($fields,$ref): array {
            $bytes = CanonicalJson::encode($fields);
            $known = $this->row('actions','barrier_key=%s AND version=%s AND operation=%s',[$ref['barrier_key'],$ref['version'],$fields['operation']]);
            if ($known !== null) {
                if ($known['fields_json'] !== $bytes || $known['fields_sha256'] !== hash('sha256',$bytes)) { throw new ModelViolation('pf_local_barrier_conflict'); }
                return $this->progress($known);
            }
            $version = $this->row('versions','barrier_key=%s AND version=%s',[$ref['barrier_key'],$ref['version']]);
            if ($fields['operation'] === 'register') {
                if ($version !== null) { throw new ModelViolation('pf_local_barrier_conflict'); }
                $previous = $this->rows($this->db->prepare('SELECT * FROM %i WHERE barrier_key=%s ORDER BY version DESC LIMIT 1 FOR UPDATE',$this->tables['versions'],$ref['barrier_key']));
                if ($previous !== [] && ($previous[0]['state'] !== 'closed' || (int) $ref['version'] !== (int) $previous[0]['version']+1)) {
                    throw new ModelViolation('pf_local_barrier_version');
                }
                $descriptor = CanonicalJson::encode($fields['object']);
                $this->insert('versions',['barrier_key' => $ref['barrier_key'],'version' => $ref['version'],'origin_id' => $this->origin,
                    'policy_version' => $this->policy,'descriptor_json' => $descriptor,'descriptor_sha256' => hash('sha256',$descriptor),'state' => 'opening']);
            } else {
                if ($version === null || !in_array($version['state'],['opening','active'],true)) { throw new ModelViolation('pf_local_barrier_conflict'); }
                $descriptor = $this->descriptor($version); $expected = RankingBarrier::reference($descriptor['content']);
                foreach (['barrier_key','version','content_sha256'] as $name) {
                    if ($ref[$name] !== $expected[$name]) { throw new ModelViolation('pf_local_barrier_conflict'); }
                }
                $this->update('versions',['state' => 'closing'],['barrier_key' => $ref['barrier_key'],'version' => $ref['version']]);
            }
            $this->insert('actions',['action_id' => $fields['action_id'],'barrier_key' => $ref['barrier_key'],'version' => $ref['version'],
                'operation' => $fields['operation'],'operation_key' => bin2hex(random_bytes(32)),'fields_json' => $bytes,
                'fields_sha256' => hash('sha256',$bytes),'phase' => 'lookup','proof_json' => '','proof_sha256' => '']);
            return $this->progress($this->required($fields['action_id']));
        });
    }

    /** @return array<string,mixed> */
    public function recover(string $action): array
    { ModelValues::uuid($action); return $this->write(fn (): array => $this->progress($this->required($action))); }

    /** Registration uncertainty must be resolved before a queued close can be sent.
     * @param array<string,mixed> $progress
     * @return array<string,mixed> */
    public function fields(array $progress): array
    {
        if (!in_array($progress['phase'],['lookup','register','close'],true)) { throw new ModelViolation('pf_local_barrier_terminal'); }
        $fields = $progress['fields'];
        if ($progress['phase'] === 'lookup') { $fields['lookup_operation'] = $fields['operation']; $fields['operation'] = 'lookup'; }
        $this->scope($fields); return $fields;
    }

    /** Exact request is durable before delivery. Every uncertain mutation resumes at lookup.
     * @param array<string,mixed> $fields
     * @param array{wire:string,nonce:string} $sealed */
    public function request(array $fields, array $sealed): void
    {
        $this->scope($fields); ModelValues::exactKeys($sealed,['wire','nonce']); DelegatedContext::nonce($sealed['nonce']);
        $this->write(function () use ($fields,$sealed): void {
            $action = $this->required($fields['action_id']); $progress = $this->progress($action);
            if ($progress['blocking_action_id'] !== '' || CanonicalJson::encode($this->fields($progress)) !== CanonicalJson::encode($fields)) {
                throw new ModelViolation('pf_local_barrier_checkpoint_moved');
            }
            $outer = CanonicalJson::object($sealed['wire'],BarrierTransport::MAX_WIRE);
            $payload = CanonicalJson::object(SignedEnvelope::decode($outer['payload_base64url'] ?? '',49152),49152);
            if (CanonicalJson::encode(array_intersect_key($payload,$fields)) !== CanonicalJson::encode($fields)
                || ($payload['operation_key'] ?? null) !== $action['operation_key'] || ($payload['nonce'] ?? null) !== $sealed['nonce']
                || ($payload['contract'] ?? null) !== BarrierTransport::CONTRACT || ($payload['kind'] ?? null) !== SignedEnvelope::BARRIER_REQUEST
                || ($payload['issuer'] ?? null) !== 'fixture.fans' || ($payload['audience'] ?? null) !== 'fixture.hub') {
                throw new ModelViolation('pf_local_barrier_request');
            }
            DelegatedContext::fresh($payload['issued_at'] ?? null,$payload['expires_at'] ?? null,time());
            $nonce = hash('sha256',$sealed['nonce']); $digest = hash('sha256',$sealed['wire']);
            $known = $this->row('requests','action_id=%s AND nonce_sha256=%s',[$fields['action_id'],$nonce]);
            if ($known !== null) {
                if ($known['wire_json'] !== $sealed['wire'] || $known['request_sha256'] !== $digest) { throw new ModelViolation('pf_local_barrier_conflict'); }
            } else {
                $this->insert('requests',['action_id' => $fields['action_id'],'nonce_sha256' => $nonce,'request_sha256' => $digest,
                    'fields_json' => CanonicalJson::encode($fields),'wire_json' => $sealed['wire']]);
            }
            $this->update('actions',['phase' => 'lookup'],['action_id' => $fields['action_id']]);
        });
    }

    /** @param array<string,mixed> $proof
     * @return array<string,mixed> */
    public function accept(array $proof): array
    {
        return $this->write(function () use ($proof): array {
            $answer = $this->verified($proof,false); $action = $this->required($proof['fields']['action_id']);
            if (in_array($action['phase'],['acknowledged','refused'],true)) { return $this->progress($action); }
            if ($answer['outcome'] === 'unknown') { throw new ModelViolation('pf_transport_unknown'); }
            if ($answer['outcome'] === 'refused') {
                $this->update('actions',['phase' => 'refused'],['action_id' => $action['action_id']]);
                return $this->progress($this->required($action['action_id']));
            }
            if ($answer['result'] === ['state' => 'not_found']) {
                $this->update('actions',['phase' => $action['operation']],['action_id' => $action['action_id']]);
                return $this->progress($this->required($action['action_id']));
            }
            $version = $this->row('versions','barrier_key=%s AND version=%s',[$action['barrier_key'],$action['version']])
                ?? throw new ModelViolation('pf_local_barrier_conflict');
            $this->descriptor($version); $state = $answer['result']['state'];
            // A late register acknowledgement can never override an already queued local close.
            if ($action['operation'] === 'close' || $version['state'] === 'opening') {
                $this->update('versions',['state' => $state],['barrier_key' => $action['barrier_key'],'version' => $action['version']]);
            }
            $bytes = CanonicalJson::encode($proof);
            $this->update('actions',['phase' => 'acknowledged','proof_json' => $bytes,'proof_sha256' => hash('sha256',$bytes)],['action_id' => $action['action_id']]);
            return $this->progress($this->required($action['action_id']));
        });
    }

    /** @param array<string,string> $action
     * @return array<string,mixed> */
    private function progress(array $action): array
    {
        $fields = CanonicalJson::object($action['fields_json']); $this->scope($fields); ModelValues::keyHash($action['operation_key']);
        $ref = $fields['operation'] === 'register' ? RankingBarrier::reference($fields['object']['content']) : RankingBarrier::closeReference($fields['object']);
        if ($action['fields_sha256'] !== hash('sha256',$action['fields_json']) || $action['action_id'] !== $fields['action_id']
            || $action['operation'] !== $fields['operation'] || $action['barrier_key'] !== $ref['barrier_key'] || $action['version'] !== $ref['version']
            || !in_array($action['phase'],['lookup',$action['operation'],'acknowledged','refused'],true)) { throw new ModelViolation('pf_local_barrier_conflict'); }
        $version = $this->row('versions','barrier_key=%s AND version=%s',[$action['barrier_key'],$action['version']])
            ?? throw new ModelViolation('pf_local_barrier_conflict');
        $this->descriptor($version); $blocking = '';
        if ($action['phase'] === 'acknowledged') {
            $proof = CanonicalJson::object($action['proof_json'],131072);
            $answer = $this->verified($proof,true); $bound = $proof['fields'];
            if ($bound['operation'] === 'lookup') { $bound['operation'] = $bound['lookup_operation']; $bound['lookup_operation'] = ''; }
            if ($action['proof_sha256'] !== hash('sha256',$action['proof_json']) || $answer['outcome'] !== 'ok'
                || $answer['result'] === ['state' => 'not_found'] || CanonicalJson::encode($bound) !== CanonicalJson::encode($fields)) {
                throw new ModelViolation('pf_local_barrier_conflict');
            }
            if (($version['state'] === 'active' && $answer['result']['state'] !== 'active')
                || ($action['operation'] === 'close' && !in_array($version['state'],['closed','superseded'],true))) {
                throw new ModelViolation('pf_local_barrier_conflict');
            }
        } elseif ($version['state'] === 'active') {
            throw new ModelViolation('pf_local_barrier_conflict');
        }
        $close = $this->row('actions','barrier_key=%s AND version=%s AND operation=%s',[$action['barrier_key'],$action['version'],'close']);
        if ($close !== null && !in_array($version['state'],['closing','closed','superseded'],true)) { throw new ModelViolation('pf_local_barrier_conflict'); }
        if ($action['operation'] === 'close' && !in_array($action['phase'],['acknowledged','refused'],true)) {
            $opening = $this->row('actions','barrier_key=%s AND version=%s AND operation=%s',[$action['barrier_key'],$action['version'],'register'])
                ?? throw new ModelViolation('pf_local_barrier_conflict');
            if ($opening['phase'] !== 'acknowledged') { $blocking = $opening['action_id']; }
        }
        return ['action_id' => $action['action_id'],'operation_key' => $action['operation_key'],'fields' => $fields,
            'phase' => $action['phase'],'state' => $version['state'],'blocking_action_id' => $blocking];
    }

    /** @param array<string,mixed> $proof
     * @return array{outcome:string,result:array<string,mixed>} */
    private function verified(array $proof, bool $historical): array
    {
        ModelValues::exactKeys($proof,['wire','fields','nonce','request_sha256']);
        if (!is_string($proof['wire']) || !is_array($proof['fields'])) { throw new ModelViolation('pf_local_barrier_request'); }
        $fields = $proof['fields']; $this->scope($fields); DelegatedContext::nonce($proof['nonce']); ModelValues::keyHash($proof['request_sha256']);
        $request = $this->row('requests','action_id=%s AND nonce_sha256=%s',[$fields['action_id'],hash('sha256',$proof['nonce'])]);
        if ($request === null || $request['request_sha256'] !== $proof['request_sha256'] || hash('sha256',$request['wire_json']) !== $proof['request_sha256']
            || $request['fields_json'] !== CanonicalJson::encode($fields)) { throw new ModelViolation('pf_local_barrier_request'); }
        $outer = CanonicalJson::object($proof['wire'],BarrierTransport::MAX_WIRE); $at = time();
        if ($historical) {
            $raw = CanonicalJson::object(SignedEnvelope::decode($outer['payload_base64url'] ?? '',49152),49152);
            $at = (int) strtotime(ModelValues::utc($raw['issued_at'] ?? null));
            if ($at > time()) { throw new ModelViolation('pf_local_barrier_request'); }
        }
        $payload = SignedEnvelope::open(SignedEnvelope::BARRIER_RESPONSE,$outer,$this->peer,$at);
        return BarrierTransport::response($payload,$this->peer,$fields,$proof['nonce'],$proof['request_sha256'],$at);
    }

    /** @param array<string,mixed> $fields */
    private function scope(array $fields): void
    {
        BarrierTransport::fields($fields);
        if ($fields['origin_id'] !== $this->origin || $fields['policy_version'] !== $this->policy) { throw new ModelViolation('pf_barrier_context_mismatch'); }
    }

    /** @param array<string,string> $row
     * @return array<string,mixed> */
    private function descriptor(array $row): array
    {
        $value = RankingBarrier::descriptor(CanonicalJson::object($row['descriptor_json'])); $ref = RankingBarrier::reference($value['content']);
        if ($row['origin_id'] !== $this->origin || $row['policy_version'] !== $this->policy || $value['content']['origin_id'] !== $this->origin
            || $value['content']['policy_version'] !== $this->policy || $row['descriptor_sha256'] !== hash('sha256',$row['descriptor_json'])
            || $row['barrier_key'] !== $ref['barrier_key'] || $row['version'] !== $ref['version']
            || !in_array($row['state'],['opening','active','closing','closed','superseded'],true)) { throw new ModelViolation('pf_local_barrier_conflict'); }
        return $value;
    }

    /** @return array<string,string> */
    private function required(string $id): array
    { return $this->row('actions','action_id=%s',[$id]) ?? throw new ModelViolation('pf_local_barrier_absent'); }

    /** @template T
     * @param callable():T $operation
     * @return T */
    private function write(callable $operation): mixed
    {
        ClosedEnvironment::assertIsolated($this->db,'fans');
        $held = $started = false; $suppressed = $this->db->suppress_errors(true);
        $lock = 'fans_pf_b3b_' . substr(hash('sha256',$this->db->prefix . ':' . $this->origin . ':' . $this->policy),0,40);
        try {
            if ((string) $this->scalar('SELECT @@in_transaction') !== '0') { throw new ModelViolation('nested_transaction_refused'); }
            if ((string) $this->scalar($this->db->prepare('SELECT GET_LOCK(%s,10)',$lock)) !== '1') { throw new ModelViolation('pf_local_barrier_busy'); }
            $held = true;
            if (!ClosedBarrierInboxSchema::ready($this->db)) { throw new ModelViolation('pf_local_barrier_schema'); }
            $this->query('START TRANSACTION'); $started = true; $result = $operation();
            if ($this->db->query('COMMIT') === false || $this->db->last_error !== '') { throw new ModelViolation('pf_local_barrier_commit_unknown'); }
            $started = false; return $result;
        } catch (Throwable $error) { if ($started) { $this->db->query('ROLLBACK'); } throw $error; }
        finally {
            $released = !$held || (string) $this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)',$lock)) === '1';
            $this->db->suppress_errors($suppressed); if (!$released) { throw new ModelViolation('pf_local_barrier_commit_unknown'); }
        }
    }

    /** @param literal-string $where
     * @param list<mixed> $values
     * @return array<string,string>|null */
    private function row(string $kind, string $where, array $values): ?array
    { $rows = $this->rows($this->db->prepare("SELECT * FROM %i WHERE $where FOR UPDATE",$this->tables[$kind],...$values)); return $rows[0] ?? null; }
    /** @return list<array<string,string>> */
    private function rows(string $sql): array
    { $rows = $this->db->get_results($sql,'ARRAY_A'); if (!is_array($rows) || $this->db->last_error !== '') { throw new ModelViolation('pf_local_barrier_storage'); } return $rows; }
    private function scalar(string $sql): mixed
    { $value = $this->db->get_var($sql); if ($this->db->last_error !== '') { throw new ModelViolation('pf_local_barrier_storage'); } return $value; }
    private function query(string $sql): void
    { if ($this->db->query($sql) === false || $this->db->last_error !== '') { throw new ModelViolation('pf_local_barrier_storage'); } }
    /** @param array<string,mixed> $values */
    private function insert(string $kind, array $values): void
    { if ($this->db->insert($this->tables[$kind],$values) !== 1 || $this->db->last_error !== '') { throw new ModelViolation('pf_local_barrier_storage'); } }
    /** @param array<string,string> $values
     * @param array<string,string> $where */
    private function update(string $kind, array $values, array $where): void
    { if ($this->db->update($this->tables[$kind],$values,$where) === false || $this->db->last_error !== '') { throw new ModelViolation('pf_local_barrier_storage'); } }
}
