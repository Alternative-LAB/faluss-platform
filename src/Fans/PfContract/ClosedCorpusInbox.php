<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\PfContract;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CorpusTransport;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\DelegatedContext;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingCorpusDocument;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingValues;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SignedEnvelope;
use Throwable;

/** Durable global owner proofs only; neither a ledger nor a public exactness claim. */
final class ClosedCorpusInbox
{
    /** @var array<string,string> */
    private readonly array $tables;

    public function __construct(private readonly \wpdb $db, private readonly PeerPolicy $peer,
        private readonly string $origin, private readonly string $policy)
    {
        ClosedEnvironment::assertIsolated($db,'fans'); $peer->allow('pf.ranking.corpus');
        ModelValues::uuid($origin); ModelValues::version($policy);
        if ($peer->node !== 'fixture.hub' || $peer->audience !== 'fixture.fans') { throw new ModelViolation('pf_fixture_peer_required'); }
        $this->tables = ClosedCorpusInboxSchema::tables($db);
    }

    /** A pending read always wins over a new caller ID; timeouts never create another key.
     * @return array<string,mixed> */
    public function prepare(string $readId): array
    {
        ModelValues::uuid($readId);
        return $this->write(function () use ($readId): array {
            $known = $this->row('reads','read_id=%s',[$readId]);
            if ($known !== null) { $this->progress($known); }
            $pending = $this->rows($this->db->prepare("SELECT * FROM %i WHERE origin_id=%s AND policy_version=%s AND phase NOT IN ('current','refused') FOR UPDATE",
                $this->tables['reads'],$this->origin,$this->policy));
            if (count($pending) > 1) { throw new ModelViolation('pf_local_corpus_conflict'); }
            if ($pending !== []) { return $this->progress($pending[0]); }
            if ($known !== null) { return $this->progress($known); }
            $value = ['read_id' => $readId,'origin_id' => $this->origin,'policy_version' => $this->policy,
                'read_key' => bin2hex(random_bytes(32)),'phase' => 'lookup','manifest' => null,'next_index' => '0','next_cursor' => null];
            $bytes = CanonicalJson::encode($value);
            $this->insert('reads',['read_id' => $readId,'origin_id' => $this->origin,'policy_version' => $this->policy,
                'read_key' => $value['read_key'],'phase' => 'lookup','progress_json' => $bytes,'progress_sha256' => hash('sha256',$bytes)]);
            $this->query($this->db->prepare("UPDATE %i SET state='refreshing' WHERE origin_id=%s AND policy_version=%s",
                $this->tables['current'],$this->origin,$this->policy));
            return $value;
        });
    }

    /** Explicit primary recheck of the same complete generation. No replacement ID/key on uncertainty.
     * A new response challenge is persisted before HTTP; an older finish cannot satisfy this recheck.
     * @return array<string,mixed> */
    public function prepareRefresh(string $readId): array
    {
        ModelValues::uuid($readId);
        return $this->write(function () use ($readId): array {
            $row = $this->required($readId); $progress = $this->progress($row);
            if ($progress['phase'] !== 'current') { return $progress; }
            $current = $this->readCurrent();
            $stored = $this->row('current','origin_id=%s AND policy_version=%s',[$this->origin,$this->policy]);
            if ($current === null || $stored === null || $stored['read_id'] !== $readId
                || CanonicalJson::encode($current['manifest']) !== CanonicalJson::encode($progress['manifest'])) {
                throw new ModelViolation('pf_local_corpus_checkpoint_moved');
            }
            $progress['phase'] = 'finish'; $progress['fence_request_sha256'] = 'pending';
            $this->query($this->db->prepare("UPDATE %i SET state='refreshing' WHERE origin_id=%s AND policy_version=%s AND read_id=%s",
                $this->tables['current'],$this->origin,$this->policy,$readId));
            return $this->save($row,$progress);
        });
    }

    /** Persist the exact signed request/digest before any HTTP; response binding survives restart.
     * @param array<string,mixed> $fields
     * @param array<string,mixed> $sealed
     * @return array<string,mixed> */
    public function request(array $fields, array $sealed): array
    {
        CorpusTransport::fields($fields); ModelValues::exactKeys($sealed,['wire','nonce']); DelegatedContext::nonce($sealed['nonce']);
        foreach ($sealed as $value) { if (!is_string($value)) { throw new ModelViolation('pf_local_corpus_request'); } }
        return $this->write(function () use ($fields,$sealed): array {
            $row = $this->required($fields['read_id']); $progress = $this->progress($row);
            if (!in_array($progress['phase'],['lookup','start','page','finish'],true)) { throw new ModelViolation('pf_local_corpus_checkpoint_moved'); }
            $expected = $this->fields($progress);
            if (CanonicalJson::encode($fields) !== CanonicalJson::encode($expected)) { throw new ModelViolation('pf_local_corpus_checkpoint_moved'); }
            $outer = CanonicalJson::object($sealed['wire'],CorpusTransport::MAX_REQUEST_WIRE);
            ModelValues::exactKeys($outer,['payload_base64url','key_id','payload_sha256','signature_base64url']);
            foreach ($outer as $value) { if (!is_string($value)) { throw new ModelViolation('pf_invalid_envelope'); } }
            $payload = CanonicalJson::object(SignedEnvelope::decode($outer['payload_base64url'],49152),49152);
            $bound = array_intersect_key($payload,array_flip(array_keys($fields)));
            if (CanonicalJson::encode($bound) !== CanonicalJson::encode($fields) || ($payload['read_key'] ?? null) !== $progress['read_key']
                || ($payload['nonce'] ?? null) !== $sealed['nonce'] || ($payload['issuer'] ?? null) !== 'fixture.fans'
                || ($payload['audience'] ?? null) !== 'fixture.hub' || ($payload['contract'] ?? null) !== RankingCorpusDocument::CONTRACT
                || ($payload['kind'] ?? null) !== SignedEnvelope::CORPUS_REQUEST) { throw new ModelViolation('pf_local_corpus_request'); }
            DelegatedContext::fresh($payload['issued_at'],$payload['expires_at'],time());
            $hash = hash('sha256',$sealed['wire']); $nonceHash = hash('sha256',$sealed['nonce']);
            $known = $this->row('requests','read_id=%s AND nonce_sha256=%s',[$fields['read_id'],$nonceHash]);
            if (isset($progress['fence_request_sha256']) && $known !== null) {
                throw new ModelViolation('pf_local_corpus_checkpoint_moved');
            }
            if ($known !== null) {
                if ($known['request_sha256'] !== $hash || $known['wire_json'] !== $sealed['wire']) { throw new ModelViolation('pf_local_corpus_conflict'); }
            } else {
                $this->insert('requests',['read_id' => $fields['read_id'],'nonce_sha256' => $nonceHash,'request_sha256' => $hash,
                    'fields_json' => CanonicalJson::encode($fields),'wire_json' => $sealed['wire']]);
            }
            if (isset($progress['fence_request_sha256'])) {
                $progress['fence_request_sha256'] = $hash; return $this->save($row,$progress);
            }
            // A lost start response always resumes at primary lookup, never under a replacement key.
            if ($progress['phase'] === 'start') { $progress['phase'] = 'lookup'; return $this->save($row,$progress); }
            return $progress;
        });
    }

    /** Verified immutable request fields for a single next network operation.
     * @param array<string,mixed> $progress
     * @return array<string,mixed> */
    public function fields(array $progress): array
    {
        $op = $progress['phase'];
        if (!in_array($op,['lookup','start','page','finish'],true)) { throw new ModelViolation('pf_local_corpus_terminal'); }
        $fields = ['operation' => $op,'read_id' => $progress['read_id'],'origin_id' => $this->origin,'policy_version' => $this->policy,
            'corpus_id' => in_array($op,['page','finish'],true) ? $progress['manifest']['corpus_id'] : '',
            'cursor' => $op === 'page' ? $progress['next_cursor'] : ''];
        CorpusTransport::fields($fields); return $fields;
    }

    /** Authenticate a fresh signed response against a durably registered request.
     * @param array<string,mixed> $proof
     * @return array<string,mixed> */
    public function accept(array $proof): array
    {
        return $this->write(function () use ($proof): array {
            $answer = $this->verified($proof,false); $row = $this->required($proof['fields']['read_id']); $progress = $this->progress($row);
            if (isset($progress['fence_request_sha256']) && $progress['fence_request_sha256'] !== $proof['request_sha256']) {
                throw new ModelViolation('pf_local_corpus_checkpoint_moved');
            }
            if ($answer['outcome'] === 'unknown') { throw new ModelViolation('pf_transport_unknown'); }
            if ($answer['outcome'] === 'refused') {
                if ($progress['phase'] === 'refused') { return $progress; }
                $progress['phase'] = 'refused';
                $this->query($this->db->prepare("UPDATE %i SET state='unavailable' WHERE origin_id=%s AND policy_version=%s AND (read_id=%s OR revision<=%d)",
                    $this->tables['current'],$this->origin,$this->policy,$progress['read_id'],(int) ($progress['manifest']['revision'] ?? '0')));
                return $this->save($row,$progress);
            }
            if ($answer['result'] === ['state' => 'absent']) {
                if ($proof['fields']['operation'] !== 'lookup') { throw new ModelViolation('pf_local_corpus_conflict'); }
                if ($progress['manifest'] !== null || !in_array($progress['phase'],['lookup','start'],true)) {
                    throw new ModelViolation('pf_local_corpus_checkpoint_moved');
                }
                $progress['phase'] = 'start'; return $this->save($row,$progress);
            }
            if ($proof['fields']['operation'] === 'finish') { return $this->finish($row,$progress,$answer['result'],$proof); }
            $page = $answer['result']['page']; $manifest = $page['manifest']; $bytes = CanonicalJson::encode($page);
            $known = $this->row('pages','read_id=%s AND page_index=%s',[$progress['read_id'],$page['page_index']]);
            if ($known !== null) {
                if ($known['payload_json'] !== $bytes || $known['payload_sha256'] !== hash('sha256',$bytes)) { throw new ModelViolation('pf_local_corpus_conflict'); }
                return $progress;
            }
            if (!in_array($progress['phase'],['lookup','start','page'],true) || $page['page_index'] !== $progress['next_index']
                || ($progress['manifest'] !== null && CanonicalJson::encode($progress['manifest']) !== CanonicalJson::encode($manifest))
                || ($progress['next_cursor'] !== null && $page['cursor'] !== $progress['next_cursor'])) { throw new ModelViolation('pf_corpus_incomplete'); }
            $proofBytes = CanonicalJson::encode($proof);
            $this->insert('pages',['read_id' => $progress['read_id'],'page_index' => $page['page_index'],'payload_json' => $bytes,
                'payload_sha256' => hash('sha256',$bytes),'proof_json' => $proofBytes,'proof_sha256' => hash('sha256',$proofBytes)]);
            $progress['manifest'] = $manifest; $progress['next_index'] = (string) ((int) $page['page_index']+1);
            $progress['next_cursor'] = $page['next_cursor']; $progress['phase'] = $page['next_cursor'] === null ? 'finish' : 'page';
            return $this->save($row,$progress);
        });
    }

    /** A historical, completely verified generation at its attested instant, not future freshness.
     * @return array{manifest:array<string,mixed>,facts:list<array<string,mixed>>,verified_at:string}|null */
    public function current(): ?array
    { return $this->withCurrent(static fn (?array $generation): ?array => $generation); }

    /** Trusted Fans composition under the same origin mutex and transaction as corpus promotion.
     * No HTTP or nested transaction inside this callback; exceptions roll back every composed write.
     * @template T
     * @param callable(array{manifest:array<string,mixed>,facts:list<array<string,mixed>>,verified_at:string}|null):T $operation
     * @return T */
    public function withCurrent(callable $operation): mixed
    { return $this->write(fn (): mixed => $operation($this->readCurrent())); }

    /** @return array{manifest:array<string,mixed>,facts:list<array<string,mixed>>,verified_at:string}|null */
    private function readCurrent(): ?array
    {
        $row = $this->row('current','origin_id=%s AND policy_version=%s',[$this->origin,$this->policy]);
        if ($row === null || $row['state'] !== 'current') { return null; }
        $manifest = CanonicalJson::object($row['manifest_json']);
        $facts = CanonicalJson::object('{"facts":' . $row['facts_json'] . '}',33554448)['facts'];
        $proof = CanonicalJson::object($row['proof_json'],8388608);
        if (!is_array($facts) || !array_is_list($facts) || $row['full_sha256'] !== hash('sha256',$row['facts_json'])
            || $row['proof_sha256'] !== hash('sha256',$row['proof_json'])) { throw new ModelViolation('pf_corpus_incomplete'); }
        RankingCorpusDocument::manifest($manifest,$this->origin,$this->policy); RankingCorpusDocument::complete($manifest,$facts);
        $verified = $this->verified($proof,true);
        if ($verified['outcome'] !== 'ok' || $verified['result']['state'] !== 'current'
            || CanonicalJson::encode($verified['result']['manifest']) !== CanonicalJson::encode($manifest)
            || $row['read_id'] !== $proof['fields']['read_id'] || $row['epoch'] !== $manifest['epoch']
            || $row['ordering_epoch'] !== $manifest['ordering_epoch'] || $row['revision'] !== $manifest['revision']
            || $row['verified_at'] !== $verified['result']['verified_at']) { throw new ModelViolation('pf_local_corpus_conflict'); }
        return ['manifest' => $manifest,'facts' => $facts,'verified_at' => $row['verified_at']];
    }

    /** @param array<string,string> $row
     * @param array<string,mixed> $progress
     * @param array<string,mixed> $fence
     * @param array<string,mixed> $proof
     * @return array<string,mixed> */
    private function finish(array $row, array $progress, array $fence, array $proof): array
    {
        if (!in_array($progress['phase'],['finish','current'],true)
            || CanonicalJson::encode($fence['manifest']) !== CanonicalJson::encode($progress['manifest'])) { throw new ModelViolation('pf_corpus_incomplete'); }
        // An old successful finish is idempotent; it never restores an earlier current generation.
        if ($progress['phase'] === 'current') { return $progress; }
        $manifest = $fence['manifest']; $facts = []; $next = null;
        $pages = $this->rows($this->db->prepare('SELECT * FROM %i WHERE read_id=%s ORDER BY page_index FOR UPDATE',$this->tables['pages'],$progress['read_id']));
        if (count($pages) !== (int) $manifest['page_count']) { throw new ModelViolation('pf_corpus_incomplete'); }
        foreach ($pages as $index => $stored) {
            $page = CanonicalJson::object($stored['payload_json'],4194304); RankingCorpusDocument::page($page,$manifest);
            $accepted = CanonicalJson::object($stored['proof_json'],8388608); $signed = $this->verified($accepted,true);
            if ($stored['payload_sha256'] !== hash('sha256',$stored['payload_json']) || $stored['proof_sha256'] !== hash('sha256',$stored['proof_json'])
                || $signed['outcome'] !== 'ok' || CanonicalJson::encode($signed['result']['page']) !== CanonicalJson::encode($page)
                || $page['page_index'] !== (string) $index || ($index > 0 && $page['cursor'] !== $next)) { throw new ModelViolation('pf_corpus_incomplete'); }
            array_push($facts,...$page['facts']); $next = $page['next_cursor'];
        }
        if ($next !== null) { throw new ModelViolation('pf_corpus_incomplete'); }
        RankingCorpusDocument::complete($manifest,$facts);
        $old = $this->row('current','origin_id=%s AND policy_version=%s',[$this->origin,$this->policy]);
        if ($old !== null && ($old['epoch'] !== $manifest['epoch'] || $old['ordering_epoch'] !== $manifest['ordering_epoch']
            || (int) $old['revision'] > (int) $manifest['revision']
            || ($old['revision'] === $manifest['revision'] && $old['full_sha256'] !== $manifest['full_sha256']))) { throw new ModelViolation('pf_corpus_regression'); }
        $pending = $this->rows($this->db->prepare("SELECT read_id FROM %i WHERE origin_id=%s AND policy_version=%s AND read_id<>%s AND phase NOT IN ('current','refused') FOR UPDATE",
            $this->tables['reads'],$this->origin,$this->policy,$progress['read_id']));
        if ($pending !== []) { throw new ModelViolation('pf_local_corpus_conflict'); }
        $proofBytes = CanonicalJson::encode($proof);
        $current = ['origin_id' => $this->origin,'policy_version' => $this->policy,'read_id' => $progress['read_id'],
            'epoch' => $manifest['epoch'],'ordering_epoch' => $manifest['ordering_epoch'],'revision' => $manifest['revision'],
            'manifest_json' => CanonicalJson::encode($manifest),'facts_json' => CanonicalJson::encode($facts),'full_sha256' => $manifest['full_sha256'],
            'proof_json' => $proofBytes,'proof_sha256' => hash('sha256',$proofBytes),'verified_at' => $fence['verified_at'],'state' => 'current'];
        if ($old === null) { $this->insert('current',$current); } else { $this->update('current',$current,['origin_id' => $this->origin,'policy_version' => $this->policy]); }
        $progress['phase'] = 'current'; return $this->save($row,$progress);
    }

    /** @param array<string,mixed> $proof
     * @return array{outcome:string,result:array<string,mixed>} */
    private function verified(array $proof, bool $historical): array
    {
        ModelValues::exactKeys($proof,['wire','fields','nonce','request_sha256']);
        if (!is_string($proof['wire']) || !is_array($proof['fields'])) { throw new ModelViolation('pf_local_corpus_request'); }
        $fields = $proof['fields']; CorpusTransport::fields($fields);
        if ($fields['origin_id'] !== $this->origin || $fields['policy_version'] !== $this->policy) { throw new ModelViolation('pf_corpus_context_mismatch'); }
        DelegatedContext::nonce($proof['nonce']); RankingValues::digest($proof['request_sha256']);
        $request = $this->row('requests','read_id=%s AND nonce_sha256=%s',[$fields['read_id'],hash('sha256',$proof['nonce'])]);
        if ($request === null || $request['request_sha256'] !== $proof['request_sha256']
            || hash('sha256',$request['wire_json']) !== $proof['request_sha256'] || $request['fields_json'] !== CanonicalJson::encode($fields)) {
            throw new ModelViolation('pf_local_corpus_request');
        }
        $outer = CanonicalJson::object($proof['wire'],CorpusTransport::MAX_RESPONSE_WIRE); $at = time();
        if ($historical) {
            if (!is_string($outer['payload_base64url'] ?? null)) { throw new ModelViolation('pf_invalid_envelope'); }
            $raw = CanonicalJson::object(SignedEnvelope::decode($outer['payload_base64url'],4194304),4194304);
            $at = (int) strtotime(ModelValues::utc($raw['issued_at'] ?? null));
            if ($at > time()) { throw new ModelViolation('pf_invalid_envelope'); }
        }
        $payload = SignedEnvelope::open(SignedEnvelope::CORPUS_RESPONSE,$outer,$this->peer,$at);
        return CorpusTransport::response($payload,$this->peer,$fields,$proof['nonce'],$proof['request_sha256'],$at);
    }

    /** @return array<string,string> */
    private function required(string $id): array
    { $row = $this->row('reads','read_id=%s',[$id]); if ($row === null) { throw new ModelViolation('pf_local_corpus_request'); } $this->progress($row); return $row; }

    /** @param array<string,string> $row
     * @return array<string,mixed> */
    private function progress(array $row): array
    {
        $value = CanonicalJson::object($row['progress_json']);
        $keys = ['read_id','origin_id','policy_version','read_key','phase','manifest','next_index','next_cursor'];
        if (array_key_exists('fence_request_sha256',$value)) {
            $keys[] = 'fence_request_sha256';
            if (!in_array($value['phase'] ?? '',['finish','current','refused'],true)) { throw new ModelViolation('pf_local_corpus_conflict'); }
            if ($value['fence_request_sha256'] !== 'pending') { RankingValues::digest($value['fence_request_sha256']); }
        }
        ModelValues::exactKeys($value,$keys);
        if ($row['progress_sha256'] !== hash('sha256',$row['progress_json']) || $value['origin_id'] !== $this->origin || $value['policy_version'] !== $this->policy
            || $row['read_id'] !== $value['read_id'] || $row['origin_id'] !== $this->origin || $row['policy_version'] !== $this->policy
            || $row['phase'] !== $value['phase'] || $row['read_key'] !== $value['read_key']) { throw new ModelViolation('pf_local_corpus_conflict'); }
        ModelValues::uuid($value['read_id']); ModelValues::keyHash($value['read_key']); ModelValues::integer($value['next_index']);
        if (!in_array($value['phase'],['lookup','start','page','finish','current','refused'],true)) { throw new ModelViolation('pf_local_corpus_conflict'); }
        if ($value['manifest'] !== null) {
            if (!is_array($value['manifest'])) { throw new ModelViolation('pf_local_corpus_conflict'); }
            RankingCorpusDocument::manifest($value['manifest'],$this->origin,$this->policy);
        }
        if ($value['next_cursor'] !== null) { ModelValues::uuid($value['next_cursor']); }
        if (in_array($value['phase'],['lookup','start'],true)
            && ($value['manifest'] !== null || $value['next_index'] !== '0' || $value['next_cursor'] !== null)) { throw new ModelViolation('pf_local_corpus_conflict'); }
        if ($value['phase'] === 'page' && ($value['manifest'] === null || $value['next_cursor'] === null
            || (int) $value['next_index'] < 1 || (int) $value['next_index'] >= (int) $value['manifest']['page_count'])) { throw new ModelViolation('pf_corpus_incomplete'); }
        if (in_array($value['phase'],['finish','current'],true) && ($value['manifest'] === null
            || $value['next_cursor'] !== null || $value['next_index'] !== $value['manifest']['page_count'])) { throw new ModelViolation('pf_corpus_incomplete'); }
        return $value;
    }

    /** @param array<string,string> $row
     * @param array<string,mixed> $value
     * @return array<string,mixed> */
    private function save(array $row, array $value): array
    {
        $bytes = CanonicalJson::encode($value);
        $this->update('reads',['phase' => $value['phase'],'progress_json' => $bytes,'progress_sha256' => hash('sha256',$bytes)],['read_id' => $row['read_id']]);
        return $value;
    }

    /** @template T
     * @param callable():T $callback
     * @return T */
    private function write(callable $callback): mixed
    {
        ClosedEnvironment::assertIsolated($this->db,'fans'); $held = $started = false; $suppressed = $this->db->suppress_errors(true);
        $lock = 'fans_pf_b3_corpus_' . substr(hash('sha256',$this->db->prefix . ':' . $this->origin . ':' . $this->policy),0,32);
        try {
            if ((string) $this->db->get_var('SELECT @@in_transaction') !== '0' || self::failed()) { throw new ModelViolation('nested_transaction_refused'); }
            if ((string) $this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s,10)',$lock)) !== '1' || self::failed()) { throw new ModelViolation('pf_local_corpus_busy'); }
            $held = true;
            if (!ClosedCorpusInboxSchema::ready($this->db)) { throw new ModelViolation('pf_local_corpus_schema'); }
            $this->query('START TRANSACTION'); $started = true; $answer = $callback();
            if ($this->db->query('COMMIT') === false || self::failed()) { throw new ModelViolation('pf_local_corpus_commit_unknown'); }
            $started = false; return $answer;
        } catch (Throwable $error) { if ($started) { $this->db->query('ROLLBACK'); } throw $error; }
        finally {
            $released = !$held || (string) $this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)',$lock)) === '1';
            $this->db->suppress_errors($suppressed); if (!$released) { throw new ModelViolation('pf_local_corpus_commit_unknown'); }
        }
    }

    /** @param literal-string $where
     * @param list<string> $values
     * @return array<string,string>|null */
    private function row(string $kind, string $where, array $values): ?array
    {
        $rows = $this->rows($this->db->prepare("SELECT * FROM %i WHERE $where FOR UPDATE",$this->tables[$kind],...$values));
        if (count($rows) > 1) { throw new ModelViolation('pf_local_corpus_conflict'); } return $rows[0] ?? null;
    }
    /** @return list<array<string,string>> */
    private function rows(string $query): array
    { $rows = $this->db->get_results($query,'ARRAY_A'); if (self::failed() || !is_array($rows)) { throw new ModelViolation('pf_local_corpus_storage'); } return $rows; }
    /** @param array<string,mixed> $data */
    private function insert(string $kind, array $data): void
    { if ($this->db->insert($this->tables[$kind],$data) !== 1 || self::failed()) { throw new ModelViolation('pf_local_corpus_storage'); } }
    /** @param array<string,mixed> $data
     * @param array<string,string> $where */
    private function update(string $kind, array $data, array $where): void
    { $changed = $this->db->update($this->tables[$kind],$data,$where); if ($changed === false || $changed > 1 || self::failed()) { throw new ModelViolation('pf_local_corpus_storage'); } }
    private function query(string $query): void
    { if ($this->db->query($query) === false || self::failed()) { throw new ModelViolation('pf_local_corpus_storage'); } }
    /** @phpstan-impure */
    private static function failed(): bool { global $wpdb; return $wpdb->last_error !== ''; }
}
