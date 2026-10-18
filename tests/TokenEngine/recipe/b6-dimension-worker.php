<?php

declare(strict_types=1);

use Faluss\Platform\Fans\Hof\ClosedParticipationLifecycle;
use Faluss\Platform\Fans\Hof\ClosedParticipationSchema;
use Faluss\Platform\Fans\Hof\ClosedSessionLifecycle;
use Faluss\Platform\Fans\Hof\ClosedSessionLifecycleSchema;
use Faluss\Platform\Fans\Hof\ClosedTerritoryChangeSchema;
use Faluss\Platform\Fans\Hof\SessionBarrierDescriptor;
use Faluss\Platform\Fans\Hof\SessionModeration;
use Faluss\Platform\Fans\Hof\SessionReviewSchema;
use Faluss\Platform\Fans\Hof\SessionRoles;
use Faluss\Platform\Fans\Hof\SessionService;
use Faluss\Platform\Fans\Hof\SessionStore;
use Faluss\Platform\Fans\Hof\TerritorySchema;
use Faluss\Platform\Fans\Hof\TerritoryService;
use Faluss\Platform\Fans\PfContract\ClosedBarrierClient;
use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;

// Included only by the existing CLI worker, after its physical-isolation/primary guard.
return match ($input['action']) {
    'dimension-install' => (static function () use ($wpdb): array {
        \Faluss\Platform\Fans\Hof\RankingRegistry::administrator();
        TerritorySchema::installOrVerify($wpdb); ClosedTerritoryChangeSchema::installForRecipe($wpdb);
        ClosedParticipationSchema::installForRecipe($wpdb); SessionReviewSchema::installOrVerify($wpdb);
        ClosedSessionLifecycleSchema::installForRecipe($wpdb); return ['ready' => true];
    })(),
    'dimension-policy' => (new TerritoryService($wpdb))->registerPolicy($input['policy'],$input['criteria']),
    'dimension-own' => (new TerritoryService($wpdb))->own(),
    'dimension-submit' => (new TerritoryService($wpdb))->submit($input['policy'],$input['country'],$input['reference'],$input['revision']),
    'dimension-decide' => (new TerritoryService($wpdb))->decide($input['creator'],$input['revision'],$input['decision'],$input['reason']),
    'dimension-create' => (static function () use ($wpdb,$input,$admin,$inbox): array {
        $sessions = new SessionService($wpdb); $reviews = new SessionModeration($wpdb);
        $now = new DateTimeImmutable((new SessionStore($wpdb))->now(),new DateTimeZone('UTC'));
        $scope = $input['scope']; $policy = $scope === 'international' ? null : $input['policy'];
        $row = $sessions->create(['title' => 'Dimension de recette ' . $scope,'rules_text' => 'Participation volontaire. Données fictives.',
            'category' => $input['category'],'scope' => $scope,'country' => $scope === 'international' ? '' : 'FR',
            'territory_ref' => $scope === 'local' ? 'FR-PARIS' : '','timezone' => 'Europe/Paris',
            'starts_at' => $now->modify('-1 minute')->format('Y-m-d H:i:s.u'),
            'ends_at' => $now->modify('+40 minutes')->format('Y-m-d H:i:s.u')]);
        $review = $reviews->submit($row['session_id'],(int) $row['revision'],0);
        wp_set_current_user($admin->ID); $reviews->decide($row['session_id'],(int) $review['revision'],'approve','allowed_session');
        wp_set_current_user($input['actor']); $row = $sessions->requestOpen($row['session_id'],(int) $row['revision'],$policy);
        $bridge = new ClosedSessionLifecycle($wpdb,$inbox,new ClosedBarrierClient($wpdb,$inbox,'recipe-fans-k1',$input['endpoint']),$input['origin']);
        $prepared = $bridge->prepare($row['session_id'],(int) $row['revision']);
        $progress = $bridge->advance($row['session_id'],(int) $row['revision']);
        if (($progress['session_state'] ?? '') !== 'open') { throw new ModelViolation('pf_fixture_session_open_required'); }
        return ['id' => $row['session_id'],'action_id' => $prepared['action_id']];
    })(),
    'dimension-admit' => (static function () use ($wpdb,$input,$inbox): array {
        $store = new SessionStore($wpdb); $sessions = new SessionService($wpdb);
        $roles = new SessionRoles($wpdb); $row = $sessions->inspect($input['session'])['session'];
        wp_set_current_user($input['participant_actor']); $own = CreatorProfileService::own();
        if ($own === null) { throw new ModelViolation('pf_fixture_creator_required'); }
        $row = $roles->apply($row['session_id'],(int) $row['revision'],$row['frozen_sha256']);
        wp_set_current_user($input['actor']);
        $bridge = new ClosedParticipationLifecycle($wpdb,$inbox,new ClosedBarrierClient($wpdb,$inbox,'recipe-fans-k1',$input['endpoint']),$input['origin']);
        $row = $bridge->admit($row['session_id'],(int) $row['revision'],$own['creator_id']);
        $progress = $bridge->advance($row['session_id'],(int) $row['revision'],$own['creator_id']);
        if (($progress['participation_state'] ?? '') !== 'admitted') { throw new ModelViolation('pf_fixture_admission_required'); }
        return $store->transaction(function () use ($wpdb,$row,$own,$store,$input): array {
            $version = $store->storage->row($wpdb->prepare('SELECT * FROM %i WHERE session_id=%s AND creator_id=%s ORDER BY barrier_version DESC LIMIT 1 FOR UPDATE',
                ClosedParticipationSchema::tables($wpdb)['versions'],$row['session_id'],$own['creator_id'])) ?? throw new ModelViolation('pf_fixture_admission_required');
            $admission = CanonicalJson::object($version['descriptor_json'])['content'];
            $policy = (new TerritoryService($wpdb))->boundPolicy($row['session_id']);
            $content = SessionBarrierDescriptor::compose($row,$input['origin'],$policy)['content'];
            return ['session_id' => $row['session_id'],'admission_revision' => $version['barrier_version'],
                'barrier_version' => $row['barrier_version'],'admitted_at' => $admission['admitted_at'],
                'territory_admission_revision' => $admission['territory_admission_revision']]
                + array_intersect_key($content,array_flip(['rules_revision','rules_sha256','starts_at','ends_at','scope','territory_policy_revision','country','territory_ref']));
        });
    })(),
    default => throw new RuntimeException('Unknown dimension fixture operation.'),
};
