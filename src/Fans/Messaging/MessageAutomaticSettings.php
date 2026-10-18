<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Messaging;

use Faluss\Platform\Fans\Profiles\CreatorProfileService;

/** Current creator-owned texts. Queued events keep their selected revision and snapshot. */
final class MessageAutomaticSettings
{
    public const KINDS=['welcome','thanks'];

    /**
     * Caller owns the messaging transaction and mutex.
     * @return array{enabled:bool,revision:int,epoch:int,body:string}|\WP_Error
     */
    public static function inTransaction(string $creator,string $kind): array|\WP_Error
    {
        if(!MessagePolicy::uuid($creator)||!in_array($kind,self::KINDS,true)||!MessageAutomationSchema::ready()) {
            return MessagePolicy::error('message_automations_unavailable');
        }
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare('SELECT enabled,revision,epoch,body FROM `'.MessageSchema::table('automatic_settings').'` WHERE creator_id=%s AND kind=%s FOR UPDATE',$creator,$kind),'ARRAY_A');
        if(MessageStore::failed()){return MessagePolicy::error('messages_read_failed');}
        if($row===null){return ['enabled'=>false,'revision'=>0,'epoch'=>0,'body'=>''];}
        if(!in_array((string)($row['enabled']??''),['0','1'],true)||!is_numeric($row['revision']??null)||(int)$row['revision']<1
            ||!is_numeric($row['epoch']??null)||(int)$row['epoch']<0||!is_string($row['body']??null)
            ||($row['body']!==''&&!MessagePolicy::text($row['body']))||((int)$row['enabled']===1&&$row['body']==='')) {
            return MessagePolicy::error('message_automations_unavailable');
        }
        return ['enabled'=>(bool)$row['enabled'],'revision'=>(int)$row['revision'],'epoch'=>(int)$row['epoch'],'body'=>$row['body']];
    }

    public static function own(): mixed
    {
        return MessageService::run(static function():mixed {
            $creator=CreatorProfileService::activeIdForOwnerInTransaction(get_current_user_id());
            if($creator===null){return MessagePolicy::error('message_automations_forbidden',403);}
            $result=[];
            foreach(self::KINDS as $kind){$row=self::inTransaction($creator,$kind);if($row instanceof \WP_Error){return $row;}$result[$kind]=$row;}
            return $result+['welcome_attachment_available'=>false,'thanks_attachment_available'=>false,'thanks_delivery_available'=>self::thanksDeliveryAvailable()];
        });
    }

    public static function save(mixed $kind,mixed $body,mixed $enabled,mixed $revision): mixed
    {
        if(!in_array($kind,self::KINDS,true)||!is_bool($enabled)||!is_string($body)||($body!==''&&!MessagePolicy::text($body))
            ||($enabled&&$body==='')||!is_int($revision)||$revision<0||$revision>999999999) {
            return MessagePolicy::error('invalid_message_automation',400);
        }
        return MessageService::run(static function()use($kind,$body,$enabled,$revision):mixed {
            $creator=CreatorProfileService::activeIdForOwnerInTransaction(get_current_user_id());
            if($creator===null){return MessagePolicy::error('message_automations_forbidden',403);}
            if($kind==='thanks'&&$enabled&&!self::thanksDeliveryAvailable()){return MessagePolicy::error('message_automatic_source_unavailable');}
            $old=self::inTransaction($creator,$kind);if($old instanceof \WP_Error){return $old;}
            if($old['revision']!==$revision){return MessagePolicy::error('message_automation_conflict',409);}
            $epoch=$old['epoch']+($enabled?0:1);
            global $wpdb;
            $sql=$revision===0
                ?$wpdb->prepare('INSERT INTO `'.MessageSchema::table('automatic_settings').'` (enabled,revision,epoch,body,updated_at,creator_id,kind) VALUES(%d,1,%d,%s,UTC_TIMESTAMP(),%s,%s)',(int)$enabled,$epoch,$body,$creator,$kind)
                :$wpdb->prepare('UPDATE `'.MessageSchema::table('automatic_settings').'` SET enabled=%d,revision=revision+1,epoch=%d,body=%s,updated_at=UTC_TIMESTAMP() WHERE creator_id=%s AND kind=%s AND revision=%d',(int)$enabled,$epoch,$body,$creator,$kind,$revision);
            if($wpdb->query($sql)!==1){return MessagePolicy::error('messages_write_failed');}
            if(!$enabled && $wpdb->query($wpdb->prepare('UPDATE `'.MessageSchema::table('automatic_events').'` SET state=%s,body=%s,fan_identity=%s,delivery_key=%s,error=%s,updated_at=UTC_TIMESTAMP() WHERE creator_id=%s AND kind=%s AND state IN (%s,%s)',
                'cancelled','','','','disabled',$creator,$kind,'pending','retry'))===false){return MessagePolicy::error('messages_write_failed');}
            return ['enabled'=>$enabled,'revision'=>$revision+1,'epoch'=>$epoch,'body'=>$body];
        });
    }

    public static function preview(mixed $kind,mixed $body): mixed
    {
        if(!in_array($kind,self::KINDS,true)||!MessagePolicy::text($body)){return MessagePolicy::error('invalid_message_automation',400);}
        return MessageService::run(static function()use($kind,$body):mixed {
            return CreatorProfileService::activeIdForOwnerInTransaction(get_current_user_id())===null
                ?MessagePolicy::error('message_automations_forbidden',403):['preview'=>true,'kind'=>$kind,'body'=>$body];
        });
    }

    /** A typed closed source, not a flag, preview or client field, makes delivery configurable. */
    public static function thanksDeliveryAvailable(): bool
    {return \Faluss\Platform\Fans\PfContract\ClosedPurchasedThanksDelivery::configured()!==null;}
}
