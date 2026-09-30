<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Messaging;

/** Additive private InnoDB tables, installed only by explicit activation. */
final class MessageSchema
{
    public const OPTION = 'faluss_fans_messages_schema_version';
    public static function table(string $kind): string
    {
        global $wpdb;
        if (!in_array($kind, ['threads','messages','blocks'], true) || !is_string($wpdb->prefix ?? null)
            || preg_match('/^[A-Za-z0-9_]+$/D', $wpdb->prefix) !== 1) { throw new \RuntimeException('Invalid message table'); }
        return $wpdb->prefix . 'faluss_fans_dm_' . $kind;
    }
    /** @return array<string,string> */
    public static function columns(string $kind): array
    {
        return match ($kind) {
            'threads' => ['thread_id'=>'char(36)','fan_user'=>'bigint(20) unsigned','creator_id'=>'char(36)','creator_user'=>'bigint(20) unsigned',
                'state'=>'varchar(16)','revision'=>'bigint(20) unsigned','last_seq'=>'bigint(20) unsigned','created_at'=>'datetime','last_sent_at'=>'datetime'],
            'messages' => ['message_id'=>'char(36)','thread_id'=>'char(36)','sequence'=>'bigint(20) unsigned','sender_id'=>'bigint(20) unsigned',
                'body'=>'text','key_hash'=>'char(64)','request_hash'=>'char(64)','created_at'=>'datetime'],
            'blocks' => ['block_id'=>'char(36)','fan_user'=>'bigint(20) unsigned','creator_id'=>'char(36)','creator_user'=>'bigint(20) unsigned','fan_block'=>'tinyint(3) unsigned','creator_block'=>'tinyint(3) unsigned','updated_at'=>'datetime'],
            default => throw new \RuntimeException('Invalid message schema'),
        };
    }
    /** @return array<string,array{bool,list<string>}> */
    public static function indexes(string $kind): array
    {
        return match ($kind) {
            'threads'=>['PRIMARY'=>[true,['thread_id']],'pair'=>[true,['fan_user','creator_id']], 'expiry'=>[false,['last_sent_at']]],
            'messages'=>['PRIMARY'=>[true,['message_id']],'position'=>[true,['thread_id','sequence']], 'replay'=>[true,['sender_id','key_hash']], 'rate'=>[false,['sender_id','created_at']]],
            'blocks'=>['PRIMARY'=>[true,['block_id']],'pair'=>[true,['fan_user','creator_id']]],
            default=>[],
        };
    }
    public static function ready(): bool
    { return get_option(self::OPTION) === '1' && self::verify('threads') && self::verify('messages') && self::verify('blocks'); }
    public static function installOrVerify(): bool
    {
        global $wpdb;
        $lock = 'fans_dm_schema_' . substr(hash('sha256', self::table('threads')),0,32);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,10)', $lock)) !== 1) { return false; }
        try {
            foreach (['threads','messages','blocks'] as $kind) {
                $table=self::table($kind); $found=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table));
                if ($found === null) {
                    if ($wpdb->last_error !== '') { return false; }
                    $defs=[];
                    foreach (self::columns($kind) as $name=>$type) { $defs[]='`'.$name.'` '.$type.' NOT NULL'; }
                    foreach (self::indexes($kind) as $name=>[$unique,$columns]) {
                        $defs[]=($name==='PRIMARY'?'PRIMARY KEY':($unique?'UNIQUE KEY ':'KEY ').'`'.$name.'`').' (`'.implode('`,`',$columns).'`)';
                    }
                    if ($wpdb->query('CREATE TABLE `'.$table.'` ('.implode(',',$defs).') ENGINE=InnoDB '.$wpdb->get_charset_collate()) === false) { return false; }
                } elseif ($found !== $table) { return false; }
                if (!self::verify($kind)) { return false; }
            }
            update_option(self::OPTION,'1',false); return self::ready();
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
    private static function verify(string $kind): bool
    {
        global $wpdb;
        $table=self::table($kind); $status=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s',$table),'ARRAY_A');
        if (!is_array($status) || strtolower((string)($status['Engine']??''))!=='innodb') { return false; }
        $rows=$wpdb->get_results('SHOW FULL COLUMNS FROM `'.$table.'`','ARRAY_A'); $columns=[];
        if (!is_array($rows)) { return false; }
        foreach ($rows as $row) {
            if (($row['Null']??'')!=='NO' || ($row['Extra']??'')!=='') { return false; }
            $columns[$row['Field']]=strtolower((string)$row['Type']);
        }
        if ($columns!==self::columns($kind)) { return false; }
        $rows=$wpdb->get_results('SHOW INDEX FROM `'.$table.'`','ARRAY_A'); $indexes=[];
        if (!is_array($rows)) { return false; }
        foreach ($rows as $row) { $indexes[$row['Key_name']][0]=(int)$row['Non_unique']===0; $indexes[$row['Key_name']][1][(int)$row['Seq_in_index']-1]=$row['Column_name']; }
        ksort($indexes); $expected=self::indexes($kind); ksort($expected);
        return $indexes===$expected;
    }
}
