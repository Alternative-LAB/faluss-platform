<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Profiles;

/** Additive editorial storage; the identity/profile tables remain unchanged. */
final class EditorialSchema
{
    public const OPTION = 'faluss_fans_editorial_schema_version';
    public const VERSION = '1';

    public static function table(bool $audit = false): ?string
    {
        global $wpdb;
        return is_string($wpdb->prefix ?? null) && preg_match('/^[A-Za-z0-9_]+$/D', $wpdb->prefix) === 1
            ? $wpdb->prefix . ($audit ? 'faluss_fans_editorial_decisions' : 'faluss_fans_editorial') : null;
    }

    /** @return array<string,string> */
    public static function columns(bool $audit): array
    {
        return $audit ? ['creator_id' => 'char(36)', 'revision' => 'bigint(20) unsigned',
            'actor_id' => 'bigint(20) unsigned', 'action' => 'varchar(16)', 'reason' => 'varchar(32)', 'occurred_at' => 'datetime']
            : ['creator_id' => 'char(36)', 'revision' => 'bigint(20) unsigned', 'state' => 'varchar(16)',
                'public_name' => 'varchar(80)', 'bio' => 'text', 'portrait_id' => 'varchar(36)',
                'portrait_revision' => 'bigint(20) unsigned', 'updated_at' => 'datetime'];
    }

    public static function ready(): bool
    {
        return get_option(self::OPTION) === self::VERSION && self::verify(false) && self::verify(true);
    }

    public static function installOrVerify(): bool
    {
        global $wpdb;
        if (self::table() === null) { return false; }
        $lock = 'fans_editorial_schema_' . substr(hash('sha256', (string) self::table()), 0, 32);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,10)', $lock)) !== 1) { return false; }
        try {
            foreach ([false, true] as $audit) {
                $table = self::table($audit);
                $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
                if ($found === null) {
                    if ($wpdb->last_error !== '') { return false; }
                    $columns = [];
                    foreach (self::columns($audit) as $name => $type) { $columns[] = '`' . $name . '` ' . $type . ' NOT NULL'; }
                    $columns[] = $audit ? 'PRIMARY KEY (creator_id,revision)' : 'PRIMARY KEY (creator_id)';
                    if (!$audit) { $columns[] = 'KEY state_creator (state,creator_id)'; }
                    if ($wpdb->query('CREATE TABLE `' . $table . '` (' . implode(',', $columns) . ') ENGINE=InnoDB '
                        . $wpdb->get_charset_collate()) === false) { return false; }
                } elseif ($found !== $table) { return false; }
                if (!self::verify($audit)) { return false; }
            }
            update_option(self::OPTION, self::VERSION, false);
            return self::ready();
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
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
        $expected = $audit ? ['PRIMARY' => ['unique' => true, 'columns' => [1 => 'creator_id', 2 => 'revision']]]
            : ['PRIMARY' => ['unique' => true, 'columns' => [1 => 'creator_id']],
                'state_creator' => ['unique' => false, 'columns' => [1 => 'state', 2 => 'creator_id']]];
        foreach ($indexes as &$index) { ksort($index['columns']); }
        unset($index);
        ksort($indexes); ksort($expected);
        return $indexes === $expected;
    }
}
