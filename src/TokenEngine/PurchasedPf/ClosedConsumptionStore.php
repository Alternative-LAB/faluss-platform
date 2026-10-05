<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

/** Closed H2 consumption; no signed receipt, admission, dispatcher or Fans API. */
final class ClosedConsumptionStore
{
    private readonly ClosedReservationDatabase $connection;
    private readonly ClosedReservationStore $reservations;
    private readonly ClosedLedgerWriter $ledger;
    /** @var array<string,string> */
    private readonly array $tables;

    /**
     * @param list<string> $clients
     * @param list<string> $sources
     */
    public function __construct(\wpdb $database, array $clients, array $sources, private readonly ?\Closure $recordReceipt = null)
    {
        $this->connection = new ClosedReservationDatabase($database);
        $this->reservations = new ClosedReservationStore($database, $clients, $sources);
        $this->ledger = new ClosedLedgerWriter($this->connection);
        $this->tables = ClosedConsumptionSchema::tables($database);
    }

    /** @return array<string,mixed> */
    public function confirm(AttributionIntent $intent, string $key): array
    {
        $this->reservations->assertClient($intent);
        $hash = ModelValues::keyHash($key);
        return $this->connection->write($intent->values['member_faluss_id'], function () use ($intent, $hash): array {
            $this->assertSchema();
            $known = $this->reservations->key($intent->values['client_authority'], 'confirm', $hash, $intent->fingerprint());
            $row = $this->reservations->reservation($intent);
            if ($known !== null) {
                if ($row === null || $row['state'] !== 'confirmed' || $known['record_id'] !== $intent->values['attribution_id']) {
                    throw new ModelViolation('h2_integrity_failure');
                }
                return $this->confirmedResult($intent, $row, $hash);
            }
            if ($row === null) {
                throw new ModelViolation('h2_reservation_closed');
            }
            if ($row['state'] === 'confirmed') {
                throw new ModelViolation('stable_key_required');
            }
            $now = $this->connection->now();
            $reservation = $this->reservations->result($row, $now);
            if ($reservation['state'] !== 'reserved') {
                throw new ModelViolation('h2_reservation_closed');
            }
            // Locks are taken in binary lot order, independently from the original FIFO selection.
            $this->validateAllocations($intent, $reservation);
            // Re-read the actual DB clock after source/ledger validation, never a client timestamp.
            $now = $this->connection->now();
            if (strcmp((string) $row['expires_at'], $now) <= 0) {
                throw new ModelViolation('h2_reservation_closed');
            }
            $quantity = (int) $intent->values['purchased_pf'];
            $allReserved = $this->reservations->reservedQuantity($intent->values['member_faluss_id'], $now);
            if ($allReserved < $quantity || $this->ledger->balance($intent->values['member_faluss_id']) < $allReserved) {
                throw new ModelViolation('h2_ledger_quantity_unavailable');
            }
            $consumptionId = wp_generate_uuid4();
            $eventId = wp_generate_uuid4();
            ModelValues::uuid($consumptionId);
            ModelValues::uuid($eventId);
            $entry = $this->ledger->appendDebit($intent->values['member_faluss_id'], $intent->values['attribution_id'],
                $intent->values['purchased_pf'], $intent->values['policy_version']);
            $payload = ['kind' => ClosedConsumptionSchema::SCOPE, 'consumption_id' => $consumptionId,
                'event_id' => $eventId, 'attribution_id' => $intent->values['attribution_id'],
                'reservation_id' => $row['reservation_id'], 'client_authority' => $intent->values['client_authority'],
                'member_faluss_id' => $intent->values['member_faluss_id'], 'creator_faluss_id' => $intent->values['creator_faluss_id'],
                'purchased_pf' => $intent->values['purchased_pf'], 'policy_version' => $intent->values['policy_version'],
                'ledger_entry_uuid' => $entry, 'confirmed_at' => $now, 'allocations' => $reservation['allocations']];
            $json = ModelValues::encode($payload);
            $digest = hash('sha256', $json);
            $this->connection->insert($this->tables['consumptions'], [
                'consumption_id' => $consumptionId, 'attribution_id' => $intent->values['attribution_id'],
                'reservation_id' => $row['reservation_id'], 'client_authority' => $intent->values['client_authority'],
                'member_faluss_id' => $intent->values['member_faluss_id'], 'creator_faluss_id' => $intent->values['creator_faluss_id'],
                'purchased_pf' => $intent->values['purchased_pf'], 'policy_version' => $intent->values['policy_version'],
                'intent_sha256' => $intent->fingerprint(), 'confirm_key_sha256' => $hash,
                'ledger_entry_uuid' => $entry, 'event_id' => $eventId, 'payload_json' => $json,
                'payload_sha256' => $digest, 'confirmed_at' => $now]);
            $this->connection->insert($this->tables['journal'], ['event_id' => $eventId, 'consumption_id' => $consumptionId,
                'payload_json' => $json, 'payload_sha256' => $digest, 'state' => 'pending', 'recorded_at' => $now]);
            // H3's private receipt joins this transaction; a signing/storage failure rolls back everything.
            if ($this->recordReceipt !== null) {
                ($this->recordReceipt)($payload);
            }
            $reservationTables = ClosedReservationSchema::tables($this->connection->database);
            $this->connection->query($this->connection->database->prepare(
                "UPDATE %i SET state='confirmed' WHERE attribution_id=%s AND state='reserved' AND expires_at>UTC_TIMESTAMP(6)",
                $reservationTables['reservations'], $intent->values['attribution_id']));
            if ($this->connection->database->rows_affected !== 1) {
                // Expiry during preceding writes rolls back the debit and journal as well.
                throw new ModelViolation('h2_reservation_closed');
            }
            $this->reservations->rememberKey($intent->values['client_authority'], 'confirm', $hash,
                $intent->fingerprint(), $intent->values['attribution_id']);
            $row['state'] = 'confirmed';
            return $this->confirmedResult($intent, $row, $hash);
        });
    }

    /**
     * An authoritative primary lookup, even when the reservation deadline passed after commit.
     * @return array<string,mixed>
     */
    public function lookup(AttributionIntent $intent, string $operation, string $key): array
    {
        $this->reservations->assertClient($intent);
        if (!in_array($operation, ['reserve', 'confirm', 'release'], true)) {
            throw new ModelViolation('invalid_h2_operation');
        }
        $hash = ModelValues::keyHash($key);
        return $this->connection->write($intent->values['member_faluss_id'], function () use ($intent, $operation, $hash): array {
            $this->assertSchema();
            $known = $this->reservations->key($intent->values['client_authority'], $operation, $hash, $intent->fingerprint());
            if ($known === null) {
                return ['kind' => ClosedConsumptionSchema::SCOPE, 'state' => 'not_found'];
            }
            $row = $this->reservations->reservation($intent);
            if ($row === null || $known['record_id'] !== $intent->values['attribution_id']) {
                throw new ModelViolation('h2_integrity_failure');
            }
            return $row['state'] === 'confirmed' ? $this->confirmedResult($intent, $row, $operation === 'confirm' ? $hash : null)
                : $this->reservations->result($row, $this->connection->now());
        });
    }

    private function assertSchema(): void
    {
        if (!ClosedConsumptionSchema::ready($this->connection->database)) {
            throw new ModelViolation('h2_consumption_schema_unavailable');
        }
    }

    /**
     * Owner composition inside the held transaction, without starting another.
     * @return array<string,mixed>
     */
    public function confirmedFact(AttributionIntent $intent): array
    {
        $this->connection->assertHeldSubject($intent->values['member_faluss_id']);
        $row = $this->reservations->reservation($intent);
        if ($row === null || $row['state'] !== 'confirmed') {
            throw new ModelViolation('h4_filiation_failure');
        }
        return $this->confirmedResult($intent, $row, null)['consumption'];
    }

    /** @param array<string,mixed> $reservation */
    private function validateAllocations(AttributionIntent $intent, array $reservation): void
    {
        $tables = ClosedReservationSchema::tables($this->connection->database);
        foreach ($reservation['allocations'] as $allocation) {
            $credit = $this->connection->row($tables['credits'], 'lot_id=%s', [$allocation['lot_id']]);
            if ($credit === null) {
                throw new ModelViolation('h2_integrity_failure');
            }
            $lot = $this->reservations->verifyCredit($credit, $intent->values['member_faluss_id']);
            $corrected = ClosedCorrectedLot::view($this->connection, $lot);
            if (!$corrected['eligible'] || $lot['policy_version'] !== $intent->values['policy_version']
                || $lot['latest_evidence_id'] !== $allocation['evidence_id'] || $lot['source_revision'] !== $allocation['source_revision']
            ) {
                throw new ModelViolation('h2_source_changed');
            }
            $quantity = (int) $allocation['purchased_pf'];
            $used = $this->connection->rows($this->connection->database->prepare(
                "SELECT COALESCE(SUM(a.purchased_pf),0) AS total FROM %i a JOIN %i r ON r.attribution_id=a.attribution_id WHERE a.lot_id=%s AND (r.state='confirmed' OR (r.state='reserved' AND r.expires_at>UTC_TIMESTAMP(6)))", $tables['allocations'], $tables['reservations'], $allocation['lot_id']));
            $total = (int) ModelValues::integer((string) $used[0]['total']);
            if ($total < $quantity || $total > (int) $credit['purchased_pf'] - $corrected['available_cancelled_pf']) {
                throw new ModelViolation('h2_integrity_failure');
            }
        }
    }

    /**
     * This immutable private record is not a D3 signed economic receipt or current source status.
     * @param array<string,mixed> $reservation
     * @return array<string,mixed>
     */
    private function confirmedResult(AttributionIntent $intent, array $reservation, ?string $confirmHash): array
    {
        $record = $this->connection->row($this->tables['consumptions'], 'attribution_id=%s', [$intent->values['attribution_id']]);
        if ($record === null || $record['intent_sha256'] !== $intent->fingerprint()
            || ($confirmHash !== null && $record['confirm_key_sha256'] !== $confirmHash)
            || $record['reservation_id'] !== $reservation['reservation_id'] || $reservation['state'] !== 'confirmed'
        ) {
            throw new ModelViolation('h2_integrity_failure');
        }
        $payload = ClosedReservationDatabase::decode((string) $record['payload_json']);
        ModelValues::exactKeys($payload, ['kind', 'consumption_id', 'event_id', 'attribution_id', 'reservation_id',
            'client_authority', 'member_faluss_id', 'creator_faluss_id', 'purchased_pf', 'policy_version',
            'ledger_entry_uuid', 'confirmed_at', 'allocations']);
        foreach (['consumption_id', 'event_id', 'attribution_id', 'reservation_id', 'client_authority', 'member_faluss_id',
            'creator_faluss_id', 'purchased_pf', 'policy_version', 'ledger_entry_uuid', 'confirmed_at'] as $field) {
            if ($payload[$field] !== $record[$field]) {
                throw new ModelViolation('h2_integrity_failure');
            }
        }
        foreach ($intent->values as $field => $value) {
            if ($record[$field] !== $value) {
                throw new ModelViolation('h2_integrity_failure');
            }
        }
        $reserved = $this->reservations->result($reservation, $this->connection->now());
        $journal = $this->connection->row($this->tables['journal'], 'event_id=%s', [$record['event_id']]);
        if ($payload['kind'] !== ClosedConsumptionSchema::SCOPE || $payload['allocations'] !== $reserved['allocations']
            || $record['payload_sha256'] !== hash('sha256', (string) $record['payload_json']) || $journal === null
            || $journal['consumption_id'] !== $record['consumption_id'] || $journal['payload_json'] !== $record['payload_json']
            || $journal['payload_sha256'] !== $record['payload_sha256'] || $journal['recorded_at'] !== $record['confirmed_at']
            || $journal['state'] !== 'pending'
        ) {
            throw new ModelViolation('h2_integrity_failure');
        }
        $this->ledger->verifyDebit((string) $record['ledger_entry_uuid'], $intent->values['member_faluss_id'],
            $intent->values['attribution_id'], $intent->values['purchased_pf'], $intent->values['policy_version']);
        return ['kind' => ClosedConsumptionSchema::SCOPE, 'state' => 'confirmed', 'consumption' => $payload, 'delivery_state' => 'pending'];
    }
}
