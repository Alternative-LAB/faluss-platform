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
        'consent' => $visibility->consent($input['family'],$input['enabled'],(int) $visibility->own()['revision']),
        'suspend' => (static function () use ($admin,$input): mixed { wp_set_current_user($admin->ID); return CreatorProfileService::setStatus($input['creator_profile'],$input['status']); })(),
        'seed' => (static function () use ($wpdb,$input,$admin,$visibility): array {
            wp_set_current_user($admin->ID);
            if (!FansSsoSchema::installOrVerify() || !CreatorProfileSchema::installOrVerify() || !EditorialSchema::installOrVerify()
                || !\Faluss\Platform\Fans\Notifications\NotificationSchema::installOrVerify() || !CreatorStatusSchema::installOrVerify()) {
                throw new RuntimeException('Fixture account schemas unavailable');
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
            if (is_wp_error($profile)) { throw new RuntimeException('Fixture profile unavailable'); }
            $actors['creator_profile'] = $profile['creator_id'];
            wp_set_current_user($admin->ID); CreatorProfileService::setStatus($profile['creator_id'],'active');
            wp_set_current_user($actors['creator_user']); $proposal = EditorialService::submit(0,'Atelier de recette B6','Présentation fictive de recette','',0);
            if (is_wp_error($proposal)) { throw new RuntimeException('Fixture editorial unavailable'); }
            wp_set_current_user($admin->ID); $approved = EditorialService::decide($profile['creator_id'],1,'approve','allowed_editorial');
            if (is_wp_error($approved)) { throw new RuntimeException('Fixture approval unavailable'); }
            wp_set_current_user($actors['creator_user']); $visibility->consent('creator',true,(int) $visibility->own()['revision']);
            wp_set_current_user($actors['fan_user']); $alias = $visibility->submit('Fan de recette B6',0);
            wp_set_current_user($admin->ID); $visibility->decide($actors['fan_user'],(int) $alias['revision'],'approve','allowed_alias');
            wp_set_current_user($actors['fan_user']); $visibility->consent('fan',true,(int) $visibility->own()['revision']);
            return $actors;
        })(),
        default => throw new RuntimeException('Unknown closed delivery fixture operation'),
    };
} catch (ModelViolation $error) { $result = ['error' => $error->reason]; }
echo wp_json_encode($result,JSON_THROW_ON_ERROR);
