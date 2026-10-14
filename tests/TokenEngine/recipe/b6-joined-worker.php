<?php

declare(strict_types=1);

use Faluss\Platform\Fans\Hof\ClosedRankingProjectionStore;
use Faluss\Platform\Fans\PfContract\ClosedBarrierClient;
use Faluss\Platform\Fans\PfContract\ClosedBarrierInbox;
use Faluss\Platform\Fans\PfContract\ClosedBarrierInboxSchema;
use Faluss\Platform\TokenEngine\PurchasedPf\ClosedBarrierTransportSchema;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingBarrier;

global $wpdb;
ClosedEnvironment::assertIsolated($wpdb,FALUSS_PLATFORM_ROLE);
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('Fixture CLI only.'); }
require_once WP_PLUGIN_DIR . '/faluss-platform/src/Federation/Legacy/includes/class-faluss-federation-crypto.php';
$input=json_decode(file_get_contents($args[0]),true,40,JSON_THROW_ON_ERROR);
try {
    if (FALUSS_PLATFORM_ROLE==='hub') {
        ClosedBarrierTransportSchema::installForRecipe($wpdb);$result=['ready'=>ClosedBarrierTransportSchema::ready($wpdb)];
    } else {
        $root=dirname(rtrim(ABSPATH,'/'));
        $policy=json_decode(file_get_contents($root.'/fans-corpus-trust.json'),true,20,JSON_THROW_ON_ERROR);
        $peer=new PeerPolicy($policy['node'],$policy['audience'],$policy['permissions'],$policy['keys']);
        if ($input['action']==='install') {
            ClosedBarrierInboxSchema::installForRecipe($wpdb);$result=['ready'=>ClosedBarrierInboxSchema::ready($wpdb)];
        } elseif ($input['action']==='describe') {
            $content=RankingBarrier::reference(['kind'=>'origin','origin_id'=>$input['origin'],'object_id'=>$input['origin'],
                'subject_id'=>'','version'=>'1','policy_version'=>'1.0.0'])['content'];
            $result=RankingBarrier::descriptor(['content'=>$content,
                'valid_from'=>gmdate('Y-m-d H:i:s',time()-1).'.000000',
                'valid_until'=>gmdate('Y-m-d H:i:s',time()+3600).'.000000']);
        } else {
            $inbox=new ClosedBarrierInbox($wpdb,$peer,$input['origin'],'1.0.0');
            $cache=new ClosedRankingProjectionStore($wpdb,$peer,$input['origin'],'1.0.0');
            $result=match ($input['action']) {
                'prepare'=>$inbox->prepare($input['fields']),
                'advance'=>(new ClosedBarrierClient($wpdb,$inbox,'recipe-fans-k1',$input['endpoint']))->advance($input['action_id']),
                'ack'=>$inbox->withAcknowledgement($input['action_id'],static fn(array $ack):array=>$ack),
                'compose'=>$inbox->withAcknowledgement($input['action_id'],static function(array $ack) use($cache,$wpdb,$input):array {
                    // The facade also serves closure governance: its caller must suppress delivery while closing.
                    if ($ack['fields']['operation']!=='register' || $ack['fields']['object']['content']['kind']!=='origin'
                        || $ack['result']['state']!=='active' || $ack['local_state']!=='active') {
                        throw new ModelViolation('pf_fixture_origin_not_active');
                    }
                    return $cache->withReadInTransaction(static function(array $generation) use($wpdb,$input):array {
                        if ($wpdb->query('INSERT INTO wp_b6_joined_probe (id) VALUES (1)')!==1) { throw new RuntimeException('Private probe failed.'); }
                        if (($input['reject']??false)===true) { throw new ModelViolation('pf_fixture_apply_refused'); }
                        return ['state'=>$generation['state'],'generation'=>$generation['generation'],
                            'transaction'=>(string)$wpdb->get_var('SELECT @@in_transaction')];
                    });
                }),
                default=>throw new RuntimeException('Unknown joined fixture operation.'),
            };
        }
    }
} catch (ModelViolation $error) { $result=['error'=>$error->reason]; }
echo wp_json_encode($result,JSON_THROW_ON_ERROR);
