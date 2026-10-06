<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;

/** Hub admission metadata only; no quantity, balance, economic ledger or automatic migration. */
final class ClosedBarrierSchema
{
    public const VERSION = '1';
    public const SCOPE = 'closed_b3_barriers';

    /** @return array<string,string> */
    public static function tables(\wpdb $db): array
    {
        if (preg_match('/^[A-Za-z0-9_]{1,20}$/D',$db->prefix) !== 1) { throw new ModelViolation('invalid_table_prefix'); }
        $tables = [];
        foreach (array_keys(self::definitions()) as $kind) { $tables[$kind] = $db->prefix . 'token_engine_pf_b3b_' . $kind; }
        return $tables;
    }

    /** @return array<string,array{columns:array<string,string>,indexes:array<string,array{bool,list<string>}>}> */
    public static function definitions(): array
    {
        return [
            'schema' => ['columns' => ['id' => 'bigint unsigned','version' => 'varchar(16)','scope' => 'varchar(64)'], 'indexes' => ['PRIMARY' => [true,['id']]]],
            'barriers' => ['columns' => ['barrier_key' => 'char(64)','version' => 'bigint unsigned','owner' => 'varchar(64)',
                'state' => 'varchar(8)','content_sha256' => 'char(64)','descriptor_json' => 'longtext','descriptor_sha256' => 'char(64)',
                'opened_at' => 'datetime(6)','closed_at' => 'varchar(26)'], 'indexes' => ['PRIMARY' => [true,['barrier_key','version']]]],
            'operations' => ['columns' => ['owner' => 'varchar(64)','operation' => 'varchar(8)','key_sha256' => 'char(64)',
                'request_sha256' => 'char(64)','payload_json' => 'longtext','payload_sha256' => 'char(64)','recorded_at' => 'datetime(6)'],
                'indexes' => ['PRIMARY' => [true,['owner','operation','key_sha256']]]],
            'events' => ['columns' => ['event_id' => 'char(36)','barrier_key' => 'char(64)','version' => 'bigint unsigned',
                'owner' => 'varchar(64)','operation' => 'varchar(8)','key_sha256' => 'char(64)','occurred_at' => 'datetime(6)'],
                'indexes' => ['PRIMARY' => [true,['event_id']], 'operation_key' => [true,['owner','operation','key_sha256']]]],
        ];
    }

    public static function ready(\wpdb $db): bool
    {
        ClosedEnvironment::assertIsolated($db,'hub'); $tables = self::tables($db);
        foreach (self::definitions() as $kind => $definition) {
            if (!self::exists($db,$tables[$kind]) || !ClosedProtocolSchema::verifyMetadata($db,$tables[$kind],$definition)) { return false; }
        }
        return $db->get_results($db->prepare('SELECT id,version,scope FROM %i',$tables['schema']),'ARRAY_A')
            === [['id' => '1','version' => self::VERSION,'scope' => self::SCOPE]] && $db->last_error === '';
    }

    public static function installForRecipe(\wpdb $db): void
    {
        ClosedEnvironment::assertIsolated($db,'hub');
        if (!ClosedProtocolSchema::ready($db)) { throw new ModelViolation('pf_protocol_schema_unavailable'); }
        if ((string) $db->get_var('SELECT @@in_transaction') !== '0' || self::failed()) { throw new ModelViolation('nested_transaction_refused'); }
        $tables = self::tables($db); $lock = 'pf_b3_barrier_schema_' . substr(hash('sha256',$db->prefix),0,24); $temporary = [];
        if ((string) $db->get_var($db->prepare('SELECT GET_LOCK(%s,10)',$lock)) !== '1' || self::failed()) { throw new ModelViolation('model_lock_unavailable'); }
        try {
            $found = array_filter($tables,static fn (string $table): bool => self::exists($db,$table));
            if ($found !== []) {
                if (count($found) !== count($tables) || !self::ready($db)) { throw new ModelViolation('model_schema_divergent'); }
                return;
            }
            $suffix = '_new_' . bin2hex(random_bytes(4));
            foreach (self::definitions() as $kind => $definition) {
                $table = $tables[$kind] . $suffix;
                if ($db->query(ClosedProtocolSchema::metadataSql($table,$definition)) === false) { throw new ModelViolation('model_schema_unavailable'); }
                $temporary[] = $table;
                if (!ClosedProtocolSchema::verifyMetadata($db,$table,$definition)) { throw new ModelViolation('model_schema_divergent'); }
            }
            if ($db->insert($tables['schema'] . $suffix,['id' => '1','version' => self::VERSION,'scope' => self::SCOPE]) !== 1) { throw new ModelViolation('model_schema_unavailable'); }
            $renames = array_map(static fn (string $table): string => $db->prepare('%i TO %i',$table . $suffix,$table),array_values($tables));
            if ($db->query('RENAME TABLE ' . implode(',',$renames)) === false || !self::ready($db)) { throw new ModelViolation('model_schema_divergent'); }
        } finally {
            foreach ($temporary as $table) { $db->query($db->prepare('DROP TABLE IF EXISTS %i',$table)); }
            if ((string) $db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)',$lock)) !== '1') { throw new ModelViolation('model_lock_release_unknown'); }
        }
    }

    private static function exists(\wpdb $db, string $table): bool
    {
        $found = $db->get_var($db->prepare('SHOW TABLES LIKE %s',$db->esc_like($table)));
        if ($db->last_error !== '') { throw new ModelViolation('model_schema_unavailable'); }
        return $found === $table;
    }

    /** wpdb queries update last_error on the same connection.
     * @phpstan-impure */
    private static function failed(): bool
    { global $wpdb; return $wpdb->last_error !== ''; }
}
