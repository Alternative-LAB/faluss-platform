<?php

declare(strict_types=1);

use Faluss\Platform\Fans\Hof\ClosedOriginOpening;
use Faluss\Platform\Fans\Hof\ClosedOriginOpeningSchema;
use Faluss\Platform\Fans\Hof\ClosedRankingDelivery;
use Faluss\Platform\Fans\Hof\ClosedRankingProjectionStore;
use Faluss\Platform\Fans\Hof\RankingRegistry;
use Faluss\Platform\Fans\Hof\RankingSchema;
use Faluss\Platform\Fans\Hof\RankingVisibility;
use Faluss\Platform\Fans\Hof\SessionSchema;
use Faluss\Platform\Fans\Hof\ClosedSessionLifecycle;
use Faluss\Platform\Fans\Hof\ClosedSessionLifecycleSchema;
use Faluss\Platform\Fans\Hof\SessionBarrierDescriptor;
use Faluss\Platform\Fans\Hof\SessionService;
use Faluss\Platform\Fans\Hof\SessionModeration;
use Faluss\Platform\Fans\Hof\SessionReviewSchema;
use Faluss\Platform\Fans\Hof\SessionRoles;
use Faluss\Platform\Fans\Hof\SessionStore;
use Faluss\Platform\Fans\PfContract\ClosedBarrierClient;
use Faluss\Platform\Fans\PfContract\ClosedBarrierInbox;
use Faluss\Platform\Fans\PfContract\ClosedBarrierPrimaryClient;
use Faluss\Platform\Fans\PfContract\ClosedBarrierPrimaryRead;
use Faluss\Platform\Fans\PfContract\ClosedBarrierReadSchema;
use Faluss\Platform\Fans\Profiles\CreatorProfileSchema;
use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\Fans\Profiles\CreatorStatusSchema;
use Faluss\Platform\Fans\Profiles\EditorialSchema;
use Faluss\Platform\Fans\Profiles\EditorialService;
use Faluss\Platform\Fans\Sso\FansSsoSchema;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;

global $wpdb;
ClosedEnvironment::assertIsolated($wpdb,'fans');
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('Fixture CLI only'); }
// Only this physically isolated CLI scenario supplies fictitious Identity configuration.
// It never performs central authentication or changes the fixture's HTTP/WordPress setup.
foreach (['FALUSS_FANS_SSO_CLIENT_ID' => 'fixture.fans.identity',
    'FALUSS_FANS_SSO_CLIENT_SECRET' => rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=')] as $name => $value) {
    if (!defined($name)) { define($name,$value); }
}
add_filter('home_url',static fn (string $url): string => (string) preg_replace('~^http://127\.0\.0\.1:[0-9]+~','https://fans.example.test',$url));
foreach (['SSO','CREATOR_PROFILES','EDITORIAL'] as $flag) {
    $name = 'FALUSS_PLATFORM_FANS_' . $flag;
    if (defined($name) && constant($name) !== true) { throw new RuntimeException('Conflicting fixture feature'); }
    if (!defined($name)) { define($name,true); }
}
require_once WP_PLUGIN_DIR . '/faluss-platform/src/Federation/Legacy/includes/class-faluss-federation-crypto.php';
$input = json_decode(file_get_contents($args[0]),true,40,JSON_THROW_ON_ERROR);
try {
    $root = dirname(rtrim(ABSPATH,'/'));
    $policy = json_decode(file_get_contents($root . '/fans-corpus-trust.json'),true,20,JSON_THROW_ON_ERROR);
    $peer = new PeerPolicy($policy['node'],$policy['audience'],$policy['permissions'],$policy['keys']);
    $inbox = new ClosedBarrierInbox($wpdb,$peer,$input['origin'],'1.0.0');
    $reader = new ClosedBarrierPrimaryRead($wpdb,$inbox,$peer);
    $opening = new ClosedOriginOpening($wpdb,$inbox,$input['origin']);
    $cache = new ClosedRankingProjectionStore($wpdb,$peer,$input['origin'],'1.0.0');
    $admin = get_users(['role' => 'administrator','number' => 1])[0] ?? null;
    if (!$admin instanceof WP_User) { throw new RuntimeException('Fixture administrator required'); }
    if (isset($input['at'])) {
        // Match only this disposable connection to the authoritative injected Hub recipe clock.
        if (!is_numeric($input['at'])) { throw new RuntimeException('Fixture clock required'); }
        $wpdb->query($wpdb->prepare('SET timestamp=%f',(float) $input['at']));
    }
    wp_set_current_user((int) ($input['actor'] ?? 0));
    $visibility = new RankingVisibility($wpdb);
    $delivery = new ClosedRankingDelivery($wpdb,$opening,$reader,$cache);
    $result = match ($input['action']) {
        'prepare-origin' => (static function () use ($wpdb,$admin,$opening,$input,$inbox): array {
            wp_set_current_user($admin->ID); RankingSchema::installOrVerify($wpdb); SessionSchema::installOrVerify($wpdb);
            ClosedOriginOpeningSchema::installForRecipe($wpdb); ClosedBarrierReadSchema::installForRecipe($wpdb);
            (new RankingRegistry($wpdb))->prepareOrigin($input['origin']);
            $prepared = $opening->prepare($input['descriptor']); return $inbox->recover($prepared['action_id']);
        })(),
        'apply-origin' => (static function () use ($admin,$opening,$input): array { wp_set_current_user($admin->ID); return $opening->apply($input['action_id']); })(),
        'prepare-read' => $reader->prepare($input['action_id']),
        'advance-read' => (new ClosedBarrierPrimaryClient($wpdb,$inbox,$reader,'recipe-fans-k1',$input['endpoint']))->advance($input['action_id']),
        'ranking' => $delivery->ranking($input['family'],$input['category'] ?? null),
        'statement' => $delivery->statement($input['family'],$input['month']),
        'sessions' => $delivery->sessions($input['after'] ?? ''),
        'overview' => $delivery->overview($input['category'] ?? null,$input['after'] ?? ''),
        'session' => $delivery->session($input['session']),
        'private-session' => (static function () use ($wpdb,$input): array {
            $reviews = new SessionModeration($wpdb);
            return (new SessionService($wpdb))->inspect($input['session']) + ['review' => $reviews->own($input['session']), 'history' => $reviews->history($input['session'])];
        })(),
        'private-sessions' => (new SessionService($wpdb))->own($input['after'] ?? ''),
        'fixture-participation-services' => (static function () use ($wpdb): array {
            RankingRegistry::administrator(); \Faluss\Platform\Fans\Hof\ClosedParticipationSchema::installForRecipe($wpdb); return ['ready' => true];
        })(),
        'participation-cases' => (new \Faluss\Platform\Fans\Hof\SessionAppeals($wpdb))->list($input['after'] ?? ''),
        'participation-case' => (new \Faluss\Platform\Fans\Hof\SessionAppeals($wpdb))->own($input['case']),
        'fixture-extra-creator' => (static function () use ($wpdb,$admin): array {
            $label = 'b6_session_' . bin2hex(random_bytes(8));
            $id = wp_insert_user(['user_login' => $label,'user_pass' => wp_generate_password(40),
                'user_email' => $label . '@example.invalid','role' => 'subscriber']);
            if (is_wp_error($id)) { throw new ModelViolation('pf_fixture_actor_required'); }
            if ($wpdb->insert(FansSsoSchema::tables()['links'],['wp_user_id' => $id,'faluss_id' => wp_generate_uuid4(),
                'created_at' => gmdate('Y-m-d H:i:s'),'last_proved_at' => gmdate('Y-m-d H:i:s')]) !== 1) { throw new ModelViolation('pf_fixture_actor_required'); }
            wp_set_current_user($id); $profile = CreatorProfileService::create('arts');
            if (is_wp_error($profile)) { throw new ModelViolation($profile->get_error_code()); }
            wp_set_current_user($admin->ID); $active = CreatorProfileService::setStatus($profile['creator_id'],'active');
            if (is_wp_error($active)) { throw new ModelViolation($active->get_error_code()); }
            wp_set_current_user($id); $proposal = EditorialService::submit(0,'Autre atelier de recette','Profil fictif pour vérifier les refus de propriété.','',0);
            if (is_wp_error($proposal)) { throw new ModelViolation($proposal->get_error_code()); }
            wp_set_current_user($admin->ID); $approved = EditorialService::decide($profile['creator_id'],1,'approve','allowed_editorial');
            if (is_wp_error($approved)) { throw new ModelViolation($approved->get_error_code()); }
            return ['creator_user' => $id,'creator_profile' => $profile['creator_id']];
        })(),
        'fixture-cookie' => (static function () use ($input): array {
            if (!isset($input['actor']) || get_user_by('id',$input['actor']) === false) { throw new ModelViolation('pf_fixture_actor_required'); }
            $expires = time() + (($input['expired'] ?? false) === true ? -1 : \Faluss\Platform\Fans\Sso\FansSsoService::SESSION_TTL);
            $token = WP_Session_Tokens::get_instance($input['actor'])->create($expires);
            return ['name' => (string) constant('LOGGED_IN_COOKIE'),'value' => wp_generate_auth_cookie($input['actor'],$expires,'logged_in',$token)];
        })(),
        'render' => (static function () use ($delivery,$input): array {
            // Actual server DTO and templates, not an injected score or simulated REST response.
            $linked = \Faluss\Platform\Fans\Sso\FansSsoService::currentLinkedSubject() !== null;
            $creator = $linked ? CreatorProfileService::own() : null;
            $requested = $input['role']; $view = $input['view'];
            $status = \Faluss\Platform\Fans\Ui\FansUiRoutes::accessStatus($requested,$view,$linked,$creator !== null);
            if ($status !== 200) { return ['status' => $status,'html' => '']; }
            $role = $linked ? $requested : 'visitor'; $_GET = $input['selection'] ?? [];
            add_filter('faluss_fans_hof_closed_delivery',static fn (): ClosedRankingDelivery => $delivery);
            $state = \Faluss\Platform\Fans\Ui\FansUiHof::load($role,$view);
            \Faluss\Platform\Fans\Sso\FansSsoService::enqueueButtonStyle();
            ob_start();
            \Faluss\Platform\Fans\Ui\FansUiView::render($role,$view,null,$status,
                \Faluss\Platform\Fans\Sso\FansSsoService::button(['return_to' => '/app/' . $requested . '/' . $view]),hof: $state);
            return ['status' => $status,'html' => ob_get_clean()];
        })(),
        'create-session' => (static function () use ($wpdb,$admin,$input,$inbox): array {
            wp_set_current_user($admin->ID); SessionReviewSchema::installOrVerify($wpdb); ClosedSessionLifecycleSchema::installForRecipe($wpdb);
            wp_set_current_user($input['actor']);
            $now = new DateTimeImmutable((new SessionStore($wpdb))->now(),new DateTimeZone('UTC'));
            $sessions = new SessionService($wpdb); $moderation = new SessionModeration($wpdb);
            $session = $sessions->create(['title' => 'Rencontres de recette B6','rules_text' => 'Participation volontaire. Données fictives de recette.',
                'category' => $input['category'],'scope' => 'international','country' => '','territory_ref' => '',
                'timezone' => 'Europe/Paris','starts_at' => $now->modify('-1 minute')->format('Y-m-d H:i:s.u'),
                'ends_at' => $now->modify('+40 minutes')->format('Y-m-d H:i:s.u')]);
            $review = $moderation->submit($session['session_id'],(int) $session['revision'],0);
            wp_set_current_user($admin->ID); $moderation->decide($session['session_id'],(int) $review['revision'],'approve','allowed_session');
            wp_set_current_user($input['actor']); $session = $sessions->requestOpen($session['session_id'],(int) $session['revision']);
            $bridge = new ClosedSessionLifecycle($wpdb,$inbox,new ClosedBarrierClient($wpdb,$inbox,'recipe-fans-k1',$input['endpoint']),$input['origin']);
            $prepared = $bridge->prepare($session['session_id'],(int) $session['revision']);
            $result = $bridge->advance($session['session_id'],(int) $session['revision']);
            if (($result['session_state'] ?? '') !== 'open') { throw new ModelViolation('pf_fixture_session_open_required'); }
            $session = $sessions->inspect($session['session_id'])['session'];
            // The organizer also explicitly accepts and joins as an individual participant.
            $roles = new SessionRoles($wpdb); $session = $roles->apply($session['session_id'],(int) $session['revision'],$session['frozen_sha256']);
            $own = CreatorProfileService::own();
            if ($own === null) { throw new ModelViolation('pf_fixture_creator_required'); }
            $roles->admit($session['session_id'],(int) $session['revision'],$own['creator_id'],true);
            $store = new SessionStore($wpdb);
            $admission = $store->transaction(fn (): ?array => $store->role($session['session_id'],$own['creator_id'],'participant'));
            if ($admission === null || $admission['state'] !== 'admitted') { throw new ModelViolation('pf_fixture_admission_required'); }
            $content = SessionBarrierDescriptor::compose($session,$input['origin'],null)['content'];
            $context = array_intersect_key($content,array_flip(['rules_revision','rules_sha256','starts_at','ends_at','scope','territory_policy_revision','country','territory_ref']));
            return ['session_id' => $session['session_id'],'action_id' => $prepared['action_id'],'context' => ['session_id' => $session['session_id'],
                'barrier_version' => $session['barrier_version'],'admission_revision' => $admission['revision'],
                'admitted_at' => $admission['admitted_at'],'territory_admission_revision' => '0'] + $context];
        })(),
        'close-session' => (static function () use ($wpdb,$input,$inbox): array {
            $sessions = new SessionService($wpdb); $session = $sessions->inspect($input['session'])['session'];
            if ($session['state'] !== 'closing') { $session = $sessions->close($input['session'],(int) $session['revision'],'cancelled'); }
            if (($input['acknowledge'] ?? false) !== true) { return $session; }
            $bridge = new ClosedSessionLifecycle($wpdb,$inbox,new ClosedBarrierClient($wpdb,$inbox,'recipe-fans-k1',$input['endpoint']),$input['origin']);
            $prepared = $bridge->prepare($input['session'],(int) $session['revision']); $result = $bridge->advance($input['session'],(int) $session['revision']);
            return $result + ['action_id' => $prepared['action_id']];
        })(),
        'private-session-bindings' => (static function () use ($wpdb,$input,$inbox): array {
            (new SessionService($wpdb))->inspect($input['session']);
            $table = ClosedSessionLifecycleSchema::tables($wpdb)['bindings'];
            $rows = $wpdb->get_results($wpdb->prepare('SELECT operation,action_id,fields_sha256,applied_at FROM %i WHERE session_id=%s ORDER BY operation',
                $table,$input['session']),'ARRAY_A');
            if (!is_array($rows) || $wpdb->last_error !== '') { throw new ModelViolation('hof_storage_unavailable'); }
            foreach ($rows as &$row) {
                $progress = $inbox->recover($row['action_id']);
                $row['operation_key_sha256'] = hash('sha256',$progress['operation_key']);
                $row['transport_phase'] = $progress['phase']; $row['transport_state'] = $progress['state'];
            }
            unset($row);
            return $rows;
        })(),
        'preferences' => $visibility->own(),
        'presentation-timezone' => (static function () use ($input): array {
            if (!current_user_can('manage_options') || !in_array($input['zone'], ['', 'UTC', 'Europe/Paris'], true)) { throw new ModelViolation('pf_fixture_timezone_refused'); }
            $previous = get_option('timezone_string', ''); update_option('timezone_string', $input['zone']);
            return ['previous' => $previous];
        })(),
        'consent' => $visibility->consent($input['family'],$input['enabled'],(int) $visibility->own()['revision']),
        'suspend' => (static function () use ($admin,$input): mixed { wp_set_current_user($admin->ID); return CreatorProfileService::setStatus($input['creator_profile'],$input['status']); })(),
        'seed' => (static function () use ($wpdb,$input,$admin,$visibility): array {
            wp_set_current_user($admin->ID);
            if (!FansSsoSchema::installOrVerify() || !CreatorProfileSchema::installOrVerify() || !EditorialSchema::installOrVerify()
                || !\Faluss\Platform\Fans\Notifications\NotificationSchema::installOrVerify() || !CreatorStatusSchema::installOrVerify()) {
                throw new ModelViolation('pf_fixture_account_schemas_unavailable');
            }
            $actors = [];
            foreach (['fan' => $input['member_faluss_id'],'creator' => $input['creator_faluss_id']] as $family => $subject) {
                ModelValues::uuid($subject);
                $label = 'b6_' . bin2hex(random_bytes(8));
                $id = wp_insert_user(['user_login' => $label,'user_pass' => wp_generate_password(40),
                    'user_email' => $label . '@example.invalid','role' => 'subscriber']);
                if (is_wp_error($id)) { throw new RuntimeException('Fixture member unavailable'); }
                if ($wpdb->insert(FansSsoSchema::tables()['links'],['wp_user_id' => $id,'faluss_id' => $subject,
                    'created_at' => gmdate('Y-m-d H:i:s'),'last_proved_at' => gmdate('Y-m-d H:i:s')]) !== 1) { throw new RuntimeException('Fixture binding unavailable'); }
                $actors[$family . '_user'] = $id;
            }
            wp_set_current_user($actors['creator_user']); $profile = CreatorProfileService::create($input['category']);
            if (is_wp_error($profile)) { throw new ModelViolation($profile->get_error_code()); }
            $actors['creator_profile'] = $profile['creator_id'];
            wp_set_current_user($admin->ID); $active = CreatorProfileService::setStatus($profile['creator_id'],'active');
            if (is_wp_error($active)) { throw new ModelViolation($active->get_error_code()); }
            wp_set_current_user($actors['creator_user']); $proposal = EditorialService::submit(0,'Atelier de recette B6','Présentation fictive de recette','',0);
            if (is_wp_error($proposal)) { throw new ModelViolation($proposal->get_error_code()); }
            wp_set_current_user($admin->ID); $approved = EditorialService::decide($profile['creator_id'],1,'approve','allowed_editorial');
            if (is_wp_error($approved)) { throw new ModelViolation($approved->get_error_code()); }
            wp_set_current_user($actors['creator_user']); $visibility->consent('creator',true,(int) $visibility->own()['revision']);
            wp_set_current_user($actors['fan_user']); $alias = $visibility->submit('Fan de recette B6',0);
            wp_set_current_user($admin->ID); $visibility->decide($actors['fan_user'],(int) $alias['revision'],'approve','allowed_alias');
            wp_set_current_user($actors['fan_user']); $visibility->consent('fan',true,(int) $visibility->own()['revision']);
            return $actors + ['admin_user' => $admin->ID];
        })(),
        default => str_starts_with($input['action'],'territory-ui-') ? require __DIR__ . '/b6-territory-ui-worker.php'
            : throw new RuntimeException('Unknown closed delivery fixture operation'),
    };
} catch (ModelViolation $error) { $result = ['error' => $error->reason]; }
echo wp_json_encode($result,JSON_THROW_ON_ERROR);
