<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingCorpusDocument;

/** Frozen exhaustive generations and a primary current-facts fence; never an economic writer. */
final class ClosedRankingCorpusStore
{
    private readonly ClosedReservationDatabase $connection;
    private readonly ClosedRankingCorpusSource $source;
    /** @var array<string,string> */
    private readonly array $tables;

    /** @param list<string> $sources */
    public function __construct(private readonly \wpdb $db, array $sources, string $keyId)
    {
        ClosedEnvironment::assertIsolated($db,'hub'); $this->connection = new ClosedReservationDatabase($db);
        $this->source = new ClosedRankingCorpusSource($db,$sources,$keyId); $this->tables = ClosedRankingCorpusSchema::tables($db);
    }

    /** @return array<string,mixed> */
    public function create(PeerPolicy $peer, string $origin, string $policy, string $key): array
    {
        self::context($peer,$origin,$policy); $hash = ModelValues::keyHash($key);
        return (new ClosedCorpusTransaction($this->db))->run(function () use ($peer,$origin,$policy,$hash): array {
            $this->available(); $counter = $this->counter();
            $known = $this->connection->row($this->tables['corpora'],'client_authority=%s AND key_sha256=%s',[$peer->node,$hash]);
            if ($known !== null) { $this->scope($known,$origin,$policy); return $this->first($known); }
            $current = $this->source->readInOwnerTransaction($peer,$origin,$policy); $facts = $current['facts'];
            $summary = RankingCorpusDocument::summary($facts);
            if ((int) $counter['revision'] >= ModelValues::MAX_INTEGER) { throw new ModelViolation('pf_corpus_counter_unavailable'); }
            $revision = (string) ((int) $counter['revision'] + 1); $id = ModelValues::uuid(wp_generate_uuid4()); $bytes = CanonicalJson::encode($facts);
            $manifest = ['contract' => RankingCorpusDocument::CONTRACT,'corpus_id' => $id,'issuer' => 'fixture.hub','audience' => $peer->node,
                'origin_id' => $origin,'policy_version' => $policy,'ordering_epoch' => $current['ordering_epoch'],'epoch' => $counter['epoch'],
                'revision' => $revision,'last_order' => $current['last_order'],'created_at' => $current['created_at'],
                'scope' => RankingCorpusDocument::SCOPE,'full_sha256' => hash('sha256',$bytes)] + $summary;
            RankingCorpusDocument::complete($manifest,$facts); $manifestBytes = CanonicalJson::encode($manifest);
            $corpus = ['corpus_id' => $id,'client_authority' => $peer->node,'origin_id' => $origin,'policy_version' => $policy,'key_sha256' => $hash,
                'epoch' => $counter['epoch'],'revision' => $revision,'manifest_json' => $manifestBytes,'manifest_sha256' => hash('sha256',$manifestBytes),
                'full_sha256' => $manifest['full_sha256'],'facts_json' => $bytes,'recorded_at' => $current['created_at']];
            $this->connection->insert($this->tables['corpora'],$corpus); $cursors = [];
            for ($page = 0; $page < (int) $summary['page_count']; $page++) { $cursors[] = wp_generate_uuid4(); }
            foreach ($cursors as $index => $cursor) {
                $payload = ['manifest' => $manifest,'page_index' => (string) $index,'cursor' => $cursor,'next_cursor' => $cursors[$index + 1] ?? null,
                    'facts' => array_slice($facts,$index * RankingCorpusDocument::PAGE_SIZE,RankingCorpusDocument::PAGE_SIZE)];
                $pageBytes = CanonicalJson::encode($payload);
                if (strlen($pageBytes) > 4194304) { throw new ModelViolation('pf_corpus_capacity_unvalidated'); }
                $this->connection->insert($this->tables['pages'],['corpus_id' => $id,'page_index' => (string) $index,'cursor' => $cursor,
                    'next_cursor' => $cursors[$index + 1] ?? null,'payload_json' => $pageBytes,'payload_sha256' => hash('sha256',$pageBytes)]);
            }
            $this->connection->update($this->tables['counter'],['revision' => $revision],['id' => '1']); return $this->first($corpus);
        });
    }

    /** Primary key lookup after an uncertain response; no replacement generation.
     * @return array<string,mixed> */
    public function lookup(PeerPolicy $peer, string $origin, string $policy, string $key): array
    {
        self::context($peer,$origin,$policy); $hash = ModelValues::keyHash($key);
        return (new ClosedCorpusTransaction($this->db))->run(function () use ($peer,$origin,$policy,$hash): array {
            $this->available(); $this->counter();
            $known = $this->connection->row($this->tables['corpora'],'client_authority=%s AND key_sha256=%s',[$peer->node,$hash]);
            if ($known === null) { return ['state' => 'absent']; }
            $this->scope($known,$origin,$policy); return ['state' => 'materialized','page' => $this->first($known)];
        });
    }

    /** Historical frozen pages are not a current-facts attestation.
     * @return array<string,mixed> */
    public function page(PeerPolicy $peer, string $origin, string $policy, string $id, string $cursor): array
    {
        self::context($peer,$origin,$policy); ModelValues::uuid($id); ModelValues::uuid($cursor);
        return (new ClosedCorpusTransaction($this->db))->run(function () use ($peer,$origin,$policy,$id,$cursor): array {
            $this->available(); $this->counter(); $corpus = $this->corpus($peer,$origin,$policy,$id);
            $page = $this->connection->row($this->tables['pages'],'corpus_id=%s AND `cursor`=%s',[$id,$cursor]);
            if ($page === null) { throw new ModelViolation('pf_corpus_page_unavailable'); }
            return $this->verifyPage($corpus,$page);
        });
    }

    /** Attests this primary transaction only. Any pending H4 fragment refuses the whole result.
     * @return array<string,mixed> */
    public function finish(PeerPolicy $peer, string $origin, string $policy, string $id): array
    {
        self::context($peer,$origin,$policy); ModelValues::uuid($id);
        return (new ClosedCorpusTransaction($this->db))->run(function () use ($peer,$origin,$policy,$id): array {
            $this->available(); $counter = $this->counter(); $corpus = $this->corpus($peer,$origin,$policy,$id); $manifest = $this->manifest($corpus);
            if ($counter['epoch'] !== $manifest['epoch']) { throw new ModelViolation('pf_corpus_counter_divergent'); }
            $pages = $this->connection->rows($this->db->prepare('SELECT * FROM %i WHERE corpus_id=%s ORDER BY page_index',$this->tables['pages'],$id));
            if (count($pages) !== (int) $manifest['page_count']) { throw new ModelViolation('pf_corpus_incomplete'); }
            $facts = [];
            foreach ($pages as $index => $page) {
                $payload = $this->verifyPage($corpus,$page);
                if ($payload['page_index'] !== (string) $index || $payload['next_cursor'] !== ($pages[$index + 1]['cursor'] ?? null)) { throw new ModelViolation('pf_corpus_incomplete'); }
                array_push($facts,...$payload['facts']);
            }
            RankingCorpusDocument::complete($manifest,$facts); $current = $this->source->readInOwnerTransaction($peer,$origin,$policy);
            if ($current['ordering_epoch'] !== $manifest['ordering_epoch']) { throw new ModelViolation('pf_corpus_ranking_epoch'); }
            if (!hash_equals($manifest['full_sha256'],hash('sha256',CanonicalJson::encode($current['facts'])))) { throw new ModelViolation('pf_corpus_superseded'); }
            return ['state' => 'current','manifest' => $manifest,'manifest_sha256' => hash('sha256',CanonicalJson::encode($manifest)),
                'verified_at' => $current['created_at']];
        });
    }

    private static function context(PeerPolicy $peer, string $origin, string $policy): void
    {
        $peer->allow('pf.ranking.corpus'); ModelValues::uuid($origin); ModelValues::version($policy);
        if ($peer->node !== 'fixture.fans' || $peer->audience !== 'fixture.hub') { throw new ModelViolation('pf_invalid_peer'); }
    }

    private function available(): void
    { if (!ClosedRankingCorpusSchema::ready($this->db)) { throw new ModelViolation('pf_corpus_schema_unavailable'); } }

    /** @return array{epoch:string,revision:string} */
    private function counter(): array
    {
        $counter = $this->connection->row($this->tables['counter'],'id=%s',['1']);
        if ($counter === null) { throw new ModelViolation('pf_corpus_counter_unavailable'); }
        $epoch = ModelValues::uuid($counter['epoch']); $revision = ModelValues::integer($counter['revision']);
        $count = $this->connection->scalar($this->db->prepare('SELECT COUNT(*) FROM %i',$this->tables['corpora']));
        $inEpoch = $this->connection->scalar($this->db->prepare('SELECT COUNT(*) FROM %i WHERE epoch=%s AND revision BETWEEN 1 AND %d',$this->tables['corpora'],$epoch,(int) $revision));
        if ((string) $count !== $revision || (string) $inEpoch !== $revision) { throw new ModelViolation('pf_corpus_counter_divergent'); }
        return ['epoch' => $epoch,'revision' => $revision];
    }

    /** @return array<string,mixed> */
    private function corpus(PeerPolicy $peer, string $origin, string $policy, string $id): array
    {
        $row = $this->connection->row($this->tables['corpora'],'corpus_id=%s AND client_authority=%s AND origin_id=%s AND policy_version=%s',[$id,$peer->node,$origin,$policy]);
        if ($row === null) { throw new ModelViolation('pf_corpus_unavailable'); } $this->manifest($row); return $row;
    }

    /** @param array<string,mixed> $row */
    private function scope(array $row, string $origin, string $policy): void
    { if ($row['origin_id'] !== $origin || $row['policy_version'] !== $policy) { throw new ModelViolation('pf_corpus_key_conflict'); } }

    /** @param array<string,mixed> $row
     * @return array<string,mixed> */
    private function first(array $row): array
    {
        $page = $this->connection->row($this->tables['pages'],'corpus_id=%s AND page_index=%s',[$row['corpus_id'],'0']);
        if ($page === null) { throw new ModelViolation('pf_corpus_incomplete'); } return $this->verifyPage($row,$page);
    }

    /** @param array<string,mixed> $row
     * @return array<string,mixed> */
    private function manifest(array $row): array
    {
        $manifest = CanonicalJson::object($row['manifest_json']); RankingCorpusDocument::manifest($manifest,$row['origin_id'],$row['policy_version']);
        if ($row['manifest_sha256'] !== hash('sha256',$row['manifest_json']) || $row['full_sha256'] !== hash('sha256',$row['facts_json'])
            || $row['corpus_id'] !== $manifest['corpus_id'] || $row['revision'] !== $manifest['revision'] || $row['full_sha256'] !== $manifest['full_sha256']
            || $row['epoch'] !== $manifest['epoch'] || $row['client_authority'] !== $manifest['audience'] || $row['recorded_at'] !== $manifest['created_at']) { throw new ModelViolation('pf_corpus_digest_mismatch'); }
        $facts = CanonicalJson::object('{"facts":' . $row['facts_json'] . '}',ClosedRankingCorpusSource::MAX_BYTES + 16)['facts'];
        RankingCorpusDocument::complete($manifest,$facts); return $manifest;
    }

    /** @param array<string,mixed> $corpus
     * @param array<string,mixed> $page
     * @return array<string,mixed> */
    private function verifyPage(array $corpus, array $page): array
    {
        $payload = CanonicalJson::object($page['payload_json'],4194304); $manifest = $this->manifest($corpus); RankingCorpusDocument::page($payload,$manifest);
        $facts = CanonicalJson::object('{"facts":' . $corpus['facts_json'] . '}',ClosedRankingCorpusSource::MAX_BYTES + 16)['facts'];
        if ($page['payload_sha256'] !== hash('sha256',$page['payload_json']) || $page['page_index'] !== $payload['page_index']
            || $page['cursor'] !== $payload['cursor'] || $page['next_cursor'] !== $payload['next_cursor'] || $page['corpus_id'] !== $manifest['corpus_id']
            || CanonicalJson::encode($payload['facts']) !== CanonicalJson::encode(array_slice($facts,(int) $page['page_index'] * RankingCorpusDocument::PAGE_SIZE,RankingCorpusDocument::PAGE_SIZE))) { throw new ModelViolation('pf_corpus_digest_mismatch'); }
        return $payload;
    }
}
