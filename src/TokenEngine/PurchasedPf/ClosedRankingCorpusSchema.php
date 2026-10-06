<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;

/** Exhaustive corpus metadata only; explicit isolated install, no historical table adoption. */
final class ClosedRankingCorpusSchema
{
    public const VERSION = '1';
    public const SCOPE = 'closed_b3_corpus';

    /** @return array<string,string> */
    public static function tables(\wpdb $db): array
    {
        if (preg_match('/^[A-Za-z0-9_]{1,20}$/D',$db->prefix) !== 1) { throw new ModelViolation('invalid_table_prefix'); }
        $tables = [];
        foreach (array_keys(self::definitions()) as $kind) { $tables[$kind] = $db->prefix . 'token_engine_pf_b3c_' . $kind; }
        return $tables;
    }

    /** @return array<string,array{columns:array<string,string>,indexes:array<string,array{bool,list<string>}>}> */
    public static function definitions(): array
    {
        return [
            'schema' => ['columns' => ['id' => 'bigint unsigned','version' => 'varchar(16)','scope' => 'varchar(64)'], 'indexes' => ['PRIMARY' => [true,['id']]]],
            'counter' => ['columns' => ['id' => 'bigint unsigned','epoch' => 'char(36)','revision' => 'bigint unsigned'], 'indexes' => ['PRIMARY' => [true,['id']]]],
            'corpora' => ['columns' => ['corpus_id' => 'char(36)','client_authority' => 'varchar(64)','origin_id' => 'char(36)','policy_version' => 'varchar(16)',
                'key_sha256' => 'char(64)','epoch' => 'char(36)','revision' => 'bigint unsigned','manifest_json' => 'longtext','manifest_sha256' => 'char(64)',
                'full_sha256' => 'char(64)','facts_json' => 'longtext','recorded_at' => 'datetime(6)'],
                'indexes' => ['PRIMARY' => [true,['corpus_id']], 'revision' => [true,['revision']], 'owner_key' => [true,['client_authority','key_sha256']]]],
            'pages' => ['columns' => ['corpus_id' => 'char(36)','page_index' => 'bigint unsigned','cursor' => 'char(36)','next_cursor' => '?char(36)',
                'payload_json' => 'longtext','payload_sha256' => 'char(64)'],
                'indexes' => ['PRIMARY' => [true,['corpus_id','page_index']], 'cursor' => [true,['cursor']]]],
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
        if (!ClosedRankingSchema::ready($db) || !ClosedSnapshotSchema::ready($db) || !ClosedRankedSnapshotSchema::ready($db)) { throw new ModelViolation('pf_corpus_dependency_unavailable'); }
        if ((string) $db->get_var('SELECT @@in_transaction') !== '0' || self::failed()) { throw new ModelViolation('nested_transaction_refused'); }
        $tables = self::tables($db); $lock = 'pf_b3_corpus_schema_' . substr(hash('sha256',$db->prefix),0,24); $temporary = [];
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
            if ($db->insert($tables['schema'] . $suffix,['id' => '1','version' => self::VERSION,'scope' => self::SCOPE]) !== 1
                || $db->insert($tables['counter'] . $suffix,['id' => '1','epoch' => ModelValues::uuid(wp_generate_uuid4()),'revision' => '0']) !== 1) { throw new ModelViolation('model_schema_unavailable'); }
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
