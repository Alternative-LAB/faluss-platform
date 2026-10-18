<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Messaging;

use Faluss\Platform\Fans\Sso\FansLinkedAccounts;

/** Durable DB-only dispatcher into the ordinary conversation, notification and report services. */
final class MessageAutomaticDelivery
{
    /** Erasure/admission closure never depend on PF proof availability or its optional source factory. */
    public static function retirePending(): mixed
    {
        if(!MessageAutomationSchema::ready()){return MessagePolicy::error('message_automations_unavailable');}
        $closed=!MessageModule::available();
        return MessageStore::transaction(static function () use ($closed): mixed {
            global $wpdb;
            $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM `'.MessageSchema::table('automatic_events').'` WHERE state IN (%s,%s) AND (DATE_ADD(created_at,INTERVAL 12 MONTH)<=UTC_TIMESTAMP() OR %d=1) ORDER BY created_at,event_key LIMIT 20 FOR UPDATE','pending','retry',(int)$closed),'ARRAY_A');
            if(!is_array($rows)||MessageStore::failed()){return MessagePolicy::error('messages_read_failed');}
            foreach($rows as $row){$result=self::finish($row,$closed?'cancelled':'expired',$closed?'admission_closed':'expired');if($result instanceof \WP_Error){return $result;}}
            return ['processed'=>count($rows)];
        });
    }
    /** Bounded job; a lost COMMIT reply leaves the same event/key for a primary reread. */
    public static function run(int $limit=20): mixed
    {
        if($limit<1||$limit>20){return MessagePolicy::error('invalid_message_automation',400);}
        if(!MessageAutomationSchema::ready()){return MessagePolicy::error('message_automations_unavailable');}
        $done=0;
        for($i=0;$i<$limit;$i++){
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
        $row=$wpdb->get_row('SELECT *,DATE_ADD(created_at,INTERVAL 12 MONTH)<=UTC_TIMESTAMP() AS expired FROM `'.$table.'` WHERE kind=\'welcome\' AND state IN (\'pending\',\'retry\') AND next_attempt_at<=UTC_TIMESTAMP() ORDER BY next_attempt_at,event_key LIMIT 1 FOR UPDATE','ARRAY_A');
        if(MessageStore::failed()){return MessagePolicy::error('messages_read_failed');}
        if($row===null){return false;}
        return self::deliverRow($row);
    }

    /** Called only within the guarded PF source composition, after exact receipt/corpus comparison. */
    public static function purchasedInTransaction(string $event,bool $positive,?string $creator,string $identity): mixed
    {
        global $wpdb;
        \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment::assertIsolated($wpdb,'fans');
        if(!MessageStore::ownsTransaction()||!MessageAutomationSchema::ready()||preg_match('/^[0-9a-f]{64}$/D',$event)!==1){return MessagePolicy::error('message_primary_required');}
        $row=$wpdb->get_row($wpdb->prepare('SELECT *,DATE_ADD(created_at,INTERVAL 12 MONTH)<=UTC_TIMESTAMP() AS expired FROM `'.MessageSchema::table('automatic_events').'` WHERE event_key=%s AND kind=%s AND state IN (%s,%s) AND next_attempt_at<=UTC_TIMESTAMP() FOR UPDATE',$event,'thanks','pending','retry'),'ARRAY_A');
        if(MessageStore::failed()){return MessagePolicy::error('messages_read_failed');}
        if($row===null){return false;}
        if($row['fan_identity']!==$identity||($creator!==null&&$row['creator_id']!==$creator)){return MessagePolicy::error('message_automations_unavailable');}
        if($creator===null){return self::finish($row,'cancelled','recipient_unavailable');}
        return $positive?self::deliverRow($row):self::finish($row,'cancelled','source_not_positive');
    }

    /** Source failures change no message or economic fact; the same key receives bounded retry backoff. */
    public static function purchasedSourceFailed(string $event): mixed
    {
        global $wpdb;
        \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment::assertIsolated($wpdb,'fans');
        return MessageStore::transaction(static function () use ($event): mixed {
            global $wpdb;
            $row=$wpdb->get_row($wpdb->prepare('SELECT *,DATE_ADD(created_at,INTERVAL 12 MONTH)<=UTC_TIMESTAMP() AS expired FROM `'.MessageSchema::table('automatic_events').'` WHERE event_key=%s AND kind=%s AND state IN (%s,%s) FOR UPDATE',$event,'thanks','pending','retry'),'ARRAY_A');
            if(MessageStore::failed()){return MessagePolicy::error('messages_read_failed');}
            if($row===null){return false;}
            if((bool)$row['expired']){return self::finish($row,'expired','expired');}
            $seconds=min(3600,60*(2**min(6,(int)$row['attempts'])));
            return $wpdb->query($wpdb->prepare('UPDATE `'.MessageSchema::table('automatic_events').'` SET state=%s,attempts=attempts+1,next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL %d SECOND),error=%s,updated_at=UTC_TIMESTAMP() WHERE event_key=%s',
                'retry',$seconds,'source_unavailable',$event))===1?true:MessagePolicy::error('messages_write_failed');
        });
    }

    /** @param array<string,mixed> $row */
    private static function deliverRow(array $row): mixed
    {
        global $wpdb;$table=MessageSchema::table('automatic_events');
        if((bool)$row['expired']){return self::finish($row,'expired','expired');}
        if(!MessageModule::available()){return self::finish($row,'cancelled','admission_closed');}
        $config=MessageAutomaticSettings::inTransaction($row['creator_id'],$row['kind']);
        if($config instanceof \WP_Error){return $config;}
        if(!$config['enabled']||$config['epoch']!==(int)$row['config_epoch']){return self::finish($row,'cancelled','disabled');}
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
