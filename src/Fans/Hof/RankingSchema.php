<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Explicit private governance storage, not a PF ledger or an activation hook. */
final class RankingSchema
{
    public const VERSION = '1';

    /** @return array<string,string> */
    public static function tables(\wpdb $db): array
    {
        if (($GLOBALS['wpdb'] ?? null) !== $db) { throw new ModelViolation('hof_invalid_database'); }
        if (preg_match('/^[A-Za-z0-9_]{1,20}$/D', $db->prefix) !== 1) { throw new ModelViolation('hof_invalid_prefix'); }
        $tables = [];
        foreach (array_keys(self::definitions()) as $kind) { $tables[$kind] = $db->prefix . 'fans_hof_' . $kind; }
        return $tables;
    }

    /** @return array<string,array{columns:array<string,string>,indexes:array<string,array{bool,list<string>}>}> */
    public static function definitions(): array
    {
        return [
            'schema' => ['columns' => ['id' => 'tinyint(3) unsigned', 'version' => 'varchar(16)'], 'indexes' => ['PRIMARY' => [true, ['id']]]],
            'origin' => ['columns' => ['id' => 'tinyint(3) unsigned', 'origin_id' => 'char(36)', 'policy_version' => 'varchar(16)',
                'state' => 'varchar(16)', 'prepared_by' => 'bigint(20) unsigned', 'prepared_at' => 'datetime(6)'], 'indexes' => ['PRIMARY' => [true, ['id']]]],
            'dimensions' => ['columns' => ['dimension_id' => 'char(36)', 'origin_id' => 'char(36)', 'kind' => 'varchar(16)',
                'value' => 'varchar(32)', 'policy_version' => 'varchar(16)', 'starts_at' => 'varchar(26)', 'ends_at' => 'varchar(26)'],
                'indexes' => ['PRIMARY' => [true, ['dimension_id']], 'origin_kind_value' => [true, ['origin_id', 'kind', 'value']]]],
            'members' => ['columns' => ['wp_user_id' => 'bigint(20) unsigned', 'revision' => 'bigint(20) unsigned',
                'alias_state' => 'varchar(16)', 'pending_alias' => 'varchar(80)', 'approved_alias' => 'varchar(80)',
                'approved_revision' => 'bigint(20) unsigned', 'fan_public' => 'tinyint(3) unsigned',
                'creator_public' => 'tinyint(3) unsigned', 'updated_at' => 'datetime(6)'],
                'indexes' => ['PRIMARY' => [true, ['wp_user_id']], 'state_member' => [false, ['alias_state', 'wp_user_id']]]],
            'decisions' => ['columns' => ['wp_user_id' => 'bigint(20) unsigned', 'revision' => 'bigint(20) unsigned',
                'actor_id' => 'bigint(20) unsigned', 'action' => 'varchar(32)', 'reason' => 'varchar(32)', 'occurred_at' => 'datetime(6)'],
                'indexes' => ['PRIMARY' => [true, ['wp_user_id', 'revision']]]],
        ];
    }

    public static function ready(\wpdb $db): bool
    {
        if (!defined('FALUSS_PLATFORM_ROLE') || constant('FALUSS_PLATFORM_ROLE') !== 'fans') { return false; }
        $tables = self::tables($db);
        foreach (self::definitions() as $kind => $definition) {
            if (!self::verify($db, $tables[$kind], $definition)) { return false; }
        }
        return $db->get_results($db->prepare('SELECT id,version FROM %i', $tables['schema']), 'ARRAY_A') === [['id' => '1', 'version' => self::VERSION]]
            && !self::failed();
    }

    /** No normal bootstrap calls this method. Incompatible/partial schemas fail closed. */
    public static function installOrVerify(\wpdb $db): void
    {
        if (!current_user_can('manage_options') || !defined('FALUSS_PLATFORM_ROLE') || constant('FALUSS_PLATFORM_ROLE') !== 'fans') {
            throw new ModelViolation('hof_forbidden');
        }
        if ((string) $db->get_var('SELECT @@in_transaction') !== '0' || self::failed()) { throw new ModelViolation('hof_nested_transaction'); }
        $tables = self::tables($db);
        $lock = 'fans_hof_schema_' . substr(hash('sha256', $db->prefix), 0, 32);
        if ((string) $db->get_var($db->prepare('SELECT GET_LOCK(%s,10)', $lock)) !== '1') { throw new ModelViolation('hof_busy'); }
        $temporary = [];
        try {
            $found = [];
            foreach ($tables as $table) {
                $exists = $db->get_var($db->prepare('SHOW TABLES LIKE %s', $db->esc_like($table)));
                if (self::failed()) { throw new ModelViolation('hof_storage_unavailable'); }
                if ($exists === $table) { $found[] = $table; }
            }
            if ($found !== []) {
                if (count($found) !== count($tables) || !self::ready($db)) { throw new ModelViolation('hof_schema_divergent'); }
                return;
            }
            $suffix = '_new_' . bin2hex(random_bytes(4));
            foreach (self::definitions() as $kind => $definition) {
                $table = $tables[$kind] . $suffix;
                $parts = [];
                foreach ($definition['columns'] as $column => $type) { $parts[] = $db->prepare('%i', $column) . ' ' . $type . ' NOT NULL'; }
                foreach ($definition['indexes'] as $name => [$unique, $columns]) {
                    $parts[] = ($name === 'PRIMARY' ? 'PRIMARY KEY' : ($unique ? 'UNIQUE KEY ' : 'KEY ') . $db->prepare('%i', $name))
                        . ' (' . implode(',', array_map(static fn (string $column): string => $db->prepare('%i', $column), $columns)) . ')';
                }
                if ($db->query($db->prepare('CREATE TABLE %i', $table) . ' (' . implode(',', $parts) . ') ENGINE=InnoDB ' . $db->get_charset_collate()) === false) {
                    throw new ModelViolation('hof_storage_unavailable');
                }
                $temporary[] = $table;
                if (!self::verify($db, $table, $definition)) { throw new ModelViolation('hof_schema_divergent'); }
            }
            if ($db->insert($tables['schema'] . $suffix, ['id' => 1, 'version' => self::VERSION]) !== 1) { throw new ModelViolation('hof_storage_unavailable'); }
            $renames = array_map(static fn (string $table): string => $db->prepare('%i TO %i', $table . $suffix, $table), array_values($tables));
            if ($db->query('RENAME TABLE ' . implode(',', $renames)) === false || !self::ready($db)) { throw new ModelViolation('hof_schema_divergent'); }
        } finally {
            foreach ($temporary as $table) { $db->query($db->prepare('DROP TABLE IF EXISTS %i', $table)); }
            $db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /** @param array{columns:array<string,string>,indexes:array<string,array{bool,list<string>}>} $definition */
    public static function verify(\wpdb $db, string $table, array $definition): bool
    {
        self::tables($db);
        $status = $db->get_row($db->prepare('SHOW TABLE STATUS LIKE %s', $db->esc_like($table)), 'ARRAY_A');
        if (!is_array($status) || self::failed() || strtolower((string) ($status['Engine'] ?? '')) !== 'innodb') { return false; }
        $columns = $db->get_results($db->prepare('SHOW FULL COLUMNS FROM %i', $table), 'ARRAY_A');
        if (!is_array($columns) || self::failed()) { return false; }
        $actual = [];
        foreach ($columns as $row) {
            if (($row['Null'] ?? '') !== 'NO' || ($row['Extra'] ?? '') !== '') { return false; }
            $actual[(string) $row['Field']] = strtolower((string) $row['Type']);
        }
        if ($actual !== $definition['columns']) { return false; }
        $rows = $db->get_results($db->prepare('SHOW INDEX FROM %i', $table), 'ARRAY_A');
        if (!is_array($rows) || self::failed()) { return false; }
        $indexes = [];
        foreach ($rows as $row) {
            $name = (string) $row['Key_name'];
            $indexes[$name][0] = (string) $row['Non_unique'] === '0';
            $indexes[$name][1][(int) $row['Seq_in_index']] = (string) $row['Column_name'];
        }
        foreach ($indexes as &$index) { ksort($index[1]); $index[1] = array_values($index[1]); }
        unset($index);
        ksort($indexes); $expected = $definition['indexes']; ksort($expected);
        return $indexes === $expected;
    }

    /** @phpstan-impure Reads the most recent database operation's error. */
    private static function failed(): bool { global $wpdb; return $wpdb->last_error !== ''; }
}
