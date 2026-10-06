<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\PfContract;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SnapshotDocument;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SnapshotEnvironment;
use Throwable;

/** Durable private proof staging, not a PF ledger, projection or ranking engine. */
final class ClosedSnapshotInbox
{
    /** @var array<string,string> */
    private readonly array $tables;

    public function __construct(private readonly \wpdb $database, private readonly string $epoch)
    {
        SnapshotEnvironment::assertIsolated($database, 'fans');
        ModelValues::uuid($epoch);
        $this->tables = ClosedSnapshotInboxSchema::tables($database);
    }

    /** Persist the read key before HTTP; a process restart resumes this exact operation.
     * @return array<string,mixed> */
    public function prepare(string $member, string $readId): array
    {
        ModelValues::uuid($readId);
        return $this->write($member, function () use ($member, $readId): array {
            $row = $this->row('reads', 'read_id=%s', [$readId]);
            if ($row !== null) {
                return $this->progress($row, $member);
            }
            $progress = ['read_id' => $readId, 'member_faluss_id' => $member, 'read_key' => bin2hex(random_bytes(32)),
                'phase' => 'start', 'manifest' => null, 'next_page' => '0', 'next_cursor' => null];
            $bytes = CanonicalJson::encode($progress);
            $this->insert('reads', ['read_id' => $readId, 'member_faluss_id' => $member, 'read_key' => $progress['read_key'],
                'progress_json' => $bytes, 'progress_sha256' => hash('sha256', $bytes)]);
            $this->query($this->database->prepare("UPDATE %i SET state='refreshing' WHERE member_faluss_id=%s", $this->tables['current'], $member));
            return $progress;
        });
    }

    /** Only called after the fresh Hub signature/request context was verified.
     * @param array<string,mixed> $page
     * @return array<string,mixed> */
    public function page(string $member, string $readId, array $page, string $wire): array
    {
        return $this->write($member, function () use ($member, $readId, $page, $wire): array {
            $row = $this->requiredRead($member, $readId);
            $progress = $this->progress($row, $member);
            $manifest = $page['manifest'] ?? null;
            if (!is_array($manifest)) { throw new ModelViolation('pf_snapshot_incomplete'); }
            SnapshotDocument::manifest($manifest, $member, $this->epoch);
            SnapshotDocument::page($page, $manifest);
            $bytes = CanonicalJson::encode($page);
            $known = $this->row('pages', 'read_id=%s AND page_index=%s', [$readId, $page['page_index']]);
            if ($known !== null) {
                if ($known['payload_json'] !== $bytes || $known['payload_sha256'] !== hash('sha256', $bytes)) {
                    throw new ModelViolation('pf_local_snapshot_conflict');
                }
                return $progress;
            }
            if (!in_array($progress['phase'], ['start', 'page'], true) || $page['page_index'] !== $progress['next_page']
                || ($progress['manifest'] !== null && CanonicalJson::encode($progress['manifest']) !== CanonicalJson::encode($manifest))
                || ($progress['next_cursor'] !== null && $page['cursor'] !== $progress['next_cursor'])
            ) { throw new ModelViolation('pf_snapshot_incomplete'); }
            $this->insert('pages', ['read_id' => $readId, 'page_index' => $page['page_index'], 'payload_json' => $bytes,
                'payload_sha256' => hash('sha256', $bytes), 'response_json' => $wire, 'response_sha256' => hash('sha256', $wire)]);
            $progress['manifest'] = $manifest;
            $progress['next_page'] = (string) ((int) $page['page_index'] + 1);
            $progress['next_cursor'] = $page['next_cursor'];
            $progress['phase'] = $page['next_cursor'] === null ? 'finish' : 'page';
            return $this->save($row, $progress);
        });
    }

    /** Complete replacement is atomic and cannot regress the currently accepted revision.
     * @param array<string,mixed> $fence
     * @return array<string,mixed> */
    public function finish(string $member, string $readId, array $fence, string $wire): array
    {
        return $this->write($member, function () use ($member, $readId, $fence, $wire): array {
            ModelValues::exactKeys($fence, ['state', 'manifest', 'manifest_sha256']);
            $row = $this->requiredRead($member, $readId);
            $progress = $this->progress($row, $member);
            if (!in_array($progress['phase'], ['finish', 'current'], true) || $fence['state'] !== 'current'
                || CanonicalJson::encode($fence['manifest']) !== CanonicalJson::encode($progress['manifest'])
                || $fence['manifest_sha256'] !== hash('sha256', CanonicalJson::encode($fence['manifest']))
            ) { throw new ModelViolation('pf_snapshot_incomplete'); }
            $manifest = $fence['manifest'];
            SnapshotDocument::manifest($manifest, $member, $this->epoch);
            $pages = $this->database->get_results($this->database->prepare('SELECT * FROM %i WHERE read_id=%s ORDER BY page_index FOR UPDATE', $this->tables['pages'], $readId), 'ARRAY_A');
            if ($this->database->last_error !== '' || count($pages) !== (int) $manifest['page_count']) {
                throw new ModelViolation('pf_snapshot_incomplete');
            }
            $rows = [];
            $next = null;
            foreach ($pages as $index => $stored) {
                $page = CanonicalJson::object($stored['payload_json'], 131072);
                SnapshotDocument::page($page, $manifest);
                if ($stored['payload_sha256'] !== hash('sha256', $stored['payload_json'])
                    || $stored['response_sha256'] !== hash('sha256', $stored['response_json'])
                    || $page['page_index'] !== (string) $index || ($index > 0 && $page['cursor'] !== $next)
                ) { throw new ModelViolation('pf_snapshot_incomplete'); }
                array_push($rows, ...$page['rows']);
                $next = $page['next_cursor'];
            }
            if ($next !== null) { throw new ModelViolation('pf_snapshot_incomplete'); }
            SnapshotDocument::complete($manifest, $rows);
            $old = $this->row('current', 'member_faluss_id=%s', [$member]);
            if ($old !== null && ($old['epoch'] !== $this->epoch || (int) $old['revision'] > (int) $manifest['revision']
                || ($old['revision'] === $manifest['revision'] && $old['full_sha256'] !== $manifest['full_sha256']))) {
                throw new ModelViolation('pf_snapshot_regression');
            }
            $current = ['member_faluss_id' => $member, 'read_id' => $readId, 'epoch' => $this->epoch,
                'revision' => $manifest['revision'], 'manifest_json' => CanonicalJson::encode($manifest),
                'rows_json' => CanonicalJson::encode($rows), 'full_sha256' => $manifest['full_sha256'],
                'proof_json' => $wire, 'proof_sha256' => hash('sha256', $wire), 'state' => 'current'];
            if ($old === null) { $this->insert('current', $current); }
            else { $this->update('current', $current, ['member_faluss_id' => $member]); }
            $progress['phase'] = 'current';
            return $this->save($row, $progress);
        });
    }

    /** A signed refusal invalidates exactness; transient/unknown responses preserve the checkpoint.
     * @return array<string,mixed> */
    public function refuse(string $member, string $readId): array
    {
        return $this->write($member, function () use ($member, $readId): array {
            $row = $this->requiredRead($member, $readId);
            $progress = $this->progress($row, $member);
            $progress['phase'] = 'refused';
            $this->query($this->database->prepare("UPDATE %i SET state='unavailable' WHERE member_faluss_id=%s AND revision<=%s",
                $this->tables['current'], $member, $progress['manifest']['revision'] ?? '0'));
            return $this->save($row, $progress);
        });
    }

    /** Fixture consumer of private evidence only; no browser endpoint or projection computation.
     * @return array{manifest:array<string,mixed>,rows:list<array<string,string>>}|null */
    public function current(string $member): ?array
    {
        return $this->write($member, function () use ($member): ?array {
            $row = $this->row('current', 'member_faluss_id=%s', [$member]);
            if ($row === null || $row['state'] !== 'current') { return null; }
            $manifest = CanonicalJson::object($row['manifest_json']);
            $rows = json_decode($row['rows_json'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($rows) || !array_is_list($rows) || $row['full_sha256'] !== hash('sha256', $row['rows_json'])
                || $row['proof_sha256'] !== hash('sha256', $row['proof_json'])) { throw new ModelViolation('pf_snapshot_incomplete'); }
            SnapshotDocument::manifest($manifest, $member, $this->epoch);
            SnapshotDocument::complete($manifest, $rows);
            return ['manifest' => $manifest, 'rows' => $rows];
        });
    }

    /** @param array<string,mixed> $row
     * @return array<string,mixed> */
    private function progress(array $row, string $member): array
    {
        $value = CanonicalJson::object($row['progress_json']);
        ModelValues::exactKeys($value, ['read_id', 'member_faluss_id', 'read_key', 'phase', 'manifest', 'next_page', 'next_cursor']);
        if ($row['member_faluss_id'] !== $member || $value['member_faluss_id'] !== $member || $row['read_id'] !== $value['read_id']
            || $row['read_key'] !== $value['read_key'] || $row['progress_sha256'] !== hash('sha256', $row['progress_json'])
            || !in_array($value['phase'], ['start', 'page', 'finish', 'current', 'refused'], true)) {
            throw new ModelViolation('pf_local_snapshot_conflict');
        }
        ModelValues::keyHash($value['read_key']);
        ModelValues::integer($value['next_page']);
        if ($value['manifest'] !== null) { SnapshotDocument::manifest($value['manifest'], $member, $this->epoch); }
        return $value;
    }

    /** @param array<string,mixed> $row
     * @param array<string,mixed> $progress
     * @return array<string,mixed> */
    private function save(array $row, array $progress): array
    {
        $bytes = CanonicalJson::encode($progress);
        if ($row['progress_json'] !== $bytes) {
            $this->update('reads', ['progress_json' => $bytes, 'progress_sha256' => hash('sha256', $bytes)], ['read_id' => $row['read_id']]);
        }
        return $progress;
    }

    /** @return array<string,mixed> */
    private function requiredRead(string $member, string $readId): array
    {
        ModelValues::uuid($readId);
        $row = $this->row('reads', 'read_id=%s AND member_faluss_id=%s', [$readId, $member]);
        if ($row === null) { throw new ModelViolation('pf_local_read_absent'); }
        return $row;
    }

    /** @template T
     * @param callable():T $operation
     * @return T */
    private function write(string $member, callable $operation): mixed
    {
        ModelValues::uuid($member);
        SnapshotEnvironment::assertIsolated($this->database, 'fans');
        if (!ClosedSnapshotInboxSchema::ready($this->database)) { throw new ModelViolation('pf_snapshot_schema_unavailable'); }
        $lock = 'fans_h4_' . substr(hash('sha256', $member), 0, 40);
        $held = $started = false;
        $suppressed = $this->database->suppress_errors(true);
        try {
            if ((string) $this->database->get_var('SELECT @@in_transaction') !== '0'
                || (string) $this->database->get_var($this->database->prepare('SELECT GET_LOCK(%s,10)', $lock)) !== '1') {
                throw new ModelViolation('pf_local_storage_unavailable');
            }
            $held = true;
            $this->query('START TRANSACTION');
            $started = true;
            $result = $operation();
            if ($this->database->query('COMMIT') === false || $this->database->last_error !== '') {
                throw new ModelViolation('pf_local_commit_unknown');
            }
            $started = false;
            return $result;
        } catch (Throwable $error) {
            if ($started) { $this->database->query('ROLLBACK'); }
            throw $error;
        } finally {
            $released = !$held || (string) $this->database->get_var($this->database->prepare('SELECT RELEASE_LOCK(%s)', $lock)) === '1';
            $this->database->suppress_errors($suppressed);
            if (!$released) { throw new ModelViolation('pf_local_commit_unknown'); }
        }
    }

    /** @param literal-string $where
     * @param list<mixed> $args
     * @return array<string,mixed>|null */
    private function row(string $kind, string $where, array $args): ?array
    {
        $row = $this->database->get_row($this->database->prepare('SELECT * FROM %i WHERE ' . $where . ' LIMIT 1 FOR UPDATE', $this->tables[$kind], ...$args), 'ARRAY_A');
        if ($this->database->last_error !== '') { throw new ModelViolation('pf_local_storage_unavailable'); }
        return $row;
    }

    /** @param array<string,mixed> $values */
    private function insert(string $kind, array $values): void
    {
        if ($this->database->insert($this->tables[$kind], $values) !== 1 || $this->database->last_error !== '') { throw new ModelViolation('pf_local_storage_unavailable'); }
    }

    /** @param array<string,mixed> $values
     * @param array<string,mixed> $where */
    private function update(string $kind, array $values, array $where): void
    {
        if ($this->database->update($this->tables[$kind], $values, $where) === false || $this->database->last_error !== '') { throw new ModelViolation('pf_local_storage_unavailable'); }
    }

    private function query(string $sql): void
    {
        if ($this->database->query($sql) === false || $this->database->last_error !== '') { throw new ModelViolation('pf_local_storage_unavailable'); }
    }
}
