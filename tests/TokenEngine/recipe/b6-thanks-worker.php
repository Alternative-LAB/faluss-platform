<?php

declare(strict_types=1);

use Faluss\Platform\Fans\Messaging\MessageAutomaticSettings;
use Faluss\Platform\Fans\Messaging\MessageAutomaticScheduler;
use Faluss\Platform\Fans\Messaging\MessageAutomationSchema;
use Faluss\Platform\Fans\Messaging\MessageContact;
use Faluss\Platform\Fans\Messaging\MessageSchema;
use Faluss\Platform\Fans\Messaging\MessageService;
use Faluss\Platform\Fans\Messaging\ReportSchema;
use Faluss\Platform\Fans\PfContract\ClosedCorpusInbox;
use Faluss\Platform\Fans\PfContract\ClosedPurchasedThanksDelivery;
use Faluss\Platform\Fans\PfContract\ClosedPurchasedThanksSchema;
use Faluss\Platform\Fans\PfContract\ClosedRankedProtocolSchema;
use Faluss\Platform\Fans\PfContract\ClosedRankedProtocolStore;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedIntent;

// Included only by the existing guarded WordPress CLI scenario. No request can supply a real peer.
add_filter('faluss_fans_closed_pf_thanks_delivery',static fn (): ClosedPurchasedThanksDelivery => new ClosedPurchasedThanksDelivery($wpdb,$opening,$reader,
    new ClosedCorpusInbox($wpdb,$peer,$input['origin'],'1.0.0'),$peer));
$result = match ($input['action']) {
    'thanks-install' => (static function () use ($wpdb): array {
        \Faluss\Platform\Fans\Hof\RankingRegistry::administrator();
        ClosedRankedProtocolSchema::installForRecipe($wpdb);
        if (!MessageSchema::installOrVerify() || !ReportSchema::installOrVerify() || !MessageContact::installOrVerify()
            || !MessageAutomationSchema::installOrVerify() || !\Faluss\Platform\Fans\Followers\FollowersSchema::installOrVerify()) { throw new ModelViolation('pf_thanks_messages_unavailable'); }
        ClosedPurchasedThanksSchema::installForRecipe($wpdb); return ['ready' => true];
    })(),
    'thanks-settings' => MessageAutomaticSettings::own(),
    'thanks-save' => MessageAutomaticSettings::save($input['kind'] ?? 'thanks',$input['body'],$input['enabled'],$input['revision']),
    'thanks-prepare' => ['key' => (new ClosedRankedProtocolStore($wpdb))->prepare(RankedIntent::fromArray($input['intent']),$input['operation'],$input['lookup'] ?? false)],
    'thanks-receive' => ['inserted' => (new ClosedRankedProtocolStore($wpdb))->receive(RankedIntent::fromArray($input['intent']),$input['receipt'],$peer)],
    'thanks-run' => MessageAutomaticScheduler::run(),
    'thanks-decide' => MessageService::decide($input['thread'],$input['revision'],$input['decision']),
    'thanks-follow' => \Faluss\Platform\Fans\Followers\FollowersService::follow($input['creator']),
    'thanks-stats' => (static function () use ($wpdb,$input): array {
        \Faluss\Platform\Fans\Hof\RankingRegistry::administrator();
        $table = MessageSchema::table('automatic_events'); $where = ' WHERE creator_id=%s';
        $events = $wpdb->get_results($wpdb->prepare('SELECT event_key,kind,config_revision,config_epoch,state,body,error,attempts,TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),next_attempt_at) AS wait FROM `'.$table.'`'.$where.' ORDER BY event_key',$input['creator']),'ARRAY_A');
        $messages = $wpdb->get_results($wpdb->prepare('SELECT m.body,m.thread_id,t.state,t.revision FROM `'.MessageSchema::table('messages').'` m INNER JOIN `'.MessageSchema::table('threads').'` t ON t.thread_id=m.thread_id WHERE t.creator_id=%s ORDER BY m.sequence',$input['creator']),'ARRAY_A');
        if (!is_array($events) || !is_array($messages) || $wpdb->last_error !== '') { throw new ModelViolation('pf_thanks_storage_unavailable'); }
        return ['events' => $events,'messages' => $messages];
    })(),
    default => throw new RuntimeException('Unknown private PF thanks scenario'),
};
if ($result instanceof WP_Error) { return ['error' => $result->get_error_code()]; }
return $result;
