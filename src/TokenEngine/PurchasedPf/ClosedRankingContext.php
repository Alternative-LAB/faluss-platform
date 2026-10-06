<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedIntent;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingValues;

/** Exact owner context, no optional downgrade. Called only inside the existing H2 transaction. */
final class ClosedRankingContext
{
    private readonly ClosedReservationDatabase $connection;
    /** @var array<string,string> */
    private readonly array $tables;
    /** @var array{ordering_epoch:string,consumption_order:string,confirmed_at:string}|null */
    private ?array $staged = null;

    public function __construct(private readonly \wpdb $db, public readonly RankedIntent $intent, private readonly string $keyId)
    {
        ClosedEnvironment::assertIsolated($db,'hub');
        $this->connection = new ClosedReservationDatabase($db); $this->tables = ClosedRankingSchema::tables($db);
    }

    public static function guardLegacy(ClosedReservationDatabase $connection, AttributionIntent $intent): void
    {
        $connection->assertHeldSubject($intent->values['member_faluss_id']); $db = $connection->database;
        if (!ClosedRankingSchema::present($db)) { return; }
        if (!ClosedRankingSchema::ready($db)) { throw new ModelViolation('pf_ranking_schema_unavailable'); }
        if ($connection->row(ClosedRankingSchema::tables($db)['bindings'],'attribution_id=%s',[$intent->values['attribution_id']]) !== null) {
            throw new ModelViolation('pf_ranking_context_required');
        }
    }

    public function guard(AttributionIntent $base, string $operation): void
    {
        $this->available($base);
        if (!in_array($operation,['reserve','confirm','release','lookup'],true)) { throw new ModelViolation('invalid_h2_operation'); }
        $row = $this->connection->row($this->tables['bindings'],'attribution_id=%s',[$base->values['attribution_id']]);
        if ($row !== null) {
            if ($row['base_sha256'] !== $base->fingerprint() || $row['ranked_sha256'] !== $this->intent->fingerprint()
                || $row['member_faluss_id'] !== $base->values['member_faluss_id'] || $row['client_authority'] !== $base->values['client_authority']
                || CanonicalJson::encode($this->intent->values) !== $row['payload_json']) { throw new ModelViolation('pf_ranking_context_conflict'); }
            return;
        }
        $existing = $this->connection->row(ClosedReservationSchema::tables($this->db)['reservations'],'attribution_id=%s',[$base->values['attribution_id']]);
        if ($existing !== null) { throw new ModelViolation('pf_ranking_retroactive_context_refused'); }
        if ($operation === 'lookup') { return; }
        if ($operation !== 'reserve') { throw new ModelViolation('pf_ranking_context_required'); }
        $this->connection->insert($this->tables['bindings'],['attribution_id' => $base->values['attribution_id'],
            'member_faluss_id' => $base->values['member_faluss_id'],'client_authority' => $base->values['client_authority'],
            'base_sha256' => $base->fingerprint(),'ranked_sha256' => $this->intent->fingerprint(),
            'payload_json' => CanonicalJson::encode($this->intent->values),'created_at' => $this->connection->now()]);
    }

    public function validateSelection(AttributionIntent $base): void
    { $this->available($base); (new ClosedBarrierStore($this->db))->lockSelection($this->intent); }

    public function beforeDebit(AttributionIntent $base): string
    {
        $this->guard($base,'confirm');
        $counter = self::orderInOwnerTransaction($this->connection,$base->values['member_faluss_id']);
        $epoch = $counter['ordering_epoch']; $last = (int) $counter['last_order'];
        if ($last >= ModelValues::MAX_INTEGER) { throw new ModelViolation('pf_ranking_counter_exhausted'); }
        // Counter then canonical barrier rows; close never takes the counter/member/lot locks.
        $now = (new ClosedBarrierStore($this->db))->lockSelection($this->intent);
        if ($counter['last_confirmed_at'] !== '' && $now < RankingValues::utc($counter['last_confirmed_at'])) { throw new ModelViolation('pf_primary_clock_regression'); }
        $this->staged = ['ordering_epoch' => $epoch,'consumption_order' => (string) ($last + 1),'confirmed_at' => $now];
        $this->connection->update($this->tables['counter'],['last_order' => $this->staged['consumption_order'],'last_confirmed_at' => $now],['id' => '1','last_order' => (string) $last]);
        return $now;
    }

    /** Read the same authority for receipts and snapshots; no new epoch or repair.
     * @return array{ordering_epoch:string,last_order:string,last_confirmed_at:string} */
    public static function orderInOwnerTransaction(ClosedReservationDatabase $connection, string $member): array
    {
        $connection->assertHeldSubject($member); $db = $connection->database;
        if (!ClosedRankingSchema::ready($db)) { throw new ModelViolation('pf_ranking_schema_unavailable'); }
        $tables = ClosedRankingSchema::tables($db);
        $counter = $connection->row($tables['counter'],'id=%s',['1']);
        if ($counter === null) { throw new ModelViolation('pf_ranking_counter_unavailable'); }
        $epoch = ModelValues::uuid($counter['ordering_epoch']); $last = (int) ModelValues::integer($counter['last_order']);
        if ($last >= ModelValues::MAX_INTEGER) { throw new ModelViolation('pf_ranking_counter_exhausted'); }
        $latest = $connection->rows($db->prepare('SELECT ordering_epoch,consumption_order,confirmed_at FROM %i ORDER BY consumption_order DESC LIMIT 1 FOR UPDATE',$tables['receipts']));
        $count = $connection->scalar($db->prepare('SELECT COUNT(*) FROM %i',$tables['receipts']));
        $inEpoch = $connection->scalar($db->prepare('SELECT COUNT(*) FROM %i WHERE ordering_epoch=%s AND consumption_order BETWEEN 1 AND %d',$tables['receipts'],$epoch,$last));
        // With the unique order index, N rows in [1,N] of this epoch prove no gap or foreign epoch.
        if ((string) $count !== (string) $last || (string) $inEpoch !== (string) $last || ($last === 0 ? ($latest !== [] || $counter['last_confirmed_at'] !== '')
            : ($latest === [] || $latest[0]['ordering_epoch'] !== $epoch || $latest[0]['consumption_order'] !== (string) $last || $latest[0]['confirmed_at'] !== $counter['last_confirmed_at']))) {
            throw new ModelViolation('pf_ranking_counter_divergent');
        }
        return ['ordering_epoch' => $epoch,'last_order' => (string) $last,'last_confirmed_at' => (string) $counter['last_confirmed_at']];
    }

    /** @param array<string,mixed> $payload */
    public function record(array $payload): void
    {
        $this->available($this->intent->base);
        if ($this->staged === null || ($payload['confirmed_at'] ?? null) !== $this->staged['confirmed_at']) { throw new ModelViolation('pf_ranking_unstaged_consumption'); }
        $store = new ClosedRankedReceiptStore($this->db,$this->keyId);
        // Preserve the old owner receipt for H4; no second consumption or debit.
        (new ClosedProtocolStore($this->db,'fixture.hub',$this->keyId))->record($payload);
        $store->record($this->intent,$payload,$this->staged);
    }

    private function available(AttributionIntent $base): void
    {
        ClosedEnvironment::assertIsolated($this->db,'hub'); $this->connection->assertHeldSubject($base->values['member_faluss_id']);
        if ($base->fingerprint() !== $this->intent->base->fingerprint()) { throw new ModelViolation('pf_ranking_context_conflict'); }
        if (!ClosedRankingSchema::ready($this->db)) { throw new ModelViolation('pf_ranking_schema_unavailable'); }
    }
}
