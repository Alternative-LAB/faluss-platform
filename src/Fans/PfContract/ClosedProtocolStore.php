<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\PfContract;

use Faluss\Platform\TokenEngine\PurchasedPf\AttributionIntent;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PrivateReceipt;
use Throwable;

/** Fans-owned immutable intentions, stable operation keys and private proof inbox; no PF ledger/score. */
final class ClosedProtocolStore
{
    /** @var array<string,string> */
    private readonly array $tables;

    public function __construct(private readonly \wpdb $database)
    {
        ClosedEnvironment::assertIsolated($database, 'fans');
        $this->tables = ClosedProtocolSchema::tables($database);
    }

    public function prepare(AttributionIntent $intent, string $operation, bool $lookup = false): string
    {
        if (!in_array($operation, ['reserve','confirm','release'], true) || $intent->values['client_authority'] !== 'fixture.fans') {
            throw new ModelViolation('pf_invalid_operation');
        }
        $result = $this->write($intent, function () use ($intent, $operation, $lookup): array {
            $bytes = CanonicalJson::encode($intent->values);
            $known = $this->row('intents', 'attribution_id=%s', [$intent->values['attribution_id']]);
            if ($known === null) {
                if ($lookup) { throw new ModelViolation('pf_local_key_absent'); }
                $this->insert('intents', ['attribution_id' => $intent->values['attribution_id'],
                    'member_faluss_id' => $intent->values['member_faluss_id'], 'payload_json' => $bytes, 'payload_sha256' => hash('sha256', $bytes)]);
            } elseif ($known['payload_json'] !== $bytes || $known['payload_sha256'] !== hash('sha256', $bytes)
                || $known['member_faluss_id'] !== $intent->values['member_faluss_id']) {
                throw new ModelViolation('pf_local_intent_conflict');
            }
            $key = $this->row('keys', 'attribution_id=%s AND operation=%s', [$intent->values['attribution_id'], $operation]);
            if ($key === null) {
                if ($lookup) { throw new ModelViolation('pf_local_key_absent'); }
                $value = bin2hex(random_bytes(32));
                $this->insert('keys', ['attribution_id' => $intent->values['attribution_id'], 'operation' => $operation, 'operation_key' => $value]);
            } else {
                $value = (string) $key['operation_key'];
                ModelValues::keyHash($value);
            }
            return ['key' => $value];
        });
        return $result['key'];
    }

    /** Signature and fresh response are checked by the client before this durable inbox write.
     * @param array<string,mixed> $payload */
    public function receive(AttributionIntent $intent, array $payload): bool
    {
        PrivateReceipt::validate($payload, 'fixture.hub', 'fixture.fans', $intent);
        $result = $this->write($intent, function () use ($intent, $payload): array {
            $stored = $this->row('intents', 'attribution_id=%s', [$intent->values['attribution_id']]);
            $intentBytes = CanonicalJson::encode($intent->values);
            if ($stored === null || $stored['payload_json'] !== $intentBytes || $stored['payload_sha256'] !== hash('sha256', $intentBytes)) {
                throw new ModelViolation('pf_local_intent_conflict');
            }
            $bytes = CanonicalJson::encode($payload);
            $digest = hash('sha256', $bytes);
            $known = $this->row('receipts', 'attribution_id=%s', [$intent->values['attribution_id']]);
            if ($known !== null) {
                if ($known['issuer'] !== $payload['issuer'] || $known['receipt_id'] !== $payload['receipt_id']
                    || $known['revision'] !== $payload['revision'] || $known['member_faluss_id'] !== $intent->values['member_faluss_id']
                    || $known['payload_json'] !== $bytes || $known['payload_sha256'] !== $digest) {
                    throw new ModelViolation('pf_receipt_conflict');
                }
                return ['inserted' => false];
            }
            $this->insert('receipts', ['issuer' => $payload['issuer'], 'receipt_id' => $payload['receipt_id'],
                'revision' => $payload['revision'], 'attribution_id' => $intent->values['attribution_id'],
                'member_faluss_id' => $intent->values['member_faluss_id'], 'payload_json' => $bytes, 'payload_sha256' => $digest]);
            return ['inserted' => true];
        });
        return $result['inserted'];
    }

    /** @param callable():array<string,mixed> $operation
     *  @return array<string,mixed> */
    private function write(AttributionIntent $intent, callable $operation): array
    {
        ClosedEnvironment::assertIsolated($this->database, 'fans');
        if (!ClosedProtocolSchema::ready($this->database)) { throw new ModelViolation('pf_protocol_schema_unavailable'); }
        $lock = 'fans_h3_' . substr(hash('sha256', $intent->values['attribution_id']), 0, 32);
        $suppressed = $this->database->suppress_errors(true);
        $held = $started = false;
        try {
            if ((string) $this->database->get_var('SELECT @@in_transaction') !== '0'
                || (string) $this->database->get_var($this->database->prepare('SELECT GET_LOCK(%s,10)', $lock)) !== '1') {
                throw new ModelViolation('pf_local_storage_unavailable');
            }
            $held = true;
            $this->query('START TRANSACTION');
            $started = true;
            $result = $operation();
            $this->commit();
            $started = false;
            return $result;
        } catch (Throwable $error) {
            if ($started) { $this->database->query('ROLLBACK'); }
            throw $error;
        } finally {
            $released = !$held || (string) $this->database->get_var($this->database->prepare('SELECT RELEASE_LOCK(%s)', $lock)) === '1';
            $this->database->suppress_errors($suppressed);
            if (!$released) { throw new ModelViolation('pf_local_commit_unknown'); }
        }
    }

    /** @param literal-string $where
     *  @param list<mixed> $arguments
     *  @return array<string,mixed>|null */
    private function row(string $kind, string $where, array $arguments): ?array
    {
        $row = $this->database->get_row($this->database->prepare('SELECT * FROM %i WHERE ' . $where . ' LIMIT 1 FOR UPDATE', $this->tables[$kind], ...$arguments), 'ARRAY_A');
        if ($this->database->last_error !== '') { throw new ModelViolation('pf_local_storage_unavailable'); }
        return $row;
    }

    /** @param array<string,mixed> $values */
    private function insert(string $kind, array $values): void
    {
        if ($this->database->insert($this->tables[$kind], $values) !== 1 || $this->database->last_error !== '') {
            throw new ModelViolation('pf_local_storage_unavailable');
        }
    }

    private function query(string $sql): void
    {
        if ($this->database->query($sql) === false || $this->database->last_error !== '') {
            throw new ModelViolation('pf_local_storage_unavailable');
        }
    }

    private function commit(): void
    {
        if ($this->database->query('COMMIT') === false || $this->database->last_error !== '') {
            throw new ModelViolation('pf_local_commit_unknown');
        }
    }
}
