<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Throwable;

/** Hub owner composition only. Never take a subject mutex after the shared global mutex. */
final class ClosedCorpusTransaction
{
    private readonly ClosedReservationDatabase $connection;

    public function __construct(private readonly \wpdb $db)
    { ClosedEnvironment::assertIsolated($db,'hub'); $this->connection = new ClosedReservationDatabase($db); }

    public static function globalLock(\wpdb $db): string
    { return 'token_engine_pf_h1_model_' . substr(hash('sha256',$db->prefix),0,24); }

    public static function leaseLock(\wpdb $db): string
    { return 'pf_b3_corpus_read_' . substr(hash('sha256',$db->prefix),0,24); }

    public static function assertActive(\wpdb $db): void
    {
        ClosedEnvironment::assertIsolated($db,'hub');
        $row = $db->get_row($db->prepare('SELECT @@in_transaction AS active, IS_USED_LOCK(%s)=CONNECTION_ID() AS owner, IS_USED_LOCK(%s)=CONNECTION_ID() AS lease',
            self::globalLock($db),self::leaseLock($db)),'ARRAY_A');
        if ($db->last_error !== '' || $row === null || (string) $row['active'] !== '1' || (string) $row['owner'] !== '1' || (string) $row['lease'] !== '1') {
            throw new ModelViolation('pf_corpus_owner_transaction_required');
        }
    }

    /** Trusted Hub code composes reads/own metadata, not a SQL sandbox or economic write grant.
     * @param callable():array<string,mixed> $callback
     * @return array<string,mixed> */
    public function run(callable $callback): array
    {
        ClosedEnvironment::assertIsolated($this->db,'hub'); $suppressed = $this->db->suppress_errors(true);
        $held = []; $started = false;
        try {
            if ((string) $this->connection->scalar('SELECT @@in_transaction') !== '0') { throw new ModelViolation('nested_transaction_refused'); }
            foreach ([self::globalLock($this->db),self::leaseLock($this->db)] as $lock) {
                if ((string) $this->connection->scalar($this->db->prepare('SELECT GET_LOCK(%s,10)',$lock)) !== '1') { throw new ModelViolation('pf_corpus_busy'); }
                $held[] = $lock;
            }
            if (!ClosedRankingSchema::ready($this->db) || !ClosedRankedSnapshotSchema::ready($this->db)
                || !ClosedSnapshotSchema::ready($this->db)) { throw new ModelViolation('pf_corpus_dependency_unavailable'); }
            $this->connection->query('START TRANSACTION'); $started = true; self::assertActive($this->db);
            $result = $callback();
            if ($this->db->query('COMMIT') === false || $this->db->last_error !== '') { throw new ModelViolation('pf_corpus_commit_unknown'); }
            $started = false; return $result;
        } catch (Throwable $error) {
            if ($started) { $this->db->query('ROLLBACK'); } throw $error;
        } finally {
            $released = true;
            foreach (array_reverse($held) as $lock) { $released = (string) $this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)',$lock)) === '1' && $released; }
            $this->db->suppress_errors($suppressed);
            if (!$released) { throw new ModelViolation('pf_corpus_lock_release_unknown'); }
        }
    }
}
