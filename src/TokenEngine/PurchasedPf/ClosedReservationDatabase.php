<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

use JsonException;
use Throwable;

/** One primary connection and one outer transaction for all H2 owner writes. */
final class ClosedReservationDatabase
{
    public function __construct(public readonly \wpdb $database, private readonly ?\Closure $beforeCommit = null)
    {
        ClosedReservationEnvironment::assertIsolated($database);
    }

    public static function subjectLock(string $member): string
    {
        return 'token_engine_pf_' . substr(hash('sha256', ModelValues::uuid($member) . '|funded'), 0, 32);
    }

    /**
     * @param callable():array<string,mixed> $callback
     * @return array<string,mixed>
     */
    public function write(string $member, callable $callback): array
    {
        ClosedReservationEnvironment::assertIsolated($this->database);
        $suppressed = $this->database->suppress_errors(true);
        $locks = [self::subjectLock($member), 'token_engine_pf_h1_model_' . substr(hash('sha256', $this->database->prefix), 0, 24)];
        $held = [];
        $started = false;
        try {
            if ($this->scalar('SELECT @@in_transaction') !== '0') {
                throw new ModelViolation('nested_transaction_refused');
            }
            foreach ($locks as $lock) {
                if ((string) $this->scalar($this->database->prepare('SELECT GET_LOCK(%s,10)', $lock)) !== '1') {
                    throw new ModelViolation('h2_lock_unavailable');
                }
                $held[] = $lock;
            }
            if (!\Token_Engine_Schema::is_ready() || !ClosedModelSchema::ready($this->database)
                || !ClosedReservationSchema::ready($this->database)
            ) {
                throw new ModelViolation('h2_schema_unavailable');
            }
            $this->query('START TRANSACTION');
            $started = true;
            // Same subject/class lock as the historical writer, including an empty ledger.
            $this->rows($this->database->prepare('SELECT id FROM %i WHERE faluss_id=%s AND economic_class=%s ORDER BY id FOR UPDATE',
                \Token_Engine_Schema::pf_ledger_table(), $member, 'funded'));
            $result = $callback();
            // A delegated request may expire while waiting on rows or writing its final journal.
            if ($this->beforeCommit !== null) { ($this->beforeCommit)(); }
            if ($this->database->query('COMMIT') === false || $this->database->last_error !== '') {
                throw new ModelViolation('h2_commit_unknown');
            }
            $started = false;

            return $result;
        } catch (Throwable $error) {
            if ($started) {
                // A rollback attempt cannot negate a COMMIT whose acknowledgement was lost.
                $this->database->query('ROLLBACK');
            }
            throw $error;
        } finally {
            $released = true;
            foreach (array_reverse($held) as $lock) {
                $released = (string) $this->database->get_var($this->database->prepare('SELECT RELEASE_LOCK(%s)', $lock)) === '1' && $released;
            }
            $this->database->suppress_errors($suppressed);
            if (!$released) {
                throw new ModelViolation('h2_lock_release_unknown');
            }
        }
    }

    public function assertHeldSubject(string $member): void
    {
        ClosedReservationEnvironment::assertIsolated($this->database);
        $row = $this->database->get_row($this->database->prepare(
            'SELECT @@in_transaction AS active, IS_USED_LOCK(%s)=CONNECTION_ID() AS held', self::subjectLock($member)), 'ARRAY_A');
        if ($this->database->last_error !== '' || $row === null || (string) $row['active'] !== '1' || (string) $row['held'] !== '1') {
            throw new ModelViolation('owner_transaction_required');
        }
    }

    /** Additional read authority only. Economic mutations must keep assertHeldSubject. */
    public function assertReadableSubject(string $member): void
    {
        ClosedReservationEnvironment::assertIsolated($this->database); ModelValues::uuid($member);
        $row = $this->database->get_row($this->database->prepare(
            'SELECT @@in_transaction AS active, IS_USED_LOCK(%s)=CONNECTION_ID() AS subject, (IS_USED_LOCK(%s)=CONNECTION_ID() AND IS_USED_LOCK(%s)=CONNECTION_ID()) AS corpus',
            self::subjectLock($member),ClosedCorpusTransaction::globalLock($this->database),ClosedCorpusTransaction::leaseLock($this->database)),'ARRAY_A');
        if ($this->database->last_error !== '' || $row === null || (string) $row['active'] !== '1'
            || ((string) $row['subject'] !== '1' && (string) $row['corpus'] !== '1')) { throw new ModelViolation('owner_transaction_required'); }
        if ((string) $row['subject'] !== '1') { ClosedCorpusTransaction::assertActive($this->database); }
    }

    public function query(string $sql): void
    {
        if ($this->database->query($sql) === false || $this->database->last_error !== '') {
            throw new ModelViolation('h2_storage_unavailable');
        }
    }

    /** @return list<array<string,mixed>> */
    public function rows(string $sql): array
    {
        $rows = $this->database->get_results($sql, 'ARRAY_A');
        if (!is_array($rows) || $this->database->last_error !== '') {
            throw new ModelViolation('h2_storage_unavailable');
        }
        return $rows;
    }

    /**
     * @param literal-string $where
     * @param list<mixed> $arguments
     * @return array<string,mixed>|null
     */
    public function row(string $table, string $where, array $arguments): ?array
    {
        $row = $this->database->get_row($this->database->prepare('SELECT * FROM %i WHERE ' . $where . ' LIMIT 1 FOR UPDATE', $table, ...$arguments), 'ARRAY_A');
        if ($this->database->last_error !== '') {
            throw new ModelViolation('h2_storage_unavailable');
        }
        return $row;
    }

    /** @param array<string,mixed> $values */
    public function insert(string $table, array $values): void
    {
        if ($this->database->insert($table, $values) !== 1 || $this->database->last_error !== '') {
            throw new ModelViolation('h2_storage_unavailable');
        }
    }

    /**
     * @param array<string,mixed> $values
     * @param array<string,mixed> $where
     */
    public function update(string $table, array $values, array $where): void
    {
        if ($this->database->update($table, $values, $where) !== 1 || $this->database->last_error !== '') {
            throw new ModelViolation('h2_storage_unavailable');
        }
    }

    public function now(): string
    {
        $now = $this->database->get_var('SELECT UTC_TIMESTAMP(6)');
        if (!is_string($now) || $this->database->last_error !== '') {
            throw new ModelViolation('h2_storage_unavailable');
        }
        return $now;
    }

    /**
     * Queries such as GET_LOCK mutate connection state.
     * @phpstan-impure
     */
    public function scalar(string $sql): mixed
    {
        $value = $this->database->get_var($sql);
        if ($this->database->last_error !== '') {
            throw new ModelViolation('h2_storage_unavailable');
        }
        return $value;
    }

    /** @return array<array-key,mixed> */
    public static function decode(string $json): array
    {
        try {
            $values = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ModelViolation('h2_integrity_failure');
        }
        if (!is_array($values)) {
            throw new ModelViolation('h2_integrity_failure');
        }
        return $values;
    }
}
