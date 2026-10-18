<?php

declare(strict_types=1);

use Faluss\Platform\Fans\Hof\ClosedAttributionPolicy;
use Faluss\Platform\Fans\Hof\ClosedAttributionSelection;
use Faluss\Platform\Fans\Hof\ClosedParticipationSchema;
use Faluss\Platform\Fans\Hof\RankingPolicy;
use Faluss\Platform\Fans\Hof\RankingRegistry;
use Faluss\Platform\Fans\Hof\SessionStore;
use Faluss\Platform\Fans\PfContract\ClosedBarrierClient;
use Faluss\Platform\Fans\PfContract\ClosedBarrierPrimaryClient;
use Faluss\Platform\Fans\PfContract\ClosedRankedClient;
use Faluss\Platform\Fans\PfContract\ClosedRankedProtocolSchema;
use Faluss\Platform\Fans\PfContract\ClosedAttributionAttempt;
use Faluss\Platform\Fans\PfContract\ClosedAttributionAttemptSchema;
use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\Fans\Sso\FansSsoService;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingBarrier;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedIntent;

// Existing physical and WP-CLI guards precede this application scenario.
if (isset($input['cookie'])) {
    if (!is_string($input['cookie']) || wp_validate_auth_cookie($input['cookie'],'logged_in')!==get_current_user_id()) {
        throw new ModelViolation('pf_linked_session_required');
    }
    $_COOKIE[(string)constant('LOGGED_IN_COOKIE')]=$input['cookie'];
}
$primaryClient=new ClosedBarrierPrimaryClient($wpdb,$inbox,$reader,'recipe-fans-k1',$input['endpoint']);
if ($input['action']==='selection-register') {
    RankingRegistry::administrator(); ClosedRankedProtocolSchema::installForRecipe($wpdb);ClosedAttributionAttemptSchema::installForRecipe($wpdb);
    $owner=CreatorProfileService::activeOwner($input['profile']);
    $subject=$owner===null?null:FansSsoService::linkedIdentity($owner);
    $profile=CreatorProfileService::publicById($input['profile']);
    if ($subject===null || $profile===null) { throw new ModelViolation('pf_fixture_actor_required'); }
    $kind=$input['kind'];
    if (!in_array($kind,['creator','country'],true)) { throw new ModelViolation('pf_fixture_operation'); }
    $now=new DateTimeImmutable((new SessionStore($wpdb))->now(),new DateTimeZone('UTC'));
    $content=['kind'=>$kind,'origin_id'=>$input['origin'],'object_id'=>$kind==='creator'?$subject:$input['country'],
        'subject_id'=>'','version'=>'1','policy_version'=>RankingPolicy::VERSION];
    if ($kind==='creator') { $content['creator_category']=$profile['category']; }
    $descriptor=RankingBarrier::descriptor(['content'=>$content,'valid_from'=>$now->modify('-1 day')->format('Y-m-d H:i:s.u'),
        'valid_until'=>$now->modify('+1 day')->format('Y-m-d H:i:s.u')]);
    $fields=['operation'=>'register','lookup_operation'=>'','action_id'=>wp_generate_uuid4(),'origin_id'=>$input['origin'],
        'policy_version'=>RankingPolicy::VERSION,'object'=>$descriptor];
    $prepared=$inbox->prepare($fields);
    $progress=(new ClosedBarrierClient($wpdb,$inbox,'recipe-fans-k1',$input['endpoint']))->advance($prepared['action_id']);
    if ($progress['state']!=='acknowledged') { throw new ModelViolation('pf_fixture_policy_required'); }
    $reader->prepare($prepared['action_id']);
    if ($primaryClient->advance($prepared['action_id'])['phase']!=='current') { throw new ModelViolation('pf_fixture_policy_required'); }
    return ['action_id'=>$prepared['action_id'],'subject'=>$subject];
}
if ($input['action']==='selection-refresh') {
    RankingRegistry::administrator();
    $tables=ClosedParticipationSchema::tables($wpdb);
    $binding=$wpdb->get_row($wpdb->prepare('SELECT action_id FROM %i WHERE session_id=%s AND creator_id=%s AND operation=%s ORDER BY barrier_version DESC LIMIT 1',
        $tables['bindings'],$input['session'],$input['profile'],'register'),'ARRAY_A');
    if (!is_array($binding) || $wpdb->last_error!=='') { throw new ModelViolation('pf_fixture_admission_required'); }
    $reader->prepare($binding['action_id']);return $primaryClient->advance($binding['action_id']);
}
$selection=new ClosedAttributionSelection($wpdb,$opening,$reader,new ClosedAttributionPolicy($wpdb,$reader,$input['creator_action'],$input['country_action']));
$client=new ClosedRankedClient($wpdb,$peer,'recipe-fans-k1',str_replace('/barrier','/ranked',$input['endpoint']));
$attempt=new ClosedAttributionAttempt($wpdb,$selection,$client);
return match ($input['action']) {
    'selection-attempt-current'=>$attempt->current()??[],
    'selection-attempt-start'=>$attempt->start($input['attribution'],$input['profile'],$input['quantity'],$input['sessions']),
    'selection-attempt-advance'=>$attempt->advance($input['attribution'],$input['operation']),
    'selection-choices'=>$selection->choices($input['profile'],$input['after']??''),
    'selection-resolve'=>$selection->resolve($input['attribution'],$input['profile'],$input['quantity'],$input['sessions'])->values,
    'selection-reserve'=>$client->reserve(RankedIntent::fromArray($input['intent'])),
    'selection-confirm'=>$client->confirm($input['attribution']),
    'selection-release'=>$client->release($input['attribution']),
    'selection-lookup'=>$client->lookup($input['attribution'],$input['operation']),
    default=>throw new ModelViolation('pf_fixture_operation'),
};
