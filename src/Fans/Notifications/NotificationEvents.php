<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Notifications;

/** Narrow transaction participant: no commit, DDL, external I/O or private content. */
final class NotificationEvents
{
    /** Same ordinary-conversation transaction; never touches isolated report evidence. */
    public static function forgetConversation(string $id): bool
    { return self::forget($id,['message_request','message_received','message_accepted','message_refused']); }
    public static function forgetReport(string $id): bool
    { return self::forget($id,['report_decision','report_final']); }
    /** @param list<string> $kinds */
    private static function forget(string $id,array $kinds): bool
    {
        global $wpdb;$table=NotificationSchema::table();if($table===null){return false;}
        $found=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table));
        if($wpdb->last_error!==''){return false;}if($found===null){return true;}
        $storage=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s',$table),'ARRAY_A');
        if(!is_array($storage)||strtolower($storage['Engine']??'')!=='innodb'){return false;}
        return $wpdb->query($wpdb->prepare('DELETE FROM `'.$table.'` WHERE object_id=%s AND kind IN ('.implode(',',array_fill(0,count($kinds),'%s')).')',$id,...$kinds))!==false;
    }
    public const KINDS = ['profile_active','profile_suspended','editorial_approved','editorial_rejected','editorial_revoked','publication_approved','publication_rejected','image_approved','image_rejected','message_request','message_received','message_accepted','message_refused','report_decision','report_final'];
    public static function record(int $recipient,string $kind,string $object,int $revision,string $reason=''): bool
    {
        if(!NotificationSchema::enabled()){return true;}
        if($recipient<1||$revision<1||!in_array($kind,self::KINDS,true)||preg_match('/^[0-9a-f-]{36}$/D',$object)!==1
            ||!in_array($reason,['','allowed_editorial','allowed_text','allowed_image','needs_revision','prohibited_content'],true)||!NotificationSchema::ready()){return false;}
        global $wpdb;
        $key=hash('sha256',$recipient.'|'.$kind.'|'.$object.'|'.$revision);
        $result=$wpdb->query($wpdb->prepare('INSERT INTO `'.NotificationSchema::table().'` (event_key,recipient,kind,object_id,reason,unread,created_at) VALUES(%s,%d,%s,%s,%s,1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE event_key=VALUES(event_key)', $key,$recipient,$kind,$object,$reason));
        return $result!==false;
    }
}
