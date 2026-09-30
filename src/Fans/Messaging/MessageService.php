<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Messaging;

use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\Fans\Sso\FansSsoService;

/** Local membership, request admission and conversation transitions; no economic authority. */
final class MessageService
{
    public static function run(callable $operation): mixed
    {
        if (!MessageModule::privateAccessAvailable()) { return MessagePolicy::error('messages_unavailable'); }
        if (FansSsoService::currentLinkedSubject()===null) { return MessagePolicy::error('messages_forbidden',403); }
        $result=MessageStore::transaction($operation);
        return is_array($result) && ($result['_expired']??false)===true ? MessagePolicy::error('conversation_expired',410) : $result;
    }
    public static function request(mixed $creatorId, mixed $body, mixed $key): mixed
    {
        if (!MessageModule::available()) {return MessagePolicy::error('message_admission_closed');}
        if (!MessagePolicy::uuid($creatorId) || !MessagePolicy::text($body,1000) || !MessagePolicy::uuid($key)) { return MessagePolicy::error('invalid_message_request',400); }
        return self::run(static function () use ($creatorId,$body,$key): mixed {
            global $wpdb;
            $fan=get_current_user_id(); $owner=CreatorProfileService::activeOwner($creatorId,true);
            if ($owner===null || $owner===$fan) { return MessagePolicy::error('recipient_unavailable',403); }
            $blocked=self::blocks($fan,$creatorId);
            if ($blocked instanceof \WP_Error) { return $blocked; }
            if ($blocked['fan_block'] || $blocked['creator_block']) { return MessagePolicy::error('conversation_blocked',403); }
            $hash=hash('sha256','request' . "\0" . $creatorId . "\0" . $body);
            $replay=self::replay($fan,$key,$hash);
            if ($replay!==null) { return $replay; }
            $threads=MessageSchema::table('threads');
            $old=$wpdb->get_row($wpdb->prepare('SELECT thread_id,DATE_ADD(last_sent_at,INTERVAL 12 MONTH)<=UTC_TIMESTAMP() AS expired FROM `'.$threads.'` WHERE fan_user=%d AND creator_id=%s',$fan,$creatorId),'ARRAY_A');
            if (MessageStore::failed()) { return MessagePolicy::error('messages_read_failed'); }
            if (is_array($old)) {
                if (!(bool)$old['expired']) { return MessagePolicy::error('conversation_exists',409); }
                if (!MessageStore::eraseThread($old['thread_id'])) { return MessagePolicy::error('messages_purge_failed'); }
            }
            foreach ([
                [$wpdb->prepare('SELECT COUNT(*) FROM `'.$threads.'` WHERE fan_user=%d AND created_at>UTC_TIMESTAMP()-INTERVAL 1 HOUR',$fan),2],
                [$wpdb->prepare('SELECT COUNT(*) FROM `'.$threads.'` WHERE fan_user=%d AND created_at>UTC_TIMESTAMP()-INTERVAL 24 HOUR',$fan),10],
                [$wpdb->prepare('SELECT COUNT(*) FROM `'.$threads.'` WHERE fan_user=%d AND state=%s AND DATE_ADD(last_sent_at,INTERVAL 12 MONTH)>UTC_TIMESTAMP()',$fan,'pending'),5],
                [$wpdb->prepare('SELECT COUNT(*) FROM `'.$threads.'` WHERE creator_id=%s AND state=%s AND DATE_ADD(last_sent_at,INTERVAL 12 MONTH)>UTC_TIMESTAMP()',$creatorId,'pending'),100],
            ] as [$query,$limit]) {
                $count=$wpdb->get_var($query);
                if (MessageStore::failed() || !is_numeric($count)) { return MessagePolicy::error('messages_read_failed'); }
                if ((int)$count >= $limit) { return MessagePolicy::error('message_request_limit',429); }
            }
            $quota=self::sendQuota($fan); if ($quota!==null) { return $quota; }
            $id=wp_generate_uuid4();
            // Direct opening stays closed until an authoritative subscription contract exists.
            if ($wpdb->query($wpdb->prepare('INSERT INTO `'.$threads.'` (thread_id,fan_user,creator_id,creator_user,state,revision,last_seq,created_at,last_sent_at) VALUES(%s,%d,%s,%d,%s,1,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())',
                $id,$fan,$creatorId,$owner,'pending'))!==1) { return MessagePolicy::error('messages_write_failed'); }
            $message=self::insert($id,1,$fan,$body,$key,$hash);
            return $message instanceof \WP_Error ? $message : ['thread_id'=>$id,'message_id'=>$message,'state'=>'pending','direct_opening_available'=>false];
        });
    }
    public static function send(mixed $threadId, mixed $body, mixed $key): mixed
    {
        if (!MessageModule::available()) {return MessagePolicy::error('message_admission_closed');}
        if (!MessagePolicy::uuid($threadId) || !MessagePolicy::text($body) || !MessagePolicy::uuid($key)) { return MessagePolicy::error('invalid_message',400); }
        return self::run(static function () use ($threadId,$body,$key): mixed {
            global $wpdb;
            $thread=self::owned($threadId); if ($thread instanceof \WP_Error || isset($thread['_expired'])) { return $thread; }
            if ($thread['state']!=='open' || !self::writable($thread)) { return MessagePolicy::error('conversation_not_open',403); }
            $blocks=self::blocks((int)$thread['fan_user'],$thread['creator_id']);
            if ($blocks instanceof \WP_Error) { return $blocks; }
            if ($blocks['fan_block'] || $blocks['creator_block']) { return MessagePolicy::error('conversation_blocked',403); }
            $sender=get_current_user_id(); $hash=hash('sha256','send'."\0".$threadId."\0".$body);
            $replay=self::replay($sender,$key,$hash); if ($replay!==null) { return $replay; }
            $quota=self::sendQuota($sender); if ($quota!==null) { return $quota; }
            $seq=(int)$thread['last_seq']+1;
            $message=self::insert($threadId,$seq,$sender,$body,$key,$hash);
            if ($message instanceof \WP_Error) { return $message; }
            if ($wpdb->query($wpdb->prepare('UPDATE `'.MessageSchema::table('threads').'` SET last_seq=%d,revision=revision+1,last_sent_at=UTC_TIMESTAMP() WHERE thread_id=%s',$seq,$threadId))!==1) { return MessagePolicy::error('messages_write_failed'); }
            return ['thread_id'=>$threadId,'message_id'=>$message,'state'=>'open'];
        });
    }
    public static function decide(mixed $threadId, mixed $revision, mixed $action): mixed
    {
        if (!MessagePolicy::uuid($threadId) || !is_int($revision) || $revision<1 || !in_array($action,['accept','refuse','block','unblock'],true)) { return MessagePolicy::error('invalid_message_decision',400); }
        return self::run(static function () use ($threadId,$revision,$action): mixed {
            global $wpdb;
            $thread=self::owned($threadId); if ($thread instanceof \WP_Error || isset($thread['_expired'])) { return $thread; }
            if ((int)$thread['revision']!==$revision) { return MessagePolicy::error('conversation_conflict',409); }
            $creator=(int)$thread['creator_user']===get_current_user_id();
            if (in_array($action,['block','unblock'],true)) {
                $field=$creator?'creator_block':'fan_block'; $value=$action==='block'?1:0;
                $sql='INSERT INTO `'.MessageSchema::table('blocks').'` (block_id,fan_user,creator_id,creator_user,fan_block,creator_block,updated_at) VALUES(%s,%d,%s,%d,%d,%d,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE '.$field.'=%d,updated_at=UTC_TIMESTAMP()';
                if ($wpdb->query($wpdb->prepare($sql,wp_generate_uuid4(),(int)$thread['fan_user'],$thread['creator_id'],(int)$thread['creator_user'],$creator?0:$value,$creator?$value:0,$value))===false) { return MessagePolicy::error('messages_write_failed'); }
            } else {
                if (!$creator || $thread['state']!=='pending' || !self::writable($thread)) { return MessagePolicy::error('message_decision_forbidden',403); }
                $blocks=self::blocks((int)$thread['fan_user'],$thread['creator_id']);
                if ($blocks instanceof \WP_Error) { return $blocks; }
                if ($action==='accept' && ($blocks['fan_block'] || $blocks['creator_block'])) { return MessagePolicy::error('conversation_blocked',403); }
                $thread['state']=$action==='accept'?'open':'refused';
            }
            if ($wpdb->query($wpdb->prepare('UPDATE `'.MessageSchema::table('threads').'` SET state=%s,revision=revision+1 WHERE thread_id=%s',$thread['state'],$threadId))!==1) { return MessagePolicy::error('messages_write_failed'); }
            return ['thread_id'=>$threadId,'state'=>$thread['state'],'revision'=>$revision+1];
        });
    }
    /**
     * Internal membership contract for report extraction, inside the message transaction.
     * @return array<string,mixed>|\WP_Error
     */
    public static function owned(string $id): array|\WP_Error
    {
        $row=MessageStore::thread($id);
        if (MessageStore::failed()) { return MessagePolicy::error('messages_read_failed'); }
        if ($row===null || !in_array(get_current_user_id(),[(int)$row['fan_user'],(int)$row['creator_user']],true)) { return MessagePolicy::error('conversation_not_found',404); }
        if ((bool)$row['expired']) { return MessageStore::eraseThread($id)?['_expired'=>true]:MessagePolicy::error('messages_purge_failed'); }
        return $row;
    }
    /** @param array<string,mixed> $thread */
    public static function writable(array $thread): bool
    {
        return FansSsoService::linkedMember((int)$thread['fan_user']) && CreatorProfileService::activeOwner($thread['creator_id'],true)===(int)$thread['creator_user'];
    }
    /** @return array{fan_block:bool,creator_block:bool}|\WP_Error */
    public static function blocks(int $fan, string $creator): array|\WP_Error
    {
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare('SELECT fan_block,creator_block FROM `'.MessageSchema::table('blocks').'` WHERE fan_user=%d AND creator_id=%s',$fan,$creator),'ARRAY_A');
        return MessageStore::failed()?MessagePolicy::error('messages_read_failed'):['fan_block'=>(bool)($row['fan_block']??false),'creator_block'=>(bool)($row['creator_block']??false)];
    }
    private static function replay(int $sender,string $key,string $hash): mixed
    {
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare('SELECT message_id,thread_id,request_hash FROM `'.MessageSchema::table('messages').'` WHERE sender_id=%d AND key_hash=%s',$sender,hash('sha256',$key)),'ARRAY_A');
        if (MessageStore::failed()) { return MessagePolicy::error('messages_read_failed'); }
        if (!is_array($row)) { return null; }
        if (!hash_equals($row['request_hash'],$hash)) { return MessagePolicy::error('message_key_conflict',409); }
        $thread=self::owned($row['thread_id']); if ($thread instanceof \WP_Error || isset($thread['_expired'])) { return $thread; }
        return ['thread_id'=>$row['thread_id'],'message_id'=>$row['message_id'],'state'=>$thread['state'],'reused'=>true];
    }
    private static function insert(string $thread,int $seq,int $sender,string $body,string $key,string $hash): string|\WP_Error
    {
        global $wpdb; $id=wp_generate_uuid4();
        return $wpdb->query($wpdb->prepare('INSERT INTO `'.MessageSchema::table('messages').'` (message_id,thread_id,sequence,sender_id,body,key_hash,request_hash,created_at) VALUES(%s,%s,%d,%d,%s,%s,%s,UTC_TIMESTAMP())',
            $id,$thread,$seq,$sender,$body,hash('sha256',$key),$hash))===1?$id:MessagePolicy::error('messages_write_failed');
    }
    private static function sendQuota(int $sender): ?\WP_Error
    {
        global $wpdb;
        $counts=$wpdb->get_row($wpdb->prepare('SELECT COUNT(*) AS daily,COALESCE(SUM(created_at>UTC_TIMESTAMP()-INTERVAL 1 HOUR),0) AS hourly FROM `'.MessageSchema::table('messages').'` WHERE sender_id=%d AND created_at>UTC_TIMESTAMP()-INTERVAL 24 HOUR',$sender),'ARRAY_A');
        if (!is_array($counts) || MessageStore::failed()) { return MessagePolicy::error('messages_read_failed'); }
        return (int)$counts['daily']>=200 || (int)$counts['hourly']>=30?MessagePolicy::error('message_send_limit',429):null;
    }
}
