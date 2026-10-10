<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf\Protocol;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;

/** Syntax of the approved 1.1 completion reason. Type and elapsed deadline require the Hub owner transaction. */
final class BarrierCompletion
{
    /** @param array<array-key,mixed> $input
     * @return array<string,string> */
    public static function reference(array $input): array
    {
        if (($input['reason'] ?? null) !== 'session_completed') { return RankingBarrier::closeReference($input); }
        ModelValues::exactKeys($input,['barrier_key','version','content_sha256','reason']);
        return ['barrier_key' => RankingValues::digest($input['barrier_key']),'version' => ModelValues::integer($input['version'],true),
            'content_sha256' => RankingValues::digest($input['content_sha256']),'reason' => 'session_completed'];
    }
}
