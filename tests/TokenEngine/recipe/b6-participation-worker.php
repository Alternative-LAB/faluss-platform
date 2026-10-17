<?php

declare(strict_types=1);

use Faluss\Platform\Fans\Hof\ClosedParticipationLifecycle;
use Faluss\Platform\Fans\Hof\ClosedParticipationSchema;
use Faluss\Platform\Fans\Hof\SessionRoles;
use Faluss\Platform\Fans\Hof\SessionAppeals;
use Faluss\Platform\Fans\PfContract\ClosedBarrierClient;
use Faluss\Platform\Fans\Hof\TerritoryService;
use Faluss\Platform\Fans\Hof\TerritorySchema;
use Faluss\Platform\Fans\Hof\ClosedTerritoryChangeSchema;
use Faluss\Platform\Fans\Hof\ClosedTerritoryChanges;
use Faluss\Platform\Fans\Hof\ClosedTerritorySessionRetirement;

// Included only by the existing physically guarded CLI worker; never loaded by the plugin.
$participations = new ClosedParticipationLifecycle($wpdb,$inbox,
    new ClosedBarrierClient($wpdb,$inbox,'recipe-fans-k1',$input['endpoint']),$input['origin']);
$roles = new SessionRoles($wpdb);
return match ($input['action']) {
    'participation-territory-schema' => (static function () use ($wpdb): array { TerritorySchema::installOrVerify($wpdb); return ['ready' => TerritorySchema::ready($wpdb)]; })(),
    'participation-territory-ready' => ['ready' => ClosedTerritoryChangeSchema::ready($wpdb)],
    'participation-territory-install' => (static function () use ($wpdb): array { ClosedTerritoryChangeSchema::installForRecipe($wpdb); return ['ready' => true]; })(),
    'participation-territory-policy' => (new TerritoryService($wpdb))->registerPolicy($input['policy'],$input['criteria']),
    'participation-territory-own' => (new TerritoryService($wpdb))->own(),
    'participation-territory-submit' => (new TerritoryService($wpdb))->submit($input['policy'],$input['country'],$input['reference'],$input['revision']),
    'participation-territory-decide' => (new TerritoryService($wpdb))->decide($input['creator'],$input['revision'],$input['decision'],$input['reason']),
    'participation-territory-withdraw' => (new TerritoryService($wpdb))->withdraw($input['revision']),
    'participation-territory-collect' => (new ClosedTerritoryChanges($wpdb))->collect($input['change'],$input['limit'] ?? 20),
    'participation-territory-retire' => $participations->retireTerritory($input['change'],$input['session'],$input['version'],$input['steps'] ?? 4),
    'participation-territory-retire-session' => (new ClosedTerritorySessionRetirement($wpdb,$inbox,
        new ClosedBarrierClient($wpdb,$inbox,'recipe-fans-k1',$input['endpoint']),$input['origin']))->advance($input['change'],$input['session'],$input['version'],$input['steps'] ?? 4),
    'participation-territory-invite' => $roles->invite($input['session'],$input['revision'],$input['creator']),
    'participation-territory-accept' => $roles->answer($input['session'],$input['revision'],true),
    'participation-ready' => ['ready' => ClosedParticipationSchema::ready($wpdb)],
    'participation-checkpoint' => $participations->checkpoint($input['session'],$input['creator']),
    'participation-install' => (static function () use ($wpdb): array { ClosedParticipationSchema::installForRecipe($wpdb); return ['ready' => true]; })(),
    'participation-request' => $roles->apply($input['session'],$input['revision'],$input['rules_sha256']),
    'participation-admit' => $participations->admit($input['session'],$input['revision'],$input['creator']),
    'participation-refuse' => $roles->admit($input['session'],$input['revision'],$input['creator'],false),
    'participation-appeal' => (new SessionAppeals($wpdb))->submit($input['session'],$input['role_revision'],$input['explanation']),
    'participation-appeal-own' => (new SessionAppeals($wpdb))->own($input['case']),
    'participation-appeal-admit' => $participations->admitAppeal($input['case'],$input['case_revision']),
    'participation-appeal-outside' => (new SessionAppeals($wpdb))->decideInTransaction($input['case'],$input['case_revision'],'admit','criteria_satisfied'),
    'participation-withdraw' => $roles->withdraw($input['session'],$input['revision']),
    'participation-prepare' => $participations->prepare($input['session'],$input['revision'],$input['creator']),
    'participation-advance' => $participations->advance($input['session'],$input['revision'],$input['creator'],$input['steps'] ?? 4),
    'participation-requalify' => $participations->requalify($input['session'],$input['revision'],$input['creator'],$input['steps'] ?? 4),
    'participation-apply' => $participations->apply($input['session'],$input['creator'],$input['action_id']),
    default => throw new RuntimeException('Unknown private participation fixture operation.'),
};
