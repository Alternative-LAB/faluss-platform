<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Sso;

final class FansSsoSchema
{
    public const VERSION = '1';
    public const OPTION = 'faluss_fans_sso_schema_version';
    private const LOCK_TIMEOUT = 10;

    /** @return array<string, array<string, mixed>> */
    public static function schema(): array
    {
        return [
            'links' => [
                'suffix' => 'faluss_fans_identity_links',
                'columns' => [
                    'id' => ['type' => 'bigint(20) unsigned', 'null' => false, 'auto' => true],
                    'wp_user_id' => ['type' => 'bigint(20) unsigned', 'null' => false],
                    'faluss_id' => ['type' => 'char(36)', 'null' => false],
                    'created_at' => ['type' => 'datetime', 'null' => false],
                    'last_proved_at' => ['type' => 'datetime', 'null' => false],
                ],
                'indexes' => [
                    'PRIMARY' => [true, ['id']],
                    'wp_user_id_unique' => [true, ['wp_user_id']],
                    'faluss_id_unique' => [true, ['faluss_id']],
                ],
            ],
            'states' => [
                'suffix' => 'faluss_fans_sso_states',
                'columns' => [
                    'id' => ['type' => 'bigint(20) unsigned', 'null' => false, 'auto' => true],
                    'state_hash' => ['type' => 'char(64)', 'null' => false],
                    'browser_hash' => ['type' => 'char(64)', 'null' => false],
                    'flow_mode' => ['type' => 'varchar(20)', 'null' => false],
                    'wp_user_id' => ['type' => 'bigint(20) unsigned', 'null' => true],
                    'expires_at' => ['type' => 'datetime', 'null' => false],
                    'consumed_at' => ['type' => 'datetime', 'null' => true],
                    'created_at' => ['type' => 'datetime', 'null' => false],
                ],
                'indexes' => [
                    'PRIMARY' => [true, ['id']],
                    'state_hash_unique' => [true, ['state_hash']],
                    'expires_at' => [false, ['expires_at']],
                ],
            ],
        ];
    }

    /** @return array{links?:string,states?:string} */
    public static function tables(): array
    {
        global $wpdb;

        if (!is_string($wpdb->prefix ?? null)
            || preg_match('/^[A-Za-z0-9_]+$/D', $wpdb->prefix) !== 1
        ) {
            return [];
        }

        $tables = [];
        foreach (self::schema() as $key => $definition) {
            $tables[$key] = $wpdb->prefix . (string) $definition['suffix'];
        }

        return $tables;
    }

    public static function ready(): bool
    {
        global $wpdb;

        return self::databaseReady($wpdb)
            && get_option(self::OPTION) === self::VERSION
            && count(self::tables()) === 2
            && self::verifyAll(self::tables());
    }

    public static function installOrVerify(): bool
    {
        global $wpdb;

        if (!self::databaseReady($wpdb)) {
            return false;
        }

        $tables = self::tables();
        if (count($tables) !== 2) {
            return false;
        }

        $found = 0;
        foreach ($tables as $table) {
            $present = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($present === null && !empty($wpdb->last_error)) {
                return false;
            }
            if ($table === $present) {
                ++$found;
            }
        }

        if ($found > 0 && $found < 2) {
            return false;
        }
        if ($found === 2) {
            if (!self::verifyAll($tables)) {
                return false;
            }
            update_option(self::OPTION, self::VERSION, false);

            return true;
        }

        $lock = 'faluss_fans_sso_' . substr(hash('sha256', $wpdb->prefix), 0, 32);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)', $lock, self::LOCK_TIMEOUT)) !== 1) {
            return false;
        }

        try {
            foreach ($tables as $table) {
                if ($table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) {
                    return false;
                }
            }
            foreach (self::schema() as $key => $definition) {
                $table = $tables[$key] ?? null;
                if (!is_string($table)
                    || $wpdb->query(self::createSql($table, $definition, $wpdb->get_charset_collate())) === false
                ) {
                    return false;
                }
            }
            if (!self::verifyAll($tables)) {
                return false;
            }
            update_option(self::OPTION, self::VERSION, false);

            return true;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /** @param array<string, string> $tables */
    private static function verifyAll(array $tables): bool
    {
        foreach (self::schema() as $key => $definition) {
            if (!isset($tables[$key]) || !self::verify($tables[$key], $definition)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $definition */
    private static function verify(string $table, array $definition): bool
    {
        global $wpdb;

        $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $table), 'ARRAY_A');
        if (!is_array($status) || strcasecmp('InnoDB', (string) ($status['Engine'] ?? '')) !== 0) {
            return false;
        }

        $columns = $wpdb->get_results('SHOW FULL COLUMNS FROM ' . self::quoteIdentifier($table), 'ARRAY_A');
        if (!is_array($columns) || count($columns) !== count((array) $definition['columns'])) {
            return false;
        }
        $actualColumns = [];
        foreach ($columns as $column) {
            if (is_array($column) && is_string($column['Field'] ?? null)) {
                $actualColumns[$column['Field']] = $column;
            }
        }
        foreach ((array) $definition['columns'] as $name => $expected) {
            if (!is_array($expected)
                || !isset($actualColumns[$name])
                || strtolower((string) $actualColumns[$name]['Type']) !== $expected['type']
                || (($actualColumns[$name]['Null'] ?? '') === 'YES') !== $expected['null']
                || (!empty($expected['auto'])
                    !== str_contains(strtolower((string) ($actualColumns[$name]['Extra'] ?? '')), 'auto_increment'))
            ) {
                return false;
            }
        }

        $rows = $wpdb->get_results('SHOW INDEX FROM ' . self::quoteIdentifier($table), 'ARRAY_A');
        $indexes = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row) || !is_string($row['Key_name'] ?? null)) {
                return false;
            }
            $name = $row['Key_name'];
            if (!isset($indexes[$name])) {
                $indexes[$name] = ['unique' => (string) ($row['Non_unique'] ?? '') === '0', 'cols' => []];
            }
            $indexes[$name]['cols'][(int) ($row['Seq_in_index'] ?? 0)] = (string) ($row['Column_name'] ?? '');
        }
        if (count($indexes) !== count((array) $definition['indexes'])) {
            return false;
        }
        foreach ((array) $definition['indexes'] as $name => $expected) {
            if (!is_array($expected) || !isset($indexes[$name])) {
                return false;
            }
            ksort($indexes[$name]['cols']);
            if ($indexes[$name]['unique'] !== $expected[0]
                || array_values($indexes[$name]['cols']) !== $expected[1]
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $definition */
    private static function createSql(string $table, array $definition, string $collate): string
    {
        $lines = [];
        foreach ((array) $definition['columns'] as $name => $column) {
            $lines[] = self::quoteIdentifier((string) $name)
                . ' ' . $column['type']
                . (!empty($column['null']) ? ' NULL' : ' NOT NULL')
                . (!empty($column['auto']) ? ' AUTO_INCREMENT' : '');
        }
        foreach ((array) $definition['indexes'] as $name => $index) {
            $columns = implode(', ', array_map(
                static fn (string $column): string => self::quoteIdentifier($column),
                $index[1]
            ));
            $lines[] = $name === 'PRIMARY'
                ? 'PRIMARY KEY (' . $columns . ')'
                : (!empty($index[0]) ? 'UNIQUE KEY ' : 'KEY ')
                    . self::quoteIdentifier((string) $name) . ' (' . $columns . ')';
        }

        return 'CREATE TABLE ' . self::quoteIdentifier($table)
            . ' (' . implode(', ', $lines) . ') ENGINE=InnoDB ' . $collate;
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private static function databaseReady(mixed $database): bool
    {
        return is_object($database)
            && is_callable([$database, 'get_var'])
            && is_callable([$database, 'prepare'])
            && is_callable([$database, 'query'])
            && is_callable([$database, 'get_row'])
            && is_callable([$database, 'get_results'])
            && is_callable([$database, 'get_charset_collate']);
    }
}
