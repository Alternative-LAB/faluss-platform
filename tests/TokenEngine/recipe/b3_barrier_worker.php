<?php

declare(strict_types=1);

use Faluss\Platform\TokenEngine\PurchasedPf\ClosedBarrierSchema;
use Faluss\Platform\TokenEngine\PurchasedPf\ClosedBarrierStore;
use Faluss\Platform\TokenEngine\PurchasedPf\ClosedReservationDatabase;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedIntent;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingBarrier;

// Included by the physically isolated CLI worker, never registered as a site route.
try {
    if ($input['action'] === 'b3b-ready') {
        $result = ['ready' => ClosedBarrierSchema::ready($wpdb)];
    } elseif ($input['action'] === 'b3b-install') {
        ClosedBarrierSchema::installForRecipe($wpdb);
        $result = ['ready' => ClosedBarrierSchema::ready($wpdb)];
    } elseif ($input['action'] === 'b3b-refs') {
        $result = ['references' => RankingBarrier::references(RankedIntent::fromArray($input['payload']))];
    } else {
        $store = new ClosedBarrierStore($wpdb);
        $peer = new PeerPolicy($input['peer'] ?? 'fixture.fans', 'fixture.hub',
            $input['permissions'] ?? ['pf.ranking.context.register','pf.ranking.context.close','pf.lookup'], []);
        if (in_array($input['action'], ['b3b-check','b3b-hold'], true)) {
            if (isset($input['clock_offset'])) { $wpdb->query('SET timestamp=UNIX_TIMESTAMP()+' . (int) $input['clock_offset']); }
            $intent = RankedIntent::fromArray($input['payload']);
            $connection = new ClosedReservationDatabase($wpdb);
            $result = $connection->write($intent->base->values['member_faluss_id'], function () use ($store,$intent,$input): array {
                $now = $store->lockSelection($intent);
                if ($input['action'] === 'b3b-hold') {
                    file_put_contents($input['marker'], 'locked');
                    $deadline = microtime(true) + 25;
                    while (!is_file($input['release'])) {
                        if (microtime(true) > $deadline) { throw new RuntimeException('Fixture selection release timed out.'); }
                        usleep(10000);
                    }
                }
                return ['checked' => true,'confirmed_at' => $now];
            });
        } else {
            $result = match ($input['action']) {
                'b3b-register' => $store->register($peer,$input['payload'],$input['key']),
                'b3b-close' => $store->close($peer,$input['payload'],$input['key']),
                'b3b-lookup' => $store->lookup($peer,$input['operation'],$input['payload'],$input['key']),
                default => throw new RuntimeException('Unknown B3b fixture action.'),
            };
        }
    }
} catch (ModelViolation $error) {
    $result = ['error' => $error->reason];
}
echo wp_json_encode($result, JSON_THROW_ON_ERROR);
