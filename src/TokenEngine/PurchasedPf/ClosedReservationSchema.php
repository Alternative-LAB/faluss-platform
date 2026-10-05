<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

/** H2 reservation schema. Explicit isolated installation; no historical DDL. */
final class ClosedReservationSchema
{
    public const VERSION = '1';
    public const SCOPE = 'closed_h2_reservations';

    /** @return array<string, string> */
    public static function tables(\wpdb $database): array
    {
        if (preg_match('/^[A-Za-z0-9_]{1,20}$/D', $database->prefix) !== 1) {
            throw new ModelViolation('invalid_table_prefix');
        }
        $tables = [];
        foreach (array_keys(self::definitions()) as $kind) {
            $tables[$kind] = $database->prefix . 'token_engine_pf_h2_' . $kind;
        }

        return $tables;
    }

    public static function quote(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $identifier) !== 1) {
            throw new ModelViolation('invalid_table_identifier');
        }

        return '`' . $identifier . '`';
    }

    public static function ready(\wpdb $database): bool
    {
        ClosedReservationEnvironment::assertIsolated($database);
        $tables = self::tables($database);
        foreach (self::definitions() as $kind => $definition) {
            if (!self::exists($database, $tables[$kind]) || !self::verify($database, $tables[$kind], $definition)) {
                return false;
            }
        }
        $rows = $database->get_results('SELECT id,version,scope FROM ' . self::quote($tables['schema']), 'ARRAY_A');

        return $database->last_error === '' && $rows === [['id' => '1', 'version' => self::VERSION, 'scope' => self::SCOPE]];
    }

    public static function installForRecipe(\wpdb $database): void
    {
        ClosedReservationEnvironment::assertIsolated($database);
        if ($database->get_var('SELECT @@in_transaction') !== '0' || $database->last_error !== '') {
            throw new ModelViolation('nested_transaction_refused');
        }
        $tables = self::tables($database);
        $lock = 'token_engine_pf_h2_schema_' . substr(hash('sha256', $database->prefix), 0, 24);
        if ((string) $database->get_var($database->prepare('SELECT GET_LOCK(%s,10)', $lock)) !== '1') {
            throw new ModelViolation('model_lock_unavailable');
        }
        $temporary = [];
        try {
            $existing = array_filter($tables, static fn (string $table): bool => self::exists($database, $table));
            if ($existing !== []) {
                if (count($existing) !== count($tables) || !self::ready($database)) {
                    throw new ModelViolation('model_schema_divergent');
                }

                return;
            }
            $suffix = '_new_' . bin2hex(random_bytes(4));
            foreach (self::definitions() as $kind => $definition) {
                $table = $tables[$kind] . $suffix;
                $sql = self::createSql($table, $definition);
                if ($database->query($sql) === false) {
                    throw new ModelViolation('model_schema_unavailable');
                }
                $temporary[] = $table;
                if (!self::verify($database, $table, $definition)) {
                    throw new ModelViolation('model_schema_unavailable');
                }
            }
            $schemaTable = $tables['schema'] . $suffix;
            if ($database->insert($schemaTable, ['id' => '1', 'version' => self::VERSION, 'scope' => self::SCOPE]) !== 1) {
                throw new ModelViolation('model_schema_unavailable');
            }
            $renames = [];
            foreach ($tables as $table) {
                $renames[] = self::quote($table . $suffix) . ' TO ' . self::quote($table);
            }
            if ($database->query('RENAME TABLE ' . implode(',', $renames)) === false || !self::ready($database)) {
                throw new ModelViolation('model_schema_unavailable');
            }
        } finally {
            // Only uniquely named, newly created temporary tables are removable.
            foreach ($temporary as $table) {
                $database->query('DROP TABLE IF EXISTS ' . self::quote($table));
            }
            if ((string) $database->get_var($database->prepare('SELECT RELEASE_LOCK(%s)', $lock)) !== '1') {
                throw new ModelViolation('model_lock_release_unknown');
            }
        }
    }

    private static function exists(\wpdb $database, string $table): bool
    {
        $found = $database->get_var($database->prepare('SHOW TABLES LIKE %s', $database->esc_like($table)));
        if ($database->last_error !== '') {
            throw new ModelViolation('model_schema_unavailable');
        }

        return $found === $table;
    }

    /** @param array{columns:array<string,string>,indexes:array<string,array{bool,list<string>}>} $definition */
    private static function createSql(string $table, array $definition): string
    {
        $parts = [];
        foreach ($definition['columns'] as $name => $type) {
            $nullable = str_starts_with($type, '?');
            $type = ltrim($type, '?');
            $charset = str_starts_with($type, 'char(') || str_starts_with($type, 'varchar(')
                ? ' CHARACTER SET ascii COLLATE ascii_bin' : '';
            $parts[] = self::quote($name) . ' ' . $type . $charset . ($nullable ? ' NULL' : ' NOT NULL');
        }
        foreach ($definition['indexes'] as $name => [$unique, $columns]) {
            $parts[] = ($name === 'PRIMARY' ? 'PRIMARY KEY' : ($unique ? 'UNIQUE KEY ' : 'KEY ') . self::quote($name))
                . ' (' . implode(',', array_map(self::quote(...), $columns)) . ')';
        }

        return 'CREATE TABLE ' . self::quote($table) . ' (' . implode(',', $parts)
            . ') ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin';
    }

    /** @param array{columns:array<string,string>,indexes:array<string,array{bool,list<string>}>} $definition */
    private static function verify(\wpdb $database, string $table, array $definition): bool
    {
        $status = $database->get_row($database->prepare('SHOW TABLE STATUS LIKE %s', $database->esc_like($table)), 'ARRAY_A');
        $statusError = $database->last_error;
        $columns = $database->get_results('SHOW FULL COLUMNS FROM ' . self::quote($table), 'ARRAY_A');
        $columnsError = $database->last_error;
        $rows = $database->get_results('SHOW INDEX FROM ' . self::quote($table), 'ARRAY_A');
        $indexesError = $database->last_error;
        // Snapshot each error before the next query replaces wpdb's mutable state.
        if (!is_array($status) || strcasecmp((string) ($status['Engine'] ?? ''), 'InnoDB') !== 0
            || ($status['Collation'] ?? '') !== 'utf8mb4_bin'
            || $statusError !== '' || $columnsError !== '' || $indexesError !== ''
        ) {
            return false;
        }
        if (array_column($columns, 'Field') !== array_keys($definition['columns'])) {
            return false;
        }
        foreach ($columns as $column) {
            $expected = $definition['columns'][$column['Field']];
            $type = ltrim($expected, '?');
            $normalType = preg_replace('/bigint\([0-9]+\)/', 'bigint', strtolower((string) $column['Type']));
            $ascii = str_starts_with($type, 'char(') || str_starts_with($type, 'varchar(');
            if ($normalType !== $type || ($column['Null'] === 'YES') !== str_starts_with($expected, '?')
                || $column['Extra'] !== '' || $column['Default'] !== null
                || ($ascii && $column['Collation'] !== 'ascii_bin')
                || ($type === 'longtext' && $column['Collation'] !== 'utf8mb4_bin')
            ) {
                return false;
            }
        }
        $indexes = [];
        foreach ($rows as $row) {
            if (($row['Index_type'] ?? '') !== 'BTREE' || $row['Sub_part'] !== null) {
                return false;
            }
            $name = (string) $row['Key_name'];
            $indexes[$name]['unique'] = (string) $row['Non_unique'] === '0';
            $indexes[$name]['columns'][(int) $row['Seq_in_index']] = (string) $row['Column_name'];
        }
        if (count($indexes) !== count($definition['indexes'])) {
            return false;
        }
        foreach ($definition['indexes'] as $name => [$unique, $names]) {
            if (!isset($indexes[$name])) {
                return false;
            }
            ksort($indexes[$name]['columns']);
            if ($indexes[$name]['unique'] !== $unique || array_values($indexes[$name]['columns']) !== $names) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,array{columns:array<string,string>,indexes:array<string,array{bool,list<string>}>}> */
    private static function definitions(): array
    {
        return [
            'schema' => ['columns' => ['id' => 'char(1)', 'version' => 'char(1)', 'scope' => 'varchar(24)'],
                'indexes' => ['PRIMARY' => [true, ['id']]]],
            'credits' => ['columns' => ['lot_id' => 'char(36)', 'member_faluss_id' => 'char(36)',
                'evidence_id' => 'char(36)', 'source_revision' => 'bigint unsigned', 'purchased_pf' => 'bigint unsigned',
                'immutable_sha256' => 'char(64)', 'policy_version' => 'varchar(32)', 'ledger_entry_uuid' => 'char(36)',
                'accepted_at' => 'datetime(6)'],
                'indexes' => ['PRIMARY' => [true, ['lot_id']], 'ledger_entry' => [true, ['ledger_entry_uuid']],
                    'member_order' => [false, ['member_faluss_id', 'accepted_at', 'lot_id']]]],
            'reservations' => ['columns' => ['attribution_id' => 'char(36)', 'reservation_id' => 'char(36)',
                'client_authority' => 'varchar(64)', 'member_faluss_id' => 'char(36)', 'creator_faluss_id' => 'char(36)',
                'purchased_pf' => 'bigint unsigned', 'payload_sha256' => 'char(64)', 'payload_json' => 'longtext',
                'reserve_key_sha256' => 'char(64)', 'state' => 'varchar(16)', 'created_at' => 'datetime(6)',
                'expires_at' => 'datetime(6)'],
                'indexes' => ['PRIMARY' => [true, ['attribution_id']], 'reservation' => [true, ['reservation_id']],
                    'member_state' => [false, ['member_faluss_id', 'state', 'expires_at']],
                    'member_created' => [false, ['member_faluss_id', 'created_at']]]],
            'allocations' => ['columns' => ['attribution_id' => 'char(36)', 'lot_id' => 'char(36)',
                'evidence_id' => 'char(36)', 'source_revision' => 'bigint unsigned', 'purchased_pf' => 'bigint unsigned'],
                'indexes' => ['PRIMARY' => [true, ['attribution_id', 'lot_id']], 'lot' => [false, ['lot_id']]]],
            'keys' => ['columns' => ['authority_id' => 'varchar(64)', 'operation' => 'varchar(16)', 'key_sha256' => 'char(64)',
                'payload_sha256' => 'char(64)', 'record_id' => 'char(36)', 'recorded_at' => 'datetime(6)'],
                'indexes' => ['PRIMARY' => [true, ['authority_id', 'operation', 'key_sha256']]]],
        ];
    }
}
