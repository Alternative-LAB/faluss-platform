<?php

declare(strict_types=1);

use Faluss\Platform\Fans\Hof\ClosedTerritoryChangeSchema;
use Faluss\Platform\Fans\Hof\ClosedTerritoryChanges;
use Faluss\Platform\Fans\Hof\RankingRegistry;
use Faluss\Platform\Fans\Hof\TerritorySchema;
use Faluss\Platform\Fans\Hof\TerritoryService;

// Existing guarded CLI scenario only; no fixture provisioning or site configuration.
return match ($input['action']) {
    'territory-ui-install' => (static function () use ($wpdb): array {
        RankingRegistry::administrator(); TerritorySchema::installOrVerify($wpdb); ClosedTerritoryChangeSchema::installForRecipe($wpdb);
        return ['ready' => true];
    })(),
    'territory-ui-policies' => (static function () use ($wpdb,$input): array {
        $service = new TerritoryService($wpdb); $result = [];
        foreach ($input['policies'] as $id) { $result[] = $service->registerPolicy($id,$input['criteria'])['policy_id']; }
        sort($result,SORT_STRING); return $result;
    })(),
    'territory-ui-own' => (new TerritoryService($wpdb))->own(),
    'territory-ui-clock' => (static function () use ($wpdb): array {
        RankingRegistry::administrator(); return ['now' => (new \Faluss\Platform\Fans\Hof\SessionStore($wpdb))->now()];
    })(),
    'territory-ui-examine' => (new TerritoryService($wpdb))->examine($input['creator']),
    'territory-ui-progress' => (new ClosedTerritoryChanges($wpdb))->progress($input['change']),
    'territory-ui-listing' => (new ClosedTerritoryChanges($wpdb))->listing($input['creator']),
    default => throw new RuntimeException('Unknown territorial UI fixture operation.'),
};
