<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Session metadata is separate from score projections and the official Hub ledger. */
final class SessionSchema
{
    public const VERSION = '1';

    /** @return array<string,string> */
    public static function tables(\wpdb $db): array
    {
        RankingSchema::tables($db);
        $tables = [];
        foreach (array_keys(self::definitions()) as $kind) { $tables[$kind] = $db->prefix . 'fans_hof_session_' . $kind; }
        return $tables;
    }

    /** @return array<string,array{columns:array<string,string>,indexes:array<string,array{bool,list<string>}>}> */
    public static function definitions(): array
    {
        return [
            'schema' => ['columns' => ['id' => 'tinyint(3) unsigned', 'version' => 'varchar(16)'], 'indexes' => ['PRIMARY' => [true, ['id']]]],
            'records' => ['columns' => ['session_id' => 'char(36)', 'owner_creator_id' => 'char(36)', 'revision' => 'bigint(20) unsigned',
                'rules_version' => 'bigint(20) unsigned', 'barrier_version' => 'bigint(20) unsigned', 'state' => 'varchar(16)',
                'closure_action' => 'varchar(16)', 'title' => 'varchar(120)', 'rules_text' => 'text', 'category' => 'varchar(32)',
                'scope' => 'varchar(16)', 'country' => 'varchar(2)', 'territory_ref' => 'varchar(80)', 'timezone' => 'varchar(64)',
                'starts_at' => 'datetime(6)', 'ends_at' => 'datetime(6)', 'frozen_sha256' => 'varchar(64)',
                'policy_version' => 'varchar(16)', 'created_at' => 'datetime(6)', 'updated_at' => 'datetime(6)'],
                'indexes' => ['PRIMARY' => [true, ['session_id']], 'owner_state' => [false, ['owner_creator_id', 'state']]]],
            'roles' => ['columns' => ['session_id' => 'char(36)', 'creator_id' => 'char(36)', 'role' => 'varchar(16)', 'state' => 'varchar(16)',
                'revision' => 'bigint(20) unsigned', 'rules_sha256' => 'varchar(64)', 'admitted_at' => 'varchar(26)',
                'reason' => 'varchar(32)', 'updated_at' => 'datetime(6)'],
                'indexes' => ['PRIMARY' => [true, ['session_id', 'creator_id', 'role']], 'creator_role' => [false, ['creator_id', 'role', 'state']]]],
            'decisions' => ['columns' => ['session_id' => 'char(36)', 'revision' => 'bigint(20) unsigned', 'actor_id' => 'bigint(20) unsigned',
                'action' => 'varchar(32)', 'target_creator_id' => 'varchar(36)', 'reason' => 'varchar(32)', 'occurred_at' => 'datetime(6)'],
                'indexes' => ['PRIMARY' => [true, ['session_id', 'revision']]]],
        ];
    }

    public static function ready(\wpdb $db): bool
    {
        if (!RankingSchema::ready($db)) { return false; }
        $tables = self::tables($db);
        foreach (self::definitions() as $kind => $definition) {
            if (!RankingSchema::verify($db, $tables[$kind], $definition)) { return false; }
        }
        return $db->get_results($db->prepare('SELECT id,version FROM %i', $tables['schema']), 'ARRAY_A') === [['id' => '1', 'version' => self::VERSION]];
    }

    public static function installOrVerify(\wpdb $db): void
    {
        RankingRegistry::administrator();
        if (!RankingSchema::ready($db)) { throw new ModelViolation('hof_schema_unavailable'); }
        if ((string) $db->get_var('SELECT @@in_transaction') !== '0') { throw new ModelViolation('hof_nested_transaction'); }
        $tables = self::tables($db);
        $lock = 'fans_hof_session_schema_' . substr(hash('sha256', $db->prefix), 0, 24);
        if ((string) $db->get_var($db->prepare('SELECT GET_LOCK(%s,10)', $lock)) !== '1') { throw new ModelViolation('hof_busy'); }
        $temporary = [];
        try {
            $existing = [];
            foreach ($tables as $table) {
                $found = $db->get_var($db->prepare('SHOW TABLES LIKE %s', $db->esc_like($table)));
                if (self::failed()) { throw new ModelViolation('hof_storage_unavailable'); }
                if ($found === $table) { $existing[] = $table; }
            }
            if ($existing !== []) {
                if (count($existing) !== count($tables) || !self::ready($db)) { throw new ModelViolation('hof_session_schema_divergent'); }
                return;
            }
            $suffix = '_new_' . bin2hex(random_bytes(4));
            foreach (self::definitions() as $kind => $definition) {
                $table = $tables[$kind] . $suffix; $parts = [];
                foreach ($definition['columns'] as $column => $type) { $parts[] = $db->prepare('%i', $column) . ' ' . $type . ' NOT NULL'; }
                foreach ($definition['indexes'] as $name => [$unique, $columns]) {
                    $parts[] = ($name === 'PRIMARY' ? 'PRIMARY KEY' : ($unique ? 'UNIQUE KEY ' : 'KEY ') . $db->prepare('%i', $name))
                        . ' (' . implode(',', array_map(static fn (string $column): string => $db->prepare('%i', $column), $columns)) . ')';
                }
                if ($db->query($db->prepare('CREATE TABLE %i', $table) . ' (' . implode(',', $parts) . ') ENGINE=InnoDB ' . $db->get_charset_collate()) === false) { throw new ModelViolation('hof_storage_unavailable'); }
                $temporary[] = $table;
                if (!RankingSchema::verify($db, $table, $definition)) { throw new ModelViolation('hof_session_schema_divergent'); }
            }
            if ($db->insert($tables['schema'] . $suffix, ['id' => 1, 'version' => self::VERSION]) !== 1) { throw new ModelViolation('hof_storage_unavailable'); }
            $renames = array_map(static fn (string $table): string => $db->prepare('%i TO %i', $table . $suffix, $table), array_values($tables));
            if ($db->query('RENAME TABLE ' . implode(',', $renames)) === false || !self::ready($db)) { throw new ModelViolation('hof_session_schema_divergent'); }
        } finally {
            foreach ($temporary as $table) { $db->query($db->prepare('DROP TABLE IF EXISTS %i', $table)); }
            $db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /** @phpstan-impure Reads the last SQL error. */
    private static function failed(): bool { global $wpdb; return $wpdb->last_error !== ''; }
}
