<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\Fans\PfContract\ClosedBarrierPrimaryRead;
use Faluss\Platform\Fans\Sso\FansLocalSession;
use Faluss\Platform\Fans\Sso\FansSsoService;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedIntent;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingContext;

/** Explicit prospective session choices, without reservation, debit, automatic selection or installation. */
final class ClosedAttributionSelection
{
    public function __construct(private readonly \wpdb $db,private readonly ClosedOriginOpening $opening,
        private readonly ClosedBarrierPrimaryRead $primary,private readonly ClosedAttributionPolicy $policy)
    {
        ClosedEnvironment::assertIsolated($db,'fans');
        if ($db !== ($GLOBALS['wpdb'] ?? null) || !$policy->usesConnection($db)) { throw new ModelViolation('hof_primary_connection_required'); }
    }

    /** @return array{items:list<array<string,string>>,next_cursor:?string} */
    public function choices(string $profile,string $after=''): array
    {
        ModelValues::uuid($profile); if ($after !== '') { ModelValues::uuid($after); }
        return $this->scope($profile,function (array $origin,array $policy,string $member) use ($profile,$after): array {
            $tables = SessionSchema::tables($this->db);
            $rows = $this->db->get_results($this->db->prepare('SELECT s.session_id FROM %i s INNER JOIN %i r ON r.session_id=s.session_id AND r.creator_id=%s AND r.role=%s AND r.state=%s WHERE s.session_id>%s AND s.state=%s AND s.category=%s ORDER BY s.session_id LIMIT 21',
                $tables['records'],$tables['roles'],$profile,'participant','admitted',$after,'open',$policy['creator_category']),'ARRAY_A');
            if (!is_array($rows) || $this->db->last_error !== '') { throw new ModelViolation('hof_storage_unavailable'); }
            $items=[]; $reader = new ClosedParticipationContext($this->db,$this->primary,$origin['origin_id']);
            foreach (array_slice($rows,0,20) as $row) {
                try { $session = $reader->readInTransaction($row['session_id'],$profile)['session']; }
                catch (ModelViolation $error) {
                    if (!in_array($error->reason,['hof_session_unavailable','hof_session_approval_required','hof_reviewed_territory_required','hof_attribution_session_unavailable'],true)) { throw $error; }
                    continue;
                }
                $items[] = array_intersect_key($session,array_flip(['session_id','title','scope','starts_at','ends_at','rules_text']));
            }
            return ['items' => $items,'next_cursor' => count($rows)>20 ? $rows[19]['session_id'] : null];
        });
    }

    /** No session is selected implicitly. Browser supplies IDs only; every frozen field comes from current primary facts.
     * @param array<array-key,mixed> $selected */
    public function resolve(string $attribution,string $profile,string $quantity,array $selected): RankedIntent
    {
        ModelValues::uuid($attribution); ModelValues::uuid($profile);
        if (!array_is_list($selected) || count($selected)>RankingPolicy::MAX_ATTRIBUTION_SESSIONS) { throw new ModelViolation('pf_ranking_invalid_sessions'); }
        $selected=array_map(static fn (mixed $id): string => ModelValues::uuid($id),$selected);
        if (count(array_unique($selected))!==count($selected)) { throw new ModelViolation('pf_ranking_duplicate_or_order'); }
        sort($selected,SORT_STRING);
        return $this->scope($profile,function (array $origin,array $policy,string $member) use ($attribution,$profile,$quantity,$selected): RankedIntent {
            $sessions=[]; $reader = new ClosedParticipationContext($this->db,$this->primary,$origin['origin_id']);
            foreach ($selected as $id) {
                $read=$reader->readInTransaction($id,$profile);
                if ($read['session']['category'] !== $policy['creator_category']) { throw new ModelViolation('hof_attribution_policy_conflict'); }
                $sessions[]=$read['context'];
            }
            $context=['origin_id' => $origin['origin_id'],'policy_version' => RankingPolicy::VERSION,
                'creator_category' => $policy['creator_category'],'category_revision' => $policy['category_revision'],
                'country_policy_revision' => $policy['country_policy_revision'],'sessions' => $sessions];
            return RankedIntent::fromArray(['attribution_id' => $attribution,'client_authority' => 'fixture.fans',
                'member_faluss_id' => $member,'creator_faluss_id' => $policy['creator_faluss_id'],'purchased_pf' => $quantity,
                'policy_version' => RankingPolicy::VERSION,'ranking_context' => $context,'context_sha256' => RankingContext::fingerprint($context)]);
        });
    }

    /** @template T
     * @param callable(array<string,string>,array<string,string>,string):T $operation
     * @return T */
    private function scope(string $profile,callable $operation): mixed
    {
        ClosedEnvironment::assertIsolated($this->db,'fans');
        $subject=FansSsoService::currentLinkedSubject(); $expires=FansLocalSession::expires();
        if ($subject === null || $expires === null || $expires<=time()) { throw new ModelViolation('pf_linked_session_required'); }
        return (new SessionStore($this->db))->withGovernanceMutex(fn (): mixed => $this->opening->withCurrentRead($this->primary,
            function (array $origin) use ($profile,$subject,$operation): mixed {
                $policy=$this->policy->contextInTransaction($profile,$origin['origin_id']);
                if ($policy['creator_faluss_id'] === $subject['faluss_id']) { throw new ModelViolation('self_attribution_refused'); }
                return $operation($origin,$policy,$subject['faluss_id']);
            }));
    }
}
