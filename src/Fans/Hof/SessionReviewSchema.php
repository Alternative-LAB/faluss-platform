<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Separate editorial decisions and private appeals; no economic adjustment or retention default. */
final class SessionReviewSchema
{
    public const VERSION = '1';

    /** @return array<string,string> */
    public static function tables(\wpdb $db): array
    {
        RankingSchema::tables($db); $tables = [];
        foreach (array_keys(self::definitions()) as $kind) { $tables[$kind] = $db->prefix . 'fans_hof_review_' . $kind; }
        return $tables;
    }

    /** @return array<string,array{columns:array<string,string>,indexes:array<string,array{bool,list<string>}>}> */
    public static function definitions(): array
    {
        return [
            'schema' => ['columns' => ['id' => 'tinyint(3) unsigned','version' => 'varchar(16)'], 'indexes' => ['PRIMARY' => [true,['id']]]],
            'sessions' => ['columns' => ['session_id' => 'char(36)','revision' => 'bigint(20) unsigned','state' => 'varchar(16)',
                'rules_sha256' => 'char(64)','reason' => 'varchar(32)','appeal_text' => 'text','updated_at' => 'datetime(6)'],
                'indexes' => ['PRIMARY' => [true,['session_id']]]],
            'events' => ['columns' => ['session_id' => 'char(36)','revision' => 'bigint(20) unsigned','actor_id' => 'bigint(20) unsigned',
                'action' => 'varchar(32)','reason' => 'varchar(32)','rules_sha256' => 'char(64)','rules_json' => 'mediumtext','appeal_text' => 'text','occurred_at' => 'datetime(6)'],
                'indexes' => ['PRIMARY' => [true,['session_id','revision']]]],
            'appeals' => ['columns' => ['case_id' => 'char(36)','session_id' => 'char(36)','creator_id' => 'char(36)',
                'role_revision' => 'bigint(20) unsigned','revision' => 'bigint(20) unsigned','state' => 'varchar(16)',
                'explanation' => 'text','result' => 'varchar(32)','reason' => 'varchar(32)','updated_at' => 'datetime(6)'],
                'indexes' => ['PRIMARY' => [true,['case_id']],'one_appeal' => [true,['session_id','creator_id','role_revision']]]],
            'appeal_log' => ['columns' => ['case_id' => 'char(36)','revision' => 'bigint(20) unsigned','actor_id' => 'bigint(20) unsigned',
                'action' => 'varchar(32)','reason' => 'varchar(32)','occurred_at' => 'datetime(6)'], 'indexes' => ['PRIMARY' => [true,['case_id','revision']]]],
        ];
    }

    public static function ready(\wpdb $db): bool
    {
        if (!SessionSchema::ready($db)) { return false; } $tables = self::tables($db);
        foreach (self::definitions() as $kind => $definition) { if (!RankingSchema::verify($db, $tables[$kind], $definition)) { return false; } }
        return $db->get_results($db->prepare('SELECT id,version FROM %i',$tables['schema']),'ARRAY_A') === [['id' => '1','version' => self::VERSION]] && !self::failed();
    }

    public static function installOrVerify(\wpdb $db): void
    {
        RankingRegistry::administrator();
        if (!defined('FALUSS_PLATFORM_ROLE') || constant('FALUSS_PLATFORM_ROLE') !== 'fans') { throw new ModelViolation('hof_forbidden'); }
        if (!SessionSchema::ready($db)) { throw new ModelViolation('hof_session_schema_unavailable'); }
        if ((string) $db->get_var('SELECT @@in_transaction') !== '0' || self::failed()) { throw new ModelViolation('hof_nested_transaction'); }
        $tables = self::tables($db); $lock = 'fans_hof_review_' . substr(hash('sha256',$db->prefix),0,24); $temporary = [];
        if ((string) $db->get_var($db->prepare('SELECT GET_LOCK(%s,10)',$lock)) !== '1') { throw new ModelViolation('hof_busy'); }
        try {
            $found = [];
            foreach ($tables as $table) {
                $exists = $db->get_var($db->prepare('SHOW TABLES LIKE %s',$db->esc_like($table)));
                if (self::failed()) { throw new ModelViolation('hof_storage_unavailable'); }
                if ($exists === $table) { $found[] = $table; }
            }
            if ($found !== []) {
                if (count($found) !== count($tables) || !self::ready($db)) { throw new ModelViolation('hof_review_schema_divergent'); }
                return;
            }
            $suffix = '_new_' . bin2hex(random_bytes(4));
            foreach (self::definitions() as $kind => $definition) {
                $table = $tables[$kind] . $suffix; $parts = [];
                foreach ($definition['columns'] as $name => $type) { $parts[] = $db->prepare('%i',$name) . ' ' . $type . ' NOT NULL'; }
                foreach ($definition['indexes'] as $name => [$unique,$columns]) {
                    $parts[] = ($name === 'PRIMARY' ? 'PRIMARY KEY' : ($unique ? 'UNIQUE KEY ' : 'KEY ') . $db->prepare('%i',$name))
                        . ' (' . implode(',',array_map(static fn (string $column): string => $db->prepare('%i',$column),$columns)) . ')';
                }
                if ($db->query($db->prepare('CREATE TABLE %i',$table) . ' (' . implode(',',$parts) . ') ENGINE=InnoDB ' . $db->get_charset_collate()) === false) { throw new ModelViolation('hof_storage_unavailable'); }
                $temporary[] = $table;
                if (!RankingSchema::verify($db,$table,$definition)) { throw new ModelViolation('hof_review_schema_divergent'); }
            }
            if ($db->insert($tables['schema'] . $suffix,['id' => 1,'version' => self::VERSION]) !== 1) { throw new ModelViolation('hof_storage_unavailable'); }
            $renames = array_map(static fn (string $table): string => $db->prepare('%i TO %i',$table . $suffix,$table),array_values($tables));
            if ($db->query('RENAME TABLE ' . implode(',',$renames)) === false || !self::ready($db)) { throw new ModelViolation('hof_review_schema_divergent'); }
        } finally {
            foreach ($temporary as $table) { $db->query($db->prepare('DROP TABLE IF EXISTS %i',$table)); }
            $db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }

    /** @phpstan-impure Reads the most recent SQL error. */
    private static function failed(): bool { global $wpdb; return $wpdb->last_error !== ''; }
}
