<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Images;

/** Private tables only; never registers a WordPress post type or media storage. */
final class ImageSchema
{
    public const OPTION = 'faluss_fans_images_schema_version';
    public const VERSION = '1';

    public static function table(bool $audit = false): ?string
    {
        global $wpdb;
        return is_string($wpdb->prefix ?? null) && preg_match('/^[A-Za-z0-9_]+$/D', $wpdb->prefix) === 1
            ? $wpdb->prefix . ($audit ? 'faluss_fans_image_decisions' : 'faluss_fans_images') : null;
    }

    /** @return array<string,string> */
    public static function columns(bool $audit): array
    {
        return $audit ? [
            'image_id' => 'char(36)', 'revision' => 'bigint(20) unsigned',
            'actor_id' => 'bigint(20) unsigned', 'action' => 'varchar(16)',
            'reason' => 'varchar(32)', 'file_hash' => 'char(64)', 'occurred_at' => 'datetime',
        ] : [
            'image_id' => 'char(36)', 'creator_id' => 'char(36)',
            'revision' => 'bigint(20) unsigned', 'file_hash' => 'char(64)', 'bytes' => 'bigint(20) unsigned', 'state' => 'varchar(16)',
            'created_at' => 'datetime', 'updated_at' => 'datetime',
        ];
    }

    public static function ready(): bool
    {
        return get_option(self::OPTION) === self::VERSION && self::verify(false) && self::verify(true);
    }

    public static function installOrVerify(): bool
    {
        global $wpdb;
        if (self::table() === null || !self::databaseReady($wpdb)) {
            return false;
        }
        $lock = 'fans_image_schema_' . substr(hash('sha256', (string) self::table()), 0, 32);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,10)', $lock)) !== 1) {
            return false;
        }
        try {
            foreach ([false, true] as $audit) {
                $table = self::table($audit);
                $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
                if ($found === null) {
                    if ($wpdb->last_error !== '') { return false; }
                    $columns = [];
                    foreach (self::columns($audit) as $name => $type) {
                        $columns[] = '`' . $name . '` ' . $type . ' NOT NULL';
                    }
                    $columns[] = $audit ? 'PRIMARY KEY (image_id,revision)' : 'PRIMARY KEY (image_id)';
                    if (!$audit) { $columns[] = 'KEY creator_id (creator_id)'; }
                    if ($wpdb->query('CREATE TABLE `' . $table . '` (' . implode(',', $columns)
                        . ') ENGINE=InnoDB ' . $wpdb->get_charset_collate()) === false) { return false; }
                } elseif ($found !== $table) { return false; }
                if (!self::verify($audit)) { return false; }
            }
            update_option(self::OPTION, self::VERSION, false);
            return self::ready();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private static function verify(bool $audit): bool
    {
        global $wpdb;
        $table = self::table($audit);
        if ($table === null) { return false; }
        $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $table), 'ARRAY_A');
        if (!is_array($status) || strtolower((string) ($status['Engine'] ?? '')) !== 'innodb') { return false; }
        $rows = $wpdb->get_results('SHOW FULL COLUMNS FROM `' . $table . '`', 'ARRAY_A');
        if (!is_array($rows) || count($rows) !== count(self::columns($audit))) { return false; }
        $actual = [];
        foreach ($rows as $row) {
            if (!is_array($row) || ($row['Null'] ?? '') !== 'NO' || ($row['Extra'] ?? '') !== '') { return false; }
            $actual[(string) ($row['Field'] ?? '')] = strtolower((string) ($row['Type'] ?? ''));
        }
        if ($actual !== self::columns($audit)) { return false; }
        $indexes = [];
        $rows = $wpdb->get_results('SHOW INDEX FROM `' . $table . '`', 'ARRAY_A');
        if (!is_array($rows)) { return false; }
        foreach ($rows as $row) {
            $name = (string) ($row['Key_name'] ?? '');
            $indexes[$name]['unique'] = (string) ($row['Non_unique'] ?? '') === '0';
            $indexes[$name]['columns'][(int) ($row['Seq_in_index'] ?? 0)] = (string) ($row['Column_name'] ?? '');
        }
        $expected = $audit ? ['PRIMARY' => ['unique' => true, 'columns' => [1 => 'image_id', 2 => 'revision']]]
            : ['PRIMARY' => ['unique' => true, 'columns' => [1 => 'image_id']], 'creator_id' => ['unique' => false, 'columns' => [1 => 'creator_id']]];
        foreach ($indexes as &$index) { ksort($index['columns']); }
        unset($index);
        ksort($indexes); ksort($expected);
        return $indexes === $expected;
    }

    private static function databaseReady(mixed $db): bool
    {
        if (!is_object($db)) { return false; }
        foreach (['prepare', 'query', 'get_var', 'get_row', 'get_results', 'get_charset_collate'] as $method) {
            if (!is_callable([$db, $method])) { return false; }
        }
        return true;
    }
}
