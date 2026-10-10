<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedIntent;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingBarrier;
use Throwable;

/** Primary barrier writes never take an economic subject lock. Selection joins the caller's owner transaction. */
final class ClosedBarrierStore
{
    private readonly ClosedReservationDatabase $connection;
    /** @var array<string,string> */
    private readonly array $tables;
    public function __construct(private readonly \wpdb $db)
    { ClosedEnvironment::assertIsolated($db,'hub'); $this->connection = new ClosedReservationDatabase($db); $this->tables = ClosedBarrierSchema::tables($db); }

    /** @param array<array-key,mixed> $input
     * @return array<string,mixed> */
    public function register(PeerPolicy $peer, array $input, string $key, ?ClosedBarrierContext $context = null): array
    {
        self::peer($peer,'register'); $descriptor = RankingBarrier::descriptor($input); $ref = RankingBarrier::reference($descriptor['content']);
        $hash = ModelValues::keyHash($key); $digest = hash('sha256',CanonicalJson::encode($descriptor));
        $requestDigest = $context?->digest('register',$descriptor) ?? $digest;
        return $this->write(function () use ($peer,$descriptor,$ref,$hash,$digest,$requestDigest): array {
            $known = $this->known($peer->node,'register',$hash,$requestDigest);
            if ($known !== null) { return $this->current($known); }
            $latest = $this->latest($ref['barrier_key']);
            if ($latest !== null) {
                if ($latest['state'] !== 'closed' || (int) $latest['version'] >= ModelValues::MAX_INTEGER
                    || (int) $ref['version'] !== (int) $latest['version'] + 1) { throw new ModelViolation('pf_barrier_stable_key_or_version_required'); }
            }
            $now = $this->connection->now();
            if ($descriptor['valid_until'] <= $now) { throw new ModelViolation('pf_barrier_expired'); }
            $this->connection->insert($this->tables['barriers'],['barrier_key' => $ref['barrier_key'],'version' => $ref['version'],'owner' => $peer->node,
                'state' => 'active','content_sha256' => $ref['content_sha256'],'descriptor_json' => CanonicalJson::encode($descriptor),
                'descriptor_sha256' => $digest,'opened_at' => $now,'closed_at' => '']);
            $result = ['operation' => 'register','barrier_key' => $ref['barrier_key'],'version' => $ref['version'],
                'content_sha256' => $ref['content_sha256'],'effective_at' => $now];
            $this->remember($peer->node,'register',$hash,$requestDigest,$result,$now); return $this->current($result);
        },$context);
    }

    /** @param array<array-key,mixed> $input
     * @return array<string,mixed> */
    public function close(PeerPolicy $peer, array $input, string $key, ?ClosedBarrierContext $context = null): array
    {
        self::peer($peer,'close'); $ref = RankingBarrier::closeReference($input); $hash = ModelValues::keyHash($key); $digest = hash('sha256',CanonicalJson::encode($ref));
        $digest = $context?->digest('close',$ref) ?? $digest;
        return $this->write(function () use ($peer,$ref,$hash,$digest,$context): array {
            $context?->assertOwner($this->version($ref));
            $known = $this->known($peer->node,'close',$hash,$digest);
            if ($known !== null) { return $this->current($known); }
            $row = $this->latest($ref['barrier_key']);
            if ($row === null || $row['version'] !== $ref['version'] || $row['content_sha256'] !== $ref['content_sha256'] || $row['state'] !== 'active'
                || $row['owner'] !== $peer->node) { throw new ModelViolation('pf_barrier_stable_key_or_version_required'); }
            $now = $this->connection->now(); $this->connection->update($this->tables['barriers'],['state' => 'closed','closed_at' => $now],
                ['barrier_key' => $ref['barrier_key'],'version' => $ref['version'],'state' => 'active']);
            $result = ['operation' => 'close','barrier_key' => $ref['barrier_key'],'version' => $ref['version'],
                'content_sha256' => $ref['content_sha256'],'reason' => $ref['reason'],'effective_at' => $now];
            $this->remember($peer->node,'close',$hash,$digest,$result,$now); return $this->current($result);
        },$context);
    }

    /** The same key and full request are required; a DB failure never means not_found.
     * @param array<array-key,mixed> $input
     * @return array<string,mixed> */
    public function lookup(PeerPolicy $peer, string $operation, array $input, string $key, ?ClosedBarrierContext $context = null): array
    {
        self::peer($peer,$operation); $peer->allow('pf.lookup');
        $request = $operation === 'register' ? RankingBarrier::descriptor($input) : RankingBarrier::closeReference($input);
        $hash = ModelValues::keyHash($key); $digest = hash('sha256',CanonicalJson::encode($request));
        $digest = $context?->digest($operation,$request) ?? $digest;
        return $this->write(function () use ($peer,$operation,$hash,$digest,$request,$context): array {
            if ($operation === 'close') { $context?->assertOwner($this->version($request)); }
            $known = $this->known($peer->node,$operation,$hash,$digest); return $known === null ? ['state' => 'not_found'] : $this->current($known);
        },$context);
    }

    /** Call immediately before the economic write; returns the DB instant after all selected rows are locked. */
    public function lockSelection(RankedIntent $intent): string
    {
        $this->available(); $this->connection->assertHeldSubject($intent->base->values['member_faluss_id']); $rows = [];
        foreach (RankingBarrier::references($intent) as $ref) {
            $row = $this->latest($ref['barrier_key']);
            if ($row === null || $row['version'] !== $ref['version'] || $row['content_sha256'] !== $ref['content_sha256'] || $row['state'] !== 'active'
                || $row['owner'] !== $intent->base->values['client_authority']) { throw new ModelViolation('pf_barrier_not_admitted'); }
            $rows[] = $row;
        }
        $now = $this->connection->now();
        foreach ($rows as $row) {
            $descriptor = RankingBarrier::descriptor(CanonicalJson::object($row['descriptor_json']));
            $ref = RankingBarrier::reference($descriptor['content']);
            if (hash('sha256',CanonicalJson::encode($descriptor)) !== $row['descriptor_sha256'] || $row['opened_at'] > $now
                || $ref['barrier_key'] !== $row['barrier_key'] || $ref['version'] !== $row['version'] || $ref['content_sha256'] !== $row['content_sha256']
                || $now < $descriptor['valid_from'] || $now >= $descriptor['valid_until']) { throw new ModelViolation('pf_barrier_not_current'); }
        }
        return $now;
    }

    private static function peer(PeerPolicy $peer, string $operation): void
    {
        if (!in_array($operation,['register','close'],true)) { throw new ModelViolation('pf_barrier_invalid_operation'); }
        $peer->allow('pf.ranking.context.' . $operation);
        if ($peer->node !== 'fixture.fans' || $peer->audience !== 'fixture.hub') { throw new ModelViolation('pf_invalid_peer'); }
    }

    private function available(): void
    { if (!ClosedBarrierSchema::ready($this->db)) { throw new ModelViolation('pf_barrier_schema_unavailable'); } }

    /** @param callable():array<string,mixed> $callback
     * @return array<string,mixed> */
    private function write(callable $callback, ?ClosedBarrierContext $context = null): array
    {
        ClosedEnvironment::assertIsolated($this->db,'hub'); $suppressed = $this->db->suppress_errors(true); $held = $started = false;
        $lock = 'pf_b3_barrier_write_' . substr(hash('sha256',$this->db->prefix),0,24);
        try {
            $context?->assertFresh();
            if ((string) $this->connection->scalar('SELECT @@in_transaction') !== '0') { throw new ModelViolation('nested_transaction_refused'); }
            if ((string) $this->connection->scalar($this->db->prepare('SELECT GET_LOCK(%s,10)',$lock)) !== '1') { throw new ModelViolation('pf_barrier_busy'); }
            $held = true; $this->available(); $this->connection->query('START TRANSACTION'); $started = true;
            $context?->assertFresh();
            $result = $callback();
            $context?->assertFresh();
            if ($this->db->query('COMMIT') === false || $this->db->last_error !== '') { throw new ModelViolation('pf_barrier_commit_unknown'); }
            $started = false; return $result;
        } catch (Throwable $error) {
            if ($started) { $this->db->query('ROLLBACK'); } throw $error;
        } finally {
            $released = !$held || (string) $this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)',$lock)) === '1';
            $this->db->suppress_errors($suppressed);
            if (!$released) { throw new ModelViolation('pf_barrier_commit_unknown'); }
        }
    }

    /** @return array<string,string>|null */
    private function latest(string $key): ?array
    {
        $rows = $this->connection->rows($this->db->prepare('SELECT * FROM %i WHERE barrier_key=%s ORDER BY version DESC LIMIT 1 FOR UPDATE',$this->tables['barriers'],$key));
        return $rows[0] ?? null;
    }

    /** Exact historical version is required for origin validation, including superseded close lookups.
     * @param array<string,string> $reference
     * @return array<string,string>|null */
    private function version(array $reference): ?array
    {
        $row = $this->connection->row($this->tables['barriers'],'barrier_key=%s AND version=%s',[$reference['barrier_key'],$reference['version']]);
        if ($row !== null && $row['content_sha256'] !== $reference['content_sha256']) { throw new ModelViolation('pf_barrier_context_mismatch'); }
        return $row;
    }

    /** @return array<string,mixed>|null */
    private function known(string $owner, string $operation, string $key, string $digest): ?array
    {
        $row = $this->connection->row($this->tables['operations'],'owner=%s AND operation=%s AND key_sha256=%s',[$owner,$operation,$key]);
        if ($row === null) { return null; }
        if ($row['request_sha256'] !== $digest) { throw new ModelViolation('pf_barrier_key_conflict'); }
        if (hash('sha256',$row['payload_json']) !== $row['payload_sha256']) { throw new ModelViolation('pf_barrier_integrity_failure'); }
        return CanonicalJson::object($row['payload_json']);
    }

    /** @param array<string,mixed> $result
     * @return array<string,mixed> */
    private function current(array $result): array
    {
        $latest = $this->latest($result['barrier_key']) ?? throw new ModelViolation('pf_barrier_integrity_failure');
        if ($latest['version'] === $result['version'] && $latest['content_sha256'] !== $result['content_sha256']) { throw new ModelViolation('pf_barrier_integrity_failure'); }
        return $result + ['state' => $latest['version'] !== $result['version'] ? 'superseded' : $latest['state']];
    }

    /** @param array<string,mixed> $result */
    private function remember(string $owner, string $operation, string $hash, string $digest, array $result, string $now): void
    {
        $bytes = CanonicalJson::encode($result);
        $this->connection->insert($this->tables['operations'],['owner' => $owner,'operation' => $operation,'key_sha256' => $hash,
            'request_sha256' => $digest,'payload_json' => $bytes,'payload_sha256' => hash('sha256',$bytes),'recorded_at' => $now]);
        $this->connection->insert($this->tables['events'],['event_id' => wp_generate_uuid4(),'barrier_key' => $result['barrier_key'],
            'version' => $result['version'],'owner' => $owner,'operation' => $operation,'key_sha256' => $hash,'occurred_at' => $now]);
    }
}
