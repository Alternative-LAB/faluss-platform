<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Explicit private principal-activity examination, never geolocation or country authorization. */
final class TerritorySchema
{
    public const VERSION = '1';

    /** @return array<string,string> */
    public static function tables(\wpdb $db): array
    {
        RankingSchema::tables($db); $tables = [];
        foreach (array_keys(self::definitions()) as $kind) { $tables[$kind] = $db->prefix . 'fans_hof_territory_' . $kind; }
        return $tables;
    }

    /** @return array<string,array{columns:array<string,string>,indexes:array<string,array{bool,list<string>}>}> */
    public static function definitions(): array
    {
        return [
            'schema' => ['columns' => ['id' => 'tinyint(3) unsigned', 'version' => 'varchar(16)'], 'indexes' => ['PRIMARY' => [true, ['id']]]],
            'policies' => ['columns' => ['policy_id' => 'char(36)', 'criteria_url' => 'varchar(512)', 'criteria_text' => 'text',
                'countries_json' => 'text', 'territories_json' => 'mediumtext', 'policy_sha256' => 'char(64)', 'actor_id' => 'bigint(20) unsigned', 'created_at' => 'datetime(6)'],
                'indexes' => ['PRIMARY' => [true, ['policy_id']]]],
            'creators' => ['columns' => ['creator_id' => 'char(36)', 'revision' => 'bigint(20) unsigned', 'state' => 'varchar(16)',
                'policy_id' => 'char(36)', 'country' => 'varchar(2)', 'territory_ref' => 'varchar(80)', 'approved_revision' => 'bigint(20) unsigned',
                'reason' => 'varchar(32)', 'appeal_text' => 'text', 'updated_at' => 'datetime(6)'], 'indexes' => ['PRIMARY' => [true, ['creator_id']]]],
            'decisions' => ['columns' => ['creator_id' => 'char(36)', 'revision' => 'bigint(20) unsigned', 'actor_id' => 'bigint(20) unsigned',
                'action' => 'varchar(32)', 'reason' => 'varchar(32)', 'policy_id' => 'varchar(36)', 'country' => 'varchar(2)', 'territory_ref' => 'varchar(80)',
                'occurred_at' => 'datetime(6)'], 'indexes' => ['PRIMARY' => [true, ['creator_id', 'revision']]]],
            'bindings' => ['columns' => ['session_id' => 'char(36)', 'policy_id' => 'char(36)', 'created_at' => 'datetime(6)'],
                'indexes' => ['PRIMARY' => [true, ['session_id']]]],
        ];
    }

    public static function ready(\wpdb $db): bool
    {
        if (!SessionSchema::ready($db)) { return false; }
        $tables = self::tables($db);
        foreach (self::definitions() as $kind => $definition) {
            if (!RankingSchema::verify($db, $tables[$kind], $definition)) { return false; }
        }
        return $db->get_results($db->prepare('SELECT id,version FROM %i', $tables['schema']), 'ARRAY_A') === [['id' => '1', 'version' => self::VERSION]] && !self::failed();
    }

    public static function installOrVerify(\wpdb $db): void
    {
        RankingRegistry::administrator();
        if (!defined('FALUSS_PLATFORM_ROLE') || constant('FALUSS_PLATFORM_ROLE') !== 'fans') { throw new ModelViolation('hof_forbidden'); }
        if (!SessionSchema::ready($db)) { throw new ModelViolation('hof_session_schema_unavailable'); }
        if ((string) $db->get_var('SELECT @@in_transaction') !== '0' || self::failed()) { throw new ModelViolation('hof_nested_transaction'); }
        $tables = self::tables($db); $lock = 'fans_hof_territory_' . substr(hash('sha256', $db->prefix), 0, 24); $temporary = [];
        if ((string) $db->get_var($db->prepare('SELECT GET_LOCK(%s,10)', $lock)) !== '1') { throw new ModelViolation('hof_busy'); }
        try {
            $found = [];
            foreach ($tables as $table) {
                $exists = $db->get_var($db->prepare('SHOW TABLES LIKE %s', $db->esc_like($table)));
                if (self::failed()) { throw new ModelViolation('hof_storage_unavailable'); }
                if ($exists === $table) { $found[] = $table; }
            }
            if ($found !== []) {
                if (count($found) !== count($tables) || !self::ready($db)) { throw new ModelViolation('hof_territory_schema_divergent'); }
                return;
            }
            $suffix = '_new_' . bin2hex(random_bytes(4));
            foreach (self::definitions() as $kind => $definition) {
                $table = $tables[$kind] . $suffix; $parts = [];
                foreach ($definition['columns'] as $name => $type) { $parts[] = $db->prepare('%i', $name) . ' ' . $type . ' NOT NULL'; }
                foreach ($definition['indexes'] as $name => [$unique, $columns]) {
                    $parts[] = ($name === 'PRIMARY' ? 'PRIMARY KEY' : ($unique ? 'UNIQUE KEY ' : 'KEY ') . $db->prepare('%i', $name))
                        . ' (' . implode(',', array_map(static fn (string $column): string => $db->prepare('%i', $column), $columns)) . ')';
                }
                if ($db->query($db->prepare('CREATE TABLE %i', $table) . ' (' . implode(',', $parts) . ') ENGINE=InnoDB ' . $db->get_charset_collate()) === false) { throw new ModelViolation('hof_storage_unavailable'); }
                $temporary[] = $table;
                if (!RankingSchema::verify($db, $table, $definition)) { throw new ModelViolation('hof_territory_schema_divergent'); }
            }
            if ($db->insert($tables['schema'] . $suffix, ['id' => 1, 'version' => self::VERSION]) !== 1) { throw new ModelViolation('hof_storage_unavailable'); }
            $renames = array_map(static fn (string $table): string => $db->prepare('%i TO %i', $table . $suffix, $table), array_values($tables));
            if ($db->query('RENAME TABLE ' . implode(',', $renames)) === false || !self::ready($db)) { throw new ModelViolation('hof_territory_schema_divergent'); }
        } finally {
            foreach ($temporary as $table) { $db->query($db->prepare('DROP TABLE IF EXISTS %i', $table)); }
            $db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /** @phpstan-impure Reads the most recent SQL operation. */
    private static function failed(): bool { global $wpdb; return $wpdb->last_error !== ''; }
}
