<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

use JsonException;
use Throwable;

/**
 * H1 persistence only. No producer registration, PF writer, reservation or API.
 * Every entry point requires the private, socket-only WordPress recipe.
 */
final class ClosedModelStore
{
    /** @var array<string, string> */
    private readonly array $tables;

    /**
     * @param list<string> $evidenceAuthorities
     * @param list<string> $clientAuthorities
     */
    public function __construct(
        private readonly \wpdb $database,
        private readonly array $evidenceAuthorities,
        private readonly array $clientAuthorities,
    ) {
        ClosedModelEnvironment::assertIsolated($database);
        foreach (array_merge($evidenceAuthorities, $clientAuthorities) as $authority) {
            ModelValues::authority($authority);
            if (!str_starts_with($authority, 'fixture.')) {
                throw new ModelViolation('fixture_authority_required');
            }
        }
        $this->tables = ClosedModelSchema::tables($database);
    }

    /** @return array<string, mixed> */
    public function recordEvidence(PurchaseEvidence $evidence, string $key): array
    {
        $scope = (string) $evidence->values['authority_id'];
        $this->assertAuthority($scope, $this->evidenceAuthorities);
        $keyHash = ModelValues::keyHash($key);

        return $this->write(function () use ($evidence, $scope, $keyHash): array {
            $fingerprint = $evidence->fingerprint();
            $knownKey = $this->key($scope, 'evidence', $keyHash);
            if ($knownKey !== null) {
                $this->assertFingerprint($knownKey, $fingerprint);

                return $this->evidenceResult((string) $knownKey['record_id'], true, $fingerprint);
            }
            $lot = $this->row('lots', 'authority_id = %s AND purchase_id = %s', [$scope, $evidence->values['purchase_id']]);
            if ($lot !== null) {
                $previous = $this->loadEvidence((string) $lot['latest_evidence_id']);
                $this->verifyLot($lot, $previous);
                if ($evidence->values['source_revision'] === (string) $lot['source_revision']) {
                    if (!hash_equals($previous->fingerprint(), $fingerprint)) {
                        throw new ModelViolation('evidence_conflict');
                    }
                    $this->rememberKey($scope, 'evidence', $keyHash, $fingerprint, (string) $lot['latest_evidence_id']);

                    return $this->evidenceResult((string) $lot['latest_evidence_id'], true);
                }
                $evidence->assertSuccessorOf($previous);
            }
            if ($this->row('evidence', 'evidence_id = %s', [$evidence->values['evidence_id']]) !== null) {
                throw new ModelViolation('evidence_conflict');
            }
            $now = $this->now();
            $this->insert('evidence', [
                'evidence_id' => $evidence->values['evidence_id'], 'authority_id' => $scope,
                'purchase_id' => $evidence->values['purchase_id'], 'source_revision' => $evidence->values['source_revision'],
                'member_faluss_id' => $evidence->values['member_faluss_id'], 'payload_sha256' => $fingerprint,
                'immutable_sha256' => $evidence->immutableFingerprint(), 'payload_json' => ModelValues::encode($evidence->values),
                'recorded_at' => $now,
            ]);
            $lotId = $lot === null ? wp_generate_uuid4() : (string) $lot['lot_id'];
            ModelValues::uuid($lotId);
            $acceptedAt = $lot['accepted_at'] ?? null;
            if ($acceptedAt === null && $evidence->values['state'] === 'confirmed') {
                $acceptedAt = $now;
            }
            $lotValues = ['lot_id' => $lotId, 'authority_id' => $scope, 'purchase_id' => $evidence->values['purchase_id'],
                'member_faluss_id' => $evidence->values['member_faluss_id'], 'latest_evidence_id' => $evidence->values['evidence_id'],
                'source_revision' => $evidence->values['source_revision'], 'source_state' => $evidence->values['state'],
                'purchased_pf' => $evidence->values['purchased_pf'], 'bonus_pf' => $evidence->values['bonus_pf'],
                'immutable_sha256' => $evidence->immutableFingerprint(), 'accepted_at' => $acceptedAt];
            if ($lot === null) {
                $this->insert('lots', $lotValues);
            } elseif ($this->database->update($this->tables['lots'], $lotValues, ['lot_id' => $lotId]) !== 1) {
                throw new ModelViolation('model_storage_unavailable');
            }
            $this->rememberKey($scope, 'evidence', $keyHash, $fingerprint, (string) $evidence->values['evidence_id']);

            return $this->evidenceResult((string) $evidence->values['evidence_id'], false);
        });
    }

    /** @return array<string, mixed> */
    public function recordIntent(AttributionIntent $intent, string $key): array
    {
        $scope = $intent->values['client_authority'];
        $this->assertAuthority($scope, $this->clientAuthorities);
        $keyHash = ModelValues::keyHash($key);

        return $this->write(function () use ($intent, $scope, $keyHash): array {
            $fingerprint = $intent->fingerprint();
            $knownKey = $this->key($scope, 'intent', $keyHash);
            if ($knownKey !== null) {
                $this->assertFingerprint($knownKey, $fingerprint);

                return $this->intentResult((string) $knownKey['record_id'], true, $fingerprint);
            }
            $existing = $this->row('intents', 'attribution_id = %s', [$intent->values['attribution_id']]);
            if ($existing !== null) {
                $this->assertFingerprint($existing, $fingerprint);
                $this->rememberKey($scope, 'intent', $keyHash, $fingerprint, $intent->values['attribution_id']);

                return $this->intentResult($intent->values['attribution_id'], true);
            }
            $plan = AllocationPlan::forIntent($intent, $this->modelLots($intent->values['member_faluss_id']));
            $planJson = ModelValues::encode($plan);
            $this->insert('intents', ['attribution_id' => $intent->values['attribution_id'], 'client_authority' => $scope,
                'member_faluss_id' => $intent->values['member_faluss_id'], 'creator_faluss_id' => $intent->values['creator_faluss_id'],
                'purchased_pf' => $intent->values['purchased_pf'], 'payload_sha256' => $fingerprint,
                'payload_json' => ModelValues::encode($intent->values), 'plan_json' => $planJson,
                'plan_sha256' => hash('sha256', $planJson), 'recorded_at' => $this->now()]);
            $this->rememberKey($scope, 'intent', $keyHash, $fingerprint, $intent->values['attribution_id']);

            return $this->intentResult($intent->values['attribution_id'], false);
        });
    }

    /**
     * Private model recovery only; not an economic or network lookup.
     * @return array<string, mixed>
     */
    public function lookup(string $scope, string $operation, string $key): array
    {
        if (!in_array($operation, ['evidence', 'intent'], true)) {
            throw new ModelViolation('invalid_model_operation');
        }
        $this->assertAuthority($scope, $operation === 'evidence' ? $this->evidenceAuthorities : $this->clientAuthorities);
        $keyHash = ModelValues::keyHash($key);
        ClosedModelEnvironment::assertIsolated($this->database);
        if (!ClosedModelSchema::ready($this->database)) {
            throw new ModelViolation('model_schema_unavailable');
        }
        $previousSuppression = $this->database->suppress_errors(true);
        try {
            $row = $this->key($scope, $operation, $keyHash);
            if ($row === null) {
                return ['kind' => ClosedModelSchema::SCOPE, 'state' => 'model_not_found'];
            }

            return $operation === 'evidence' ? $this->evidenceResult((string) $row['record_id'], true, (string) $row['payload_sha256'])
                : $this->intentResult((string) $row['record_id'], true, (string) $row['payload_sha256']);
        } finally {
            $this->database->suppress_errors($previousSuppression);
        }
    }

    /**
     * @param callable():array<string,mixed> $callback
     * @return array<string,mixed>
     */
    private function write(callable $callback): array
    {
        ClosedModelEnvironment::assertIsolated($this->database);
        $suppressed = $this->database->suppress_errors(true);
        $lock = 'token_engine_pf_h1_model_' . substr(hash('sha256', $this->database->prefix), 0, 24);
        $locked = false;
        try {
            if ($this->database->get_var('SELECT @@in_transaction') !== '0') {
                throw new ModelViolation('nested_transaction_refused');
            }
            $locked = (string) $this->database->get_var($this->database->prepare('SELECT GET_LOCK(%s,10)', $lock)) === '1';
            if (!$locked || !ClosedModelSchema::ready($this->database)) {
                throw new ModelViolation('model_schema_or_lock_unavailable');
            }
            if ($this->database->query('START TRANSACTION') === false) {
                throw new ModelViolation('model_storage_unavailable');
            }
            $result = $callback();
            if ($this->database->query('COMMIT') === false || $this->database->last_error !== '') {
                throw new ModelViolation('model_commit_unknown');
            }

            return $result;
        } catch (Throwable $error) {
            if ($locked) {
                // This does not assert that an ambiguously acknowledged COMMIT rolled back.
                $this->database->query('ROLLBACK');
            }
            throw $error;
        } finally {
            $released = !$locked || (string) $this->database->get_var($this->database->prepare('SELECT RELEASE_LOCK(%s)', $lock)) === '1';
            $this->database->suppress_errors($suppressed);
            if (!$released) {
                throw new ModelViolation('model_lock_release_unknown');
            }
        }
    }

    /** @param list<string> $allowed */
    private function assertAuthority(string $authority, array $allowed): void
    {
        ModelValues::authority($authority);
        if (!in_array($authority, $allowed, true)) {
            throw new ModelViolation('model_authority_not_admitted');
        }
    }

    /** @param array<string,mixed> $row */
    private function assertFingerprint(array $row, string $expected): void
    {
        if (!is_string($row['payload_sha256'] ?? null) || !hash_equals($row['payload_sha256'], $expected)) {
            throw new ModelViolation('idempotency_or_attribution_conflict');
        }
    }

    /**
     * @param literal-string $where
     * @param list<mixed> $arguments
     * @return array<string,mixed>|null
     */
    private function row(string $kind, string $where, array $arguments): ?array
    {
        $query = $this->database->prepare('SELECT * FROM %i WHERE ' . $where . ' LIMIT 1', $this->tables[$kind], ...$arguments);
        $row = $this->database->get_row($query, 'ARRAY_A');
        if ($this->database->last_error !== '') {
            throw new ModelViolation('model_storage_unavailable');
        }

        return $row;
    }

    /** @return array<string,mixed>|null */
    private function key(string $scope, string $operation, string $hash): ?array
    {
        return $this->row('keys', 'authority_id = %s AND operation = %s AND key_sha256 = %s', [$scope, $operation, $hash]);
    }

    private function rememberKey(string $scope, string $operation, string $hash, string $fingerprint, string $recordId): void
    {
        $this->insert('keys', ['authority_id' => $scope, 'operation' => $operation, 'key_sha256' => $hash,
            'payload_sha256' => $fingerprint, 'record_id' => $recordId, 'recorded_at' => $this->now()]);
    }

    /** @param array<string,mixed> $values */
    private function insert(string $kind, array $values): void
    {
        if ($this->database->insert($this->tables[$kind], $values) !== 1 || $this->database->last_error !== '') {
            throw new ModelViolation('model_storage_unavailable');
        }
    }

    private function now(): string
    {
        $now = $this->database->get_var('SELECT UTC_TIMESTAMP(6)');
        if (!is_string($now) || $this->database->last_error !== '') {
            throw new ModelViolation('model_storage_unavailable');
        }

        return $now;
    }

    /** @return array<array-key,mixed> */
    private function decode(string $json): array
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ModelViolation('model_integrity_failure');
        }
        if (!is_array($data)) {
            throw new ModelViolation('model_integrity_failure');
        }

        return $data;
    }

    private function loadEvidence(string $id): PurchaseEvidence
    {
        $row = $this->row('evidence', 'evidence_id = %s', [$id]);
        if ($row === null) {
            throw new ModelViolation('model_integrity_failure');
        }
        $evidence = PurchaseEvidence::fromArray($this->decode((string) $row['payload_json']));
        $this->assertFingerprint($row, $evidence->fingerprint());
        if ($evidence->values['evidence_id'] !== $id || $row['authority_id'] !== $evidence->values['authority_id']
            || $row['purchase_id'] !== $evidence->values['purchase_id']
            || (string) $row['source_revision'] !== $evidence->values['source_revision']
            || $row['member_faluss_id'] !== $evidence->values['member_faluss_id']
            || $row['immutable_sha256'] !== $evidence->immutableFingerprint()
        ) {
            throw new ModelViolation('model_integrity_failure');
        }

        return $evidence;
    }

    /** @param array<string,mixed> $lot */
    private function verifyLot(array $lot, PurchaseEvidence $evidence): void
    {
        foreach (['authority_id', 'purchase_id', 'member_faluss_id', 'purchased_pf', 'bonus_pf', 'source_revision'] as $field) {
            if ((string) $lot[$field] !== $evidence->values[$field]) {
                throw new ModelViolation('model_integrity_failure');
            }
        }
        if ($lot['source_state'] !== $evidence->values['state'] || $lot['immutable_sha256'] !== $evidence->immutableFingerprint()) {
            throw new ModelViolation('model_integrity_failure');
        }
    }

    /** @return array<string,mixed> */
    private function evidenceResult(string $id, bool $existing, ?string $expectedFingerprint = null): array
    {
        $evidence = $this->loadEvidence($id);
        if ($expectedFingerprint !== null && !hash_equals($expectedFingerprint, $evidence->fingerprint())) {
            throw new ModelViolation('model_integrity_failure');
        }
        $lot = $this->row('lots', 'authority_id = %s AND purchase_id = %s', [$evidence->values['authority_id'], $evidence->values['purchase_id']]);
        if ($lot === null || $lot['immutable_sha256'] !== $evidence->immutableFingerprint()) {
            throw new ModelViolation('model_integrity_failure');
        }

        return ['kind' => ClosedModelSchema::SCOPE, 'state' => $existing ? 'model_replayed' : 'model_recorded',
            'evidence_id' => $id, 'lot_id' => (string) $lot['lot_id'], 'source_revision' => $evidence->values['source_revision']];
    }

    /** @return list<array{lot_id:string,member_faluss_id:string,purchased_pf:string,evidence_id:string,source_revision:string,accepted_at:string}> */
    private function modelLots(string $member): array
    {
        $query = $this->database->prepare('SELECT * FROM %i WHERE member_faluss_id = %s AND source_state = %s AND accepted_at IS NOT NULL ORDER BY accepted_at,lot_id LIMIT 33', $this->tables['lots'], $member, 'confirmed');
        $rows = $this->database->get_results($query, 'ARRAY_A');
        if (!is_array($rows) || $this->database->last_error !== '') {
            throw new ModelViolation('model_storage_unavailable');
        }
        $lots = [];
        foreach ($rows as $row) {
            $evidence = $this->loadEvidence((string) $row['latest_evidence_id']);
            $this->verifyLot($row, $evidence);
            $lots[] = ['lot_id' => (string) $row['lot_id'], 'member_faluss_id' => $member,
                'purchased_pf' => (string) $row['purchased_pf'], 'evidence_id' => (string) $row['latest_evidence_id'],
                'source_revision' => (string) $row['source_revision'], 'accepted_at' => (string) $row['accepted_at']];
        }

        return $lots;
    }

    /** @return array<string,mixed> */
    private function intentResult(string $id, bool $existing, ?string $expectedFingerprint = null): array
    {
        $row = $this->row('intents', 'attribution_id = %s', [$id]);
        if ($row === null) {
            throw new ModelViolation('model_integrity_failure');
        }
        $intent = AttributionIntent::fromArray($this->decode((string) $row['payload_json']));
        $this->assertFingerprint($row, $intent->fingerprint());
        if ($expectedFingerprint !== null && !hash_equals($expectedFingerprint, $intent->fingerprint())) {
            throw new ModelViolation('model_integrity_failure');
        }
        $planJson = (string) $row['plan_json'];
        $plan = $this->decode($planJson);
        if ($intent->values['attribution_id'] !== $id || !array_is_list($plan)
            || !hash_equals((string) $row['plan_sha256'], hash('sha256', $planJson))
        ) {
            throw new ModelViolation('model_integrity_failure');
        }
        foreach (['client_authority', 'member_faluss_id', 'creator_faluss_id', 'purchased_pf'] as $field) {
            if ((string) $row[$field] !== $intent->values[$field]) {
                throw new ModelViolation('model_integrity_failure');
            }
        }

        return ['kind' => ClosedModelSchema::SCOPE, 'state' => $existing ? 'model_replayed' : 'model_recorded',
            'attribution_id' => $id, 'model_allocations' => $plan];
    }
}
