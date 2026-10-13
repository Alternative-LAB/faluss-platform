<?php

declare(strict_types=1);

use Faluss\Platform\Fans\PfContract\ClosedRankedProtocolSchema;
use Faluss\Platform\Fans\PfContract\ClosedRankedProtocolStore;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedIntent;

global $wpdb,$table_prefix;
ClosedEnvironment::assertIsolated($wpdb,'fans');
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('Fixture CLI only.'); }
require_once WP_PLUGIN_DIR . '/faluss-platform/src/Federation/Legacy/includes/class-faluss-federation-crypto.php';
$input = json_decode(file_get_contents($args[0]),true,40,JSON_THROW_ON_ERROR);

final class RankedInboxFixtureDatabase extends wpdb
{
    public string $fault = '';
    public string $marker = '';
    public function query($query)
    {
        if (($this->fault === 'key-insert' && preg_match('/^INSERT INTO `[^`]+fans_pf_b3r_keys` /',$query) === 1)
            || ($this->fault === 'receipt-insert' && preg_match('/^INSERT INTO `[^`]+fans_pf_b3r_receipts` /',$query) === 1)) {
            $this->fault = ''; return false;
        }
        if (strtoupper(trim($query)) !== 'COMMIT' || $this->fault === '') { return parent::query($query); }
        $fault = $this->fault; $this->fault = '';
        if ($fault === 'before-commit') { $this->pause(); }
        $result = parent::query($query);
        if ($result === false) { throw new RuntimeException('Fixture COMMIT failed.'); }
        if ($fault === 'after-commit') { $this->pause(); }
        return false;
    }
    private function pause(): void
    {
        file_put_contents($this->marker,'held'); $deadline = microtime(true)+25;
        while (microtime(true)<$deadline) { usleep(10000); }
        throw new RuntimeException('Fixture controller did not terminate process.');
    }
}
if (isset($input['fault'])) {
    $wpdb = new RankedInboxFixtureDatabase(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST); $wpdb->set_prefix($table_prefix);
    $wpdb->fault = $input['fault']; $wpdb->marker = $input['marker'] ?? '';
}
try {
    if ($input['action'] === 'ready') { $result = ['ready' => ClosedRankedProtocolSchema::ready($wpdb)]; }
    elseif ($input['action'] === 'install') { ClosedRankedProtocolSchema::installForRecipe($wpdb); $result = ['ready' => ClosedRankedProtocolSchema::ready($wpdb)]; }
    else {
        $store = new ClosedRankedProtocolStore($wpdb);
        if ($input['action'] === 'recover') { $result = ['intent' => $store->recover($input['attribution'],$input['member'])->values]; }
        elseif ($input['action'] === 'prepare') {
            $result = ['key' => $store->prepare(RankedIntent::fromArray($input['intent']),$input['operation'],$input['lookup'] ?? false)];
        } else {
            $policy = json_decode(file_get_contents(dirname(rtrim(ABSPATH,'/')) . '/fans-ranked-trust.json'),true,20,JSON_THROW_ON_ERROR);
            $peer = new PeerPolicy($policy['node'],$policy['audience'],$policy['permissions'],$policy['keys']);
            $result = ['inserted' => $store->receive(RankedIntent::fromArray($input['intent']),$input['receipt'],$peer)];
        }
    }
} catch (ModelViolation $error) { $result = ['error' => $error->reason]; }
echo wp_json_encode($result,JSON_THROW_ON_ERROR);
