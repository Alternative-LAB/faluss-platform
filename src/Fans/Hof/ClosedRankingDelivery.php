<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\Fans\PfContract\ClosedBarrierPrimaryRead;
use Faluss\Platform\Fans\Sso\FansLinkedAccounts;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;

/** Closed actual corpus delivery; not a producer, ledger, public route or activation switch. */
final class ClosedRankingDelivery
{
    public function __construct(private readonly \wpdb $db, private readonly ClosedOriginOpening $opening,
        private readonly ClosedBarrierPrimaryRead $barriers, private readonly ClosedRankingProjectionStore $cache)
    {
        ClosedEnvironment::assertIsolated($db,'fans');
        if ($db !== ($GLOBALS['wpdb'] ?? null)) { throw new ModelViolation('hof_primary_connection_required'); }
    }

    /** @return array{items:list<array{name:string,points:string,position:string}>,source_at:string} */
    public function ranking(string $family, ?string $category = null): array
    {
        self::family($family); if ($category !== null) { RankingPolicy::category($category); }
        return $this->withSource(fn (array $document, array $origin): array => $this->rankingInTransaction($document,$family,$category));
    }

    /** Private current linked member only. Public consent and active creator visibility are separate.
     * @return array{month:string,start:string,end:string,points:string,source_at:string} */
    public function statement(string $family, string $month): array
    {
        self::family($family); $bounds = RankingCalendar::month($month);
        if (!is_user_logged_in() || get_current_user_id() < 1) { throw new ModelViolation('hof_linked_member_required'); }
        return $this->withSource(function (array $document, array $origin) use ($family,$month,$bounds): array {
            $subject = FansLinkedAccounts::identityInTransaction(get_current_user_id());
            if ($subject === null) { throw new ModelViolation('hof_linked_member_required'); }
            if ($bounds['end'] <= $origin['admissible_from'] || $bounds['start'] > $document['source']['verified_at']) {
                throw new ModelViolation('hof_statement_period_unavailable');
            }
            $points = '0';
            foreach ($document['months'][$month][$family === 'fan' ? 'fans' : 'creators'] ?? [] as $row) {
                if ($row['faluss_id'] === $subject) { $points = $row['points']; }
            }
            return ['month' => $month,'start' => $bounds['start'],'end' => $bounds['end'],'points' => $points,
                'source_at' => $document['source']['verified_at']];
        });
    }

    /** Current complete source and approved frozen session, with no mutation or private subject in the DTO.
     * @return array<string,mixed> */
    public function session(string $id, string $family = 'creator'): array
    {
        self::family($family);
        return $this->withSource(fn (array $document, array $origin): array => $this->sessionInTransaction($document,$origin,$id,$family));
    }

    /** Keyset pages cover all published candidates; an unverified page fails closed rather than claiming completeness.
     * @return array{items:list<array<string,mixed>>,next_cursor:?string,source_at:string} */
    public function sessions(string $after = ''): array
    {
        if ($after !== '') { \Faluss\Platform\TokenEngine\PurchasedPf\ModelValues::uuid($after); }
        return $this->withSource(fn (array $document, array $origin): array => $this->sessionsInTransaction($document,$origin,$after));
    }

    /** One page cannot mix rankings and sessions from different corpus generations/visibility snapshots.
     * @return array{ranking:array{items:list<array{name:string,points:string,position:string}>,source_at:string},sessions:array{items:list<array<string,mixed>>,next_cursor:?string,source_at:string}} */
    public function overview(?string $category = null, string $after = ''): array
    {
        if ($category !== null) { RankingPolicy::category($category); }
        if ($after !== '') { \Faluss\Platform\TokenEngine\PurchasedPf\ModelValues::uuid($after); }
        return $this->withSource(fn (array $document, array $origin): array => [
            'ranking' => $this->rankingInTransaction($document,'creator',$category),
            'sessions' => $this->sessionsInTransaction($document,$origin,$after)]);
    }

    /** @param array<string,mixed> $document
     * @return array{items:list<array{name:string,points:string,position:string}>,source_at:string} */
    private function rankingInTransaction(array $document, string $family, ?string $category): array
    {
        $selected = $category === null ? $document['general'] : $document['categories'][$category];
        $identity = new RankingIdentity($this->db);
        return ['items' => RankingVisibleProjection::build($selected[$family === 'fan' ? 'fans' : 'creators'],
            static fn (string $id): ?string => $identity->visibleNameInTransaction($family,$id)),
            'source_at' => $document['source']['verified_at']];
    }

    /** @param array<string,mixed> $document
     * @param array<string,string> $origin
     * @return array{items:list<array<string,mixed>>,next_cursor:?string,source_at:string} */
    private function sessionsInTransaction(array $document, array $origin, string $after): array
    {
        $rows = $this->db->get_results($this->db->prepare('SELECT session_id FROM %i WHERE session_id>%s AND state IN (%s,%s,%s) ORDER BY session_id LIMIT 21',
            SessionSchema::tables($this->db)['records'],$after,'open','closed','cancelled'),'ARRAY_A');
        if (!is_array($rows) || $this->db->last_error !== '') { throw new ModelViolation('hof_storage_unavailable'); }
        $items = [];
        foreach (array_slice($rows,0,20) as $row) {
            try { $items[] = $this->sessionInTransaction($document,$origin,$row['session_id'],'creator'); }
            catch (ModelViolation $error) {
                // Suspension or editorial withdrawal suppresses this session. Proof/schema/conflict errors suppress the page.
                if (!in_array($error->reason,['hof_session_unavailable','hof_session_approval_required'],true)) { throw $error; }
            }
        }
        return ['items' => $items,'next_cursor' => count($rows) > 20 ? $rows[19]['session_id'] : null,'source_at' => $document['source']['verified_at']];
    }

    /** @param array<string,mixed> $document
     * @param array<string,string> $origin
     * @return array<string,mixed> */
    private function sessionInTransaction(array $document, array $origin, string $id, string $family): array
    {
        $verified = (new ClosedSessionRead($this->db,$this->barriers,$origin['origin_id']))->readInTransaction($id);
        $row = $verified['row']; $ranked = [];
        foreach ($document['sessions'] as $dimension) {
            if ($dimension['session_id'] !== $id) { continue; }
            foreach (['rules_revision','rules_sha256','starts_at','ends_at','scope','territory_policy_revision','country','territory_ref'] as $field) {
                if ($dimension[$field] !== $verified['content'][$field]) { throw new ModelViolation('hof_conflicting_frozen_session'); }
            }
            $ranked = $dimension[$family === 'fan' ? 'fans' : 'creators'];
        }
        $identity = new RankingIdentity($this->db);
        return array_intersect_key($row,array_flip(['title','rules_text','category','scope','starts_at','ends_at','state']))
            + ['id' => $id,'organizers' => $verified['organizers'],'items' => RankingVisibleProjection::build($ranked,
                static fn (string $subject): ?string => $identity->visibleNameInTransaction($family,$subject)),
                'source_at' => $document['source']['verified_at']];
    }

    /** The sessions mutex precedes the origin transaction and the authenticated corpus mutex.
     * @template T
     * @param callable(array<string,mixed>,array<string,string>):T $operation
     * @return T */
    private function withSource(callable $operation): mixed
    {
        ClosedEnvironment::assertIsolated($this->db,'fans');
        return (new SessionStore($this->db))->withGovernanceMutex(fn (): mixed => $this->opening->withCurrentRead($this->barriers,
            fn (array $origin): mixed => $this->cache->withReadInTransaction(function (array $value) use ($origin,$operation): mixed {
                $document = $value['document'];
                if ($value['state'] !== 'reconciled' || $document['source']['origin_id'] !== $origin['origin_id']
                    || $document['source']['policy_version'] !== $origin['policy_version']) { throw new ModelViolation('hof_projection_source_mismatch'); }
                return $operation($document,$origin);
            })));
    }

    private static function family(string $family): void
    { if (!in_array($family,['fan','creator'],true)) { throw new ModelViolation('hof_invalid_family'); } }
}
