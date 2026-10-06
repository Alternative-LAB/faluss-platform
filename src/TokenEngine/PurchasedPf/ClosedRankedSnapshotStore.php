<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedIntent;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedSnapshotDocument;

/** Member snapshots 2.0 from complete H4 facts and the immutable owner receipt. */
final class ClosedRankedSnapshotStore
{
    private readonly ClosedReservationDatabase $connection;
    /** @var array<string,string> */
    private readonly array $tables;

    /** @param list<string> $sources */
    public function __construct(private readonly \wpdb $db, private readonly array $sources, private readonly string $keyId)
    { ClosedEnvironment::assertIsolated($db,'hub'); $this->connection = new ClosedReservationDatabase($db); $this->tables = ClosedRankedSnapshotSchema::tables($db); }

    /** @return array<string,mixed> */
    public function create(string $member, string $client, string $key): array
    {
        self::context($member,$client); $hash = ModelValues::keyHash($key);
        return $this->connection->write($member,function () use ($member,$client,$hash): array {
            $this->available();
            $known = $this->connection->row($this->tables['snapshots'],'member_faluss_id=%s AND client_authority=%s AND key_sha256=%s',[$member,$client,$hash]);
            if ($known !== null) { return $this->first($known); }
            $order = ClosedRankingContext::orderInOwnerTransaction($this->connection,$member);
            $rows = $this->currentRows($member); $summary = RankedSnapshotDocument::summary($rows);
            $counter = $this->counter();
            if ((int) $counter['revision'] >= ModelValues::MAX_INTEGER) { throw new ModelViolation('pf_snapshot_counter_unavailable'); }
            $revision = (string) ((int) $counter['revision'] + 1); $id = wp_generate_uuid4(); $bytes = CanonicalJson::encode($rows);
            $manifest = ['contract' => RankedSnapshotDocument::CONTRACT,'snapshot_id' => $id,'issuer' => 'fixture.hub','audience' => $client,
                'member_faluss_id' => $member,'epoch' => ModelValues::uuid($counter['epoch']),'revision' => $revision,
                'ordering_epoch' => $order['ordering_epoch'],'full_sha256' => hash('sha256',$bytes),'created_at' => gmdate('Y-m-d\TH:i:s\Z')] + $summary;
            RankedSnapshotDocument::complete($manifest,$rows); $manifestBytes = CanonicalJson::encode($manifest);
            $snapshot = ['snapshot_id' => $id,'client_authority' => $client,'member_faluss_id' => $member,'key_sha256' => $hash,
                'epoch' => $counter['epoch'],'revision' => $revision,'manifest_json' => $manifestBytes,'manifest_sha256' => hash('sha256',$manifestBytes),
                'full_sha256' => $manifest['full_sha256'],'rows_json' => $bytes,'recorded_at' => $this->connection->now()];
            $this->connection->insert($this->tables['snapshots'],$snapshot); $cursors = [];
            for ($page = 0; $page < (int) $summary['page_count']; $page++) { $cursors[] = wp_generate_uuid4(); }
            foreach ($cursors as $index => $cursor) {
                $payload = ['manifest' => $manifest,'page_index' => (string) $index,'cursor' => $cursor,'next_cursor' => $cursors[$index + 1] ?? null,
                    'rows' => array_slice($rows,$index * RankedSnapshotDocument::PAGE_SIZE,RankedSnapshotDocument::PAGE_SIZE)];
                $pageBytes = CanonicalJson::encode($payload);
                $this->connection->insert($this->tables['pages'],['snapshot_id' => $id,'page_index' => (string) $index,'cursor' => $cursor,
                    'next_cursor' => $cursors[$index + 1] ?? null,'payload_json' => $pageBytes,'payload_sha256' => hash('sha256',$pageBytes)]);
            }
            $this->connection->update($this->tables['counter'],['revision' => $revision],['id' => '1']); return $this->first($snapshot);
        });
    }

    /** @return array<string,mixed> */
    public function page(string $member, string $client, string $id, string $cursor): array
    {
        self::context($member,$client); ModelValues::uuid($id); ModelValues::uuid($cursor);
        return $this->connection->write($member,function () use ($member,$client,$id,$cursor): array {
            $this->available(); $snapshot = $this->snapshot($member,$client,$id);
            $page = $this->connection->row($this->tables['pages'],'snapshot_id=%s AND `cursor`=%s',[$id,$cursor]);
            if ($page === null) { throw new ModelViolation('pf_snapshot_page_unavailable'); }
            return $this->verifyPage($snapshot,$page);
        });
    }

    /** Final primary fence, not a promise of freshness after this transaction.
     * @return array<string,mixed> */
    public function finish(string $member, string $client, string $id): array
    {
        self::context($member,$client); ModelValues::uuid($id);
        return $this->connection->write($member,function () use ($member,$client,$id): array {
            $this->available(); $snapshot = $this->snapshot($member,$client,$id); $manifest = $this->manifest($snapshot);
            if ($this->counter()['epoch'] !== $manifest['epoch']) { throw new ModelViolation('pf_snapshot_counter_divergent'); }
            $pages = $this->connection->rows($this->db->prepare('SELECT * FROM %i WHERE snapshot_id=%s ORDER BY page_index',$this->tables['pages'],$id));
            if (count($pages) !== (int) $manifest['page_count']) { throw new ModelViolation('pf_snapshot_incomplete'); }
            $rows = [];
            foreach ($pages as $index => $page) {
                $payload = $this->verifyPage($snapshot,$page);
                if ($payload['page_index'] !== (string) $index || $payload['next_cursor'] !== ($pages[$index + 1]['cursor'] ?? null)) { throw new ModelViolation('pf_snapshot_incomplete'); }
                array_push($rows,...$payload['rows']);
            }
            RankedSnapshotDocument::complete($manifest,$rows);
            $order = ClosedRankingContext::orderInOwnerTransaction($this->connection,$member);
            if ($order['ordering_epoch'] !== $manifest['ordering_epoch']) { throw new ModelViolation('pf_snapshot_ranking_epoch'); }
            if (!hash_equals($manifest['full_sha256'],hash('sha256',CanonicalJson::encode($this->currentRows($member))))) { throw new ModelViolation('pf_snapshot_superseded'); }
            return ['state' => 'current','manifest' => $manifest,'manifest_sha256' => hash('sha256',CanonicalJson::encode($manifest))];
        });
    }

    /** Narrow owner composition only; old allocations remain explicitly without ranking authority.
     * @return list<array<string,mixed>> */
    private function currentRows(string $member): array
    {
        $this->connection->assertHeldSubject($member);
        $rows = (new ClosedSnapshotStore($this->db,$this->sources))->currentRows($member);
        $bindings = ClosedRankingSchema::tables($this->db)['bindings']; $receipts = ClosedRankingSchema::tables($this->db)['receipts'];
        $facts = []; $result = [];
        foreach ($rows as $row) {
            if ($row['kind'] !== 'allocation') { $result[] = $row; continue; }
            $id = $row['attribution_id'];
            if (!array_key_exists($id,$facts)) {
                $binding = $this->connection->row($bindings,'attribution_id=%s',[$id]);
                if ($binding === null) {
                    if ($this->connection->row($receipts,'attribution_id=%s',[$id]) !== null) { throw new ModelViolation('pf_snapshot_ranking_filiation'); }
                    $facts[$id] = null;
                } else {
                    $intent = RankedIntent::fromArray(CanonicalJson::object($binding['payload_json']));
                    $consumption = (new ClosedConsumptionStore($this->db,['fixture.fans'],$this->sources))->confirmedFact($intent->base);
                    $facts[$id] = (new ClosedRankedReceiptStore($this->db,$this->keyId))->historicalFactInOwnerTransaction($intent,$consumption);
                }
            }
            $fact = $facts[$id]; $ranking = null;
            if ($fact !== null) {
                foreach (['attribution_id','member_faluss_id','creator_faluss_id','confirmed_at','ledger_entry_uuid'] as $field) {
                    if ($fact[$field] !== $row[$field]) { throw new ModelViolation('pf_snapshot_ranking_filiation'); }
                }
                if ($fact['receipt_id'] !== $row['consumption_id'] || $fact['purchased_pf'] !== $row['attribution_original_pf']) { throw new ModelViolation('pf_snapshot_ranking_filiation'); }
                $allocation = array_values(array_filter($fact['allocations'],static fn (array $a): bool => $a['lot_id'] === $row['lot_id']));
                if (count($allocation) !== 1 || $allocation[0]['purchased_pf'] !== $row['original_pf']) { throw new ModelViolation('pf_snapshot_ranking_filiation'); }
                $ranking = array_intersect_key($fact,array_flip(['ordering_epoch','consumption_order','ranking_context','context_sha256']));
            }
            $result[] = $row + ['ranking' => $ranking];
        }
        return $result;
    }

    private static function context(string $member, string $client): void
    { ModelValues::uuid($member); if ($client !== 'fixture.fans') { throw new ModelViolation('pf_permission_denied'); } }

    private function available(): void
    {
        if (!ClosedRankedSnapshotSchema::ready($this->db) || !ClosedRankingSchema::ready($this->db) || !ClosedSnapshotSchema::ready($this->db)) { throw new ModelViolation('pf_snapshot_schema_unavailable'); }
    }

    /** Refuse a contradictory restored generation rather than assigning a new authority.
     * @return array{epoch:string,revision:string} */
    private function counter(): array
    {
        $counter = $this->connection->row($this->tables['counter'],'id=%s',['1']);
        if ($counter === null) { throw new ModelViolation('pf_snapshot_counter_unavailable'); }
        $epoch = ModelValues::uuid($counter['epoch']); $revision = ModelValues::integer($counter['revision']);
        $count = $this->connection->scalar($this->db->prepare('SELECT COUNT(*) FROM %i',$this->tables['snapshots']));
        $inEpoch = $this->connection->scalar($this->db->prepare('SELECT COUNT(*) FROM %i WHERE epoch=%s AND revision BETWEEN 1 AND %d',$this->tables['snapshots'],$epoch,(int) $revision));
        if ((string) $count !== $revision || (string) $inEpoch !== $revision) { throw new ModelViolation('pf_snapshot_counter_divergent'); }
        return ['epoch' => $epoch,'revision' => $revision];
    }

    /** @return array<string,mixed> */
    private function snapshot(string $member, string $client, string $id): array
    {
        $row = $this->connection->row($this->tables['snapshots'],'snapshot_id=%s AND member_faluss_id=%s AND client_authority=%s',[$id,$member,$client]);
        if ($row === null) { throw new ModelViolation('pf_snapshot_unavailable'); }
        $this->manifest($row); return $row;
    }

    /** @param array<string,mixed> $row
     * @return array<string,mixed> */
    private function first(array $row): array
    {
        $page = $this->connection->row($this->tables['pages'],'snapshot_id=%s AND page_index=%s',[$row['snapshot_id'],'0']);
        if ($page === null) { throw new ModelViolation('pf_snapshot_incomplete'); }
        return $this->verifyPage($row,$page);
    }

    /** @param array<string,mixed> $row
     * @return array<string,mixed> */
    private function manifest(array $row): array
    {
        $manifest = CanonicalJson::object($row['manifest_json']); RankedSnapshotDocument::manifest($manifest,$row['member_faluss_id'],$manifest['epoch']);
        if ($row['manifest_sha256'] !== hash('sha256',$row['manifest_json']) || $row['full_sha256'] !== hash('sha256',$row['rows_json'])
            || $row['snapshot_id'] !== $manifest['snapshot_id'] || $row['revision'] !== $manifest['revision'] || $row['full_sha256'] !== $manifest['full_sha256']
            || $row['epoch'] !== $manifest['epoch'] || $row['client_authority'] !== $manifest['audience']) { throw new ModelViolation('pf_snapshot_digest_mismatch'); }
        return $manifest;
    }

    /** @param array<string,mixed> $snapshot
     * @param array<string,mixed> $page
     * @return array<string,mixed> */
    private function verifyPage(array $snapshot, array $page): array
    {
        $payload = CanonicalJson::object($page['payload_json'],1048576); $manifest = $this->manifest($snapshot); RankedSnapshotDocument::page($payload,$manifest);
        if ($page['payload_sha256'] !== hash('sha256',$page['payload_json']) || $page['page_index'] !== $payload['page_index']
            || $page['cursor'] !== $payload['cursor'] || $page['next_cursor'] !== $payload['next_cursor'] || $page['snapshot_id'] !== $manifest['snapshot_id']) { throw new ModelViolation('pf_snapshot_digest_mismatch'); }
        return $payload;
    }
}
