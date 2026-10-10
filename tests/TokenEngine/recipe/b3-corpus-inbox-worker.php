<?php

declare(strict_types=1);

use Faluss\Platform\Fans\PfContract\ClosedCorpusInbox;
use Faluss\Platform\Fans\PfContract\ClosedCorpusInboxSchema;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;

global $wpdb;
ClosedEnvironment::assertIsolated($wpdb,'fans');
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('Fixture CLI only.'); }
require_once WP_PLUGIN_DIR . '/faluss-platform/src/Federation/Legacy/includes/class-faluss-federation-crypto.php';
$input = json_decode(file_get_contents($args[0]),true,40,JSON_THROW_ON_ERROR);

/** Fails around actual private-primary COMMITs, without an HTTP or production hook. */
final class FansCorpusRecipeDatabase extends wpdb
{
    public string $fault = '';
    public string $marker = '';

    public function query($query)
    {
        if (($this->fault === 'page-insert' && str_starts_with($query,'INSERT INTO `wp_fans_pf_b3c_pages`'))
            || ($this->fault === 'current-write' && preg_match('/^(INSERT INTO|UPDATE) `wp_fans_pf_b3c_current`/',$query) === 1)) {
            $this->fault = ''; return false;
        }
        if (strtoupper(trim($query)) !== 'COMMIT' || $this->fault === '') { return parent::query($query); }
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
    if ($input['action'] === 'ready') { $result = ['ready' => ClosedCorpusInboxSchema::ready($wpdb)]; }
    elseif ($input['action'] === 'install') {
        ClosedCorpusInboxSchema::installForRecipe($wpdb); $result = ['ready' => ClosedCorpusInboxSchema::ready($wpdb)];
    } else {
        if (isset($input['fault'])) {
            $db = new FansCorpusRecipeDatabase(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST); $db->set_prefix($wpdb->prefix);
            $wpdb = $db; $db->fault = $input['fault']; $db->marker = $input['marker'] ?? '';
        }
        $root = dirname(rtrim(ABSPATH,'/')); $policy = json_decode(file_get_contents($root . '/fans-corpus-trust.json'),true,20,JSON_THROW_ON_ERROR);
        $peer = new PeerPolicy($policy['node'],$policy['audience'],$policy['permissions'],$policy['keys']);
        $inbox = new ClosedCorpusInbox($wpdb,$peer,$input['origin'],'1.0.0');
        $result = match ($input['action']) {
            'prepare' => $inbox->prepare($input['read_id']),
            'fields' => $inbox->fields($inbox->prepare($input['read_id'])),
            'request' => $inbox->request($input['fields'],$input['sealed']),
            'accept' => $inbox->accept($input['proof']),
            'current' => ['current' => $inbox->current()],
            default => throw new RuntimeException('Unknown closed inbox operation.'),
        };
    }
} catch (ModelViolation $error) { $result = ['error' => $error->reason]; }
echo wp_json_encode($result,JSON_THROW_ON_ERROR);
