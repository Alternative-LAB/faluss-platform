<?php

declare(strict_types=1);

use Faluss\Platform\Fans\PfContract\ClosedCorpusInbox;
use Faluss\Platform\Fans\PfContract\ClosedCorpusClient;
use Faluss\Platform\Fans\PfContract\ClosedCorpusReader;
use Faluss\Platform\Fans\PfContract\ClosedCorpusInboxSchema;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;

global $wpdb;
ClosedEnvironment::assertIsolated($wpdb,'fans');
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('Fixture CLI only.'); }
require_once WP_PLUGIN_DIR . '/faluss-platform/src/Federation/Legacy/includes/class-faluss-federation-crypto.php';
$input = json_decode(file_get_contents($args[0]),true,40,JSON_THROW_ON_ERROR);
require_once __DIR__ . '/corpus-http-metrics.php';
corpus_recipe_http_metrics($args[0]);

/** Fails around actual private-primary COMMITs, without an HTTP or production hook. */
final class FansCorpusRecipeDatabase extends wpdb
{
    public string $fault = '';
    public string $marker = '';

    public function query($query)
    {
        if ($this->fault === 'cache-insert' && str_starts_with($query,'INSERT INTO `wp_fans_hof_b4_generation`')) { $this->fault = ''; return false; }
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

/** A trusted outer facade probe, not a fabricated Hub acknowledgement. */
function corpus_recipe_compose(wpdb $db, PeerPolicy $peer, array $input): array
{
    $origin = $input['origin']; $scope = $input['scope'] ?? 'valid'; $held = $started = false;
    $lock = 'fans_pf_b3b_' . substr(hash('sha256',$db->prefix . ':' . ($scope === 'wrong-origin' ? $input['other_origin'] : $origin) . ':1.0.0'),0,40);
    $cache = new \Faluss\Platform\Fans\Hof\ClosedRankingProjectionStore($db,$peer,$origin,'1.0.0');
    try {
        if ($scope !== 'no-lock') {
            if ((string) $db->get_var($db->prepare('SELECT GET_LOCK(%s,10)',$lock)) !== '1') { throw new RuntimeException('Fixture outer lock failed.'); }
            $held = true;
        }
        if ($scope !== 'no-transaction') {
            if ($db->query('START TRANSACTION') === false) { throw new RuntimeException('Fixture outer transaction failed.'); }
            $started = true;
        }
        $value = $cache->withReadInTransaction(static function (array $generation) use ($db,$cache,$input,$scope): array {
            if ($db->query('INSERT INTO wp_corpus_tx_probe (id) VALUES (1)') !== 1) { throw new RuntimeException('Fixture probe write failed.'); }
            if ($scope === 'recursive') { $cache->withReadInTransaction(static fn (array $row): array => $row); }
            if ($scope === 'ordinary-nested') { $cache->read(); }
            if ($scope === 'reject') { throw new ModelViolation('pf_fixture_apply_refused'); }
            if (isset($input['marker'])) {
                file_put_contents($input['marker'],'ready'); $deadline = microtime(true)+20;
                while (!is_file($input['release']) && microtime(true) < $deadline) { usleep(10000); }
                if (!is_file($input['release'])) { throw new RuntimeException('Fixture release missing.'); }
            }
            return ['state' => $generation['state'],'generation' => $generation['generation'],
                'transaction' => (string) $db->get_var('SELECT @@in_transaction')];
        });
        $active = (string) $db->get_var('SELECT @@in_transaction');
        if ($db->query(($input['rollback'] ?? false) ? 'ROLLBACK' : 'COMMIT') === false) { throw new ModelViolation('pf_fixture_outer_commit_unknown'); }
        $started = false; return $value + ['outer_transaction' => $active];
    } catch (Throwable $error) { if ($started) { $db->query('ROLLBACK'); } throw $error; }
    finally { if ($held && (string) $db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)',$lock)) !== '1') { throw new RuntimeException('Fixture outer lock release failed.'); } }
}

try {
    if ($input['action'] === 'cache-ready') { $result = ['ready' => \Faluss\Platform\Fans\Hof\ClosedRankingProjectionSchema::ready($wpdb)]; }
    elseif ($input['action'] === 'cache-install') { \Faluss\Platform\Fans\Hof\ClosedRankingProjectionSchema::installForRecipe($wpdb); $result = ['ready' => true]; }
    elseif ($input['action'] === 'ready') { $result = ['ready' => ClosedCorpusInboxSchema::ready($wpdb)]; }
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
        $trace = [];
        if (($input['monitor'] ?? false) === true) {
            add_filter('pre_http_request',static function ($pre, $args) use ($wpdb,$input,&$trace) {
                $lock = 'fans_pf_b3_corpus_' . substr(hash('sha256',$wpdb->prefix . ':' . $input['origin'] . ':1.0.0'),0,32);
                $trace[] = ['transaction' => (string) $wpdb->get_var('SELECT @@in_transaction'),
                    'mutex_free' => (string) $wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)',$lock)),
                    'registered' => (string) $wpdb->get_var($wpdb->prepare(
                        'SELECT COUNT(*) FROM %i q JOIN %i r ON q.read_id=r.read_id WHERE r.origin_id=%s AND r.policy_version=%s AND q.request_sha256=%s',
                        $wpdb->prefix . 'fans_pf_b3c_requests',$wpdb->prefix . 'fans_pf_b3c_reads',$input['origin'],'1.0.0',hash('sha256',$args['body'])))];
                return $pre;
            },10,2);
        }
        $result = match ($input['action']) {
            'advance' => (new ClosedCorpusReader($inbox,new ClosedCorpusClient($wpdb,$peer,'recipe-fans-k1',
                $input['endpoint'] ?? file_get_contents($root . '/corpus-endpoint'),$input['origin'],'1.0.0')))->advance($input['read_id'],$input['steps'] ?? 4),
            'prepare' => $inbox->prepare($input['read_id']),
            'fields' => $inbox->fields($inbox->prepare($input['read_id'])),
            'request' => $inbox->request($input['fields'],$input['sealed']),
            'accept' => $inbox->accept($input['proof']),
            'current' => ['current' => $inbox->current()],
            'cache-rebuild' => (new \Faluss\Platform\Fans\Hof\ClosedRankingProjectionStore($wpdb,$peer,$input['origin'],'1.0.0'))->rebuild(),
            'cache-read' => (new \Faluss\Platform\Fans\Hof\ClosedRankingProjectionStore($wpdb,$peer,$input['origin'],'1.0.0'))->read(),
            'cache-compose' => corpus_recipe_compose($wpdb,$peer,$input),
            default => throw new RuntimeException('Unknown closed inbox operation.'),
        };
        if (($input['monitor'] ?? false) === true) { $result['http_trace'] = $trace; }
    }
} catch (ModelViolation $error) { $result = ['error' => $error->reason]; }
echo wp_json_encode($result,JSON_THROW_ON_ERROR);
