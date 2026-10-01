<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Moderation;

/** Local operator journal. Intent is durable before nontransactional maintenance starts. */
final class AdminActionLog
{
    public static function table(): ?string
    {
        global $wpdb;
        return preg_match('/^[a-zA-Z0-9_]+$/D', $wpdb->prefix) === 1 ? $wpdb->prefix.'faluss_fans_admin_actions' : null;
    }
    public static function prepare(): bool
    {
        if (!BackOffice::allowed() || self::table() === null) { return false; }
        global $wpdb;
        if ($wpdb->query('CREATE TABLE IF NOT EXISTS `'.self::table().'` (event_id char(36) NOT NULL,actor_id bigint unsigned NOT NULL,target_id bigint unsigned NOT NULL,action varchar(32) NOT NULL,outcome varchar(16) NOT NULL,created_at datetime NOT NULL,PRIMARY KEY(event_id)) ENGINE=InnoDB '.$wpdb->get_charset_collate()) === false) { return false; }
        $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s',self::table()),'ARRAY_A');
        $columns = $wpdb->get_results('SHOW FULL COLUMNS FROM `'.self::table().'`','ARRAY_A');
        if (!is_array($status) || strtolower($status['Engine'] ?? '') !== 'innodb' || !is_array($columns)) { return false; }
        $expected = ['event_id'=>'char(36)','actor_id'=>'bigint(20) unsigned','target_id'=>'bigint(20) unsigned','action'=>'varchar(32)','outcome'=>'varchar(16)','created_at'=>'datetime'];
        $actual=[];foreach($columns as $column){if($column['Null']!=='NO'||$column['Extra']!==''){return false;}$actual[$column['Field']]=strtolower($column['Type']);}
        $indexes=$wpdb->get_results('SHOW INDEX FROM `'.self::table().'`','ARRAY_A');
        return $actual===$expected && is_array($indexes) && count($indexes)===1 && $indexes[0]['Key_name']==='PRIMARY' && $indexes[0]['Column_name']==='event_id' && (int)$indexes[0]['Non_unique']===0;
    }
    public static function begin(string $action,int $target=0): ?string
    {
        if (!BackOffice::allowed() || !in_array($action,['messages_prepare','messages_purge','images_cleanup','catalog_create','staff_grant','staff_revoke'],true) || self::table()===null) {return null;}
        global $wpdb;$id=wp_generate_uuid4();
        return $wpdb->query($wpdb->prepare('INSERT INTO `'.self::table().'` (event_id,actor_id,target_id,action,outcome,created_at) VALUES (%s,%d,%d,%s,%s,UTC_TIMESTAMP())',$id,get_current_user_id(),$target,$action,'started'))===1?$id:null;
    }
    public static function finish(string $id,bool $success): bool
    {
        if (!BackOffice::allowed() || self::table()===null) {return false;}
        global $wpdb;
        return $wpdb->query($wpdb->prepare('UPDATE `'.self::table().'` SET outcome=%s WHERE event_id=%s AND actor_id=%d AND outcome=%s',$success?'completed':'failed',$id,get_current_user_id(),'started'))===1;
    }
    /** @return list<array<string,mixed>>|null */
    public static function recent(): ?array
    {
        if(!BackOffice::allowed() || self::table()===null){return null;}
        global $wpdb;
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',self::table()))!==self::table()){return null;}
        $rows=$wpdb->get_results('SELECT actor_id,target_id,action,outcome,created_at FROM `'.self::table().'` ORDER BY created_at DESC,event_id DESC LIMIT 50','ARRAY_A');
        return is_array($rows)&&$wpdb->last_error===''?array_values($rows):null;
    }
}
