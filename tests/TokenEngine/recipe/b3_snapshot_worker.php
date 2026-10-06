<?php

declare(strict_types=1);

use Faluss\Platform\TokenEngine\PurchasedPf\ClosedRankedSnapshotSchema;
use Faluss\Platform\TokenEngine\PurchasedPf\ClosedRankedSnapshotStore;
use Faluss\Platform\TokenEngine\PurchasedPf\ClosedRankedStore;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedIntent;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedSnapshotDocument;

// Only the private disposable CLI worker, never a deployed router.
try {
    require_once dirname(__DIR__,3) . '/src/Federation/Legacy/includes/class-faluss-federation-crypto.php';
    $store = new ClosedRankedSnapshotStore($wpdb,['fixture.purchase'],'recipe-hub-k1');
    $result = match ($input['action']) {
        'b3s-ready' => ['ready' => ClosedRankedSnapshotSchema::ready($wpdb)],
        'b3s-install' => (function () use ($wpdb): array { ClosedRankedSnapshotSchema::installForRecipe($wpdb); return ['ready' => ClosedRankedSnapshotSchema::ready($wpdb)]; })(),
        'b3s-create' => $store->create($input['member'],$input['client'] ?? 'fixture.fans',$input['key']),
        'b3s-page' => $store->page($input['member'],$input['client'] ?? 'fixture.fans',$input['snapshot_id'],$input['cursor']),
        'b3s-finish' => $store->finish($input['member'],$input['client'] ?? 'fixture.fans',$input['snapshot_id']),
        'b3s-verify' => (function () use ($input): array { RankedSnapshotDocument::complete($input['manifest'],$input['rows']); return ['valid' => true]; })(),
        'b3s-seed-ranked' => (function () use ($input,$wpdb): array {
            $count = (int) $input['count']; if ($count < 1 || $count > 150) { throw new RuntimeException('Invalid isolated seed size.'); }
            $owner = new ClosedRankedStore($wpdb,'recipe-hub-k1'); $peer = new PeerPolicy('fixture.fans','fixture.hub',['pf.reserve','pf.confirm'],[]);
            $ids = [];
            // Advance only this fixture connection's primary clock across the existing
            // ten-reservations/minute windows. Never weaken quotas or alter stored facts.
            $last = (string) $wpdb->get_var('SELECT last_confirmed_at FROM wp_token_engine_pf_b3r_counter WHERE id=1');
            $clock = max((int) $wpdb->get_var('SELECT UNIX_TIMESTAMP()'),(int) strtotime($last . ' UTC'));
            for ($index = 0; $index < $count; $index++) {
                $clock += 7; $wpdb->query('SET timestamp=' . $clock);
                $values = $input['payload']; $values['attribution_id'] = wp_generate_uuid4(); $values['purchased_pf'] = '1';
                $intent = RankedIntent::fromArray($values); $owner->execute($peer,$intent,'reserve',bin2hex(random_bytes(32)));
                $owner->execute($peer,$intent,'confirm',bin2hex(random_bytes(32))); $ids[] = $values['attribution_id'];
            }
            return ['attributions' => $ids];
        })(),
        default => throw new RuntimeException('Unknown isolated snapshot action.'),
    };
} catch (ModelViolation $error) { $result = ['error' => $error->reason]; }
echo wp_json_encode($result,JSON_THROW_ON_ERROR);
