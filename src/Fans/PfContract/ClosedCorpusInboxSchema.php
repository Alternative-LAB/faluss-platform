<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\PfContract;

use Faluss\Platform\TokenEngine\PurchasedPf\ClosedProtocolSchema;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;

/** Private owner proof staging only. No ledger, browser route or automatic installation. */
final class ClosedCorpusInboxSchema
{
    public const VERSION = '1';
    public const SCOPE = 'closed_b3_corpus_fans';

    /** @return array<string,string> */
    public static function tables(\wpdb $db): array
    {
        if (preg_match('/^[A-Za-z0-9_]{1,20}$/D',$db->prefix) !== 1) { throw new ModelViolation('invalid_table_prefix'); }
        $tables = [];
        foreach (array_keys(self::definitions()) as $kind) { $tables[$kind] = $db->prefix . 'fans_pf_b3c_' . $kind; }
        return $tables;
    }

    /** @return array<string,array{columns:array<string,string>,indexes:array<string,array{bool,list<string>}>}> */
    public static function definitions(): array
    {
        return [
            'schema' => ['columns' => ['id' => 'bigint unsigned','version' => 'varchar(16)','scope' => 'varchar(64)'],'indexes' => ['PRIMARY' => [true,['id']]]],
            'reads' => ['columns' => ['read_id' => 'char(36)','origin_id' => 'char(36)','policy_version' => 'varchar(32)','read_key' => 'char(64)',
                'phase' => 'varchar(16)','progress_json' => 'longtext','progress_sha256' => 'char(64)'],
                'indexes' => ['PRIMARY' => [true,['read_id']],'read_key' => [true,['read_key']],'pending' => [false,['origin_id','policy_version','phase']]]],
            'requests' => ['columns' => ['read_id' => 'char(36)','nonce_sha256' => 'char(64)','request_sha256' => 'char(64)',
                'fields_json' => 'longtext','wire_json' => 'longtext'],'indexes' => ['PRIMARY' => [true,['read_id','nonce_sha256']]]],
            'pages' => ['columns' => ['read_id' => 'char(36)','page_index' => 'bigint unsigned','payload_json' => 'longtext',
                'payload_sha256' => 'char(64)','proof_json' => 'longtext','proof_sha256' => 'char(64)'],
                'indexes' => ['PRIMARY' => [true,['read_id','page_index']]]],
            'current' => ['columns' => ['origin_id' => 'char(36)','policy_version' => 'varchar(32)','read_id' => 'char(36)',
                'epoch' => 'char(36)','ordering_epoch' => 'char(36)','revision' => 'bigint unsigned','state' => 'varchar(16)',
                'manifest_json' => 'longtext','facts_json' => 'longtext','full_sha256' => 'char(64)',
                'proof_json' => 'longtext','proof_sha256' => 'char(64)','verified_at' => 'datetime(6)'],
                'indexes' => ['PRIMARY' => [true,['origin_id','policy_version']]]],
        ];
    }

    public static function ready(\wpdb $db): bool
    {
        ClosedEnvironment::assertIsolated($db,'fans'); $tables = self::tables($db);
        foreach (self::definitions() as $kind => $definition) {
            $found = $db->get_var($db->prepare('SHOW TABLES LIKE %s',$db->esc_like($tables[$kind])));
            if (self::failed()) { throw new ModelViolation('pf_local_corpus_storage'); }
            if ($found !== $tables[$kind] || !ClosedProtocolSchema::verifyMetadata($db,$tables[$kind],$definition)) { return false; }
        }
        return $db->get_results($db->prepare('SELECT id,version,scope FROM %i',$tables['schema']),'ARRAY_A')
            === [['id' => '1','version' => self::VERSION,'scope' => self::SCOPE]] && !self::failed();
    }

    public static function installForRecipe(\wpdb $db): void
    {
        ClosedEnvironment::assertIsolated($db,'fans');
        if ((string) $db->get_var('SELECT @@in_transaction') !== '0' || self::failed()) { throw new ModelViolation('nested_transaction_refused'); }
        $tables = self::tables($db); $lock = 'fans_pf_b3_corpus_schema_' . substr(hash('sha256',$db->prefix),0,24); $temporary = [];
        if ((string) $db->get_var($db->prepare('SELECT GET_LOCK(%s,10)',$lock)) !== '1' || self::failed()) { throw new ModelViolation('pf_local_corpus_busy'); }
        try {
            $existing = [];
            foreach ($tables as $table) {
                $found = $db->get_var($db->prepare('SHOW TABLES LIKE %s',$db->esc_like($table)));
                if (self::failed()) { throw new ModelViolation('pf_local_corpus_storage'); }
                if ($found === $table) { $existing[] = $table; }
            }
            if ($existing !== []) {
                if (count($existing) !== count($tables) || !self::ready($db)) { throw new ModelViolation('model_schema_divergent'); }
                return;
            }
            $suffix = '_new_' . bin2hex(random_bytes(4));
            foreach (self::definitions() as $kind => $definition) {
                $table = $tables[$kind] . $suffix;
                if ($db->query(ClosedProtocolSchema::metadataSql($table,$definition)) === false) { throw new ModelViolation('pf_local_corpus_storage'); }
                $temporary[] = $table;
                if (!ClosedProtocolSchema::verifyMetadata($db,$table,$definition)) { throw new ModelViolation('model_schema_divergent'); }
            }
            if ($db->insert($tables['schema'] . $suffix,['id' => '1','version' => self::VERSION,'scope' => self::SCOPE]) !== 1) { throw new ModelViolation('pf_local_corpus_storage'); }
            $renames = array_map(static fn (string $table): string => $db->prepare('%i TO %i',$table . $suffix,$table),array_values($tables));
            if ($db->query('RENAME TABLE ' . implode(',',$renames)) === false || !self::ready($db)) { throw new ModelViolation('model_schema_divergent'); }
        } finally {
            foreach ($temporary as $table) { $db->query($db->prepare('DROP TABLE IF EXISTS %i',$table)); }
            if ((string) $db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)',$lock)) !== '1') { throw new ModelViolation('pf_local_corpus_commit_unknown'); }
        }
    }

    /** @phpstan-impure */
    private static function failed(): bool { global $wpdb; return $wpdb->last_error !== ''; }
}
