<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\PfProjection;

use Faluss\Platform\Fans\PfContract\ClosedSnapshotInbox;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;

/** Complete private derived generation. No input event quantity, ledger access or public route. */
final class ClosedProjectionStore
{
    /** @var array<string,string> */
    private readonly array $tables;

    public function __construct(private readonly \wpdb $database, private readonly string $epoch)
    {
        ClosedProjectionEnvironment::assertIsolated($database);
        ModelValues::uuid($epoch);
        $this->tables = ClosedProjectionSchema::tables($database);
    }

    /** A receipt/event is only a wake-up hint; always re-read the latest complete H4 facts.
     * @return array{state:string,generation:string} */
    public function rebuild(): array
    {
        return $this->fenced(function (array $document): array {
            foreach (['attributions', 'fans', 'creators', 'generation'] as $kind) {
                $this->query($this->database->prepare('DELETE FROM %i', $this->tables[$kind]));
            }
            foreach (['attributions', 'fans', 'creators'] as $kind) {
                foreach ($document[$kind] as $row) {
                    if ($this->database->insert($this->tables[$kind], $row) !== 1 || $this->database->last_error !== '') {
                        throw new ModelViolation('pf_projection_storage_unavailable');
                    }
                }
            }
            $bytes = CanonicalJson::encode($document);
            $digest = hash('sha256', $bytes);
            if ($this->database->insert($this->tables['generation'], ['id' => '1', 'document_json' => $bytes,
                'document_sha256' => $digest]) !== 1 || $this->database->last_error !== '') {
                throw new ModelViolation('pf_projection_storage_unavailable');
            }
            return ['state' => 'attested', 'generation' => $digest];
        });
    }

    /** No stale/partial generation is returned. The source vector is private evidence, not a ranking policy.
     * @return array<string,mixed> */
    public function read(): array
    {
        return $this->fenced(function (array $document): array {
            $bytes = CanonicalJson::encode($document);
            $stored = $this->rows('generation', 'id');
            if ($stored !== [['id' => '1', 'document_json' => $bytes,
                'document_sha256' => hash('sha256', $bytes)]]) { throw new ModelViolation('pf_projection_not_reconciled'); }
            foreach (['attributions' => 'attribution_id', 'fans' => 'faluss_id', 'creators' => 'faluss_id'] as $kind => $order) {
                if (CanonicalJson::encode($this->rows($kind, $order)) !== CanonicalJson::encode($document[$kind])) {
                    throw new ModelViolation('pf_projection_not_reconciled');
                }
            }
            return ['state' => 'attested', 'generation' => hash('sha256', $bytes),
                'scope' => 'reconciled_members',
                'sources' => $document['sources'], 'fans' => $document['fans'], 'creators' => $document['creators']];
        });
    }

    /** @template T
     * @param callable(array{contract:string,epoch:string,sources:list<array<string,string>>,attributions:list<array<string,string>>,
     *     fans:list<array{faluss_id:string,points:string}>,creators:list<array{faluss_id:string,points:string}>}):T $operation
     * @return T */
    private function fenced(callable $operation): mixed
    {
        ClosedProjectionEnvironment::assertIsolated($this->database);
        if (!ClosedProjectionSchema::ready($this->database)) { throw new ModelViolation('pf_projection_schema_unavailable'); }
        $lock = 'fans_f1a_' . substr(hash('sha256', $this->database->prefix), 0, 40);
        if ((string) $this->database->get_var('SELECT @@in_transaction') !== '0'
            || (string) $this->database->get_var($this->database->prepare('SELECT GET_LOCK(%s,10)', $lock)) !== '1') {
            throw new ModelViolation('pf_projection_storage_unavailable');
        }
        try {
            return (new ClosedSnapshotInbox($this->database, $this->epoch))->withReconciledSet(
                fn (array $snapshots): mixed => $operation(ProjectionFacts::build($snapshots, $this->epoch)));
        } finally {
            if ((string) $this->database->get_var($this->database->prepare('SELECT RELEASE_LOCK(%s)', $lock)) !== '1') {
                throw new ModelViolation('pf_local_commit_unknown');
            }
        }
    }

    /** @return list<array<string,string>> */
    private function rows(string $kind, string $order): array
    {
        $rows = $this->database->get_results($this->database->prepare('SELECT * FROM %i ORDER BY %i FOR UPDATE',
            $this->tables[$kind], $order), 'ARRAY_A');
        if ($this->database->last_error !== '' || !is_array($rows)) {
            throw new ModelViolation('pf_projection_not_reconciled');
        }

        return $rows;
    }

    private function query(string $sql): void
    {
        if ($this->database->query($sql) === false || $this->database->last_error !== '') {
            throw new ModelViolation('pf_projection_storage_unavailable');
        }
    }
}
