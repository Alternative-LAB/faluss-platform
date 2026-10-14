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
        return $this->withSource(function (array $document, array $origin) use ($family,$category): array {
            $selected = $category === null ? $document['general'] : $document['categories'][$category];
            $identity = new RankingIdentity($this->db);
            return ['items' => RankingVisibleProjection::build($selected[$family === 'fan' ? 'fans' : 'creators'],
                static fn (string $id): ?string => $identity->visibleNameInTransaction($family,$id)),
                'source_at' => $document['source']['verified_at']];
        });
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
