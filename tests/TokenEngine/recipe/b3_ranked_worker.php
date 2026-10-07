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
    if ($input['action'] === 'b3r-hold-row') {
        // A separate real SQL connection holds only the selected metadata row, not owner mutexes.
        $table = match ($input['row_kind']) {
            'counter' => ClosedRankingSchema::tables($wpdb)['counter'],
            'barrier' => Faluss\Platform\TokenEngine\PurchasedPf\ClosedBarrierSchema::tables($wpdb)['barriers'],
            default => throw new RuntimeException('Unknown isolated row.'),
        };
        $wpdb->query('START TRANSACTION');
        $where = $input['row_kind'] === 'counter' ? 'id=1' : $wpdb->prepare('barrier_key=%s',$input['row_key']);
        if ($wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE ' . $where . ' FOR UPDATE',$table)) === null || $wpdb->last_error !== '') {
            throw new RuntimeException('Isolated row not found.');
        }
        file_put_contents($input['marker'],'locked'); $deadline = microtime(true)+25;
        while (!is_file($input['marker'] . '.release')) {
            if (microtime(true)>$deadline) { throw new RuntimeException('Isolated row release timed out.'); }
            usleep(10000);
        }
        $wpdb->query('COMMIT'); $result = ['released' => true];
    } elseif ($input['action'] === 'b3r-ready') {
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
        $fresh = isset($input['fresh_until']) ? static function () use ($input): void {
            Faluss\Platform\TokenEngine\PurchasedPf\Protocol\DelegatedContext::fresh($input['fresh_from'],$input['fresh_until'],time());
        } : null;
        $result = $store->execute($peer,$intent,substr($input['action'],4),$input['key'],$input['operation'] ?? null,$fresh);
    }
} catch (ModelViolation $error) {
    $result = ['error' => $error->reason];
}
echo wp_json_encode($result, JSON_THROW_ON_ERROR);
