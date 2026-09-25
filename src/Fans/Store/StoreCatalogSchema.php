<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Store;

final class StoreCatalogSchema
{
    public const OPTION = 'faluss_fans_store_catalog_schema_version';
    public const VERSION = '1';

    public static function table(): ?string
    {
        global $wpdb;
        $prefix = $wpdb->prefix ?? null;

        return is_string($prefix) && preg_match('/^[A-Za-z0-9_]+$/D', $prefix) === 1
            ? $prefix . 'faluss_fans_store_catalog'
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
        $lock = 'faluss_fans_store_' . substr(hash('sha256', $wpdb->prefix), 0, 32);
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

    private static function createSql(string $table, string $collate): string
    {
        return 'CREATE TABLE ' . self::quote($table) . ' ('
            . '`product_id` char(36) NOT NULL, '
            . '`request_key` char(36) NOT NULL, '
            . '`creator_id` char(36) NOT NULL, '
            . '`category` varchar(32) NOT NULL, '
            . '`visibility` varchar(16) NOT NULL, '
            . '`created_at` datetime NOT NULL, '
            . 'PRIMARY KEY (`product_id`), '
            . 'UNIQUE KEY `request_key_unique` (`request_key`), '
            . 'KEY `category_visibility` (`category`, `visibility`), '
            . 'KEY `creator_id` (`creator_id`)'
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
        $expectedColumns = [
            'product_id' => 'char(36)',
            'request_key' => 'char(36)',
            'creator_id' => 'char(36)',
            'category' => 'varchar(32)',
            'visibility' => 'varchar(16)',
            'created_at' => 'datetime',
        ];
        $columns = $wpdb->get_results('SHOW FULL COLUMNS FROM ' . self::quote($table), 'ARRAY_A');
        if (!is_array($columns) || count($columns) !== count($expectedColumns)) {
            return false;
        }
        foreach ($columns as $column) {
            if (!is_array($column)
                || !isset($expectedColumns[$column['Field'] ?? ''])
                || strtolower((string) ($column['Type'] ?? '')) !== $expectedColumns[$column['Field']]
                || ($column['Null'] ?? '') !== 'NO'
                || ($column['Extra'] ?? '') !== ''
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
            'PRIMARY' => [true, ['product_id']],
            'request_key_unique' => [true, ['request_key']],
            'category_visibility' => [false, ['category', 'visibility']],
            'creator_id' => [false, ['creator_id']],
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
