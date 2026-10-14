<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingBarrier;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingValues;

/** Pure formatting of an authenticated primary ACK; cannot authenticate a browser supplied array. */
final class OriginOpening
{
    /** @param array<string,mixed> $ack
     * @return array{primary_ack_at:string,admissible_from:string} */
    public static function fromAcknowledgement(array $ack, string $origin): array
    {
        $fields=$ack['fields'];$descriptor=RankingBarrier::descriptor($fields['object']);$content=$descriptor['content'];
        if ($ack['contract']!=='hub.purchased-pf.ranking-barriers/1.0.0' || $fields['operation']!=='register'
            || $fields['origin_id']!==$origin || $fields['policy_version']!==RankingPolicy::VERSION
            || $content['kind']!=='origin' || $content['origin_id']!==$origin || $content['policy_version']!==RankingPolicy::VERSION
            || $ack['local_state']!=='active' || $ack['result']['operation']!=='register' || $ack['result']['state']!=='active') {
            throw new ModelViolation('hof_origin_acknowledgement_required');
        }
        $ref=RankingBarrier::reference($content);
        foreach (['barrier_key','version','content_sha256'] as $field) {
            if ($ack['result'][$field]!==$ref[$field]) { throw new ModelViolation('hof_origin_conflict'); }
        }
        $instant=RankingValues::utc($ack['result']['effective_at']);
        if ($instant>=$descriptor['valid_until']) { throw new ModelViolation('hof_origin_conflict'); }
        return ['primary_ack_at'=>$instant,'admissible_from'=>max($instant,$descriptor['valid_from'])];
    }
}
