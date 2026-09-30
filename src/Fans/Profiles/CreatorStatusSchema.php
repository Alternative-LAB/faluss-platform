<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Profiles;

/** Separate admission journal; existing profiles and editorial approvals remain untouched. */
final class CreatorStatusSchema
{
    public const OPTION = 'faluss_fans_creator_status_schema_version';
    public const VERSION = '1';

    public static function table(): ?string
    {
        global $wpdb;
        return is_string($wpdb->prefix ?? null) && preg_match('/^[A-Za-z0-9_]+$/D', $wpdb->prefix) === 1
            ? $wpdb->prefix . 'faluss_fans_creator_status_decisions' : null;
    }

    /** @return array<string,string> */
    public static function columns(): array
    {
        return ['creator_id' => 'char(36)', 'revision' => 'bigint(20) unsigned',
            'actor_id' => 'bigint(20) unsigned', 'previous_status' => 'varchar(16)',
            'status' => 'varchar(16)', 'occurred_at' => 'datetime'];
    }

    public static function ready(): bool
    { return get_option(self::OPTION) === self::VERSION && self::verify(); }

    public static function installOrVerify(): bool
    {
        global $wpdb;
        $table = self::table();
        if ($table === null) { return false; }
        $lock = 'fans_status_schema_' . substr(hash('sha256', $table), 0, 32);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,10)', $lock)) !== 1) { return false; }
        try {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($found === null) {
                if ($wpdb->last_error !== '') { return false; }
                $columns = [];
                foreach (self::columns() as $name => $type) { $columns[] = '`' . $name . '` ' . $type . ' NOT NULL'; }
                if ($wpdb->query('CREATE TABLE `' . $table . '` (' . implode(',', $columns)
                    . ',PRIMARY KEY (creator_id,revision)) ENGINE=InnoDB ' . $wpdb->get_charset_collate()) === false) { return false; }
            } elseif ($found !== $table) { return false; }
            if (!self::verify()) { return false; }
            update_option(self::OPTION, self::VERSION, false);
            return self::ready();
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
    }

    private static function verify(): bool
    {
        global $wpdb;
        $table = self::table();
        if ($table === null) { return false; }
        $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $table), 'ARRAY_A');
        if (!is_array($status) || strtolower((string) ($status['Engine'] ?? '')) !== 'innodb') { return false; }
        $rows = $wpdb->get_results('SHOW FULL COLUMNS FROM `' . $table . '`', 'ARRAY_A');
        if (!is_array($rows) || count($rows) !== count(self::columns())) { return false; }
        $actual = [];
        foreach ($rows as $row) {
            if (!is_array($row) || ($row['Null'] ?? '') !== 'NO' || ($row['Extra'] ?? '') !== '') { return false; }
            $actual[(string) ($row['Field'] ?? '')] = strtolower((string) ($row['Type'] ?? ''));
        }
        if ($actual !== self::columns()) { return false; }
        $rows = $wpdb->get_results('SHOW INDEX FROM `' . $table . '`', 'ARRAY_A');
        if (!is_array($rows) || count($rows) !== 2) { return false; }
        $columns = [];
        foreach ($rows as $row) {
            if (($row['Key_name'] ?? '') !== 'PRIMARY' || (string) ($row['Non_unique'] ?? '') !== '0') { return false; }
            $columns[(int) ($row['Seq_in_index'] ?? 0)] = (string) ($row['Column_name'] ?? '');
        }
        ksort($columns);
        return $columns === [1 => 'creator_id', 2 => 'revision'];
    }
}
