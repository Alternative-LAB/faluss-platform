<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Transactions for this domain only. Never enters another module's transaction. */
final class RankingStorage
{
    public function __construct(public readonly \wpdb $database) {}

    /** @template T
     * @param callable():T $operation
     * @return T */
    public function transaction(string $scope, callable $operation): mixed
    {
        $db = $this->database;
        if (!RankingSchema::ready($db)) { throw new ModelViolation('hof_schema_unavailable'); }
        if ((string) $db->get_var('SELECT @@in_transaction') !== '0' || $this->failed()) { throw new ModelViolation('hof_nested_transaction'); }
        $lock = 'fans_hof_' . substr(hash('sha256', $db->prefix . '|' . $scope), 0, 48);
        if ((string) $db->get_var($db->prepare('SELECT GET_LOCK(%s,10)', $lock)) !== '1') { throw new ModelViolation('hof_busy'); }
        $previous = $db->suppress_errors(true);
        try {
            $this->query('START TRANSACTION');
            $result = $operation();
            if ($db->query('COMMIT') === false || $this->failed()) { throw new ModelViolation('hof_commit_unknown'); }
            return $result;
        } finally {
            $db->query('ROLLBACK'); $db->suppress_errors($previous);
            $db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    public function query(string $sql): int|bool
    {
        $result = $this->database->query($sql);
        if ($result === false || $this->failed()) { throw new ModelViolation('hof_storage_unavailable'); }
        return $result;
    }

    /** @return array<string,string>|null */
    public function row(string $sql): ?array
    {
        $row = $this->database->get_row($sql, 'ARRAY_A');
        if ($this->failed()) { throw new ModelViolation('hof_storage_unavailable'); }
        return $row;
    }

    /** @phpstan-impure Reads the most recent database operation's error. */
    private function failed(): bool { return $this->database->last_error !== ''; }
}
