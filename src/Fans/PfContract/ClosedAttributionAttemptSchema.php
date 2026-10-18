<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\PfContract;

use Faluss\Platform\TokenEngine\PurchasedPf\ClosedProtocolSchema;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;

/** Member-owned recovery checkpoint, never a balance, ledger or automatic migration. */
final class ClosedAttributionAttemptSchema
{
    public const SCOPE = 'closed_fans_attribution_attempt';

    /** @return array<string,string> */
    public static function tables(\wpdb $db): array
    {
        if (preg_match('/^[A-Za-z0-9_]{1,20}$/D',$db->prefix)!==1) { throw new ModelViolation('invalid_table_prefix'); }
        return ['schema'=>$db->prefix.'fans_pf_attempt_schema','current'=>$db->prefix.'fans_pf_attempt_current'];
    }

    /** @return array<string,array{columns:array<string,string>,indexes:array<string,array{bool,list<string>}>}> */
    public static function definitions(): array
    {
        return [
            'schema'=>['columns'=>['id'=>'bigint unsigned','version'=>'varchar(16)','scope'=>'varchar(64)'],
                'indexes'=>['PRIMARY'=>[true,['id']]]],
            'current'=>['columns'=>['member_faluss_id'=>'char(36)','attribution_id'=>'char(36)','creator_profile_id'=>'char(36)',
                'operation'=>'varchar(16)','phase'=>'varchar(16)','updated_at'=>'datetime(6)'],
                'indexes'=>['PRIMARY'=>[true,['member_faluss_id']],'attribution'=>[true,['attribution_id']]]],
        ];
    }

    public static function ready(\wpdb $db): bool
    {
        ClosedEnvironment::assertIsolated($db,'fans');$tables=self::tables($db);
        foreach (self::definitions() as $kind=>$definition) {
            if (self::scalar($db,$db->prepare('SHOW TABLES LIKE %s',$db->esc_like($tables[$kind])))!==$tables[$kind]
                || !ClosedProtocolSchema::verifyMetadata($db,$tables[$kind],$definition)) { return false; }
        }
        $rows=$db->get_results($db->prepare('SELECT id,version,scope FROM %i',$tables['schema']),'ARRAY_A');
        return $db->last_error==='' && $rows===[['id'=>'1','version'=>'1','scope'=>self::SCOPE]];
    }

    public static function installForRecipe(\wpdb $db): void
    {
        ClosedEnvironment::assertIsolated($db,'fans');
        if ((string)self::scalar($db,'SELECT @@in_transaction')!=='0') { throw new ModelViolation('nested_transaction_refused'); }
        $tables=self::tables($db);$temporary=[];$lock='fans_pf_attempt_schema_'.substr(hash('sha256',$db->prefix),0,24);
        if ((string)self::scalar($db,$db->prepare('SELECT GET_LOCK(%s,10)',$lock))!=='1') { throw new ModelViolation('pf_local_attempt_busy'); }
        try {
            $found=[];
            foreach ($tables as $table) {
                if (self::scalar($db,$db->prepare('SHOW TABLES LIKE %s',$db->esc_like($table)))===$table) { $found[]=$table; }
            }
            if ($found!==[]) {
                if (count($found)!==count($tables) || !self::ready($db)) { throw new ModelViolation('model_schema_divergent'); }
                return;
            }
            $suffix='_new_'.bin2hex(random_bytes(4));
            foreach (self::definitions() as $kind=>$definition) {
                $table=$tables[$kind].$suffix;
                if ($db->query(ClosedProtocolSchema::metadataSql($table,$definition))===false) { throw new ModelViolation('pf_local_attempt_storage'); }
                $temporary[]=$table;
                if (!ClosedProtocolSchema::verifyMetadata($db,$table,$definition)) { throw new ModelViolation('model_schema_divergent'); }
            }
            if ($db->insert($tables['schema'].$suffix,['id'=>'1','version'=>'1','scope'=>self::SCOPE])!==1) { throw new ModelViolation('pf_local_attempt_storage'); }
            $renames=array_map(static fn(string $table):string=>$db->prepare('%i TO %i',$table.$suffix,$table),array_values($tables));
            if ($db->query('RENAME TABLE '.implode(',',$renames))===false || !self::ready($db)) { throw new ModelViolation('model_schema_divergent'); }
        } finally {
            foreach ($temporary as $table) { $db->query($db->prepare('DROP TABLE IF EXISTS %i',$table)); }
            if ((string)self::scalar($db,$db->prepare('SELECT RELEASE_LOCK(%s)',$lock))!=='1') { throw new ModelViolation('pf_local_attempt_unknown'); }
        }
    }

    private static function scalar(\wpdb $db,string $sql): mixed
    {
        $value=$db->get_var($sql);
        if ($db->last_error!=='') { throw new ModelViolation('pf_local_attempt_storage'); }
        return $value;
    }
}
