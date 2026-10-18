<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\PfContract;

use Faluss\Platform\TokenEngine\PurchasedPf\ClosedProtocolSchema;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;

/** Source references only: no PF quantity, balance, ledger or ordinary message content. */
final class ClosedPurchasedThanksSchema
{
    public const VERSION = '1';
    public const SCOPE = 'closed_fans_purchased_thanks';

    /** @return array<string,string> */
    public static function tables(\wpdb $db): array
    {
        if (preg_match('/^[A-Za-z0-9_]{1,20}$/D',$db->prefix) !== 1) { throw new ModelViolation('invalid_table_prefix'); }
        return ['schema' => $db->prefix . 'fans_pf_thanks_schema','bindings' => $db->prefix . 'fans_pf_thanks_bindings'];
    }

    /** @return array<string,array{columns:array<string,string>,indexes:array<string,array{bool,list<string>}>}> */
    public static function definitions(): array
    {
        return [
            'schema' => ['columns' => ['id' => 'bigint unsigned','version' => 'varchar(16)','scope' => 'varchar(64)'],'indexes' => ['PRIMARY' => [true,['id']]]],
            'bindings' => ['columns' => ['event_key' => 'char(64)','attribution_id' => 'char(36)','origin_id' => 'char(36)',
                'member_faluss_id' => 'char(36)','creator_faluss_id' => 'char(36)','receipt_id' => 'char(36)',
                'receipt_sha256' => 'char(64)','context_sha256' => 'char(64)','consumption_order' => 'bigint unsigned',
                'ordering_epoch' => 'char(36)','state' => 'varchar(16)','created_at' => 'datetime(6)'],
                'indexes' => ['PRIMARY' => [true,['event_key']],'attribution' => [true,['attribution_id']]]],
        ];
    }

    public static function ready(\wpdb $db): bool
    {
        ClosedEnvironment::assertIsolated($db,'fans'); $tables = self::tables($db);
        foreach (self::definitions() as $kind => $definition) {
            if (self::scalar($db,$db->prepare('SHOW TABLES LIKE %s',$db->esc_like($tables[$kind]))) !== $tables[$kind]
                || !ClosedProtocolSchema::verifyMetadata($db,$tables[$kind],$definition)) { return false; }
        }
        return $db->get_results($db->prepare('SELECT id,version,scope FROM %i',$tables['schema']),'ARRAY_A')
            === [['id' => '1','version' => self::VERSION,'scope' => self::SCOPE]] && $db->last_error === '';
    }

    public static function installed(\wpdb $db): bool
    {
        ClosedEnvironment::assertIsolated($db,'fans'); $tables = self::tables($db); $found = false;
        foreach ($tables as $table) { $found = self::scalar($db,$db->prepare('SHOW TABLES LIKE %s',$db->esc_like($table))) === $table || $found; }
        if ($found && !self::ready($db)) { throw new ModelViolation('pf_thanks_schema_unavailable'); }
        return $found;
    }

    public static function installForRecipe(\wpdb $db): void
    {
        ClosedEnvironment::assertIsolated($db,'fans');
        if (!ClosedRankedProtocolSchema::ready($db)) { throw new ModelViolation('pf_protocol_schema_unavailable'); }
        if ((string) self::scalar($db,'SELECT @@in_transaction') !== '0') { throw new ModelViolation('nested_transaction_refused'); }
        $tables = self::tables($db); $lock = 'fans_pf_thanks_schema_' . substr(hash('sha256',$db->prefix),0,24); $temporary = [];
        if ((string) self::scalar($db,$db->prepare('SELECT GET_LOCK(%s,10)',$lock)) !== '1') { throw new ModelViolation('pf_local_storage_unavailable'); }
        try {
            if (self::installed($db)) { return; }
            $suffix = '_new_' . bin2hex(random_bytes(4));
            foreach (self::definitions() as $kind => $definition) {
                $table = $tables[$kind] . $suffix;
                if ($db->query(ClosedProtocolSchema::metadataSql($table,$definition)) === false) { throw new ModelViolation('pf_local_storage_unavailable'); }
                $temporary[] = $table;
                if (!ClosedProtocolSchema::verifyMetadata($db,$table,$definition)) { throw new ModelViolation('model_schema_divergent'); }
            }
            if ($db->insert($tables['schema'] . $suffix,['id' => '1','version' => self::VERSION,'scope' => self::SCOPE]) !== 1) { throw new ModelViolation('pf_local_storage_unavailable'); }
            $renames = array_map(static fn (string $table): string => $db->prepare('%i TO %i',$table . $suffix,$table),array_values($tables));
            if ($db->query('RENAME TABLE ' . implode(',',$renames)) === false || !self::ready($db)) { throw new ModelViolation('model_schema_divergent'); }
        } finally {
            foreach ($temporary as $table) { $db->query($db->prepare('DROP TABLE IF EXISTS %i',$table)); }
            if ((string) self::scalar($db,$db->prepare('SELECT RELEASE_LOCK(%s)',$lock)) !== '1') { throw new ModelViolation('pf_local_commit_unknown'); }
        }
    }

    private static function scalar(\wpdb $db, string $query): mixed
    { $value = $db->get_var($query); if ($db->last_error !== '') { throw new ModelViolation('pf_local_storage_unavailable'); } return $value; }
}
