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
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SignedEnvelope;

/** A fresh signed lookup is a read, never a second registration or a new mutation key. */
final class ClosedBarrierPrimaryRead
{
    /** @var array<string,string> */
    private readonly array $tables;

    public function __construct(private readonly \wpdb $db, private readonly ClosedBarrierInbox $inbox,
        private readonly PeerPolicy $peer)
    {
        ClosedEnvironment::assertIsolated($db,'fans'); $peer->allow('pf.lookup');
        if (!$inbox->usesConnection($db) || $peer->node !== 'fixture.hub' || $peer->audience !== 'fixture.fans') {
            throw new ModelViolation('pf_local_barrier_primary_connection');
        }
        $this->tables = ClosedBarrierReadSchema::tables($db);
    }

    public function usesInbox(ClosedBarrierInbox $inbox): bool { return $this->inbox === $inbox; }

    /** Explicit preparation closes delivery atomically before any HTTP; a pending revision is retained.
     * @return array<string,mixed> */
    public function prepare(string $action): array
    {
        return $this->write($action,function (array $ack) use ($action): array {
            $old = $this->row($action);
            if ($old !== null && in_array($old['phase'],['prepared','checking'],true)) { return $this->progress($old,$ack); }
            $revision = (int) ($old['revision'] ?? '0') + 1;
            if ($revision < 1 || $revision >= 2147483647) { throw new ModelViolation('pf_local_barrier_primary_revision'); }
            $values = ['action_id' => $action,'revision' => (string) $revision,'phase' => 'prepared',
                'request_json' => '','request_sha256' => '','proof_json' => '','proof_sha256' => ''];
            if ($old === null) { $this->insert('reads',$values); }
            else { $this->update($action,$values); }
            return $this->progress($values,$ack);
        });
    }

    /** @return array<string,mixed> */
    public function recover(string $action): array
    { return $this->write($action,fn (array $ack): array => $this->progress($this->required($action),$ack)); }

    /** @param array{wire:string,nonce:string} $sealed */
    public function request(string $action, array $sealed): void
    {
        ModelValues::exactKeys($sealed,['wire','nonce']); DelegatedContext::nonce($sealed['nonce']);
        $original = $this->inbox->recover($action);
        $this->write($action,function (array $ack) use ($action,$sealed,$original): void {
            $row = $this->required($action); $this->progress($row,$ack);
            if (!in_array($row['phase'],['prepared','checking'],true)) { throw new ModelViolation('pf_local_barrier_checkpoint_moved'); }
            $fields = self::fields($ack); $outer = CanonicalJson::object($sealed['wire'],BarrierTransport::MAX_WIRE);
            $payload = CanonicalJson::object(SignedEnvelope::decode($outer['payload_base64url'] ?? '',49152),49152);
            if (CanonicalJson::encode(array_intersect_key($payload,$fields)) !== CanonicalJson::encode($fields)
                || ($payload['operation_key'] ?? null) !== $original['operation_key'] || ($payload['nonce'] ?? null) !== $sealed['nonce']
                || ($payload['contract'] ?? null) !== $ack['contract'] || ($payload['kind'] ?? null) !== SignedEnvelope::BARRIER_REQUEST
                || ($payload['issuer'] ?? null) !== 'fixture.fans' || ($payload['audience'] ?? null) !== 'fixture.hub') {
                throw new ModelViolation('pf_local_barrier_request');
            }
            DelegatedContext::fresh($payload['issued_at'] ?? null,$payload['expires_at'] ?? null,time());
            $nonce = hash('sha256',$sealed['nonce']); $digest = hash('sha256',$sealed['wire']);
            $history = $this->db->get_row($this->db->prepare('SELECT revision,request_sha256 FROM %i WHERE action_id=%s AND nonce_sha256=%s FOR UPDATE',
                $this->tables['requests'],$action,$nonce),'ARRAY_A'); $this->healthy();
            if ($history !== null) {
                if ($history['revision'] !== $row['revision'] || $history['request_sha256'] !== $digest || $row['request_sha256'] !== $digest) {
                    throw new ModelViolation('pf_local_barrier_checkpoint_moved');
                }
                return;
            }
            $this->insert('requests',['action_id' => $action,'nonce_sha256' => $nonce,'revision' => $row['revision'],'request_sha256' => $digest]);
            $this->update($action,['phase' => 'checking','request_json' => CanonicalJson::encode($sealed),'request_sha256' => $digest]);
        });
    }

    /** @param array{wire:string,nonce:string,request_sha256:string} $proof
     * @return array<string,mixed> */
    public function accept(string $action, array $proof): array
    {
        return $this->write($action,function (array $ack) use ($action,$proof): array {
            $row = $this->required($action); $this->progress($row,$ack);
            if ($row['phase'] !== 'checking') { throw new ModelViolation('pf_local_barrier_checkpoint_moved'); }
            $answer = $this->verified($row,$ack,$proof,false);
            if ($answer['outcome'] === 'unknown') { throw new ModelViolation('pf_transport_unknown'); }
            $bytes = CanonicalJson::encode($proof);
            $phase = $answer['outcome'] === 'ok' && $answer['result'] !== ['state' => 'not_found'] ? 'current' : 'refused';
            $this->update($action,['phase' => $phase,'proof_json' => $bytes,'proof_sha256' => hash('sha256',$bytes)]);
            return $this->progress($this->required($action),$ack);
        });
    }

    /** Delivery still checks the local state; an old active response never undoes local closing.
     * @template T
     * @param callable(array<string,mixed>):T $operation
     * @return T */
    public function withCurrentAcknowledgement(string $action, callable $operation): mixed
    { return $this->current($action,$operation,false); }

    /** Same origin owner must already hold the transaction and mutex; no independent COMMIT.
     * @template T
     * @param callable(array<string,mixed>):T $operation
     * @return T */
    public function withCurrentAcknowledgementInTransaction(string $action, callable $operation): mixed
    { return $this->current($action,$operation,true); }

    /** @template T
     * @param callable(array<string,mixed>):T $operation
     * @return T */
    private function current(string $action, callable $operation, bool $callerTransaction): mixed
    {
        return $this->write($action,function (array $ack) use ($action,$operation): mixed {
            $row = $this->required($action); $this->progress($row,$ack);
            if ($row['phase'] !== 'current') { throw new ModelViolation('pf_local_barrier_primary_read_required'); }
            $answer = $this->verified($row,$ack,CanonicalJson::object($row['proof_json'],131072),true);
            return $operation(['fields' => $ack['fields'],'contract' => $ack['contract'],
                'result' => $answer['result'],'local_state' => $ack['local_state'],'read_revision' => $row['revision']]);
        },$callerTransaction);
    }

    /** @param array<string,mixed> $ack
     * @return array<string,mixed> */
    private static function fields(array $ack): array
    { return array_replace($ack['fields'],['operation' => 'lookup','lookup_operation' => $ack['fields']['operation']]); }

    /** @param array<string,string> $row
     * @param array<string,mixed> $ack
     * @return array<string,mixed> */
    private function progress(array $row, array $ack): array
    {
        ModelValues::integer($row['revision'],true);
        if ((int) $row['revision'] >= 2147483647 || !in_array($row['phase'],['prepared','checking','current','refused'],true)) { throw new ModelViolation('pf_local_barrier_conflict'); }
        if ($row['phase'] === 'prepared') {
            if ($row['request_json'] !== '' || $row['request_sha256'] !== '' || $row['proof_json'] !== '' || $row['proof_sha256'] !== '') {
                throw new ModelViolation('pf_local_barrier_conflict');
            }
        } else { $this->requestValue($row); }
        if (in_array($row['phase'],['current','refused'],true)) {
            if ($row['proof_sha256'] !== hash('sha256',$row['proof_json'])) { throw new ModelViolation('pf_local_barrier_conflict'); }
            $answer = $this->verified($row,$ack,CanonicalJson::object($row['proof_json'],131072),true);
            $current = $answer['outcome'] === 'ok' && $answer['result'] !== ['state' => 'not_found'];
            if ($answer['outcome'] === 'unknown' || ($row['phase'] === 'current') !== $current) { throw new ModelViolation('pf_local_barrier_conflict'); }
        } elseif ($row['proof_json'] !== '' || $row['proof_sha256'] !== '') { throw new ModelViolation('pf_local_barrier_conflict'); }
        return ['action_id' => $row['action_id'],'revision' => $row['revision'],'phase' => $row['phase'],
            'fields' => self::fields($ack),'contract' => $ack['contract'],'local_state' => $ack['local_state']];
    }

    /** @param array<string,string> $row
     * @return array{wire:string,nonce:string} */
    private function requestValue(array $row): array
    {
        $sealed = CanonicalJson::object($row['request_json'],131072); ModelValues::exactKeys($sealed,['wire','nonce']);
        if (!is_string($sealed['wire']) || !is_string($sealed['nonce']) || $row['request_sha256'] !== hash('sha256',$sealed['wire'])) {
            throw new ModelViolation('pf_local_barrier_conflict');
        }
        DelegatedContext::nonce($sealed['nonce']);
        $history = $this->db->get_row($this->db->prepare('SELECT revision,request_sha256 FROM %i WHERE action_id=%s AND nonce_sha256=%s FOR UPDATE',
            $this->tables['requests'],$row['action_id'],hash('sha256',$sealed['nonce'])),'ARRAY_A'); $this->healthy();
        if (!is_array($history) || $history['revision'] !== $row['revision'] || $history['request_sha256'] !== $row['request_sha256']) {
            throw new ModelViolation('pf_local_barrier_conflict');
        }
        return ['wire' => $sealed['wire'],'nonce' => $sealed['nonce']];
    }

    /** @param array<string,string> $row
     * @param array<string,mixed> $ack
     * @param array<string,mixed> $proof
     * @return array{outcome:string,result:array<string,mixed>} */
    private function verified(array $row, array $ack, array $proof, bool $historical): array
    {
        ModelValues::exactKeys($proof,['wire','nonce','request_sha256']); $request = $this->requestValue($row);
        if (!is_string($proof['wire']) || $proof['nonce'] !== $request['nonce'] || $proof['request_sha256'] !== $row['request_sha256']) {
            throw new ModelViolation('pf_local_barrier_checkpoint_moved');
        }
        $outer = CanonicalJson::object($proof['wire'],BarrierTransport::MAX_WIRE); $at = time();
        if ($historical) {
            $raw = CanonicalJson::object(SignedEnvelope::decode($outer['payload_base64url'] ?? '',49152),49152);
            $at = (int) strtotime(ModelValues::utc($raw['issued_at'] ?? null));
            if ($at > time()) { throw new ModelViolation('pf_local_barrier_request'); }
        }
        $payload = SignedEnvelope::open(SignedEnvelope::BARRIER_RESPONSE,$outer,$this->peer,$at);
        return BarrierTransport::response($payload,$this->peer,self::fields($ack),$request['nonce'],$row['request_sha256'],$at,$ack['contract']);
    }

    /** @template T
     * @param callable(array<string,mixed>):T $operation
     * @return T */
    private function write(string $action, callable $operation, bool $callerTransaction = false): mixed
    {
        $compose = function (array $ack) use ($operation): mixed {
            if ((string) $this->db->get_var('SELECT @@in_transaction') !== '1') { throw new ModelViolation('pf_local_barrier_primary_connection'); }
            $this->healthy();
            if (!ClosedBarrierReadSchema::ready($this->db)) { throw new ModelViolation('pf_local_barrier_primary_schema'); }
            return $operation($ack);
        };
        return $callerTransaction ? $this->inbox->withAcknowledgementInTransaction($action,$compose)
            : $this->inbox->withAcknowledgement($action,$compose);
    }

    /** @return array<string,string>|null */
    private function row(string $action): ?array
    { $row = $this->db->get_row($this->db->prepare('SELECT * FROM %i WHERE action_id=%s FOR UPDATE',$this->tables['reads'],$action),'ARRAY_A'); $this->healthy(); return $row; }
    /** @return array<string,string> */
    private function required(string $action): array
    { return $this->row($action) ?? throw new ModelViolation('pf_local_barrier_primary_read_required'); }
    /** @param array<string,string> $values */
    private function insert(string $kind, array $values): void
    { if ($this->db->insert($this->tables[$kind],$values) !== 1) { throw new ModelViolation('pf_local_barrier_storage'); } $this->healthy(); }
    /** @param array<string,string> $values */
    private function update(string $action, array $values): void
    { if ($this->db->update($this->tables['reads'],$values,['action_id' => $action]) === false) { throw new ModelViolation('pf_local_barrier_storage'); } $this->healthy(); }
    private function healthy(): void
    { if ($this->db->last_error !== '') { throw new ModelViolation('pf_local_barrier_storage'); } }
}
