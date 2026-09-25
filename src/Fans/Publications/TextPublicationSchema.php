<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Publications;

/** Private tables only; never registers a WordPress post type or media storage. */
final class TextPublicationSchema
{
    public const OPTION = 'faluss_fans_text_publications_schema_version';
    public const VERSION = '2';

    public static function table(bool|string $audit = false): ?string
    {
        global $wpdb;
        return is_string($wpdb->prefix ?? null) && preg_match('/^[A-Za-z0-9_]+$/D', $wpdb->prefix) === 1
            ? $wpdb->prefix . ($audit === 'requests' ? 'faluss_fans_text_requests' : ($audit ? 'faluss_fans_text_decisions' : 'faluss_fans_text_publications')) : null;
    }

    /** @return array<string,string> */
    public static function columns(bool|string $audit): array
    {
        if ($audit === 'requests') {
            return ['creator_id' => 'char(36)', 'key_hash' => 'char(64)', 'request_hash' => 'char(64)', 'publication_id' => 'char(36)'];
        }
        return $audit ? [
            'publication_id' => 'char(36)', 'revision' => 'bigint(20) unsigned',
            'actor_id' => 'bigint(20) unsigned', 'action' => 'varchar(16)',
            'reason' => 'varchar(32)', 'text_hash' => 'char(64)', 'occurred_at' => 'datetime',
        ] : [
            'publication_id' => 'char(36)', 'creator_id' => 'char(36)',
            'revision' => 'bigint(20) unsigned', 'body' => 'text', 'state' => 'varchar(16)',
            'category' => 'varchar(32)', 'created_at' => 'datetime', 'updated_at' => 'datetime',
        ];
    }

    public static function ready(): bool
    {
        return get_option(self::OPTION) === self::VERSION && self::verify(false) && self::verify(true) && self::verify('requests');
    }

    public static function installOrVerify(): bool
    {
        global $wpdb;
        if (self::table() === null || !self::databaseReady($wpdb)) {
            return false;
        }
        $lock = 'fans_text_schema_' . substr(hash('sha256', (string) self::table()), 0, 32);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,10)', $lock)) !== 1) {
            return false;
        }
        try {
            foreach ([false, true, 'requests'] as $audit) {
                $table = self::table($audit);
                $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
                if ($found === null) {
                    if ($wpdb->last_error !== '') { return false; }
                    $columns = [];
                    foreach (self::columns($audit) as $name => $type) {
                        $columns[] = '`' . $name . '` ' . $type . ' NOT NULL';
                    }
                    $columns[] = $audit === 'requests' ? 'PRIMARY KEY (creator_id,key_hash)' : ($audit ? 'PRIMARY KEY (publication_id,revision)' : 'PRIMARY KEY (publication_id)');
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

    private static function verify(bool|string $audit): bool
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
        $expected = $audit ? ['PRIMARY' => ['unique' => true, 'columns' => [1 => 'publication_id', 2 => 'revision']]]
            : ['PRIMARY' => ['unique' => true, 'columns' => [1 => 'publication_id']], 'creator_id' => ['unique' => false, 'columns' => [1 => 'creator_id']]];
        if ($audit === 'requests') { $expected = ['PRIMARY' => ['unique' => true, 'columns' => [1 => 'creator_id', 2 => 'key_hash']]]; }
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
