<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedIntent;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedReceipt;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingBarrier;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingCorpusDocument;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingValues;

/** Exhaustive primary owner read; no member list supplied by Fans and no second consumption. */
final class ClosedRankingCorpusSource
{
    // Conservative closed implementation bounds, not product limits or production sizing proof.
    public const MAX_OWNER_FACTS = 1000;
    public const MAX_BYTES = 33554432;
    private readonly ClosedReservationDatabase $connection;

    /** @param list<string> $sources */
    public function __construct(private readonly \wpdb $db, private readonly array $sources, private readonly string $keyId)
    { ClosedEnvironment::assertIsolated($db,'hub'); $this->connection = new ClosedReservationDatabase($db); }

    /** @return array<string,mixed> */
    public function read(PeerPolicy $peer, string $origin, string $policy): array
    {
        self::peer($peer); ModelValues::uuid($origin); ModelValues::version($policy);
        return (new ClosedCorpusTransaction($this->db))->run(fn (): array => $this->readInOwnerTransaction($peer,$origin,$policy));
    }

    /** Future materialization/fence reuse exactly this owner authority without nested transactions.
     * @return array<string,mixed> */
    public function readInOwnerTransaction(PeerPolicy $peer, string $origin, string $policy): array
    {
        self::peer($peer); ModelValues::uuid($origin); ModelValues::version($policy); ClosedCorpusTransaction::assertActive($this->db);
        $this->origin($peer,$origin,$policy); $order = ClosedRankingContext::orderInCorpusTransaction($this->connection);
        if ((int) $order['last_order'] > self::MAX_OWNER_FACTS) { throw new ModelViolation('pf_corpus_capacity_unvalidated'); }
        $tables = ClosedRankingSchema::tables($this->db); $h2 = ClosedReservationSchema::tables($this->db);
        $missing = $this->connection->rows($this->db->prepare("SELECT b.attribution_id FROM %i b JOIN %i r ON r.attribution_id=b.attribution_id LEFT JOIN %i f ON f.attribution_id=b.attribution_id WHERE r.state='confirmed' AND f.attribution_id IS NULL LIMIT 1 FOR UPDATE",
            $tables['bindings'],$h2['reservations'],$tables['receipts']));
        if ($missing !== []) { throw new ModelViolation('pf_corpus_missing_filiation'); }
        $receipts = $this->connection->rows($this->db->prepare('SELECT * FROM %i ORDER BY consumption_order FOR UPDATE',$tables['receipts']));
        if (count($receipts) !== (int) $order['last_order']) { throw new ModelViolation('pf_ranking_counter_divergent'); }
        $facts = []; $members = [];
        foreach ($receipts as $row) {
            $binding = $this->connection->row($tables['bindings'],'attribution_id=%s',[$row['attribution_id']]);
            if ($binding === null) { throw new ModelViolation('pf_corpus_missing_filiation'); }
            $intent = RankedIntent::fromArray(CanonicalJson::object($binding['payload_json']));
            (new ClosedRankingContext($this->db,$intent,$this->keyId))->assertBoundForOwnerRead();
            $payload = CanonicalJson::object($row['payload_json']); RankedReceipt::validate($payload,'fixture.hub','fixture.fans',$intent);
            foreach (['attribution_id','receipt_id','confirmed_at','ordering_epoch','consumption_order'] as $field) {
                if ($row[$field] !== $payload[$field]) { throw new ModelViolation('pf_corpus_missing_filiation'); }
            }
            if ($row['payload_sha256'] !== hash('sha256',$row['payload_json'])) { throw new ModelViolation('pf_corpus_missing_filiation'); }
            if ($intent->values['ranking_context']['origin_id'] !== $origin) { continue; }
            if ($intent->values['ranking_context']['policy_version'] !== $policy) { throw new ModelViolation('pf_corpus_context_mismatch'); }
            $member = $intent->base->values['member_faluss_id'];
            if (!isset($members[$member])) { $members[$member] = (new ClosedSnapshotStore($this->db,$this->sources))->currentRowsForCorpus($member); }
            $consumption = (new ClosedConsumptionStore($this->db,['fixture.fans'],$this->sources))->confirmedFact($intent->base);
            $original = (new ClosedRankedReceiptStore($this->db,$this->keyId))->historicalFactInCorpusTransaction($intent,$consumption);
            $facts[] = $this->fact($original,$members[$member]);
        }
        RankingCorpusDocument::summary($facts); $now = RankingValues::utc($this->connection->now());
        if ($order['last_confirmed_at'] !== '' && $now < $order['last_confirmed_at']) { throw new ModelViolation('pf_primary_clock_regression'); }
        if (strlen(CanonicalJson::encode($facts)) > self::MAX_BYTES) { throw new ModelViolation('pf_corpus_capacity_unvalidated'); }
        return ['ordering_epoch' => $order['ordering_epoch'],'last_order' => $order['last_order'],'created_at' => $now,'facts' => $facts];
    }

    private static function peer(PeerPolicy $peer): void
    { $peer->allow('pf.ranking.corpus'); if ($peer->node !== 'fixture.fans' || $peer->audience !== 'fixture.hub') { throw new ModelViolation('pf_invalid_peer'); } }

    private function origin(PeerPolicy $peer, string $origin, string $policy): void
    {
        $ref = RankingBarrier::reference(['kind' => 'origin','origin_id' => $origin,'object_id' => $origin,'subject_id' => '', 'version' => '1','policy_version' => $policy]);
        $row = $this->connection->row(ClosedBarrierSchema::tables($this->db)['barriers'],'barrier_key=%s AND version=%s',[$ref['barrier_key'],'1']);
        if ($row === null || $row['owner'] !== $peer->node || !in_array($row['state'],['active','closed'],true)
            || $row['content_sha256'] !== $ref['content_sha256'] || $row['descriptor_sha256'] !== hash('sha256',$row['descriptor_json'])) { throw new ModelViolation('pf_corpus_origin_not_admitted'); }
        $descriptor = RankingBarrier::descriptor(CanonicalJson::object($row['descriptor_json']));
        if (CanonicalJson::encode($descriptor['content']) !== CanonicalJson::encode($ref['content'])) { throw new ModelViolation('pf_corpus_origin_not_admitted'); }
        // Historical read stays possible after origin closure; no re-opening of choices.
    }

    /** @param array<string,mixed> $original
     * @param list<array<string,string>> $rows
     * @return array<string,mixed> */
    private function fact(array $original, array $rows): array
    {
        $lots = []; $allocations = []; $cancelled = $suspended = $net = 0; $ledgerDigest = null;
        foreach ($rows as $row) { if ($row['kind'] === 'lot') { $lots[$row['lot_id']] = $row; } }
        foreach ($original['allocations'] as $allocation) {
            $matches = array_values(array_filter($rows,static fn (array $r): bool => $r['kind'] === 'allocation'
                && $r['lot_id'] === $allocation['lot_id'] && $r['attribution_id'] === $original['attribution_id']));
            $lot = $lots[$allocation['lot_id']] ?? null;
            if (count($matches) !== 1 || $lot === null) { throw new ModelViolation('pf_corpus_missing_filiation'); }
            $row = $matches[0];
            foreach (['attribution_id','member_faluss_id','creator_faluss_id','confirmed_at','ledger_entry_uuid'] as $field) {
                if ($row[$field] !== $original[$field]) { throw new ModelViolation('pf_corpus_missing_filiation'); }
            }
            $ledgerDigest ??= $row['ledger_fact_sha256'];
            if ($row['original_pf'] !== $allocation['purchased_pf'] || $row['attribution_original_pf'] !== $original['purchased_pf']
                || $row['consumption_id'] !== $original['receipt_id'] || $ledgerDigest !== $row['ledger_fact_sha256']
                || $row['source_revision'] !== $lot['source_revision']) { throw new ModelViolation('pf_corpus_missing_filiation'); }
            $allocations[] = ['lot_id' => $row['lot_id'],'source_revision' => $lot['source_revision'],'source_state' => $lot['state'],
                'source_evidence_id' => $lot['source_evidence_id'],'source_evidence_sha256' => $lot['source_evidence_sha256'],
                'original_pf' => $row['original_pf'],'cancelled_pf' => $row['cancelled_pf'],'suspended_pf' => $row['suspended_pf'],'net_pf' => $row['net_pf']];
            $cancelled += (int) $row['cancelled_pf']; $suspended += (int) $row['suspended_pf']; $net += (int) $row['net_pf'];
        }
        usort($allocations,static fn (array $a,array $b): int => strcmp($a['lot_id'],$b['lot_id']));
        $fact = array_intersect_key($original,array_flip(['attribution_id','member_faluss_id','creator_faluss_id','purchased_pf','confirmed_at',
            'consumption_order','ordering_epoch','ranking_context','context_sha256','ledger_entry_uuid']))
            + ['consumption_id' => $original['receipt_id'],'client_authority' => 'fixture.fans','ledger_fact_sha256' => $ledgerDigest,
                'cancelled_pf' => (string) $cancelled,'suspended_pf' => (string) $suspended,'net_pf' => (string) $net,'allocations' => $allocations];
        RankingCorpusDocument::fact($fact); return $fact;
    }
}
