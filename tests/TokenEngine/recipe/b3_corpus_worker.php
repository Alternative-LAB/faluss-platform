<?php

declare(strict_types=1);

use Faluss\Platform\TokenEngine\PurchasedPf\ClosedRankingCorpusSchema;
use Faluss\Platform\TokenEngine\PurchasedPf\ClosedRankingCorpusStore;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingCorpusDocument;

// Private disposable CLI only; the application never registers this worker.
try {
    if (isset($input['clock_value'])) {
        if (preg_match('/^[1-9][0-9]{9}\.[0-9]{6}$/D',$input['clock_value']) !== 1) { throw new RuntimeException('Invalid fixture clock.'); }
        $wpdb->query('SET timestamp=' . $input['clock_value']);
    }
    $owner = new ClosedRankingCorpusStore($wpdb,['fixture.purchase'],'recipe-hub-k1');
    $peer = new PeerPolicy($input['peer'] ?? 'fixture.fans',$input['audience'] ?? 'fixture.hub',$input['permissions'] ?? ['pf.ranking.corpus'],[]);
    $origin = $input['origin'] ?? ''; $policy = $input['policy'] ?? '1.0.0';
    $result = match ($input['action']) {
        'b3c-ready' => ['ready' => ClosedRankingCorpusSchema::ready($wpdb)],
        'b3c-install' => (function () use ($wpdb): array { ClosedRankingCorpusSchema::installForRecipe($wpdb); return ['ready' => ClosedRankingCorpusSchema::ready($wpdb)]; })(),
        'b3c-create' => $owner->create($peer,$origin,$policy,$input['key']),
        'b3c-lookup' => $owner->lookup($peer,$origin,$policy,$input['key']),
        'b3c-page' => $owner->page($peer,$origin,$policy,$input['corpus_id'],$input['cursor']),
        'b3c-finish' => $owner->finish($peer,$origin,$policy,$input['corpus_id']),
        'b3c-verify' => (function () use ($input): array { RankingCorpusDocument::complete($input['manifest'],$input['facts']); return ['valid' => true]; })(),
        default => throw new RuntimeException('Unknown isolated corpus action.'),
    };
} catch (ModelViolation $error) { $result = ['error' => $error->reason]; }
if (!empty($input['observe']) && $wpdb instanceof HubPfRecipeDatabase) { $result['global_lock_ms'] = sprintf('%.3f',$wpdb->globalHeldMs); }
echo wp_json_encode($result,JSON_THROW_ON_ERROR);
