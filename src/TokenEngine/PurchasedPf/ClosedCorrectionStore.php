<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

/** Closed owner correction primitive and durable, deterministic checkpoints. */
final class ClosedCorrectionStore
{
    private readonly ClosedReservationDatabase $connection;
    private readonly ClosedCorrectionFacts $facts;
    /** @var array<string,string> */
    private readonly array $tables;

    /** @param list<string> $sources */
    public function __construct(\wpdb $database, private readonly array $sources)
    {
        ClosedCorrectionEnvironment::assertIsolated($database);
        foreach ($sources as $source) {
            ModelValues::authority($source);
            if (!str_starts_with($source, 'fixture.')) {
                throw new ModelViolation('fixture_authority_required');
            }
        }
        $this->connection = new ClosedReservationDatabase($database);
        $this->facts = new ClosedCorrectionFacts($this->connection, $sources);
        $this->tables = ClosedCorrectionSchema::tables($database);
    }

    /** @return array<string,mixed> */
    public function begin(PurchaseEvidence $evidence, string $key): array
    {
        if (!in_array($evidence->values['authority_id'], $this->sources, true)) {
            throw new ModelViolation('model_authority_not_admitted');
        }
        $hash = ModelValues::keyHash($key);
        $member = $evidence->values['member_faluss_id'];
        return $this->connection->write($member, function () use ($evidence, $hash, $member): array {
            $this->assertSchema();
            $known = $this->connection->row($this->tables['plans'], 'key_sha256=%s', [$hash]);
            if ($known !== null) {
                if ($known['member_faluss_id'] !== $member || $known['evidence_sha256'] !== $evidence->fingerprint()) {
                    throw new ModelViolation('idempotency_or_attribution_conflict');
                }
                return $this->result($known);
            }
            $h1 = ClosedModelSchema::tables($this->connection->database);
            $lot = $this->connection->row($h1['lots'], 'authority_id=%s AND purchase_id=%s',
                [$evidence->values['authority_id'], $evidence->values['purchase_id']]);
            if ($lot === null || $lot['member_faluss_id'] !== $member) {
                throw new ModelViolation('h4_source_unavailable');
            }
            $lotId = (string) $lot['lot_id'];
            $latest = $this->latest($lotId);
            if ($latest !== null && $latest['state'] !== 'complete') {
                throw new ModelViolation('h4_reconciliation_incomplete');
            }
            $old = $this->connection->row($h1['evidence'], 'evidence_id=%s', [$lot['latest_evidence_id']]);
            if ($old === null) {
                throw new ModelViolation('h4_filiation_failure');
            }
            $previous = PurchaseEvidence::fromArray(ClosedReservationDatabase::decode((string) $old['payload_json']));
            if ($old['payload_sha256'] !== $previous->fingerprint() || $lot['immutable_sha256'] !== $previous->immutableFingerprint()) {
                throw new ModelViolation('h4_filiation_failure');
            }
            if ($evidence->values['source_revision'] === $previous->values['source_revision']) {
                throw new ModelViolation($evidence->fingerprint() === $previous->fingerprint() ? 'stable_key_required' : 'evidence_conflict');
            }
            $evidence->assertSuccessorOf($previous);
            if ($this->connection->row($h1['evidence'], 'evidence_id=%s', [$evidence->values['evidence_id']]) !== null) {
                throw new ModelViolation('evidence_conflict');
            }
            $planId = wp_generate_uuid4();
            $state = 'reconciling';
            $plan = ['available_cancelled_pf' => '0', 'allocated_cancelled_pf' => '0', 'rows' => []];
            try {
                $this->facts->lot($lotId, $member);
                $this->assertBalance($member);
                $plan = CorrectionPlan::build($evidence->values['purchased_pf'], $evidence->values['cancelled_purchased_pf_cumulative'],
                    $evidence->values['state'] === 'disputed', $this->facts->allocations($lotId, $member));
                if ((int) $plan['available_cancelled_pf'] < (int) ($latest['available_cancelled_pf'] ?? '0')) {
                    throw new ModelViolation('h4_cancellation_regression');
                }
            } catch (ModelViolation $error) {
                if (!in_array($error->reason, ['h2_integrity_failure', 'h2_ledger_filiation_failure',
                    'h4_filiation_failure', 'h4_balance_mismatch', 'h4_cancellation_regression'], true)) {
                    throw $error;
                }
                $state = 'review_required';
            }
            $now = $this->connection->now();
            $this->connection->insert($h1['evidence'], ['evidence_id' => $evidence->values['evidence_id'],
                'authority_id' => $evidence->values['authority_id'], 'purchase_id' => $evidence->values['purchase_id'],
                'source_revision' => $evidence->values['source_revision'], 'member_faluss_id' => $member,
                'payload_sha256' => $evidence->fingerprint(), 'immutable_sha256' => $evidence->immutableFingerprint(),
                'payload_json' => ModelValues::encode($evidence->values), 'recorded_at' => $now]);
            $this->connection->update($h1['lots'], ['latest_evidence_id' => $evidence->values['evidence_id'],
                'source_revision' => $evidence->values['source_revision'], 'source_state' => $evidence->values['state']], ['lot_id' => $lotId]);
            // Closing a reservation closes its entire multi-lot intent, never just one leg.
            $h2 = ClosedReservationSchema::tables($this->connection->database);
            $this->connection->query($this->connection->database->prepare(
                "UPDATE %i r JOIN %i a ON a.attribution_id=r.attribution_id SET r.state='released' WHERE a.lot_id=%s AND r.state='reserved'",
                $h2['reservations'], $h2['allocations'], $lotId));
            $payload = ['source' => $evidence->values, 'plan' => $plan];
            $row = ['plan_id' => $planId, 'lot_id' => $lotId, 'member_faluss_id' => $member,
                'source_revision' => $evidence->values['source_revision'], 'evidence_sha256' => $evidence->fingerprint(),
                'key_sha256' => $hash, 'state' => $state, 'payload_json' => ModelValues::encode($payload),
                'payload_sha256' => ModelValues::fingerprint($payload),
                'fragment_count' => (string) (int) ceil(count($plan['rows']) / CorrectionPlan::FRAGMENT_SIZE), 'next_fragment' => '0',
                'available_cancelled_pf' => $plan['available_cancelled_pf'], 'allocated_cancelled_pf' => $plan['allocated_cancelled_pf'],
                'policy_version' => $evidence->values['policy_version'], 'recorded_at' => $now];
            $this->connection->insert($this->tables['plans'], $row);
            if ($state === 'reconciling') {
                $delta = (string) ((int) $plan['available_cancelled_pf'] - (int) ($latest['available_cancelled_pf'] ?? '0'));
                $this->recordFragment($row, '0', [], '0', $delta);
                if ($row['fragment_count'] === '0') {
                    $this->verifyCompleted($row, $payload);
                    $this->connection->update($this->tables['plans'], ['state' => 'complete'], ['plan_id' => $planId]);
                    $row['state'] = 'complete';
                }
            }
            return $this->result($row);
        });
    }

    /** @return array<string,mixed> */
    public function resume(string $member, string $planId, string $fragment): array
    {
        ModelValues::uuid($member);
        ModelValues::uuid($planId);
        ModelValues::integer($fragment, true);
        return $this->connection->write($member, function () use ($member, $planId, $fragment): array {
            $this->assertSchema();
            $row = $this->connection->row($this->tables['plans'], 'plan_id=%s AND member_faluss_id=%s', [$planId, $member]);
            if ($row === null) {
                throw new ModelViolation('h4_plan_unavailable');
            }
            $payload = $this->payload($row);
            $known = $this->connection->row($this->tables['fragments'], 'plan_id=%s AND fragment=%s', [$planId, $fragment]);
            if ($known !== null) {
                $this->verifyFragment($row, $known);
                return $this->result($row);
            }
            if ($row['state'] !== 'reconciling' || (int) $fragment !== (int) $row['next_fragment'] + 1
                || (int) $fragment > (int) $row['fragment_count']
            ) {
                throw new ModelViolation('h4_fragment_out_of_order');
            }
            // Validate every original link again before any fragment write.
            try {
                $lot = $this->facts->lot((string) $row['lot_id'], $member);
                if ($lot['source_revision'] !== $row['source_revision']) {
                    throw new ModelViolation('h4_source_changed');
                }
                $facts = $this->facts->allocations((string) $row['lot_id'], $member);
                if (count($facts) !== count($payload['plan']['rows'])) {
                    throw new ModelViolation('h4_filiation_failure');
                }
            } catch (ModelViolation $error) {
                if (!in_array($error->reason, ['h2_integrity_failure', 'h2_ledger_filiation_failure',
                    'h4_filiation_failure', 'h4_source_changed'], true)) {
                    throw $error;
                }
                $this->connection->update($this->tables['plans'], ['state' => 'review_required'], ['plan_id' => $planId]);
                return $this->result(array_replace($row, ['state' => 'review_required']));
            }
            /** @var list<array<string,string>> $chunk Validated by payload(). */
            $chunk = array_slice($payload['plan']['rows'], ((int) $fragment - 1) * CorrectionPlan::FRAGMENT_SIZE, CorrectionPlan::FRAGMENT_SIZE);
            $restore = 0;
            foreach ($chunk as $allocation) {
                $state = ['attribution_id' => $allocation['attribution_id'], 'lot_id' => $row['lot_id'], 'plan_id' => $planId,
                    'source_revision' => $row['source_revision'], 'original_pf' => $allocation['original_pf'],
                    'cancelled_pf' => $allocation['cancelled_pf'], 'suspended_pf' => $allocation['suspended_pf'], 'net_pf' => $allocation['net_pf']];
                $state['payload_sha256'] = ModelValues::fingerprint($state);
                $old = $this->connection->row($this->tables['states'], 'attribution_id=%s AND lot_id=%s', [$state['attribution_id'], $row['lot_id']]);
                if ($old === null) {
                    $this->connection->insert($this->tables['states'], $state);
                } else {
                    $this->connection->update($this->tables['states'], $state, ['attribution_id' => $state['attribution_id'], 'lot_id' => $row['lot_id']]);
                }
                $restore += (int) $allocation['cancelled_pf'] - (int) $allocation['previous_cancelled_pf'];
            }
            $this->recordFragment($row, $fragment, $chunk, (string) $restore, (string) $restore);
            $changes = ['next_fragment' => $fragment];
            if ($fragment === $row['fragment_count']) {
                $this->verifyCompleted($row, $payload);
                $changes['state'] = 'complete';
            }
            $this->connection->update($this->tables['plans'], $changes, ['plan_id' => $planId]);
            return $this->result(array_replace($row, $changes));
        });
    }

    /** @return array<string,mixed> */
    public function lookup(string $member, string $key): array
    {
        ModelValues::uuid($member);
        $hash = ModelValues::keyHash($key);
        return $this->connection->write($member, function () use ($member, $hash): array {
            $this->assertSchema();
            $row = $this->connection->row($this->tables['plans'], 'key_sha256=%s AND member_faluss_id=%s', [$hash, $member]);
            return $row === null ? ['state' => 'not_found'] : $this->result($row);
        });
    }

    /**
     * Verified owner composition for subsequent snapshots; no nested transaction.
     * @return array<string,mixed>|null
     */
    public function verifiedLotPlan(string $lotId, string $member): ?array
    {
        $this->connection->assertHeldSubject($member);
        $this->assertSchema();
        $lot = $this->facts->lot($lotId, $member);
        $row = $this->latest($lotId);
        if ($row === null) {
            $credit = $this->connection->row(ClosedReservationSchema::tables($this->connection->database)['credits'], 'lot_id=%s', [$lotId]);
            if ($credit === null || $credit['source_revision'] !== $lot['source_revision']) {
                throw new ModelViolation('h4_reconciliation_incomplete');
            }
            return null;
        }
        if ($row['member_faluss_id'] !== $member || $row['state'] !== 'complete' || $row['source_revision'] !== $lot['source_revision']
            || $row['next_fragment'] !== $row['fragment_count']
        ) {
            throw new ModelViolation('h4_reconciliation_incomplete');
        }
        $payload = $this->payload($row);
        $this->verifyCompleted($row, $payload);
        return $payload;
    }

    /** @return array<string,mixed>|null */
    private function latest(string $lotId): ?array
    {
        $rows = $this->connection->rows($this->connection->database->prepare(
            'SELECT * FROM %i WHERE lot_id=%s ORDER BY source_revision DESC LIMIT 1 FOR UPDATE', $this->tables['plans'], $lotId));
        return $rows[0] ?? null;
    }

    private function assertSchema(): void
    {
        if (!ClosedCorrectionSchema::ready($this->connection->database) || !ClosedConsumptionSchema::ready($this->connection->database)) {
            throw new ModelViolation('h4_schema_unavailable');
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function payload(array $row): array
    {
        $payload = ClosedReservationDatabase::decode((string) $row['payload_json']);
        ModelValues::exactKeys($payload, ['source', 'plan']);
        if (!is_array($payload['plan']) || !is_array($payload['source'])) {
            throw new ModelViolation('h4_filiation_failure');
        }
        ModelValues::exactKeys($payload['plan'], ['available_cancelled_pf', 'allocated_cancelled_pf', 'rows']);
        if (!is_array($payload['plan']['rows']) || !array_is_list($payload['plan']['rows'])) {
            throw new ModelViolation('h4_filiation_failure');
        }
        $allocated = 0;
        $seen = [];
        foreach ($payload['plan']['rows'] as $allocation) {
            if (!is_array($allocation)) {
                throw new ModelViolation('h4_filiation_failure');
            }
            ModelValues::exactKeys($allocation, ['attribution_id', 'lot_id', 'original_pf', 'cancelled_pf',
                'previous_cancelled_pf', 'suspended_pf', 'net_pf']);
            ModelValues::uuid($allocation['attribution_id']);
            ModelValues::uuid($allocation['lot_id']);
            foreach (['original_pf', 'cancelled_pf', 'previous_cancelled_pf', 'suspended_pf', 'net_pf'] as $field) {
                ModelValues::integer($allocation[$field]);
            }
            if ($allocation['lot_id'] !== $row['lot_id'] || isset($seen[$allocation['attribution_id']])
                || (int) $allocation['original_pf'] !== (int) $allocation['cancelled_pf'] + (int) $allocation['suspended_pf'] + (int) $allocation['net_pf']
                || (int) $allocation['previous_cancelled_pf'] > (int) $allocation['cancelled_pf']
            ) {
                throw new ModelViolation('h4_filiation_failure');
            }
            $seen[$allocation['attribution_id']] = true;
            if ($allocated > ModelValues::MAX_INTEGER - (int) $allocation['cancelled_pf']) {
                throw new ModelViolation('h4_filiation_failure');
            }
            $allocated += (int) $allocation['cancelled_pf'];
        }
        if ($row['state'] !== 'review_required' && ((string) $allocated !== $payload['plan']['allocated_cancelled_pf']
            || $allocated + (int) $payload['plan']['available_cancelled_pf'] !== (int) $payload['source']['cancelled_purchased_pf_cumulative'])
        ) {
            throw new ModelViolation('h4_filiation_failure');
        }
        if ($row['payload_sha256'] !== ModelValues::fingerprint($payload)
            || $row['evidence_sha256'] !== PurchaseEvidence::fromArray($payload['source'])->fingerprint()
            || $row['source_revision'] !== $payload['source']['source_revision']
            || $row['member_faluss_id'] !== $payload['source']['member_faluss_id']
            || $row['policy_version'] !== $payload['source']['policy_version']
            || $row['available_cancelled_pf'] !== $payload['plan']['available_cancelled_pf']
            || $row['allocated_cancelled_pf'] !== $payload['plan']['allocated_cancelled_pf']
            || (int) $row['fragment_count'] !== (int) ceil(count($payload['plan']['rows']) / CorrectionPlan::FRAGMENT_SIZE)
        ) {
            throw new ModelViolation('h4_filiation_failure');
        }
        return $payload;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function result(array $row): array
    {
        $this->payload($row);
        return array_intersect_key($row, array_flip(['plan_id', 'lot_id', 'state', 'source_revision',
            'fragment_count', 'next_fragment', 'available_cancelled_pf', 'allocated_cancelled_pf']));
    }

    /**
     * @param array<string,mixed> $row
     * @param list<array<string,string>> $rows
     */
    private function recordFragment(array $row, string $fragment, array $rows, string $restore, string $cancel): void
    {
        $entries = (new ClosedCorrectionLedger($this->connection))->apply((string) $row['member_faluss_id'],
            (string) $row['plan_id'], $fragment, $restore, $cancel, (string) $row['policy_version']);
        $payload = ['plan_id' => $row['plan_id'], 'source_revision' => $row['source_revision'], 'fragment' => $fragment,
            'rows' => $rows, 'restore_pf' => $restore, 'cancel_pf' => $cancel] + $entries;
        $this->connection->insert($this->tables['fragments'], ['plan_id' => $row['plan_id'], 'fragment' => $fragment,
            'restore_pf' => $restore, 'cancel_pf' => $cancel] + $entries + ['payload_json' => ModelValues::encode($payload),
            'payload_sha256' => ModelValues::fingerprint($payload), 'delivery_state' => 'pending', 'recorded_at' => $this->connection->now()]);
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $fragment
     */
    private function verifyFragment(array $plan, array $fragment): void
    {
        $payload = ClosedReservationDatabase::decode((string) $fragment['payload_json']);
        if ($fragment['payload_sha256'] !== ModelValues::fingerprint($payload) || $payload['plan_id'] !== $plan['plan_id']) {
            throw new ModelViolation('h4_filiation_failure');
        }
        foreach (['restore_pf', 'cancel_pf', 'restore_entry', 'cancel_entry', 'fragment'] as $field) {
            if ($payload[$field] !== $fragment[$field]) {
                throw new ModelViolation('h4_filiation_failure');
            }
        }
        foreach (['credit' => ['restore_pf', 'restore_entry', 'pf_allocation_restore'],
            'debit' => ['cancel_pf', 'cancel_entry', 'pf_pack_cancel']] as $direction => [$amount, $reference, $category]) {
            if ($fragment[$amount] === '0' && $fragment[$reference] === null) {
                continue;
            }
            $entry = $this->connection->row(\Token_Engine_Schema::pf_ledger_table(), 'entry_uuid=%s', [$fragment[$reference]]);
            $ref = $plan['plan_id'] . '.' . $fragment['fragment'];
            $expected = ['faluss_id' => $plan['member_faluss_id'], 'amount_pf' => $fragment[$amount], 'direction' => $direction,
                'economic_class' => 'funded', 'category' => $category, 'category_version' => '1.0.0',
                'source_owner' => 'faluss-hub', 'source_event_reference' => 'fixture.h4.' . $direction . '.' . $ref,
                'idempotency_key' => 'pf.h4.' . $direction . '.' . hash('sha256', $ref), 'policy_version' => $plan['policy_version'],
                'metadata' => ModelValues::encode(['closed_h4_recipe' => true]), 'compensates_entry_uuid' => null];
            foreach ($expected as $field => $value) {
                if ($entry === null || $entry[$field] !== $value) {
                    throw new ModelViolation('h4_filiation_failure');
                }
            }
            if ($this->connection->row(\Token_Engine_Schema::pf_ledger_table(), 'compensates_entry_uuid=%s', [$fragment[$reference]]) !== null) {
                throw new ModelViolation('h4_filiation_failure');
            }
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $payload
     */
    private function verifyCompleted(array $row, array $payload): void
    {
        $states = $this->connection->rows($this->connection->database->prepare('SELECT * FROM %i WHERE plan_id=%s ORDER BY attribution_id',
            $this->tables['states'], $row['plan_id']));
        if (count($states) !== count($payload['plan']['rows'])) {
            throw new ModelViolation('h4_reconciliation_incomplete');
        }
        $cancelled = 0;
        foreach ($states as $state) {
            ClosedCorrectionFacts::verifyState($state);
            $expected = array_values(array_filter($payload['plan']['rows'], static fn (array $a): bool => $a['attribution_id'] === $state['attribution_id']));
            if (count($expected) !== 1) {
                throw new ModelViolation('h4_reconciliation_incomplete');
            }
            foreach (['lot_id', 'original_pf', 'cancelled_pf', 'suspended_pf', 'net_pf'] as $field) {
                if ($state[$field] !== $expected[0][$field]) {
                    throw new ModelViolation('h4_reconciliation_incomplete');
                }
            }
            $cancelled += (int) $state['cancelled_pf'];
        }
        $fragments = $this->connection->rows($this->connection->database->prepare('SELECT * FROM %i WHERE plan_id=%s ORDER BY fragment',
            $this->tables['fragments'], $row['plan_id']));
        if (count($fragments) !== (int) $row['fragment_count'] + 1 || (string) $cancelled !== $row['allocated_cancelled_pf']) {
            throw new ModelViolation('h4_reconciliation_incomplete');
        }
        foreach ($fragments as $index => $fragment) {
            if ((int) $fragment['fragment'] !== $index) {
                throw new ModelViolation('h4_reconciliation_incomplete');
            }
            $this->verifyFragment($row, $fragment);
        }
        $this->assertBalance((string) $row['member_faluss_id']);
    }

    private function assertBalance(string $member): void
    {
        $database = $this->connection->database;
        $h2 = ClosedReservationSchema::tables($database);
        $credits = $this->connection->rows($database->prepare('SELECT * FROM %i WHERE member_faluss_id=%s ORDER BY lot_id FOR UPDATE', $h2['credits'], $member));
        $expected = 0;
        foreach ($credits as $credit) {
            $this->facts->lot((string) $credit['lot_id'], $member);
            $allocated = $this->facts->allocations((string) $credit['lot_id'], $member);
            $used = array_sum(array_map(static fn (array $a): int => (int) $a['purchased_pf'], $allocated));
            $plans = $this->connection->rows($database->prepare('SELECT * FROM %i WHERE lot_id=%s ORDER BY source_revision', $this->tables['plans'], $credit['lot_id']));
            $plan = $plans === [] ? null : $plans[count($plans) - 1];
            foreach ($plans as $previous) {
                $fragments = $this->connection->rows($database->prepare('SELECT * FROM %i WHERE plan_id=%s ORDER BY fragment', $this->tables['fragments'], $previous['plan_id']));
                foreach ($fragments as $fragment) {
                    $this->verifyFragment($previous, $fragment);
                }
            }
            $remaining = (int) $credit['purchased_pf'] - $used - (int) ($plan['available_cancelled_pf'] ?? '0');
            if ($remaining < 0 || $expected > ModelValues::MAX_INTEGER - $remaining) {
                throw new ModelViolation('h4_balance_mismatch');
            }
            $expected += $remaining;
        }
        if ((new ClosedLedgerWriter($this->connection))->balance($member) !== $expected) {
            throw new ModelViolation('h4_balance_mismatch');
        }
    }
}
