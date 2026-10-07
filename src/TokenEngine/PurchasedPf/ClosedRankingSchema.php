<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;

/** Closed B3 metadata joins the official owner transaction; never a PF balance or ledger. */
final class ClosedRankingSchema
{
    public const VERSION = '1';
    public const SCOPE = 'closed_b3_ranked';

    /** @return array<string,string> */
    public static function tables(\wpdb $db): array
    {
        if (preg_match('/^[A-Za-z0-9_]{1,20}$/D',$db->prefix) !== 1) { throw new ModelViolation('invalid_table_prefix'); }
        $tables = [];
        foreach (array_keys(self::definitions()) as $kind) { $tables[$kind] = $db->prefix . 'token_engine_pf_b3r_' . $kind; }
        return $tables;
    }

    /** @return array<string,array{columns:array<string,string>,indexes:array<string,array{bool,list<string>}>}> */
    public static function definitions(): array
    {
        return [
            'schema' => ['columns' => ['id' => 'bigint unsigned','version' => 'varchar(16)','scope' => 'varchar(64)'], 'indexes' => ['PRIMARY' => [true,['id']]]],
            'counter' => ['columns' => ['id' => 'bigint unsigned','ordering_epoch' => 'char(36)','last_order' => 'bigint unsigned','last_confirmed_at' => 'varchar(26)'],
                'indexes' => ['PRIMARY' => [true,['id']]]],
            'bindings' => ['columns' => ['attribution_id' => 'char(36)','member_faluss_id' => 'char(36)','client_authority' => 'varchar(64)',
                'base_sha256' => 'char(64)','ranked_sha256' => 'char(64)','payload_json' => 'longtext','created_at' => 'datetime(6)'],
                'indexes' => ['PRIMARY' => [true,['attribution_id']], 'member' => [false,['member_faluss_id','attribution_id']]]],
            'receipts' => ['columns' => ['attribution_id' => 'char(36)','receipt_id' => 'char(36)','ordering_epoch' => 'char(36)',
                'consumption_order' => 'bigint unsigned','confirmed_at' => 'datetime(6)','payload_json' => 'longtext',
                'payload_sha256' => 'char(64)','original_envelope_json' => 'longtext'],
                'indexes' => ['PRIMARY' => [true,['attribution_id']], 'receipt' => [true,['receipt_id']], 'owner_order' => [true,['consumption_order']]]],
            'journal' => ['columns' => ['event_id' => 'char(36)','attribution_id' => 'char(36)','payload_json' => 'longtext','payload_sha256' => 'char(64)',
                'state' => 'varchar(8)','recorded_at' => 'datetime(6)'],
                'indexes' => ['PRIMARY' => [true,['event_id']], 'attribution' => [true,['attribution_id']]]],
        ];
    }

    /** Detection alone does not install or require a new contract for historical attributions. */
    public static function present(\wpdb $db): bool
    {
        foreach (self::tables($db) as $table) {
            $found = $db->get_var($db->prepare('SHOW TABLES LIKE %s',$db->esc_like($table)));
            if (self::failed()) { throw new ModelViolation('model_schema_unavailable'); }
            if ($found === $table) { return true; }
        }
        return false;
    }

    public static function ready(\wpdb $db): bool
    {
        ClosedEnvironment::assertIsolated($db,'hub'); $tables = self::tables($db);
        foreach (self::definitions() as $kind => $definition) {
            $found = $db->get_var($db->prepare('SHOW TABLES LIKE %s',$db->esc_like($tables[$kind])));
            if (self::failed()) { throw new ModelViolation('model_schema_unavailable'); }
            if ($found !== $tables[$kind] || !ClosedProtocolSchema::verifyMetadata($db,$tables[$kind],$definition)) { return false; }
        }
        return $db->get_results($db->prepare('SELECT id,version,scope FROM %i',$tables['schema']),'ARRAY_A')
            === [['id' => '1','version' => self::VERSION,'scope' => self::SCOPE]] && !self::failed();
    }

    public static function installForRecipe(\wpdb $db): void
    {
        ClosedEnvironment::assertIsolated($db,'hub');
        if (!ClosedBarrierSchema::ready($db)) { throw new ModelViolation('pf_barrier_schema_unavailable'); }
        if ((string) $db->get_var('SELECT @@in_transaction') !== '0' || self::failed()) { throw new ModelViolation('nested_transaction_refused'); }
        $tables = self::tables($db); $lock = 'pf_b3_ranked_schema_' . substr(hash('sha256',$db->prefix),0,24); $temporary = [];
        if ((string) $db->get_var($db->prepare('SELECT GET_LOCK(%s,10)',$lock)) !== '1' || self::failed()) { throw new ModelViolation('model_lock_unavailable'); }
        try {
            if (self::present($db)) {
                if (!self::ready($db)) { throw new ModelViolation('model_schema_divergent'); }
                return;
            }
            $suffix = '_new_' . bin2hex(random_bytes(4));
            foreach (self::definitions() as $kind => $definition) {
                $table = $tables[$kind] . $suffix;
                if ($db->query(ClosedProtocolSchema::metadataSql($table,$definition)) === false) { throw new ModelViolation('model_schema_unavailable'); }
                $temporary[] = $table;
                if (!ClosedProtocolSchema::verifyMetadata($db,$table,$definition)) { throw new ModelViolation('model_schema_divergent'); }
            }
            if ($db->insert($tables['schema'] . $suffix,['id' => '1','version' => self::VERSION,'scope' => self::SCOPE]) !== 1
                || $db->insert($tables['counter'] . $suffix,['id' => '1','ordering_epoch' => ModelValues::uuid(wp_generate_uuid4()),'last_order' => '0','last_confirmed_at' => '']) !== 1) {
                throw new ModelViolation('model_schema_unavailable');
            }
            $renames = array_map(static fn (string $table): string => $db->prepare('%i TO %i',$table . $suffix,$table),array_values($tables));
            if ($db->query('RENAME TABLE ' . implode(',',$renames)) === false || !self::ready($db)) { throw new ModelViolation('model_schema_divergent'); }
        } finally {
            foreach ($temporary as $table) { $db->query($db->prepare('DROP TABLE IF EXISTS %i',$table)); }
            if ((string) $db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)',$lock)) !== '1') { throw new ModelViolation('model_lock_release_unknown'); }
        }
    }

    /** @phpstan-impure */
    private static function failed(): bool { global $wpdb; return $wpdb->last_error !== ''; }
}
