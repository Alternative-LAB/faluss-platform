<?php

declare(strict_types=1);

use Faluss\Platform\Fans\PfContract\ClosedBarrierClient;
use Faluss\Platform\Fans\PfContract\ClosedBarrierInbox;
use Faluss\Platform\Fans\PfContract\ClosedBarrierInboxSchema;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\BarrierTransport;

global $wpdb;
ClosedEnvironment::assertIsolated($wpdb,'fans');
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('Fixture CLI only.'); }
require_once WP_PLUGIN_DIR . '/faluss-platform/src/Federation/Legacy/includes/class-faluss-federation-crypto.php';
$input = json_decode(file_get_contents($args[0]),true,40,JSON_THROW_ON_ERROR);

final class FansBarrierRecipeDatabase extends wpdb
{
    public string $fault = '';
    public string $marker = '';
    public function query($query)
    {
        foreach (['action-insert' => 'actions','request-insert' => 'requests'] as $fault => $table) {
            if ($this->fault === $fault && str_starts_with($query,'INSERT INTO `wp_fans_pf_b3b_' . $table . '`')) {
                $this->fault = ''; return false;
            }
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
        while (microtime(true) < $deadline) { usleep(10000); }
        throw new RuntimeException('Fixture controller did not terminate worker.');
    }
}

try {
    if ($input['action'] === 'ready') { $result = ['ready' => ClosedBarrierInboxSchema::ready($wpdb)]; }
    elseif ($input['action'] === 'install') { ClosedBarrierInboxSchema::installForRecipe($wpdb); $result = ['ready' => true]; }
    else {
        if (isset($input['fault'])) {
            $db = new FansBarrierRecipeDatabase(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST); $db->set_prefix($wpdb->prefix);
            $wpdb = $db; $db->fault = $input['fault']; $db->marker = $input['marker'] ?? '';
        }
        $root = dirname(rtrim(ABSPATH,'/'));
        $policy = json_decode(file_get_contents($root . '/fans-barrier-trust.json'),true,20,JSON_THROW_ON_ERROR);
        $peer = new PeerPolicy($policy['node'],$policy['audience'],$policy['permissions'],$policy['keys']);
        $inbox = new ClosedBarrierInbox($wpdb,$peer,$input['origin'],'1.0.0');
        $trace = [];
        if (($input['monitor'] ?? false) === true) {
            add_filter('pre_http_request',static function ($pre, $args) use ($wpdb,$input,&$trace) {
                $lock = 'fans_pf_b3b_' . substr(hash('sha256',$wpdb->prefix . ':' . $input['origin'] . ':1.0.0'),0,40);
                $trace[] = ['transaction' => (string) $wpdb->get_var('SELECT @@in_transaction'),
                    'mutex_free' => (string) $wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)',$lock)),
                    'registered' => (string) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE request_sha256=%s',
                        $wpdb->prefix . 'fans_pf_b3b_requests',hash('sha256',$args['body'])))];
                return $pre;
            },10,2);
        }
        if ($input['action'] === 'request') { $inbox->request($input['fields'],$input['sealed']); $result = ['accepted' => true]; }
        else { $result = match ($input['action']) {
            'prepare' => $inbox->prepare($input['fields'],$input['contract'] ?? BarrierTransport::CONTRACT),
            'recover' => $inbox->recover($input['action_id']),
            'accept' => $inbox->accept($input['proof']),
            'ack' => $inbox->withAcknowledgement($input['action_id'],static function (array $ack) use ($wpdb,$input,$inbox): array {
                if (($input['probe'] ?? false) === true) {
                    if ($wpdb->query($wpdb->prepare('INSERT INTO wp_barrier_ack_probe (action_id) VALUES (%s)',$input['action_id'])) !== 1) {
                        throw new ModelViolation('pf_fixture_probe_failed');
                    }
                }
                if (($input['reject_apply'] ?? false) === true) { throw new ModelViolation('pf_fixture_apply_refused'); }
                if (($input['nested'] ?? false) === true) { $inbox->recover($input['action_id']); }
                return $ack + ['transaction' => (string) $wpdb->get_var('SELECT @@in_transaction')];
            }),
            'advance' => (new ClosedBarrierClient($wpdb,$inbox,'recipe-fans-k1',$input['endpoint']))->advance($input['action_id'],$input['steps'] ?? 4),
            default => throw new RuntimeException('Unknown fixture action.'),
        }; }
        if (($input['monitor'] ?? false) === true) { $result['http_trace'] = $trace; }
    }
} catch (ModelViolation $error) { $result = ['error' => $error->reason]; }
echo wp_json_encode($result,JSON_THROW_ON_ERROR);
