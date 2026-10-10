<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\Fans\PfContract\ClosedCorpusInbox;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;

/** Derived cache only, always fenced by the current complete authenticated corpus. No event/receipt input or ledger. */
final class ClosedRankingProjectionStore
{
    private readonly ClosedCorpusInbox $inbox;
    private readonly string $table;

    public function __construct(private readonly \wpdb $db, PeerPolicy $peer,
        private readonly string $origin, private readonly string $policy)
    {
        ClosedEnvironment::assertIsolated($db,'fans'); ModelValues::uuid($origin);
        if ($policy !== RankingPolicy::VERSION) { throw new ModelViolation('hof_projection_source_mismatch'); }
        $this->inbox = new ClosedCorpusInbox($db,$peer,$origin,$policy);
        $this->table = ClosedRankingProjectionSchema::tables($db)['generation'];
    }

    /** A notification only triggers this method; it cannot supply quantities or an old generation.
     * @return array{state:string,generation:string} */
    public function rebuild(): array
    {
        return $this->fenced(function (array $document): array {
            $bytes = RankingProjectionDocument::encode($document); $digest = hash('sha256',$bytes);
            $this->query($this->db->prepare('DELETE FROM %i WHERE origin_id=%s AND policy_version=%s',
                $this->table,$this->origin,$this->policy));
            $this->insert(['origin_id' => $this->origin,'policy_version' => $this->policy,
                'document_json' => $bytes,'document_sha256' => $digest]);
            return ['state' => 'reconciled','generation' => $digest];
        });
    }

    /** Private corrected generation at its primary attested instant, never a public visibility projection.
     * @return array<string,mixed> */
    public function read(): array
    {
        return $this->fenced(fn (array $document): array => $this->readCached($document));
    }

    /** Private source composition with authenticated barriers and visibility in the caller's transaction.
     * A named lock alone is not a Hub proof, identity mapping or public delivery grant.
     * @template T
     * @param callable(array<string,mixed>):T $operation
     * @return T */
    public function withReadInTransaction(callable $operation): mixed
    {
        return $this->fenced(fn (array $document): mixed => $operation($this->readCached($document)),true);
    }

    /** @template T
     * @param callable(array<string,mixed>):T $operation
     * @return T */
    private function fenced(callable $operation, bool $callerTransaction = false): mixed
    {
        ClosedEnvironment::assertIsolated($this->db,'fans');
        if (!ClosedRankingProjectionSchema::ready($this->db)) { throw new ModelViolation('hof_projection_schema_unavailable'); }
        $compose = function (?array $verified) use ($operation): mixed {
            if ($verified === null) { throw new ModelViolation('hof_projection_source_unavailable'); }
            /** @var array{manifest:array<string,mixed>,facts:list<array<string,mixed>>,verified_at:string} $verified Authenticated inbox callback shape. */
            $document = RankingCorpusProjection::build($verified);
            $sessions = RankingSessionProjection::build($verified);
            return $operation($document + ['cache_format' => '2','sessions' => $sessions['sessions']]);
        };
        return $callerTransaction ? $this->inbox->withCurrentInTransaction($compose) : $this->inbox->withCurrent($compose);
    }

    /** @param array<string,mixed> $document
     * @return array<string,mixed> */
    private function readCached(array $document): array
    {
        $bytes = RankingProjectionDocument::encode($document); $digest = hash('sha256',$bytes);
        $row = $this->db->get_row($this->db->prepare('SELECT document_json,document_sha256 FROM %i WHERE origin_id=%s AND policy_version=%s FOR UPDATE',
            $this->table,$this->origin,$this->policy),'ARRAY_A');
        if ($this->db->last_error !== '' || $row !== ['document_json' => $bytes,'document_sha256' => $digest]) {
            throw new ModelViolation('hof_projection_not_reconciled');
        }
        return ['state' => 'reconciled','generation' => $digest,'document' => $document];
    }

    private function query(string $sql): void
    {
        if ($this->db->query($sql) === false || $this->db->last_error !== '') { throw new ModelViolation('hof_projection_storage_unavailable'); }
    }

    /** @param array<string,string> $values */
    private function insert(array $values): void
    {
        if ($this->db->insert($this->table,$values) !== 1 || $this->db->last_error !== '') { throw new ModelViolation('hof_projection_storage_unavailable'); }
    }
}
