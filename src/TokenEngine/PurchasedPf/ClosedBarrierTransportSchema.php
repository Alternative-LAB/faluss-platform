<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;

/** Dedicated barrier transport nonces. No member identity, economic entry or automatic migration. */
final class ClosedBarrierTransportSchema
{
    public const VERSION = '1';
    public const SCOPE = 'closed_b3_barrier_http';

    /** @return array<string,string> */
    public static function tables(\wpdb $db): array
    {
        if (preg_match('/^[A-Za-z0-9_]{1,20}$/D',$db->prefix) !== 1) { throw new ModelViolation('invalid_table_prefix'); }
        return ['schema' => $db->prefix . 'token_engine_pf_b3bh_schema','nonces' => $db->prefix . 'token_engine_pf_b3bh_nonces'];
    }

    /** @return array<string,array{columns:array<string,string>,indexes:array<string,array{bool,list<string>}>}> */
    public static function definitions(): array
    {
        return [
            'schema' => ['columns' => ['id' => 'bigint unsigned','version' => 'varchar(16)','scope' => 'varchar(64)'],'indexes' => ['PRIMARY' => [true,['id']]]],
            'nonces' => ['columns' => ['peer' => 'varchar(64)','nonce_sha256' => 'char(64)','request_sha256' => 'char(64)',
                'action_id' => 'char(36)','origin_id' => 'char(36)','policy_version' => 'varchar(32)','issued_at' => 'varchar(20)','expires_at' => 'varchar(20)','admitted_at' => 'datetime(6)'],
                'indexes' => ['PRIMARY' => [true,['peer','nonce_sha256']]]],
        ];
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
        if (!ClosedBarrierSchema::ready($db)) { throw new ModelViolation('pf_barrier_dependency_unavailable'); }
        if ((string) $db->get_var('SELECT @@in_transaction') !== '0' || self::failed()) { throw new ModelViolation('nested_transaction_refused'); }
        $tables = self::tables($db); $lock = 'pf_b3_barrier_http_schema_' . substr(hash('sha256',$db->prefix),0,24); $temporary = [];
        if ((string) $db->get_var($db->prepare('SELECT GET_LOCK(%s,10)',$lock)) !== '1' || self::failed()) { throw new ModelViolation('model_lock_unavailable'); }
        try {
            $existing = [];
            foreach ($tables as $table) {
                $found = $db->get_var($db->prepare('SHOW TABLES LIKE %s',$db->esc_like($table)));
                if (self::failed()) { throw new ModelViolation('model_schema_unavailable'); }
                if ($found === $table) { $existing[] = $table; }
            }
            if ($existing !== []) {
                if (count($existing) !== count($tables) || !self::ready($db)) { throw new ModelViolation('model_schema_divergent'); }
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

    /** @phpstan-impure */
    private static function failed(): bool { global $wpdb; return $wpdb->last_error !== ''; }
}
