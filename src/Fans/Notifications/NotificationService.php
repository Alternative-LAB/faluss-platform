<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Notifications;
use Faluss\Platform\Fans\Sso\FansSsoService;

final class NotificationService
{
    private static function allowed(): bool
    { return NotificationSchema::ready() && FansSsoService::currentLinkedSubject()!==null; }
    public static function error(int $status=503): \WP_Error
    { return new \WP_Error('notifications_unavailable','Notifications indisponibles.',['status'=>$status]); }
    public static function count(): ?int
    {
        if(!self::allowed()){return null;}global $wpdb;
        $n=$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.NotificationSchema::table().'` WHERE recipient=%d AND unread=1',get_current_user_id()));
        return is_numeric($n)&&$wpdb->last_error===''?(int)$n:null;
    }
    /** @return array{items:list<array<string,mixed>>,next_cursor:?string,unread:int}|\WP_Error */
    public static function listing(string $cursor='',string $filter='all'): array|\WP_Error
    {
        if(!self::allowed()){return self::error(403);}
        if(($cursor!==''&&preg_match('/^[1-9][0-9]{0,17}$/D',$cursor)!==1)||!in_array($filter,['all','unread'],true)){return self::error(400);}
        global $wpdb;
        if($wpdb->query('START TRANSACTION')===false){return self::error();}
        try{
            $rows=$wpdb->get_results($wpdb->prepare('SELECT id,kind,object_id,reason,unread,created_at FROM `'.NotificationSchema::table().'` WHERE recipient=%d'
                .($filter==='unread'?' AND unread=1':'').($cursor!==''?' AND id<'.(int)$cursor:'').' ORDER BY id DESC LIMIT 21',get_current_user_id()),'ARRAY_A');
            if(!is_array($rows)||$wpdb->last_error!==''){$wpdb->query('ROLLBACK');return self::error();}
            $count=self::count();if($count===null||$wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return self::error();}
            $more=count($rows)>20;$rows=array_values(array_slice($rows,0,20));
            return ['items'=>$rows,'next_cursor'=>$more?(string)$rows[19]['id']:null,'unread'=>$count];
        }catch(\Throwable){$wpdb->query('ROLLBACK');return self::error();}
    }
    /** @return array<string,mixed>|\WP_Error */
    public static function item(string $id): array|\WP_Error
    {
        if(!self::allowed()){return self::error(403);}
        if(preg_match('/^[1-9][0-9]{0,17}$/D',$id)!==1){return self::error(400);}global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare('SELECT id,kind,object_id,reason,unread,created_at FROM `'.NotificationSchema::table().'` WHERE id=%d AND recipient=%d',(int)$id,get_current_user_id()),'ARRAY_A');
        if($wpdb->last_error!==''){return self::error();}
        return is_array($row)?$row:self::error(404);
    }
    public static function mark(string $id,bool $unread): bool|\WP_Error
    {
        if(!self::allowed()){return self::error(403);}
        if(preg_match('/^[1-9][0-9]{0,17}$/D',$id)!==1){return self::error(400);}global $wpdb;
        $found=$wpdb->get_var($wpdb->prepare('SELECT id FROM `'.NotificationSchema::table().'` WHERE id=%d AND recipient=%d',(int)$id,get_current_user_id()));
        if($wpdb->last_error!==''){return self::error();}if($found===null){return self::error(404);}
        return $wpdb->query($wpdb->prepare('UPDATE `'.NotificationSchema::table().'` SET unread=%d WHERE id=%d AND recipient=%d',$unread?1:0,(int)$id,get_current_user_id()))===false?self::error():true;
    }
}
