<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

/** Fictitious provenanced credits and non-renewing reservations, never site APIs. */
final class ClosedReservationStore
{
    public const TTL_SECONDS = 120;
    /** @var array<string,string> */
    private readonly array $tables;
    private readonly ClosedReservationDatabase $connection;
    private readonly ClosedLedgerWriter $ledger;

    /**
     * @param list<string> $clients
     * @param list<string> $sources
     */
    public function __construct(\wpdb $database, private readonly array $clients, private readonly array $sources)
    {
        $this->connection = new ClosedReservationDatabase($database);
        $this->ledger = new ClosedLedgerWriter($this->connection);
        $this->tables = ClosedReservationSchema::tables($database);
        foreach (array_merge($clients, $sources) as $authority) {
            ModelValues::authority($authority);
            if (!str_starts_with($authority, 'fixture.')) {
                throw new ModelViolation('fixture_authority_required');
            }
        }
    }

    /**
     * Explicit synthetic admission; no purchase provider is registered.
     * @return array<string,mixed>
     */
    public function admitLotForRecipe(string $lotId, string $member, string $key): array
    {
        ModelValues::uuid($lotId);
        ModelValues::uuid($member);
        $keyHash = ModelValues::keyHash($key);
        $fingerprint = ModelValues::fingerprint(['lot_id' => $lotId, 'member_faluss_id' => $member]);
        return $this->connection->write($member, function () use ($lotId, $member, $keyHash, $fingerprint): array {
            $lot = $this->sourceLot($lotId, $member);
            $scope = (string) $lot['authority_id'];
            $known = $this->key($scope, 'admit', $keyHash, $fingerprint);
            $credit = $this->connection->row($this->tables['credits'], 'lot_id=%s', [$lotId]);
            if ($known !== null) {
                if ($credit === null || $known['record_id'] !== $lotId) {
                    throw new ModelViolation('h2_integrity_failure');
                }
                $this->verifyCredit($credit, $member);
                return ['kind' => ClosedReservationSchema::SCOPE, 'state' => 'admitted', 'lot_id' => $lotId,
                    'ledger_entry_uuid' => $credit['ledger_entry_uuid']];
            }
            if ($credit !== null) {
                throw new ModelViolation('stable_key_required');
            }
            if ($lot['source_state'] !== 'confirmed') {
                throw new ModelViolation('h2_source_unavailable');
            }
            $entry = $this->ledger->appendCredit($member, $lotId, (string) $lot['purchased_pf'], (string) $lot['policy_version']);
            $this->connection->insert($this->tables['credits'], ['lot_id' => $lotId, 'member_faluss_id' => $member,
                'evidence_id' => $lot['latest_evidence_id'], 'source_revision' => $lot['source_revision'],
                'purchased_pf' => $lot['purchased_pf'], 'immutable_sha256' => $lot['immutable_sha256'],
                'policy_version' => $lot['policy_version'], 'ledger_entry_uuid' => $entry, 'accepted_at' => $this->connection->now()]);
            $this->rememberKey($scope, 'admit', $keyHash, $fingerprint, $lotId);
            return ['kind' => ClosedReservationSchema::SCOPE, 'state' => 'admitted', 'lot_id' => $lotId, 'ledger_entry_uuid' => $entry];
        });
    }

    /** @return array<string,mixed> */
    public function reserve(AttributionIntent $intent, string $key): array
    {
        $this->assertClient($intent);
        $hash = ModelValues::keyHash($key);
        return $this->connection->write($intent->values['member_faluss_id'], function () use ($intent, $hash): array {
            $now = $this->connection->now();
            $this->expire($intent->values['member_faluss_id'], $now);
            $known = $this->key($intent->values['client_authority'], 'reserve', $hash, $intent->fingerprint());
            $existing = $this->reservation($intent);
            if ($known !== null) {
                if ($existing === null || $known['record_id'] !== $intent->values['attribution_id']
                    || $existing['reserve_key_sha256'] !== $hash
                ) {
                    throw new ModelViolation('h2_integrity_failure');
                }
                return $this->result($existing, $now);
            }
            if ($existing !== null) {
                // Never create a replacement reservation or renew its deadline after a timeout.
                throw new ModelViolation('stable_key_required');
            }
            $this->checkCreationLimits($intent->values['member_faluss_id'], $now);
            $lots = $this->availableLots($intent, $now);
            $plan = AllocationPlan::forIntent($intent, $lots);
            if ($this->reservedQuantity($intent->values['member_faluss_id'], $now) > $this->ledger->balance($intent->values['member_faluss_id']) - (int) $intent->values['purchased_pf']) {
                throw new ModelViolation('h2_ledger_quantity_unavailable');
            }
            $row = ['attribution_id' => $intent->values['attribution_id'], 'reservation_id' => wp_generate_uuid4(),
                'client_authority' => $intent->values['client_authority'], 'member_faluss_id' => $intent->values['member_faluss_id'],
                'creator_faluss_id' => $intent->values['creator_faluss_id'], 'purchased_pf' => $intent->values['purchased_pf'],
                'payload_sha256' => $intent->fingerprint(), 'payload_json' => ModelValues::encode($intent->values),
                'reserve_key_sha256' => $hash, 'state' => 'reserved', 'created_at' => $now,
                'expires_at' => (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))->modify('+' . self::TTL_SECONDS . ' seconds')->format('Y-m-d H:i:s.u')];
            $this->connection->insert($this->tables['reservations'], $row);
            foreach ($plan as $allocation) {
                $this->connection->insert($this->tables['allocations'], ['attribution_id' => $intent->values['attribution_id']] + $allocation);
            }
            $this->rememberKey($intent->values['client_authority'], 'reserve', $hash, $intent->fingerprint(), $intent->values['attribution_id']);
            return $this->result($row, $now);
        });
    }

    /** @return array<string,mixed> */
    public function release(AttributionIntent $intent, string $key): array
    {
        $this->assertClient($intent);
        $hash = ModelValues::keyHash($key);
        return $this->connection->write($intent->values['member_faluss_id'], function () use ($intent, $hash): array {
            $now = $this->connection->now();
            $this->expire($intent->values['member_faluss_id'], $now);
            $known = $this->key($intent->values['client_authority'], 'release', $hash, $intent->fingerprint());
            $row = $this->reservation($intent);
            if ($row === null || $row['state'] === 'confirmed') {
                throw new ModelViolation('h2_reservation_closed');
            }
            if ($known !== null) {
                if ($known['record_id'] !== $intent->values['attribution_id']) {
                    throw new ModelViolation('h2_integrity_failure');
                }
                return $this->result($row, $now);
            }
            if ($row['state'] === 'reserved') {
                $this->connection->update($this->tables['reservations'], ['state' => 'released'], ['attribution_id' => $intent->values['attribution_id'], 'state' => 'reserved']);
                $row['state'] = 'released';
            }
            $this->rememberKey($intent->values['client_authority'], 'release', $hash, $intent->fingerprint(), $intent->values['attribution_id']);
            return $this->result($row, $now);
        });
    }

    /**
     * Primary, serialized recovery. No state mutation or alternative key.
     * @return array<string,mixed>
     */
    public function lookup(AttributionIntent $intent, string $operation, string $key): array
    {
        $this->assertClient($intent);
        if (!in_array($operation, ['reserve', 'release'], true)) {
            throw new ModelViolation('invalid_h2_operation');
        }
        $hash = ModelValues::keyHash($key);
        return $this->connection->write($intent->values['member_faluss_id'], function () use ($intent, $operation, $hash): array {
            $known = $this->key($intent->values['client_authority'], $operation, $hash, $intent->fingerprint());
            if ($known === null) {
                return ['kind' => ClosedReservationSchema::SCOPE, 'state' => 'not_found'];
            }
            $row = $this->reservation($intent);
            if ($row === null || $known['record_id'] !== $intent->values['attribution_id']) {
                throw new ModelViolation('h2_integrity_failure');
            }
            return $this->result($row, $this->connection->now());
        });
    }

    /** @return array<string,mixed> */
    public function sourceLot(string $lotId, string $member): array
    {
        $this->connection->assertHeldSubject($member);
        $tables = ClosedModelSchema::tables($this->connection->database);
        $lot = $this->connection->row($tables['lots'], 'lot_id=%s', [$lotId]);
        if ($lot === null || $lot['member_faluss_id'] !== $member || !in_array($lot['authority_id'], $this->sources, true)) {
            throw new ModelViolation('h2_source_unavailable');
        }
        $proof = $this->connection->row($tables['evidence'], 'evidence_id=%s', [$lot['latest_evidence_id']]);
        if ($proof === null) {
            throw new ModelViolation('h2_integrity_failure');
        }
        $evidence = PurchaseEvidence::fromArray(ClosedReservationDatabase::decode((string) $proof['payload_json']));
        foreach (['authority_id', 'purchase_id', 'member_faluss_id', 'source_revision', 'evidence_id'] as $field) {
            if ((string) $proof[$field] !== $evidence->values[$field]) {
                throw new ModelViolation('h2_integrity_failure');
            }
        }
        foreach (['authority_id', 'purchase_id', 'member_faluss_id', 'source_revision', 'purchased_pf', 'bonus_pf'] as $field) {
            if ((string) $lot[$field] !== $evidence->values[$field]) {
                throw new ModelViolation('h2_integrity_failure');
            }
        }
        if ($proof['payload_sha256'] !== $evidence->fingerprint() || $proof['immutable_sha256'] !== $evidence->immutableFingerprint()
            || $lot['immutable_sha256'] !== $evidence->immutableFingerprint() || $lot['source_state'] !== $evidence->values['state']
        ) {
            throw new ModelViolation('h2_integrity_failure');
        }
        return $lot + ['policy_version' => $evidence->values['policy_version']];
    }

    /**
     * @param array<string,mixed> $credit
     * @return array<string,mixed>
     */
    public function verifyCredit(array $credit, string $member): array
    {
        $lot = $this->sourceLot((string) $credit['lot_id'], $member);
        if ($credit['member_faluss_id'] !== $member || $credit['purchased_pf'] !== $lot['purchased_pf']
            || $credit['immutable_sha256'] !== $lot['immutable_sha256'] || $credit['policy_version'] !== $lot['policy_version']
        ) {
            throw new ModelViolation('h2_integrity_failure');
        }
        $this->ledger->verifyCredit((string) $credit['ledger_entry_uuid'], $member, (string) $credit['lot_id'],
            (string) $credit['purchased_pf'], (string) $credit['policy_version']);
        return $lot;
    }

    public function assertClient(AttributionIntent $intent): void
    {
        if (!in_array($intent->values['client_authority'], $this->clients, true)) {
            throw new ModelViolation('model_authority_not_admitted');
        }
    }

    /** @return array<string,mixed>|null */
    public function reservation(AttributionIntent $intent): ?array
    {
        $this->connection->assertHeldSubject($intent->values['member_faluss_id']);
        $row = $this->connection->row($this->tables['reservations'], 'attribution_id=%s', [$intent->values['attribution_id']]);
        if ($row === null) {
            return null;
        }
        $stored = AttributionIntent::fromArray(ClosedReservationDatabase::decode((string) $row['payload_json']));
        foreach ($stored->values as $field => $value) {
            if ($field !== 'policy_version' && (string) $row[$field] !== $value) {
                throw new ModelViolation('h2_integrity_failure');
            }
        }
        if ($row['payload_sha256'] !== $stored->fingerprint()) {
            throw new ModelViolation('h2_integrity_failure');
        }
        if (!hash_equals($stored->fingerprint(), $intent->fingerprint())) {
            throw new ModelViolation('idempotency_or_attribution_conflict');
        }
        if (!in_array($row['state'], ['reserved', 'confirmed', 'released', 'expired'], true)) {
            throw new ModelViolation('h2_integrity_failure');
        }
        return $row;
    }

    /** @return array<string,mixed>|null */
    public function key(string $scope, string $operation, string $hash, string $fingerprint): ?array
    {
        $row = $this->connection->row($this->tables['keys'], 'authority_id=%s AND operation=%s AND key_sha256=%s', [$scope, $operation, $hash]);
        if ($row !== null && !hash_equals((string) $row['payload_sha256'], $fingerprint)) {
            throw new ModelViolation('idempotency_or_attribution_conflict');
        }
        return $row;
    }

    public function rememberKey(string $scope, string $operation, string $hash, string $fingerprint, string $record): void
    {
        $this->connection->insert($this->tables['keys'], ['authority_id' => $scope, 'operation' => $operation,
            'key_sha256' => $hash, 'payload_sha256' => $fingerprint, 'record_id' => $record, 'recorded_at' => $this->connection->now()]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function result(array $row, string $now): array
    {
        $allocations = $this->connection->rows($this->connection->database->prepare('SELECT lot_id,evidence_id,source_revision,purchased_pf FROM %i WHERE attribution_id=%s ORDER BY lot_id', $this->tables['allocations'], $row['attribution_id']));
        $sum = 0;
        if (count($allocations) === 0 || count($allocations) > 32) {
            throw new ModelViolation('h2_integrity_failure');
        }
        foreach ($allocations as $allocation) {
            ModelValues::uuid($allocation['lot_id']);
            ModelValues::uuid($allocation['evidence_id']);
            ModelValues::integer($allocation['source_revision'], true);
            $quantity = (int) ModelValues::integer($allocation['purchased_pf'], true);
            if ($sum > (int) $row['purchased_pf'] - $quantity) {
                throw new ModelViolation('h2_integrity_failure');
            }
            $sum += $quantity;
        }
        if ($sum !== (int) $row['purchased_pf']) {
            throw new ModelViolation('h2_integrity_failure');
        }
        return ['kind' => ClosedReservationSchema::SCOPE,
            'state' => $row['state'] === 'reserved' && strcmp((string) $row['expires_at'], $now) <= 0 ? 'expired' : $row['state'],
            'attribution_id' => $row['attribution_id'], 'reservation_id' => $row['reservation_id'],
            'expires_at' => $row['expires_at'], 'allocations' => $allocations];
    }

    private function expire(string $member, string $now): void
    {
        $this->connection->query($this->connection->database->prepare("UPDATE %i SET state='expired' WHERE member_faluss_id=%s AND state='reserved' AND expires_at<=%s", $this->tables['reservations'], $member, $now));
    }

    private function checkCreationLimits(string $member, string $now): void
    {
        $rows = $this->connection->rows($this->connection->database->prepare(
            "SELECT COUNT(CASE WHEN state='reserved' THEN 1 END) AS active, COUNT(CASE WHEN created_at>DATE_SUB(%s,INTERVAL 1 MINUTE) THEN 1 END) AS recent FROM %i WHERE member_faluss_id=%s", $now, $this->tables['reservations'], $member));
        if ((int) $rows[0]['active'] >= 3 || (int) $rows[0]['recent'] >= 10) {
            throw new ModelViolation('h2_reservation_limit');
        }
    }

    public function reservedQuantity(string $member, string $now): int
    {
        $rows = $this->connection->rows($this->connection->database->prepare(
            "SELECT COALESCE(SUM(purchased_pf),0) AS total FROM %i WHERE member_faluss_id=%s AND state='reserved' AND expires_at>%s", $this->tables['reservations'], $member, $now));
        return (int) ModelValues::integer((string) $rows[0]['total']);
    }

    /** @return list<array{lot_id:string,member_faluss_id:string,purchased_pf:string,evidence_id:string,source_revision:string,accepted_at:string}> */
    private function availableLots(AttributionIntent $intent, string $now): array
    {
        $database = $this->connection->database;
        $credits = $this->connection->rows($database->prepare('SELECT * FROM %i WHERE member_faluss_id=%s ORDER BY lot_id FOR UPDATE', $this->tables['credits'], $intent->values['member_faluss_id']));
        $lots = [];
        foreach ($credits as $credit) {
            $lot = $this->verifyCredit($credit, $intent->values['member_faluss_id']);
            if ($lot['source_state'] !== 'confirmed' || $credit['policy_version'] !== $intent->values['policy_version']) {
                continue;
            }
            $allocated = $this->connection->rows($database->prepare(
                "SELECT COALESCE(SUM(a.purchased_pf),0) AS total FROM %i a JOIN %i r ON r.attribution_id=a.attribution_id WHERE a.lot_id=%s AND (r.state='confirmed' OR (r.state='reserved' AND r.expires_at>%s))", $this->tables['allocations'], $this->tables['reservations'], $credit['lot_id'], $now));
            $used = (int) ModelValues::integer((string) $allocated[0]['total']);
            $quantity = (int) ModelValues::integer((string) $credit['purchased_pf'], true);
            if ($used > $quantity) {
                throw new ModelViolation('h2_integrity_failure');
            }
            if ($used < $quantity) {
                $lots[] = ['lot_id' => (string) $credit['lot_id'], 'member_faluss_id' => $intent->values['member_faluss_id'],
                    'purchased_pf' => (string) ($quantity - $used), 'evidence_id' => (string) $lot['latest_evidence_id'],
                    'source_revision' => (string) $lot['source_revision'], 'accepted_at' => (string) $credit['accepted_at']];
            }
        }
        return $lots;
    }
}
