<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ClosedProtocolSchema;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;

/** Private immutable B2 lifecycle bindings; no economic storage or automatic installation. */
final class ClosedSessionLifecycleSchema
{
    public const VERSION = '1';
    public const SCOPE = 'closed_fans_hof_b3_sessions';

    /** @return array<string,string> */
    public static function tables(\wpdb $db): array
    {
        if (preg_match('/^[A-Za-z0-9_]{1,20}$/D',$db->prefix) !== 1) { throw new ModelViolation('invalid_table_prefix'); }
        $tables = [];
        foreach (array_keys(self::definitions()) as $kind) { $tables[$kind] = $db->prefix . 'fans_hof_b3_' . $kind; }
        return $tables;
    }

    /** @return array<string,array{columns:array<string,string>,indexes:array<string,array{bool,list<string>}>}> */
    public static function definitions(): array
    {
        return [
            'schema' => ['columns' => ['id' => 'bigint unsigned','version' => 'varchar(16)','scope' => 'varchar(64)'],'indexes' => ['PRIMARY' => [true,['id']]]],
            'bindings' => ['columns' => ['session_id' => 'char(36)','barrier_version' => 'bigint unsigned','operation' => 'varchar(16)',
                'action_id' => 'char(36)','contract' => 'varchar(96)','fields_json' => 'longtext','fields_sha256' => 'char(64)',
                'applied_at' => 'varchar(26)'],
                'indexes' => ['PRIMARY' => [true,['session_id','barrier_version','operation']],'action' => [true,['action_id']]]],
        ];
    }

    public static function ready(\wpdb $db): bool
    {
        ClosedEnvironment::assertIsolated($db,'fans'); $tables = self::tables($db);
        if (!SessionSchema::ready($db)) { return false; }
        foreach (self::definitions() as $kind => $definition) {
            $found = self::scalar($db,$db->prepare('SHOW TABLES LIKE %s',$db->esc_like($tables[$kind])));
            if ($found !== $tables[$kind] || !ClosedProtocolSchema::verifyMetadata($db,$tables[$kind],$definition)) { return false; }
        }
        return $db->get_results($db->prepare('SELECT id,version,scope FROM %i',$tables['schema']),'ARRAY_A')
            === [['id' => '1','version' => self::VERSION,'scope' => self::SCOPE]] && !($db->last_error !== '');
    }

    public static function installForRecipe(\wpdb $db): void
    {
        ClosedEnvironment::assertIsolated($db,'fans');
        if (!SessionSchema::ready($db)) { throw new ModelViolation('hof_session_schema_unavailable'); }
        if ((string) self::scalar($db,'SELECT @@in_transaction') !== '0') { throw new ModelViolation('nested_transaction_refused'); }
        $tables = self::tables($db); $lock = 'fans_hof_b3_schema_' . substr(hash('sha256',$db->prefix),0,24); $temporary = [];
        if ((string) self::scalar($db,$db->prepare('SELECT GET_LOCK(%s,10)',$lock)) !== '1') { throw new ModelViolation('pf_local_barrier_busy'); }
        try {
            $existing = [];
            foreach ($tables as $table) {
                $found = self::scalar($db,$db->prepare('SHOW TABLES LIKE %s',$db->esc_like($table)));
                if ($found === $table) { $existing[] = $table; }
            }
            if ($existing !== []) {
                if (count($existing) !== count($tables) || !self::ready($db)) { throw new ModelViolation('model_schema_divergent'); }
                return;
            }
            $suffix = '_new_' . bin2hex(random_bytes(4));
            foreach (self::definitions() as $kind => $definition) {
                $table = $tables[$kind] . $suffix;
                if ($db->query(ClosedProtocolSchema::metadataSql($table,$definition)) === false) { throw new ModelViolation('pf_local_barrier_storage'); }
                $temporary[] = $table;
                if (!ClosedProtocolSchema::verifyMetadata($db,$table,$definition)) { throw new ModelViolation('model_schema_divergent'); }
            }
            if ($db->insert($tables['schema'] . $suffix,['id' => '1','version' => self::VERSION,'scope' => self::SCOPE]) !== 1) { throw new ModelViolation('pf_local_barrier_storage'); }
            $renames = array_map(static fn (string $table): string => $db->prepare('%i TO %i',$table . $suffix,$table),array_values($tables));
            if ($db->query('RENAME TABLE ' . implode(',',$renames)) === false || !self::ready($db)) { throw new ModelViolation('model_schema_divergent'); }
        } finally {
            foreach ($temporary as $table) { $db->query($db->prepare('DROP TABLE IF EXISTS %i',$table)); }
            if ((string) self::scalar($db,$db->prepare('SELECT RELEASE_LOCK(%s)',$lock)) !== '1') { throw new ModelViolation('pf_local_barrier_commit_unknown'); }
        }
    }

    private static function scalar(\wpdb $db, string $query): mixed
    {
        $value = $db->get_var($query);
        if ($db->last_error !== '') { throw new ModelViolation('pf_local_barrier_storage'); }
        return $value;
    }

}
