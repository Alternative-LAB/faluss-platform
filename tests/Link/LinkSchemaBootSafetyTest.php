<?php

declare(strict_types=1);

namespace Faluss\Platform\Link;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class LinkSchemaBootSafetyTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFrontendBootNeverRunsV4DdlOrBackfill(): void
    {
        $this->assertOrdinaryBootIsReadOnly(false);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPrivilegedAdminBootNeverRunsV4DdlOrBackfill(): void
    {
        $this->assertOrdinaryBootIsReadOnly(true);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOrdinaryBootDoesNotFinalizeAV4StructureWithoutTheExplicitVersionMarker(): void
    {
        link_test_reset();
        $database = new LinkV3WpdbSpy();
        $database->schemaState = 'v4';
        $GLOBALS['wpdb'] = $database;
        $GLOBALS['link_test_options']['faluss_link_schema_version'] = '3';

        (new LinkModule())->boot();

        self::assertTrue(\Faluss_Link_Schema::maybe_install());
        self::assertFalse(\Faluss_Link_Schema::composition_ready());
        self::assertSame([], $GLOBALS['link_test_option_updates']);
        self::assertSame([], $database->writes);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMigrationActionRejectsARequestWithoutManageOptionsBeforeAnySchemaMutation(): void
    {
        $database = $this->bootAdminOnV3(false);
        $_POST = ['faluss_link_migrate_v4_nonce' => 'valid'];
        $GLOBALS['link_test_nonce_valid'] = true;

        try {
            $this->migrationCallback()();
            self::fail('The migration action should reject an unauthorized request.');
        } catch (\LinkTestWpDie $exception) {
            self::assertSame('Accès refusé.', $exception->getMessage());
        }

        self::assertSame([], $database->writes);
        self::assertSame('v3', $database->schemaState);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAuthorizedMigrationActionPromotesV3AndRedirectsToSuccess(): void
    {
        $database = $this->bootAdminOnV3(true);
        $_POST = ['faluss_link_migrate_v4_nonce' => 'valid'];
        $GLOBALS['link_test_nonce_valid'] = true;

        try {
            $this->migrationCallback()();
            self::fail('The migration action should end with a redirect.');
        } catch (\LinkTestRedirect $exception) {
            self::assertStringEndsWith('migration_v4=success', $exception->getMessage());
        }

        self::assertSame('v4', $database->schemaState);
        self::assertSame('4', $GLOBALS['link_test_options']['faluss_link_schema_version']);
        self::assertTrue(\Faluss_Link_Schema::composition_ready());
        self::assertCount(2, array_filter($database->writes, static fn (string $sql): bool => str_contains($sql, 'ALTER TABLE')));
    }

    private function assertOrdinaryBootIsReadOnly(bool $admin): void
    {
        $database = $this->bootOnV3($admin, $admin);

        self::assertTrue(\Faluss_Link_Schema::maybe_install());
        self::assertFalse(\Faluss_Link_Schema::composition_ready());
        self::assertFalse(LinkStudioContract::available());
        self::assertArrayHasKey('faluss_link_studio', $GLOBALS['link_test_shortcodes']);
        if ($admin) {
            self::assertArrayHasKey('admin_post_faluss_link_migrate_v4', $GLOBALS['link_test_actions']);
        } else {
            self::assertArrayNotHasKey('admin_post_faluss_link_migrate_v4', $GLOBALS['link_test_actions']);
        }
        self::assertSame([], $GLOBALS['link_test_option_updates']);

        $sql = implode("\n", array_merge($database->reads, $database->writes));
        self::assertStringNotContainsString('ALTER TABLE', strtoupper($sql));
        self::assertStringNotContainsString('SELECT FALUSS_ID,SOCIAL_LINKS,COMPOSITION', strtoupper($sql));
        self::assertStringNotContainsString(' SET COMPOSITION=', strtoupper($sql));
        self::assertStringNotContainsString('GET_LOCK', strtoupper($sql));
    }

    private function bootAdminOnV3(bool $manageOptions): LinkV3WpdbSpy
    {
        return $this->bootOnV3(true, $manageOptions);
    }

    private function bootOnV3(bool $admin, bool $manageOptions): LinkV3WpdbSpy
    {
        link_test_reset();
        $database = new LinkV3WpdbSpy();
        $GLOBALS['wpdb'] = $database;
        $GLOBALS['link_test_is_admin'] = $admin;
        $GLOBALS['link_test_manage_options'] = $manageOptions;
        $GLOBALS['link_test_options']['faluss_link_schema_version'] = '3';
        (new LinkModule())->boot();

        return $database;
    }

    private function migrationCallback(): callable
    {
        $registrations = $GLOBALS['link_test_actions']['admin_post_faluss_link_migrate_v4'] ?? [];
        self::assertCount(1, $registrations);

        return $registrations[0]['callback'];
    }
}

final class LinkV3WpdbSpy
{
    public string $prefix = 'wp_';
    public string $schemaState = 'v3';

    /** @var list<string> */
    public array $reads = [];

    /** @var list<string> */
    public array $writes = [];

    public function prepare(string $query, mixed ...$arguments): string
    {
        return $query . ' /* ' . implode(', ', array_map('strval', $arguments)) . ' */';
    }

    public function get_var(string $query): mixed
    {
        $this->reads[] = $query;
        if (str_contains($query, 'SHOW TABLES LIKE')) {
            return 'exists';
        }
        if (str_contains($query, 'GET_LOCK') || str_contains($query, 'RELEASE_LOCK')) {
            return 1;
        }

        return null;
    }

    /** @return array<string, string> */
    public function get_row(string $query, mixed $format = null): array
    {
        unset($format);
        $this->reads[] = $query;

        return ['Engine' => 'InnoDB'];
    }

    /** @return list<array<string, string>> */
    public function get_results(string $query, mixed $format = null): array
    {
        unset($format);
        $this->reads[] = $query;
        if (str_contains($query, 'SHOW FULL COLUMNS')) {
            return $this->columns($query);
        }
        if (str_contains($query, 'SHOW INDEX')) {
            return array_map(
                static fn (string $name): array => ['Key_name' => $name],
                $this->indexes($query)
            );
        }

        return [];
    }

    public function query(string $query): int|false
    {
        $this->writes[] = $query;
        if (str_contains($query, 'ADD `composition`')) {
            $this->schemaState = 'partial';
        } elseif (str_contains($query, 'MODIFY `composition`')) {
            $this->schemaState = 'v4';
        }

        return 1;
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4';
    }

    /** @return list<array{Field: string, Type: string, Null: string}> */
    private function columns(string $query): array
    {
        $columns = match (true) {
            str_contains($query, 'faluss_link_cards') => $this->cardColumns(),
            str_contains($query, 'faluss_link_blocks') => [
                'id' => 'bigint(20) unsigned', 'faluss_id' => 'char(36)', 'block_id' => 'char(36)',
                'sort_order' => 'smallint(5) unsigned', 'block_type' => 'varchar(20)', 'payload' => 'longtext',
                'created_at' => 'datetime', 'updated_at' => 'datetime',
            ],
            str_contains($query, 'faluss_link_discoveries') => [
                'id' => 'bigint(20) unsigned', 'viewer_faluss_id' => 'char(36)', 'discovered_faluss_id' => 'char(36)',
                'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'view_count' => 'int(10) unsigned',
            ],
            str_contains($query, 'faluss_link_discovery_settings') => [
                'id' => 'bigint(20) unsigned', 'viewer_faluss_id' => 'char(36)',
                'recording_enabled' => 'tinyint(1)', 'updated_at' => 'datetime',
            ],
            default => [],
        };

        return array_map(
            fn (string $field, string $type): array => [
                'Field' => $field,
                'Type' => $type,
                'Null' => $field === 'composition' && $this->schemaState === 'partial' ? 'YES' : 'NO',
            ],
            array_keys($columns),
            array_values($columns)
        );
    }

    /** @return array<string, string> */
    private function cardColumns(): array
    {
        $columns = [
                'id' => 'bigint(20) unsigned', 'faluss_id' => 'char(36)', 'cover_attachment_id' => 'bigint(20) unsigned',
                'avatar_visible' => 'tinyint(1)', 'name_weight' => 'varchar(20)', 'name_treatment' => 'varchar(20)',
                'available' => 'tinyint(1)', 'bio_mode' => 'varchar(20)', 'announcement' => 'varchar(120)',
                'announcement_variant' => 'varchar(20)', 'social_links' => 'longtext', 'social_layout' => 'varchar(20)',
                'link_style' => 'varchar(20)',
        ];
        if ($this->schemaState !== 'v3') {
            $columns['composition'] = 'longtext';
        }
        $columns['created_at'] = 'datetime';
        $columns['updated_at'] = 'datetime';

        return $columns;
    }

    /** @return list<string> */
    private function indexes(string $query): array
    {
        return match (true) {
            str_contains($query, 'faluss_link_cards') => ['PRIMARY', 'faluss_id_unique'],
            str_contains($query, 'faluss_link_blocks') => ['PRIMARY', 'faluss_block_id', 'faluss_block_order'],
            str_contains($query, 'faluss_link_discoveries') => ['PRIMARY', 'faluss_discovery_pair', 'faluss_discovery_recent'],
            str_contains($query, 'faluss_link_discovery_settings') => ['PRIMARY', 'faluss_discovery_viewer'],
            default => [],
        };
    }
}
