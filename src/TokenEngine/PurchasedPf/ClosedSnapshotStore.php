<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SnapshotDocument;

/** Materialized private owner snapshots; pagination never reads a changing window. */
final class ClosedSnapshotStore
{
    private readonly ClosedReservationDatabase $connection;
    /** @var array<string,string> */
    private readonly array $tables;

    /** @param list<string> $sources */
    public function __construct(\wpdb $database, private readonly array $sources)
    {
        ClosedCorrectionEnvironment::assertIsolated($database);
        $this->connection = new ClosedReservationDatabase($database);
        $this->tables = ClosedSnapshotSchema::tables($database);
    }

    /** @return array<string,mixed> */
    public function create(string $member, string $client, string $key): array
    {
        self::context($member, $client);
        $hash = ModelValues::keyHash($key);
        return $this->connection->write($member, function () use ($member, $client, $hash): array {
            $this->assertSchema();
            $known = $this->connection->row($this->tables['snapshots'], 'member_faluss_id=%s AND client_authority=%s AND key_sha256=%s', [$member, $client, $hash]);
            if ($known !== null) {
                return $this->first($known);
            }
            $rows = $this->currentRows($member);
            $summary = SnapshotDocument::summary($rows);
            $counter = $this->connection->row($this->tables['counter'], 'id=%s', ['1']);
            if ($counter === null || (int) ModelValues::integer((string) $counter['revision']) >= ModelValues::MAX_INTEGER) {
                throw new ModelViolation('pf_snapshot_counter_unavailable');
            }
            $revision = (string) ((int) $counter['revision'] + 1);
            $id = wp_generate_uuid4();
            $bytes = CanonicalJson::encode($rows);
            $manifest = ['contract' => SnapshotDocument::CONTRACT, 'snapshot_id' => $id, 'issuer' => 'fixture.hub',
                'audience' => $client, 'member_faluss_id' => $member, 'epoch' => ModelValues::uuid($counter['epoch']),
                'revision' => $revision, 'full_sha256' => hash('sha256', $bytes), 'created_at' => gmdate('Y-m-d\TH:i:s\Z')] + $summary;
            SnapshotDocument::complete($manifest, $rows);
            $manifestBytes = CanonicalJson::encode($manifest);
            $snapshot = ['snapshot_id' => $id, 'client_authority' => $client, 'member_faluss_id' => $member,
                'key_sha256' => $hash, 'revision' => $revision, 'manifest_json' => $manifestBytes,
                'manifest_sha256' => hash('sha256', $manifestBytes), 'full_sha256' => $manifest['full_sha256'],
                'rows_json' => $bytes, 'recorded_at' => $this->connection->now()];
            $this->connection->insert($this->tables['snapshots'], $snapshot);
            $cursors = [];
            for ($page = 0; $page < (int) $summary['page_count']; $page++) {
                $cursors[] = wp_generate_uuid4();
            }
            foreach ($cursors as $index => $cursor) {
                $payload = ['manifest' => $manifest, 'page_index' => (string) $index, 'cursor' => $cursor,
                    'next_cursor' => $cursors[$index + 1] ?? null,
                    'rows' => array_slice($rows, $index * SnapshotDocument::PAGE_SIZE, SnapshotDocument::PAGE_SIZE)];
                $pageBytes = CanonicalJson::encode($payload);
                $this->connection->insert($this->tables['pages'], ['snapshot_id' => $id, 'page_index' => (string) $index,
                    'cursor' => $cursor, 'next_cursor' => $cursors[$index + 1] ?? null,
                    'payload_json' => $pageBytes, 'payload_sha256' => hash('sha256', $pageBytes)]);
            }
            $this->connection->update($this->tables['counter'], ['revision' => $revision], ['id' => '1']);
            return $this->first($snapshot);
        });
    }

    /** @return array<string,mixed> */
    public function page(string $member, string $client, string $id, string $cursor): array
    {
        self::context($member, $client);
        ModelValues::uuid($id);
        ModelValues::uuid($cursor);
        return $this->connection->write($member, function () use ($member, $client, $id, $cursor): array {
            $this->assertSchema();
            $snapshot = $this->snapshot($member, $client, $id);
            $page = $this->connection->row($this->tables['pages'], 'snapshot_id=%s AND `cursor`=%s', [$id, $cursor]);
            if ($page === null) {
                throw new ModelViolation('pf_snapshot_page_unavailable');
            }
            return $this->verifyPage($snapshot, $page);
        });
    }

    /** Final primary fence: a stale or incompletely reconciled Hub never attests a current snapshot.
     * @return array<string,mixed> */
    public function finish(string $member, string $client, string $id): array
    {
        self::context($member, $client);
        ModelValues::uuid($id);
        return $this->connection->write($member, function () use ($member, $client, $id): array {
            $this->assertSchema();
            $snapshot = $this->snapshot($member, $client, $id);
            $manifest = $this->manifest($snapshot);
            $pages = $this->connection->rows($this->connection->database->prepare('SELECT * FROM %i WHERE snapshot_id=%s ORDER BY page_index', $this->tables['pages'], $id));
            if (count($pages) !== (int) $manifest['page_count']) {
                throw new ModelViolation('pf_snapshot_incomplete');
            }
            $rows = [];
            foreach ($pages as $index => $page) {
                $payload = $this->verifyPage($snapshot, $page);
                if ($payload['page_index'] !== (string) $index || $payload['next_cursor'] !== ($pages[$index + 1]['cursor'] ?? null)) {
                    throw new ModelViolation('pf_snapshot_incomplete');
                }
                array_push($rows, ...$payload['rows']);
            }
            SnapshotDocument::complete($manifest, $rows);
            $current = $this->currentRows($member);
            if (!hash_equals($manifest['full_sha256'], hash('sha256', CanonicalJson::encode($current)))) {
                throw new ModelViolation('pf_snapshot_superseded');
            }
            return ['state' => 'current', 'manifest' => $manifest, 'manifest_sha256' => hash('sha256', CanonicalJson::encode($manifest))];
        });
    }

    /** @return list<array<string,string>> */
    public function currentRows(string $member): array
    {
        $this->connection->assertHeldSubject($member);
        $database = $this->connection->database;
        $h1 = ClosedModelSchema::tables($database);
        $h2 = ClosedReservationSchema::tables($database);
        $h2c = ClosedConsumptionSchema::tables($database);
        $facts = new ClosedCorrectionFacts($this->connection, $this->sources);
        $corrections = new ClosedCorrectionStore($database, $this->sources);
        $credits = $this->connection->rows($database->prepare('SELECT * FROM %i WHERE member_faluss_id=%s ORDER BY lot_id FOR UPDATE', $h2['credits'], $member));
        $rows = [];
        foreach ($credits as $credit) {
            $lotId = (string) $credit['lot_id'];
            $lot = $facts->lot($lotId, $member);
            $plan = $corrections->verifiedLotPlan($lotId, $member);
            $allocations = $facts->allocations($lotId, $member);
            $used = $cancelled = 0;
            foreach ($allocations as $allocation) {
                $record = $this->connection->row($h2c['consumptions'], 'attribution_id=%s', [$allocation['attribution_id']]);
                if ($record === null || $record['client_authority'] !== 'fixture.fans') {
                    throw new ModelViolation('pf_snapshot_missing_filiation');
                }
                $targets = $plan === null ? [] : array_values(array_filter($plan['plan']['rows'], static fn (array $a): bool => $a['attribution_id'] === $allocation['attribution_id']));
                $target = $targets[0] ?? ['cancelled_pf' => '0', 'suspended_pf' => '0', 'net_pf' => $allocation['purchased_pf']];
                $used += (int) $allocation['purchased_pf'];
                $cancelled += (int) $target['cancelled_pf'];
                $rows[] = ['kind' => 'allocation', 'lot_id' => $lotId, 'source_revision' => (string) $lot['source_revision'],
                    'original_pf' => $allocation['purchased_pf'], 'cancelled_pf' => $target['cancelled_pf'],
                    'attribution_id' => $allocation['attribution_id'], 'consumption_id' => (string) $record['consumption_id'],
                    'member_faluss_id' => $member, 'creator_faluss_id' => (string) $record['creator_faluss_id'],
                    'client_authority' => (string) $record['client_authority'], 'attribution_original_pf' => (string) $record['purchased_pf'],
                    'suspended_pf' => $target['suspended_pf'], 'net_pf' => $target['net_pf'],
                    'confirmed_at' => $allocation['confirmed_at'], 'ledger_entry_uuid' => (string) $record['ledger_entry_uuid'],
                    'ledger_fact_sha256' => (string) $record['payload_sha256']];
            }
            $proof = $this->connection->row($h1['evidence'], 'evidence_id=%s', [$lot['latest_evidence_id']]);
            if ($proof === null) {
                throw new ModelViolation('pf_snapshot_missing_filiation');
            }
            $source = PurchaseEvidence::fromArray(ClosedReservationDatabase::decode((string) $proof['payload_json']));
            $availableCancelled = (int) ($plan['plan']['available_cancelled_pf'] ?? '0');
            $rows[] = ['kind' => 'lot', 'lot_id' => $lotId, 'source_revision' => (string) $lot['source_revision'],
                'original_pf' => (string) $lot['purchased_pf'], 'cancelled_pf' => $source->values['cancelled_purchased_pf_cumulative'],
                'state' => (string) $lot['source_state'], 'available_cancelled_pf' => (string) $availableCancelled,
                'allocated_original_pf' => (string) $used, 'allocated_cancelled_pf' => (string) $cancelled,
                'available_pf' => (string) ((int) $lot['purchased_pf'] - $used - $availableCancelled),
                'source_evidence_id' => (string) $lot['latest_evidence_id'], 'source_evidence_sha256' => $source->fingerprint()];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp(SnapshotDocument::order($a), SnapshotDocument::order($b)));
        $summary = SnapshotDocument::summary($rows);
        if ((int) $summary['available_pf'] !== (new ClosedLedgerWriter($this->connection))->balance($member)) {
            throw new ModelViolation('h4_balance_mismatch');
        }
        return $rows;
    }

    private static function context(string $member, string $client): void
    {
        ModelValues::uuid($member);
        if ($client !== 'fixture.fans') {
            throw new ModelViolation('pf_permission_denied');
        }
    }

    private function assertSchema(): void
    {
        if (!ClosedSnapshotSchema::ready($this->connection->database)) {
            throw new ModelViolation('pf_snapshot_schema_unavailable');
        }
    }

    /** @return array<string,mixed> */
    private function snapshot(string $member, string $client, string $id): array
    {
        $row = $this->connection->row($this->tables['snapshots'], 'snapshot_id=%s AND member_faluss_id=%s AND client_authority=%s', [$id, $member, $client]);
        if ($row === null) {
            throw new ModelViolation('pf_snapshot_unavailable');
        }
        $this->manifest($row);
        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function first(array $row): array
    {
        $page = $this->connection->row($this->tables['pages'], 'snapshot_id=%s AND page_index=%s', [$row['snapshot_id'], '0']);
        if ($page === null) {
            throw new ModelViolation('pf_snapshot_incomplete');
        }
        return $this->verifyPage($row, $page);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function manifest(array $row): array
    {
        $manifest = CanonicalJson::object((string) $row['manifest_json']);
        SnapshotDocument::manifest($manifest, (string) $row['member_faluss_id'], $manifest['epoch']);
        if ($row['manifest_sha256'] !== hash('sha256', (string) $row['manifest_json'])
            || $row['revision'] !== $manifest['revision'] || $row['snapshot_id'] !== $manifest['snapshot_id']
            || $row['full_sha256'] !== $manifest['full_sha256'] || $row['full_sha256'] !== hash('sha256', (string) $row['rows_json'])
        ) {
            throw new ModelViolation('pf_snapshot_digest_mismatch');
        }
        return $manifest;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $page
     * @return array<string,mixed>
     */
    private function verifyPage(array $snapshot, array $page): array
    {
        $payload = CanonicalJson::object((string) $page['payload_json'], 131072);
        $manifest = $this->manifest($snapshot);
        SnapshotDocument::page($payload, $manifest);
        if ($page['payload_sha256'] !== hash('sha256', (string) $page['payload_json']) || $payload['page_index'] !== $page['page_index']
            || $payload['cursor'] !== $page['cursor'] || $payload['next_cursor'] !== $page['next_cursor']
        ) {
            throw new ModelViolation('pf_snapshot_digest_mismatch');
        }
        return $payload;
    }
}
