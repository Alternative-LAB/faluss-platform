<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedIntent;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedReceipt;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SignedEnvelope;

/** Signed historical facts and pending delivery join the debit transaction; no current score. */
final class ClosedRankedReceiptStore
{
    private readonly ClosedReservationDatabase $connection;
    /** @var array<string,string> */
    private readonly array $tables;
    public function __construct(private readonly \wpdb $db, private readonly string $keyId)
    { ClosedEnvironment::assertIsolated($db,'hub'); $this->connection = new ClosedReservationDatabase($db); $this->tables = ClosedRankingSchema::tables($db); }

    /** @param array<string,mixed> $consumption
     * @param array{ordering_epoch:string,consumption_order:string,confirmed_at:string} $order */
    public function record(RankedIntent $intent, array $consumption, array $order): void
    {
        $this->connection->assertHeldSubject($intent->base->values['member_faluss_id']);
        (new ClosedRankingContext($this->db,$intent,$this->keyId))->guard($intent->base,'confirm');
        $this->owner($intent,$consumption);
        if ($consumption['confirmed_at'] !== $order['confirmed_at']) { throw new ModelViolation('pf_receipt_integrity'); }
        $counter = $this->connection->row($this->tables['counter'],'id=%s',['1']);
        $count = $this->connection->scalar($this->db->prepare('SELECT COUNT(*) FROM %i',$this->tables['receipts']));
        if ($counter === null || $counter['ordering_epoch'] !== $order['ordering_epoch'] || $counter['last_order'] !== $order['consumption_order']
            || $counter['last_confirmed_at'] !== $order['confirmed_at'] || (int) $count !== (int) $order['consumption_order'] - 1) {
            throw new ModelViolation('pf_ranking_order_not_staged');
        }
        $receipt = array_intersect_key($intent->values,array_flip(['attribution_id','member_faluss_id','creator_faluss_id','purchased_pf','policy_version','ranking_context','context_sha256']))
            + ['contract' => RankedIntent::CONTRACT,'kind' => SignedEnvelope::RANKING_RECEIPT,'issuer' => 'fixture.hub','audience' => 'fixture.fans',
                'receipt_id' => $consumption['consumption_id'],'revision' => '1','reservation_id' => $consumption['reservation_id'],
                'ledger_entry_uuid' => $consumption['ledger_entry_uuid'],'allocations' => []] + $order;
        $lots = new ClosedReservationStore($this->db,['fixture.fans'],['fixture.purchase']);
        foreach ($consumption['allocations'] as $allocation) {
            $lot = $lots->sourceLot($allocation['lot_id'],$intent->base->values['member_faluss_id']);
            $receipt['allocations'][] = $allocation + ['allocation_id' => hash('sha256',$intent->base->values['attribution_id'] . '|' . $allocation['lot_id']),
                'purchase_authority' => $lot['authority_id'],'purchase_reference' => $lot['purchase_id']];
        }
        usort($receipt['allocations'],static fn (array $a,array $b): int => strcmp($a['allocation_id'],$b['allocation_id']));
        RankedReceipt::validate($receipt,'fixture.hub','fixture.fans',$intent); $bytes = CanonicalJson::encode($receipt);
        $envelope = SignedEnvelope::seal(SignedEnvelope::RANKING_RECEIPT,$bytes,$this->keyId);
        $this->connection->insert($this->tables['receipts'],['attribution_id' => $intent->base->values['attribution_id'],'receipt_id' => $receipt['receipt_id'],
            'ordering_epoch' => $order['ordering_epoch'],'consumption_order' => $order['consumption_order'],'confirmed_at' => $order['confirmed_at'],
            'payload_json' => $bytes,'payload_sha256' => hash('sha256',$bytes),'original_envelope_json' => CanonicalJson::encode($envelope)]);
        $this->connection->insert($this->tables['journal'],['event_id' => $consumption['event_id'],'attribution_id' => $intent->base->values['attribution_id'],
            'payload_json' => $bytes,'payload_sha256' => hash('sha256',$bytes),'state' => 'pending','recorded_at' => $order['confirmed_at']]);
    }

    /** @param array<string,mixed> $consumption
     * @return array<string,string> */
    public function receipt(RankedIntent $intent, array $consumption): array
    {
        return $this->connection->write($intent->base->values['member_faluss_id'],fn (): array => SignedEnvelope::seal(
            SignedEnvelope::RANKING_RECEIPT,CanonicalJson::encode($this->historicalFactInOwnerTransaction($intent,$consumption)),$this->keyId));
    }

    /** Owner read only, including after corrections/closure; never a current net.
     * @param array<string,mixed> $consumption
     * @return array<string,mixed> */
    public function historicalFactInOwnerTransaction(RankedIntent $intent, array $consumption): array
    {
        $this->connection->assertHeldSubject($intent->base->values['member_faluss_id']);
        (new ClosedRankingContext($this->db,$intent,$this->keyId))->guard($intent->base,'lookup'); $this->owner($intent,$consumption);
        $row = $this->connection->row($this->tables['receipts'],'attribution_id=%s',[$intent->base->values['attribution_id']]);
        $journal = $this->connection->row($this->tables['journal'],'event_id=%s',[$consumption['event_id']]);
        if ($row === null || $journal === null || $row['payload_sha256'] !== hash('sha256',$row['payload_json'])
            || $journal['payload_json'] !== $row['payload_json'] || $journal['payload_sha256'] !== $row['payload_sha256']
            || $journal['state'] !== 'pending' || $journal['attribution_id'] !== $intent->base->values['attribution_id']
            || $journal['recorded_at'] !== $row['confirmed_at']) { throw new ModelViolation('pf_receipt_integrity'); }
        $payload = CanonicalJson::object($row['payload_json']); RankedReceipt::validate($payload,'fixture.hub','fixture.fans',$intent);
        foreach (['ordering_epoch','consumption_order','confirmed_at','receipt_id','attribution_id'] as $field) {
            if ($row[$field] !== ($payload[$field] ?? null)) { throw new ModelViolation('pf_receipt_integrity'); }
        }
        foreach (['ledger_entry_uuid','confirmed_at','reservation_id'] as $field) {
            if ($consumption[$field] !== $payload[$field]) { throw new ModelViolation('pf_receipt_integrity'); }
        }
        if ($payload['receipt_id'] !== $consumption['consumption_id']) { throw new ModelViolation('pf_receipt_integrity'); }
        $allocations = array_map(static fn (array $allocation): array => array_intersect_key($allocation,array_flip(['lot_id','evidence_id','source_revision','purchased_pf'])),$payload['allocations']);
        usort($allocations,static fn (array $a,array $b): int => strcmp($a['lot_id'],$b['lot_id']));
        if (CanonicalJson::encode($allocations) !== CanonicalJson::encode($consumption['allocations'])) { throw new ModelViolation('pf_receipt_integrity'); }
        $original = CanonicalJson::object($row['original_envelope_json']);
        if (($original['payload_sha256'] ?? null) !== $row['payload_sha256'] || SignedEnvelope::decode($original['payload_base64url'] ?? '') !== $row['payload_json']) { throw new ModelViolation('pf_receipt_integrity'); }
        return $payload;
    }

    /** @param array<string,mixed> $consumption */
    private function owner(RankedIntent $intent, array $consumption): void
    {
        if (!ClosedRankingSchema::ready($this->db)) { throw new ModelViolation('pf_ranking_schema_unavailable'); }
        $row = $this->connection->row(ClosedConsumptionSchema::tables($this->db)['consumptions'],'attribution_id=%s',[$intent->base->values['attribution_id']]);
        $bytes = ModelValues::encode($consumption);
        if ($row === null || $row['payload_json'] !== $bytes || $row['payload_sha256'] !== hash('sha256',$bytes)
            || $row['intent_sha256'] !== $intent->base->fingerprint()) { throw new ModelViolation('pf_receipt_integrity'); }
        (new ClosedLedgerWriter($this->connection))->verifyDebit($consumption['ledger_entry_uuid'],$intent->base->values['member_faluss_id'],
            $intent->base->values['attribution_id'],$intent->base->values['purchased_pf'],$intent->base->values['policy_version']);
    }
}
