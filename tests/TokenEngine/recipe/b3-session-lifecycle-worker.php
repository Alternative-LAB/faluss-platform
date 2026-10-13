<?php

declare(strict_types=1);

use Faluss\Platform\Fans\Hof\ClosedSessionLifecycle;
use Faluss\Platform\Fans\Hof\ClosedSessionLifecycleSchema;
use Faluss\Platform\Fans\Hof\RankingRegistry;
use Faluss\Platform\Fans\Hof\RankingSchema;
use Faluss\Platform\Fans\Hof\SessionSchema;
use Faluss\Platform\Fans\Hof\SessionService;
use Faluss\Platform\Fans\Hof\SessionModeration;
use Faluss\Platform\Fans\Hof\SessionReviewSchema;
use Faluss\Platform\Fans\PfContract\ClosedBarrierInbox;
use Faluss\Platform\Fans\PfContract\ClosedBarrierClient;
use Faluss\Platform\Fans\Profiles\CreatorProfileSchema;
use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\Fans\Profiles\CreatorStatusSchema;
use Faluss\Platform\Fans\Profiles\EditorialSchema;
use Faluss\Platform\Fans\Profiles\EditorialService;
use Faluss\Platform\Fans\Notifications\NotificationSchema;
use Faluss\Platform\Fans\Sso\FansSsoSchema;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;

global $wpdb;
ClosedEnvironment::assertIsolated($wpdb,'fans');
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('Private fixture CLI only.'); }
$input = json_decode(file_get_contents($args[0]),true,32,JSON_THROW_ON_ERROR);

final class FansSessionLifecycleRecipeDatabase extends wpdb
{
    public string $fault = '';
    public string $marker = '';
    public function query($query)
    {
        foreach (['binding-insert' => 'wp_fans_hof_b3_bindings','decision-insert' => 'wp_fans_hof_session_decisions'] as $fault => $table) {
            if ($this->fault === $fault && str_starts_with($query,'INSERT INTO `' . $table . '`')) { $this->fault = ''; return false; }
        }
        if (strtoupper(trim($query)) !== 'COMMIT' || !in_array($this->fault,['before-commit','after-commit','commit-unknown'],true)) { return parent::query($query); }
        $fault = $this->fault; $this->fault = '';
        if ($fault === 'before-commit') { $this->pause(); }
        $result = parent::query($query);
        if ($result === false) { throw new RuntimeException('Real fixture COMMIT failed.'); }
        if ($fault === 'after-commit') { $this->pause(); }
        return false;
    }
    private function pause(): void
    {
        file_put_contents($this->marker,'ready'); $deadline = microtime(true)+25;
        while (microtime(true)<$deadline) { usleep(10000); }
        throw new RuntimeException('Fixture controller did not terminate worker.');
    }
}
if (isset($input['fault'])) {
    $db = new FansSessionLifecycleRecipeDatabase(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST); $db->set_prefix($wpdb->prefix);
    $wpdb = $db; $db->fault = $input['fault']; $db->marker = $input['marker'] ?? '';
}
$admin = get_user_by('login','fixture');
if (!$admin instanceof WP_User || !user_can($admin,'manage_options')) { throw new RuntimeException('Private fixture administrator required.'); }
wp_set_current_user((int) ($input['actor'] ?? $admin->ID));
try {
    if ($input['action'] === 'seed') {
        if (!FansSsoSchema::installOrVerify() || !CreatorProfileSchema::installOrVerify() || !CreatorStatusSchema::installOrVerify()
            || !EditorialSchema::installOrVerify() || !NotificationSchema::installOrVerify()) { throw new ModelViolation('pf_fixture_profile_schemas_unavailable'); }
        RankingSchema::installOrVerify($wpdb); SessionSchema::installOrVerify($wpdb); SessionReviewSchema::installOrVerify($wpdb);
        (new RankingRegistry($wpdb))->prepareOrigin($input['origin']);
        $members = [];
        foreach (['owner','other','fan','unlinked'] as $name) {
            $member = wp_insert_user(['user_login' => 'hof_bridge_' . $name,'user_pass' => wp_generate_password(40),
                'user_email' => 'hof_bridge_' . $name . '@example.invalid','role' => 'subscriber']);
            if (is_wp_error($member)) { throw new RuntimeException('Private fixture account failed.'); }
            if ($name !== 'unlinked') {
                if ($wpdb->insert(FansSsoSchema::tables()['links'],['wp_user_id' => $member,'faluss_id' => wp_generate_uuid4(),
                    'created_at' => gmdate('Y-m-d H:i:s'),'last_proved_at' => gmdate('Y-m-d H:i:s')]) !== 1) { throw new RuntimeException('Private fixture link failed.'); }
            }
            $members[$name] = ['id' => $member];
            if (!in_array($name,['owner','other'],true)) { continue; }
            wp_set_current_user($member); $profile = CreatorProfileService::create('arts');
            if (is_wp_error($profile)) { throw new ModelViolation($profile->get_error_code()); }
            wp_set_current_user($admin->ID); $active = CreatorProfileService::setStatus($profile['creator_id'],'active');
            if (is_wp_error($active)) { throw new ModelViolation($active->get_error_code()); }
            wp_set_current_user($member); $submitted = EditorialService::submit(0,'Fictitious ' . $name,'Isolated fixture biography.','',0);
            if (is_wp_error($submitted)) { throw new ModelViolation($submitted->get_error_code()); }
            wp_set_current_user($admin->ID); $approved = EditorialService::decide($profile['creator_id'],1,'approve','allowed_editorial');
            if (is_wp_error($approved)) { throw new ModelViolation($approved->get_error_code()); }
            $members[$name]['creator'] = $profile['creator_id'];
        }
        $result = $members + ['admin' => ['id' => $admin->ID]];
    } elseif ($input['action'] === 'ready') { $result = ['ready' => ClosedSessionLifecycleSchema::ready($wpdb)]; }
    elseif ($input['action'] === 'install') { ClosedSessionLifecycleSchema::installForRecipe($wpdb); $result = ['ready' => true]; }
    else {
        $sessions = new SessionService($wpdb); $moderation = new SessionModeration($wpdb);
        $root = dirname(rtrim(ABSPATH,'/'));
        require_once WP_PLUGIN_DIR . '/faluss-platform/src/Federation/Legacy/includes/class-faluss-federation-crypto.php';
        $policy = json_decode(file_get_contents($root . '/fans-barrier-trust.json'),true,20,JSON_THROW_ON_ERROR);
        $peer = new PeerPolicy($policy['node'],$policy['audience'],$policy['permissions'],$policy['keys']);
        $inbox = new ClosedBarrierInbox($wpdb,$peer,$input['origin'],'1.0.0');
        $bridge = new ClosedSessionLifecycle($wpdb,$inbox,new ClosedBarrierClient($wpdb,$inbox,'recipe-fans-k1',$input['endpoint']),$input['origin']);
        $result = match ($input['action']) {
            'create' => $sessions->create($input['rules']),
            'review-submit' => $moderation->submit($input['session'],$input['revision'],$input['review_revision'] ?? 0),
            'review-decide' => $moderation->decide($input['session'],$input['review_revision'],$input['decision'],$input['reason']),
            'open' => $sessions->requestOpen($input['session'],$input['revision']),
            'close' => $sessions->close($input['session'],$input['revision'],$input['state']),
            'readmit' => $sessions->requestReadmission($input['session'],$input['revision']),
            'inspect' => $sessions->inspect($input['session']),
            'prepare' => $bridge->prepare($input['session'],$input['revision']),
            'advance' => $bridge->advance($input['session'],$input['revision'],$input['steps'] ?? 4),
            'apply' => $bridge->apply($input['session'],$input['action_id']),
            'profile-status' => CreatorProfileService::setStatus($input['creator'],$input['state']),
            default => throw new RuntimeException('Unknown private fixture operation.'),
        };
        if (is_wp_error($result)) { $result = ['error' => $result->get_error_code()]; }
    }
} catch (ModelViolation $error) { $result = ['error' => $error->reason]; }
echo wp_json_encode($result,JSON_THROW_ON_ERROR);
