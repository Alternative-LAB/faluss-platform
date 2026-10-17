<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Messaging;

use Faluss\Platform\Fans\Sso\FansLinkedAccounts;

/** Durable DB-only dispatcher into the ordinary conversation, notification and report services. */
final class MessageAutomaticDelivery
{
    /** Bounded job; a lost COMMIT reply leaves the same event/key for a primary reread. */
    public static function run(): mixed
    {
        if(!MessageAutomationSchema::ready()){return MessagePolicy::error('message_automations_unavailable');}
        $done=0;
        for($i=0;$i<20;$i++){
            $result=MessageStore::transaction([self::class,'nextInTransaction']);
            if($result instanceof \WP_Error){return $result;}
            if($result===false){break;}
            $done++;
        }
        return ['processed'=>$done];
    }

    public static function nextInTransaction(): mixed
    {
        if(!MessageStore::ownsTransaction()||!MessageAutomationSchema::ready()){return MessagePolicy::error('message_primary_required');}
        global $wpdb;$table=MessageSchema::table('automatic_events');
        $row=$wpdb->get_row('SELECT *,DATE_ADD(created_at,INTERVAL 12 MONTH)<=UTC_TIMESTAMP() AS expired FROM `'.$table.'` WHERE state IN (\'pending\',\'retry\') AND next_attempt_at<=UTC_TIMESTAMP() ORDER BY next_attempt_at,event_key LIMIT 1 FOR UPDATE','ARRAY_A');
        if(MessageStore::failed()){return MessagePolicy::error('messages_read_failed');}
        if($row===null){return false;}
        if((bool)$row['expired']){return self::finish($row,'expired','expired');}
        if(!MessageModule::available()){return self::finish($row,'cancelled','admission_closed');}
        $config=MessageAutomaticSettings::inTransaction($row['creator_id'],$row['kind']);
        if($config instanceof \WP_Error){return $config;}
        if(!$config['enabled']||$config['epoch']!==(int)$row['config_epoch']){return self::finish($row,'cancelled','disabled');}
        // Only the follow event is connected in this first dispatcher. Never treat a row as PF proof.
        if($row['kind']!=='welcome'){return self::finish($row,'cancelled','source_unavailable');}
        $fan=FansLinkedAccounts::memberInTransaction($row['fan_identity']);
        if($fan===null){return self::finish($row,'cancelled','recipient_unavailable');}
        if(!MessagePolicy::text($row['body'])||!MessagePolicy::uuid($row['delivery_key'])){return MessagePolicy::error('message_automations_unavailable');}
        $message=MessageService::appendAutomaticInTransaction($fan,$row['creator_id'],$row['body'],$row['delivery_key']);
        if($message instanceof \WP_Error){
            $data=$message->get_error_data();$status=is_array($data)?($data['status']??503):503;
            if($status===429){
                $seconds=min(3600,60*(2**min(6,(int)$row['attempts'])));
                return $wpdb->query($wpdb->prepare('UPDATE `'.$table.'` SET state=%s,attempts=attempts+1,next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL %d SECOND),error=%s,updated_at=UTC_TIMESTAMP() WHERE event_key=%s',
                    'retry',$seconds,'message_send_limit',$row['event_key']))===1?true:MessagePolicy::error('messages_write_failed');
            }
            if(in_array($status,[403,404,410],true)){return self::finish($row,'cancelled','recipient_unavailable');}
            // Rollback preserves the pending snapshot/key; no message can commit without its outcome.
            return $message;
        }
        return self::finish($row,'sent','',$message['thread_id'],$message['message_id']);
    }

    /** @param array<string,mixed> $row */
    private static function finish(array $row,string $state,string $error,string $thread='',string $message=''): bool|\WP_Error
    {
        global $wpdb;
        return $wpdb->query($wpdb->prepare('UPDATE `'.MessageSchema::table('automatic_events').'` SET state=%s,body=%s,fan_identity=%s,delivery_key=%s,error=%s,thread_id=%s,message_id=%s,attempts=attempts+1,updated_at=UTC_TIMESTAMP() WHERE event_key=%s',
            $state,'','','',$error,$thread,$message,$row['event_key']))===1?true:MessagePolicy::error('messages_write_failed');
    }
}
