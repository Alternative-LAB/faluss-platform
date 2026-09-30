<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Messaging;

/** Bounded private projections. Local user IDs and idempotency material never leave storage. */
final class MessageReading
{
    public static function inbox(mixed $cursor=''): mixed
    {
        if ($cursor!=='' && !MessagePolicy::uuid($cursor)) { return MessagePolicy::error('invalid_message_cursor',400); }
        return MessageService::run(static function () use($cursor): mixed {
            global $wpdb; $user=get_current_user_id();
            $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM `'.MessageSchema::table('threads').'` WHERE (fan_user=%d OR creator_user=%d) AND thread_id>%s AND DATE_ADD(last_sent_at,INTERVAL 12 MONTH)>UTC_TIMESTAMP() ORDER BY thread_id LIMIT 21',$user,$user,$cursor),'ARRAY_A');
            if (!is_array($rows) || MessageStore::failed()) { return MessagePolicy::error('messages_read_failed'); }
            $more=count($rows)>20; $rows=array_slice($rows,0,20); $items=[];
            foreach ($rows as $row) { $items[]=self::projection($row); }
            return ['items'=>$items,'next_cursor'=>$more?$rows[19]['thread_id']:null,'direct_opening_available'=>false];
        });
    }
    public static function conversation(mixed $id,mixed $after=0): mixed
    {
        if (!MessagePolicy::uuid($id) || !is_int($after) || $after<0) { return MessagePolicy::error('invalid_message_cursor',400); }
        return MessageService::run(static function () use($id,$after): mixed {
            global $wpdb; $thread=MessageService::owned($id);
            if ($thread instanceof \WP_Error || isset($thread['_expired'])) { return $thread; }
            $rows=$wpdb->get_results($wpdb->prepare('SELECT message_id,sequence,sender_id,body,created_at FROM `'.MessageSchema::table('messages').'` WHERE thread_id=%s AND sequence>%d ORDER BY sequence LIMIT 51',$id,$after),'ARRAY_A');
            if (!is_array($rows) || MessageStore::failed()) { return MessagePolicy::error('messages_read_failed'); }
            $blocks=MessageService::blocks((int)$thread['fan_user'],$thread['creator_id']);
            if ($blocks instanceof \WP_Error) { return $blocks; }
            $more=count($rows)>50; $rows=array_slice($rows,0,50); $messages=[];
            foreach($rows as $row) { $messages[]=['message_id'=>$row['message_id'],'sequence'=>(int)$row['sequence'],'mine'=>(int)$row['sender_id']===get_current_user_id(),'body'=>$row['body'],'sent_at'=>$row['created_at']]; }
            $mine=(int)$thread['creator_user']===get_current_user_id()?'creator_block':'fan_block';
            return self::projection($thread)+['messages'=>$messages,'blocked'=>$blocks['fan_block']||$blocks['creator_block'],
                'blocked_by_me'=>$blocks[$mine],'can_send'=>MessageModule::available() && $thread['state']==='open' && !$blocks['fan_block'] && !$blocks['creator_block'] && MessageService::writable($thread),
                'next_after'=>$more?(int)$rows[49]['sequence']:null];
        });
    }
    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function projection(array $row): array
    {
        return ['thread_id'=>$row['thread_id'],'creator_id'=>$row['creator_id'],'as_creator'=>(int)$row['creator_user']===get_current_user_id(),
            'state'=>$row['state'],'revision'=>(int)$row['revision'],'last_sent_at'=>$row['last_sent_at']];
    }
    /** Blocks are account preferences, independently reversible after conversation expiry. */
    public static function blocked(mixed $cursor=''): mixed
    {
        if ($cursor!=='' && !MessagePolicy::uuid($cursor)) { return MessagePolicy::error('invalid_message_cursor',400); }
        return MessageService::run(static function () use($cursor): mixed {
            global $wpdb; $user=get_current_user_id();
            $rows=$wpdb->get_results($wpdb->prepare('SELECT block_id,creator_id,creator_user FROM `'.MessageSchema::table('blocks').'` WHERE ((fan_user=%d AND fan_block=1) OR (creator_user=%d AND creator_block=1)) AND block_id>%s ORDER BY block_id LIMIT 21',$user,$user,$cursor),'ARRAY_A');
            if (!is_array($rows)||MessageStore::failed()) { return MessagePolicy::error('messages_read_failed'); }
            $more=count($rows)>20; $rows=array_slice($rows,0,20); $items=[];
            foreach($rows as $row) { $items[]=['block_id'=>$row['block_id'],'creator_id'=>$row['creator_id'],'as_creator'=>(int)$row['creator_user']===$user]; }
            return ['items'=>$items,'next_cursor'=>$more?$rows[19]['block_id']:null];
        });
    }
    public static function unblock(mixed $id): mixed
    {
        if (!MessagePolicy::uuid($id)) { return MessagePolicy::error('invalid_message_block',400); }
        return MessageService::run(static function () use($id): mixed {
            global $wpdb;
            $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM `'.MessageSchema::table('blocks').'` WHERE block_id=%s FOR UPDATE',$id),'ARRAY_A');
            if (MessageStore::failed()) { return MessagePolicy::error('messages_read_failed'); }
            if (!is_array($row)||!in_array(get_current_user_id(),[(int)$row['fan_user'],(int)$row['creator_user']],true)) { return MessagePolicy::error('block_not_found',404); }
            $field=(int)$row['creator_user']===get_current_user_id()?'creator_block':'fan_block';
            if ($wpdb->query($wpdb->prepare('UPDATE `'.MessageSchema::table('blocks').'` SET '.$field.'=0,updated_at=UTC_TIMESTAMP() WHERE block_id=%s',$id))===false) { return MessagePolicy::error('messages_write_failed'); }
            return ['unblocked'=>true];
        });
    }
}
