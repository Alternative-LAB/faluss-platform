<?php

declare(strict_types=1);

use Faluss\Platform\Fans\Hof\ClosedParticipationLifecycle;
use Faluss\Platform\Fans\Hof\ClosedParticipationSchema;
use Faluss\Platform\Fans\Hof\SessionRoles;
use Faluss\Platform\Fans\Hof\SessionAppeals;
use Faluss\Platform\Fans\PfContract\ClosedBarrierClient;

// Included only by the existing physically guarded CLI worker; never loaded by the plugin.
$participations = new ClosedParticipationLifecycle($wpdb,$inbox,
    new ClosedBarrierClient($wpdb,$inbox,'recipe-fans-k1',$input['endpoint']),$input['origin']);
$roles = new SessionRoles($wpdb);
return match ($input['action']) {
    'participation-ready' => ['ready' => ClosedParticipationSchema::ready($wpdb)],
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
