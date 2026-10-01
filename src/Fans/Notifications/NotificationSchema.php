<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Notifications;

/** Additive private metadata only. Domain writers never perform DDL. */
final class NotificationSchema
{
    public const OPTION = 'faluss_fans_notifications_schema';
    public static function enabled(): bool
    { return defined('FALUSS_PLATFORM_ROLE') && FALUSS_PLATFORM_ROLE === 'fans' && defined('FALUSS_PLATFORM_FANS_SSO') && FALUSS_PLATFORM_FANS_SSO === true; }
    public static function table(): ?string
    { global $wpdb; return is_string($wpdb->prefix ?? null) && preg_match('/^[a-zA-Z0-9_]+$/D',$wpdb->prefix) === 1 ? $wpdb->prefix.'faluss_fans_notifications' : null; }
    public static function upgrade(): void
    {
        if (self::enabled() && \Faluss\Platform\Fans\Sso\FansSsoSchema::ready() && get_option(self::OPTION) !== '1') { self::installOrVerify(); }
    }
    /** @return array<string,string> */
    private static function columns(): array
    { return ['id'=>'bigint(20) unsigned','event_key'=>'char(64)','recipient'=>'bigint(20) unsigned','kind'=>'varchar(32)','object_id'=>'char(36)','reason'=>'varchar(32)','unread'=>'tinyint(1)','created_at'=>'datetime']; }
    public static function installOrVerify(): bool
    {
        if (!self::enabled() || self::table() === null) { return false; }
        global $wpdb;
        if ($wpdb->query('CREATE TABLE IF NOT EXISTS `'.self::table().'` (id bigint unsigned NOT NULL AUTO_INCREMENT,event_key char(64) NOT NULL,recipient bigint unsigned NOT NULL,kind varchar(32) NOT NULL,object_id char(36) NOT NULL,reason varchar(32) NOT NULL,unread tinyint(1) NOT NULL,created_at datetime NOT NULL,PRIMARY KEY(id),UNIQUE KEY event_key(event_key),KEY recipient_id(recipient,id),KEY expiry(created_at)) ENGINE=InnoDB '.$wpdb->get_charset_collate()) === false || !self::verify()) { return false; }
        update_option(self::OPTION,'1',false); return self::ready();
    }
    public static function ready(): bool
    { return self::enabled() && get_option(self::OPTION) === '1' && self::verify(); }
    private static function verify(): bool
    {
        if (self::table() === null) { return false; } global $wpdb;
        $status=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s',self::table()),'ARRAY_A');
        if (!is_array($status) || strtolower($status['Engine']??'') !== 'innodb') { return false; }
        $rows=$wpdb->get_results('SHOW FULL COLUMNS FROM `'.self::table().'`','ARRAY_A'); if (!is_array($rows)) { return false; }
        $actual=[]; foreach($rows as $row){ if($row['Null']!=='NO'||$row['Extra']!==($row['Field']==='id'?'auto_increment':'')){return false;} $actual[$row['Field']]=strtolower($row['Type']); }
        if($actual!==self::columns()){return false;}
        $rows=$wpdb->get_results('SHOW INDEX FROM `'.self::table().'`','ARRAY_A'); if(!is_array($rows)){return false;}
        $indexes=[];foreach($rows as $row){$indexes[$row['Key_name']]['unique']=(int)$row['Non_unique']===0;$indexes[$row['Key_name']]['columns'][(int)$row['Seq_in_index']]=$row['Column_name'];}
        $expected=['PRIMARY'=>['unique'=>true,'columns'=>[1=>'id']],'event_key'=>['unique'=>true,'columns'=>[1=>'event_key']],'recipient_id'=>['unique'=>false,'columns'=>[1=>'recipient',2=>'id']],'expiry'=>['unique'=>false,'columns'=>[1=>'created_at']]];
        foreach($indexes as &$index){ksort($index['columns']);}unset($index);ksort($indexes);ksort($expected);return $indexes===$expected;
    }
}
