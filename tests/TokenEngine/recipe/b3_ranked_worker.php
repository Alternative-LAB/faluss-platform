<?php

declare(strict_types=1);

use Faluss\Platform\TokenEngine\PurchasedPf\ClosedRankedStore;
use Faluss\Platform\TokenEngine\PurchasedPf\ClosedRankingSchema;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedIntent;

// Only the disposable CLI worker includes this; no hook or real site admission.
try {
    require_once dirname(__DIR__, 3) . '/src/Federation/Legacy/includes/class-faluss-federation-crypto.php';
    if ($input['action'] === 'b3r-ready') {
        $result = ['ready' => ClosedRankingSchema::ready($wpdb)];
    } elseif ($input['action'] === 'b3r-install') {
        ClosedRankingSchema::installForRecipe($wpdb);
        $result = ['ready' => ClosedRankingSchema::ready($wpdb)];
    } elseif ($input['action'] === 'b3r-raw-record') {
        $intent = RankedIntent::fromArray($input['payload']);
        $connection = new Faluss\Platform\TokenEngine\PurchasedPf\ClosedReservationDatabase($wpdb);
        $result = $connection->write($intent->base->values['member_faluss_id'],function () use ($intent,$input,$wpdb): array {
            $fact = (new Faluss\Platform\TokenEngine\PurchasedPf\ClosedConsumptionStore($wpdb,['fixture.fans'],['fixture.purchase']))->confirmedFact($intent->base);
            (new Faluss\Platform\TokenEngine\PurchasedPf\ClosedRankedReceiptStore($wpdb,'recipe-hub-k1'))->record($intent,$fact,$input['order']);
            return ['recorded' => true];
        });
    } else {
        if (isset($input['clock_offset'])) { $wpdb->query('SET timestamp=UNIX_TIMESTAMP()+' . (int) $input['clock_offset']); }
        if (isset($input['clock_value'])) {
            if (preg_match('/^[1-9][0-9]{9}\.[0-9]{6}$/D',$input['clock_value']) !== 1) { throw new RuntimeException('Invalid fixture clock.'); }
            $wpdb->query('SET timestamp=' . $input['clock_value']);
        }
        $intent = RankedIntent::fromArray($input['payload']);
        $peer = new PeerPolicy($input['peer'] ?? 'fixture.fans','fixture.hub',
            $input['permissions'] ?? ['pf.reserve','pf.confirm','pf.release','pf.lookup'],[]);
        $store = new ClosedRankedStore($wpdb,$input['key_id'] ?? 'recipe-hub-k1');
        $result = $store->execute($peer,$intent,substr($input['action'],4),$input['key'],$input['operation'] ?? null);
    }
} catch (ModelViolation $error) {
    $result = ['error' => $error->reason];
}
echo wp_json_encode($result, JSON_THROW_ON_ERROR);
