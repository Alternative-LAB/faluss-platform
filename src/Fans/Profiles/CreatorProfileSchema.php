<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Profiles;

final class CreatorProfileSchema
{
    public const OPTION = 'faluss_fans_creator_profiles_schema_version';
    public const VERSION = '1';
    private const SUFFIX = 'faluss_fans_creator_profiles';

    public static function table(): ?string
    {
        global $wpdb;
        $prefix = $wpdb->prefix ?? null;

        return is_string($prefix) && preg_match('/^[A-Za-z0-9_]+$/D', $prefix) === 1
            ? $prefix . self::SUFFIX
            : null;
    }

    public static function ready(): bool
    {
        $table = self::table();

        return $table !== null && get_option(self::OPTION) === self::VERSION && self::verify($table);
    }

    public static function installOrVerify(): bool
    {
        global $wpdb;
        $table = self::table();
        if ($table === null || !self::databaseReady($wpdb)) {
            return false;
        }
        $lock = 'faluss_fans_profiles_' . substr(hash('sha256', $wpdb->prefix), 0, 32);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)', $lock, 10)) !== 1) {
            return false;
        }

        try {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($found === null) {
                if ($wpdb->last_error !== '' || $wpdb->query(self::createSql($table, $wpdb->get_charset_collate())) === false) {
                    return false;
                }
            } elseif ($found !== $table) {
                return false;
            }
            if (!self::verify($table)) {
                return false;
            }

            update_option(self::OPTION, self::VERSION, false);

            return true;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /** @return array<string, array{type:string,null:bool,auto?:bool}> */
    public static function columns(): array
    {
        return [
            'id' => ['type' => 'bigint(20) unsigned', 'null' => false, 'auto' => true],
            'creator_id' => ['type' => 'char(36)', 'null' => false],
            'wp_user_id' => ['type' => 'bigint(20) unsigned', 'null' => false],
            'category' => ['type' => 'varchar(32)', 'null' => false],
            'status' => ['type' => 'varchar(16)', 'null' => false],
            'created_at' => ['type' => 'datetime', 'null' => false],
            'updated_at' => ['type' => 'datetime', 'null' => false],
        ];
    }

    private static function createSql(string $table, string $collate): string
    {
        return 'CREATE TABLE ' . self::quote($table) . ' ('
            . '`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, '
            . '`creator_id` char(36) NOT NULL, '
            . '`wp_user_id` bigint(20) unsigned NOT NULL, '
            . '`category` varchar(32) NOT NULL, '
            . '`status` varchar(16) NOT NULL, '
            . '`created_at` datetime NOT NULL, '
            . '`updated_at` datetime NOT NULL, '
            . 'PRIMARY KEY (`id`), '
            . 'UNIQUE KEY `creator_id_unique` (`creator_id`), '
            . 'UNIQUE KEY `wp_user_id_unique` (`wp_user_id`), '
            . 'KEY `status_category` (`status`, `category`)'
            . ') ENGINE=InnoDB ' . $collate;
    }

    private static function verify(string $table): bool
    {
        global $wpdb;
        if (!self::databaseReady($wpdb)) {
            return false;
        }
        $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $table), 'ARRAY_A');
        if (!is_array($status) || strcasecmp((string) ($status['Engine'] ?? ''), 'InnoDB') !== 0) {
            return false;
        }
        $actual = $wpdb->get_results('SHOW FULL COLUMNS FROM ' . self::quote($table), 'ARRAY_A');
        if (!is_array($actual) || count($actual) !== count(self::columns())) {
            return false;
        }
        $byName = [];
        foreach ($actual as $column) {
            if (!is_array($column) || !is_string($column['Field'] ?? null)) {
                return false;
            }
            $byName[$column['Field']] = $column;
        }
        foreach (self::columns() as $name => $expected) {
            if (!isset($byName[$name])
                || strtolower((string) ($byName[$name]['Type'] ?? '')) !== $expected['type']
                || (($byName[$name]['Null'] ?? '') === 'YES') !== $expected['null']
                || str_contains(strtolower((string) ($byName[$name]['Extra'] ?? '')), 'auto_increment') !== !empty($expected['auto'])
            ) {
                return false;
            }
        }
        $rows = $wpdb->get_results('SHOW INDEX FROM ' . self::quote($table), 'ARRAY_A');
        $indexes = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row) || !is_string($row['Key_name'] ?? null)) {
                return false;
            }
            $name = $row['Key_name'];
            if (!isset($indexes[$name])) {
                $indexes[$name] = ['unique' => (string) ($row['Non_unique'] ?? '') === '0', 'columns' => []];
            }
            $indexes[$name]['columns'][(int) ($row['Seq_in_index'] ?? 0)] = (string) ($row['Column_name'] ?? '');
        }
        $expectedIndexes = [
            'PRIMARY' => [true, ['id']],
            'creator_id_unique' => [true, ['creator_id']],
            'wp_user_id_unique' => [true, ['wp_user_id']],
            'status_category' => [false, ['status', 'category']],
        ];
        if (count($indexes) !== count($expectedIndexes)) {
            return false;
        }
        foreach ($expectedIndexes as $name => $expected) {
            if (!isset($indexes[$name])) {
                return false;
            }
            ksort($indexes[$name]['columns']);
            if ($indexes[$name]['unique'] !== $expected[0]
                || array_values($indexes[$name]['columns']) !== $expected[1]
            ) {
                return false;
            }
        }

        return true;
    }

    private static function quote(string $value): string
    {
        return '`' . str_replace('`', '``', $value) . '`';
    }

    private static function databaseReady(mixed $database): bool
    {
        return is_object($database)
            && is_callable([$database, 'prepare'])
            && is_callable([$database, 'get_var'])
            && is_callable([$database, 'get_row'])
            && is_callable([$database, 'get_results'])
            && is_callable([$database, 'query'])
            && is_callable([$database, 'get_charset_collate']);
    }
}
