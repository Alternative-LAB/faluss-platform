<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\DelegatedContext;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PrivateReceipt;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SignedEnvelope;
use Throwable;

/** Hub-owned nonce admission and durable private receipts; no bootstrap/dispatcher. */
final class ClosedProtocolStore
{
    /** @var array<string,string> */
    private readonly array $tables;

    public function __construct(private readonly \wpdb $database, private readonly string $issuer,
        private readonly string $keyId)
    {
        ClosedEnvironment::assertIsolated($database, 'hub');
        if ($issuer !== 'fixture.hub') {
            throw new ModelViolation('pf_fixture_peer_required');
        }
        $this->tables = ClosedProtocolSchema::tables($database);
    }

    public function acceptNonce(string $peer, string $nonce, string $requestDigest, string $member): void
    {
        $this->assertSchema();
        DelegatedContext::nonce($nonce);
        ModelValues::uuid($member);
        if ($peer !== 'fixture.fans' || preg_match('/^[a-f0-9]{64}$/D', $requestDigest) !== 1) {
            throw new ModelViolation('pf_invalid_peer');
        }
        $lock = 'pf_h3_nonce_' . substr(hash('sha256', $peer), 0, 32);
        $suppressed = $this->database->suppress_errors(true);
        $held = false;
        $started = false;
        $sqlConnection = new ClosedReservationDatabase($this->database);
        try {
            if ((string) $this->database->get_var('SELECT @@in_transaction') !== '0'
                || (string) $this->database->get_var($this->database->prepare('SELECT GET_LOCK(%s,10)', $lock)) !== '1'
            ) {
                throw new ModelViolation('pf_nonce_storage_unavailable');
            }
            $held = true;
            $this->query('START TRANSACTION');
            $started = true;
            $known = $this->database->get_row($this->database->prepare('SELECT nonce_sha256 FROM %i WHERE peer=%s AND nonce_sha256=%s FOR UPDATE',
                $this->tables['nonces'], $peer, hash('sha256', $nonce)), 'ARRAY_A');
            if ($this->database->last_error !== '') {
                throw new ModelViolation('pf_nonce_storage_unavailable');
            }
            if ($known !== null) {
                throw new ModelViolation('pf_network_replay');
            }
            foreach ([['peer', $peer, 600], ['member_faluss_id', $member, 60]] as [$field, $value, $limit]) {
                $count = $sqlConnection->scalar($this->database->prepare('SELECT COUNT(*) FROM %i WHERE %i=%s AND seen_at>UTC_TIMESTAMP(6)-INTERVAL 1 MINUTE',
                    $this->tables['nonces'], $field, $value));
                if (!is_numeric($count)) {
                    throw new ModelViolation('pf_nonce_storage_unavailable');
                }
                if ((int) $count >= $limit) {
                    throw new ModelViolation('pf_request_quota');
                }
            }
            $now = $sqlConnection->scalar('SELECT UTC_TIMESTAMP(6)');
            if (!is_string($now)
                || $this->database->insert($this->tables['nonces'], ['peer' => $peer, 'nonce_sha256' => hash('sha256', $nonce),
                    'request_sha256' => $requestDigest, 'member_faluss_id' => $member, 'seen_at' => $now]) !== 1
            ) {
                throw new ModelViolation('pf_nonce_storage_unavailable');
            }
            $this->commit();
            $started = false;
        } catch (Throwable $error) {
            if ($started) {
                $this->database->query('ROLLBACK');
            }
            throw $error;
        } finally {
            $released = !$held || (string) $this->database->get_var($this->database->prepare('SELECT RELEASE_LOCK(%s)', $lock)) === '1';
            $this->database->suppress_errors($suppressed);
            if (!$released) {
                throw new ModelViolation('pf_nonce_commit_unknown');
            }
        }
    }

    /** Invoked inside the official H2 consumption transaction before its final commit.
     *  @param array<string,mixed> $consumption
     */
    public function record(array $consumption): void
    {
        $this->assertSchema();
        $intent = self::intent($consumption);
        $connection = new ClosedReservationDatabase($this->database);
        $connection->assertHeldSubject($intent->values['member_faluss_id']);
        $owner = $connection->row(ClosedConsumptionSchema::tables($this->database)['consumptions'],
            'consumption_id=%s', [$consumption['consumption_id']]);
        if ($owner === null || $owner['payload_json'] !== ModelValues::encode($consumption)
            || $owner['payload_sha256'] !== hash('sha256', ModelValues::encode($consumption))
        ) {
            throw new ModelViolation('pf_receipt_integrity');
        }
        (new ClosedLedgerWriter($connection))->verifyDebit($consumption['ledger_entry_uuid'],
            $intent->values['member_faluss_id'], $intent->values['attribution_id'],
            $intent->values['purchased_pf'], $intent->values['policy_version']);
        $receipt = ['contract' => DelegatedContext::CONTRACT, 'kind' => SignedEnvelope::RECEIPT,
            'issuer' => $this->issuer, 'audience' => $intent->values['client_authority'],
            'receipt_id' => $consumption['consumption_id'], 'revision' => '1',
            'reservation_id' => $consumption['reservation_id'], 'ledger_entry_uuid' => $consumption['ledger_entry_uuid'],
            'confirmed_at' => self::utc($consumption['confirmed_at']), 'allocations' => []];
        foreach (['attribution_id', 'member_faluss_id', 'creator_faluss_id', 'purchased_pf', 'policy_version'] as $field) {
            $receipt[$field] = $intent->values[$field];
        }
        $lots = new ClosedReservationStore($this->database, ['fixture.fans'], ['fixture.purchase']);
        foreach ($consumption['allocations'] as $allocation) {
            $lot = $lots->sourceLot($allocation['lot_id'], $intent->values['member_faluss_id']);
            $receipt['allocations'][] = $allocation + [
                'allocation_id' => hash('sha256', $intent->values['attribution_id'] . '|' . $allocation['lot_id']),
                'purchase_authority' => $lot['authority_id'], 'purchase_reference' => $lot['purchase_id']];
        }
        usort($receipt['allocations'], static fn (array $a, array $b): int => strcmp($a['allocation_id'], $b['allocation_id']));
        PrivateReceipt::validate($receipt, $this->issuer, $intent->values['client_authority'], $intent);
        $bytes = CanonicalJson::encode($receipt);
        $envelope = SignedEnvelope::seal(SignedEnvelope::RECEIPT, $bytes, $this->keyId);
        $connection->insert($this->tables['receipts'], ['receipt_id' => $receipt['receipt_id'],
            'attribution_id' => $intent->values['attribution_id'], 'payload_json' => $bytes,
            'payload_sha256' => hash('sha256', $bytes), 'original_envelope_json' => CanonicalJson::encode($envelope),
            'created_at' => $consumption['confirmed_at']]);
    }

    /** @param array<string,mixed> $consumption
     *  @return array<string,string>
     */
    public function receipt(AttributionIntent $intent, array $consumption): array
    {
        $this->assertSchema();
        $connection = new ClosedReservationDatabase($this->database);
        return $connection->write($intent->values['member_faluss_id'], function () use ($intent, $consumption, $connection): array {
            $owner = $connection->row(ClosedConsumptionSchema::tables($this->database)['consumptions'],
                'consumption_id=%s', [$consumption['consumption_id'] ?? null]);
            if ($owner === null || $owner['payload_json'] !== ModelValues::encode($consumption)
                || $owner['payload_sha256'] !== hash('sha256', ModelValues::encode($consumption))
            ) {
                throw new ModelViolation('pf_receipt_integrity');
            }
            (new ClosedLedgerWriter($connection))->verifyDebit($consumption['ledger_entry_uuid'],
                $intent->values['member_faluss_id'], $intent->values['attribution_id'],
                $intent->values['purchased_pf'], $intent->values['policy_version']);
            $row = $connection->row($this->tables['receipts'], 'attribution_id=%s', [$intent->values['attribution_id']]);
            if ($row === null || $row['receipt_id'] !== $consumption['consumption_id']
                || $row['payload_sha256'] !== hash('sha256', (string) $row['payload_json'])
            ) {
                throw new ModelViolation('pf_receipt_integrity');
            }
            $payload = CanonicalJson::object((string) $row['payload_json']);
            PrivateReceipt::validate($payload, $this->issuer, $intent->values['client_authority'], $intent);
            if ($payload['reservation_id'] !== $consumption['reservation_id']
                || $payload['ledger_entry_uuid'] !== $consumption['ledger_entry_uuid']
                || $payload['confirmed_at'] !== self::utc($consumption['confirmed_at'])
            ) {
                throw new ModelViolation('pf_receipt_integrity');
            }
            $allocations = [];
            $lots = new ClosedReservationStore($this->database, ['fixture.fans'], ['fixture.purchase']);
            foreach ($payload['allocations'] as $allocation) {
                $lot = $lots->sourceLot($allocation['lot_id'], $intent->values['member_faluss_id']);
                if ($allocation['purchase_authority'] !== $lot['authority_id'] || $allocation['purchase_reference'] !== $lot['purchase_id']) {
                    throw new ModelViolation('pf_receipt_integrity');
                }
                $allocations[] = array_intersect_key($allocation, array_flip(['lot_id','evidence_id','source_revision','purchased_pf']));
            }
            usort($allocations, static fn (array $a, array $b): int => strcmp($a['lot_id'], $b['lot_id']));
            if (CanonicalJson::encode($allocations) !== CanonicalJson::encode($consumption['allocations'])) {
                throw new ModelViolation('pf_receipt_integrity');
            }
            $original = CanonicalJson::object((string) $row['original_envelope_json']);
            if (($original['payload_sha256'] ?? '') !== $row['payload_sha256']
                || SignedEnvelope::decode((string) ($original['payload_base64url'] ?? '')) !== $row['payload_json']
            ) {
                throw new ModelViolation('pf_receipt_integrity');
            }
            // Re-attest unchanged canonical bytes with the currently configured fixture key; no second debit.
            return SignedEnvelope::seal(SignedEnvelope::RECEIPT, (string) $row['payload_json'], $this->keyId);
        });
    }

    private function assertSchema(): void
    {
        if (!ClosedProtocolSchema::ready($this->database)) {
            throw new ModelViolation('pf_protocol_schema_unavailable');
        }
    }

    private function query(string $sql): void
    {
        if ($this->database->query($sql) === false || $this->database->last_error !== '') {
            throw new ModelViolation('pf_nonce_storage_unavailable');
        }
    }

    private function commit(): void
    {
        if ($this->database->query('COMMIT') === false || $this->database->last_error !== '') {
            throw new ModelViolation('pf_nonce_commit_unknown');
        }
    }

    /** @param array<string,mixed> $consumption */
    private static function intent(array $consumption): AttributionIntent
    {
        $values = [];
        foreach (['attribution_id', 'client_authority', 'member_faluss_id', 'creator_faluss_id', 'purchased_pf', 'policy_version'] as $field) {
            $values[$field] = $consumption[$field] ?? null;
        }
        return AttributionIntent::fromArray($values);
    }

    private static function utc(string $databaseDate): string
    {
        return ModelValues::utc(str_replace(' ', 'T', substr($databaseDate, 0, 19)) . 'Z');
    }
}
