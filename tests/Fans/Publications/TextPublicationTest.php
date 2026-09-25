<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Publications;

use Faluss\Platform\Fans\Profiles\CreatorProfileDb;
use Faluss\Platform\Fans\Profiles\CreatorProfileSchema;
use Faluss\Platform\Fans\Sso\FansSsoSchema;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Profiles/CreatorProfileTest.php';

function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['text_options'][$key] ?? $default; }
function get_current_user_id(): int { return $GLOBALS['fans_sso_current_user']; }
function current_user_can(string $cap): bool { return $cap === 'manage_options' && $GLOBALS['profile_admin']; }
function wp_generate_uuid4(): string { return '22222222-2222-4222-8222-222222222222'; }

/** Transaction model for failure-path tests; real InnoDB behaviour is tested by the disposable recipe. */
final class TextPublicationDb extends CreatorProfileDb
{
    public ?array $publication = null;
    public array $audit = [];
    public array $publications = [];
    public array $profiles = [];
    public int $pageQueries = 0;
    public bool $failAudit = false;
    private ?array $snapshot = null;
    public bool $suppressed = false;
    public function suppress_errors(bool $value = true): bool
    {
        $previous = $this->suppressed; $this->suppressed = $value; return $previous;
    }

    public function get_results(string $query, mixed $output = null): array
    {
        if (str_starts_with($query, 'SELECT publication_id,updated_at FROM')) {
            ++$this->pageQueries;
            $args = end($this->prepared)['args'];
            $field = str_contains($query, 'WHERE creator_id=') ? 'creator_id' : 'state';
            $ascending = str_contains($query, 'updated_at ASC');
            $rows = array_values(array_filter($this->publications, static function ($row) use ($args, $field, $ascending) {
                if ($row[$field] !== $args[0]) { return false; }
                if (count($args) === 1) { return true; }
                $comparison = [$row['updated_at'], $row['publication_id']] <=> [$args[1], $args[3]];
                return $ascending ? $comparison > 0 : $comparison < 0;
            }));
            usort($rows, static fn ($a, $b) => ($ascending ? 1 : -1) * ([$a['updated_at'], $a['publication_id']] <=> [$b['updated_at'], $b['publication_id']]));
            return array_map(static fn ($row) => array_intersect_key($row, array_flip(['publication_id', 'updated_at'])), array_slice($rows, 0, 50));
        }
        $schema = null;
        foreach (FansSsoSchema::schema() as $definition) {
            if (str_contains($query, $definition['suffix'])) { $schema = $definition; }
        }
        if (str_contains($query, 'faluss_fans_text_')) {
            $audit = str_contains($query, 'text_decisions');
            $schema = ['columns' => array_map(static fn ($type) => ['type' => $type, 'null' => false], TextPublicationSchema::columns($audit)),
                'indexes' => $audit ? ['PRIMARY' => [true, ['publication_id', 'revision']]]
                    : ['PRIMARY' => [true, ['publication_id']], 'creator_id' => [false, ['creator_id']]]];
        }
        if ($schema !== null && str_starts_with($query, 'SHOW FULL COLUMNS')) {
            $rows = [];
            foreach ($schema['columns'] as $name => $column) {
                $rows[] = ['Field' => $name, 'Type' => $column['type'], 'Null' => $column['null'] ? 'YES' : 'NO', 'Extra' => !empty($column['auto']) ? 'auto_increment' : ''];
            }
            return $rows;
        }
        if ($schema !== null && str_starts_with($query, 'SHOW INDEX')) {
            $rows = [];
            foreach ($schema['indexes'] as $name => [$unique, $columns]) {
                foreach ($columns as $i => $column) { $rows[] = ['Key_name' => $name, 'Non_unique' => $unique ? '0' : '1', 'Seq_in_index' => $i + 1, 'Column_name' => $column]; }
            }
            return $rows;
        }
        return parent::get_results($query, $output);
    }
    public function get_row(string $query, mixed $output = null): ?array
    {
        if (str_starts_with($query, 'SELECT * FROM `wp_faluss_fans_text_publications')) {
            return $this->publications !== [] ? ($this->publications[end($this->prepared)['args'][0]] ?? null) : $this->publication;
        }
        if (str_contains($query, 'WHERE creator_id') && str_contains($query, 'faluss_fans_creator_profiles') && $this->profiles !== []) {
            $profile = $this->profiles[end($this->prepared)['args'][0]] ?? null;
            return $profile !== null && $profile['status'] === 'active' ? $profile : null;
        }
        return parent::get_row($query, $output);
    }
    public function query(string $query): int|false
    {
        $args = end($this->prepared)['args'] ?? [];
        if ($query === 'START TRANSACTION') { $this->snapshot = [$this->publication, $this->audit]; return 1; }
        if ($query === 'COMMIT') { $this->snapshot = null; return 1; }
        if ($query === 'ROLLBACK') {
            if ($this->snapshot !== null) { [$this->publication, $this->audit] = $this->snapshot; $this->snapshot = null; }
            return 1;
        }
        if (str_starts_with($query, 'INSERT INTO `wp_faluss_fans_text_decisions')) {
            if ($this->failAudit) { return false; }
            $this->audit[] = $args;
            return 1;
        }
        if (str_starts_with($query, 'INSERT INTO `wp_faluss_fans_text_publications')) {
            $this->publication = array_combine(array_keys(TextPublicationSchema::columns(false)), $args);
            return 1;
        }
        if (str_starts_with($query, 'UPDATE `wp_faluss_fans_text_publications')) {
            if ($this->publication['revision'] !== $args[5]) { return 0; }
            [$this->publication['body'], $this->publication['state'], $this->publication['revision'], $this->publication['updated_at']] = $args;
            return 1;
        }
        return parent::query($query);
    }
}

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TextPublicationTest extends TestCase
{
    protected function setUp(): void
    {
        \Faluss\Platform\Fans\Sso\fans_sso_reset();
        define('FALUSS_PLATFORM_ROLE', 'fans');
        foreach (['FALUSS_PLATFORM_FANS_SSO', 'FALUSS_PLATFORM_FANS_CREATOR_PROFILES', 'FALUSS_PLATFORM_FANS_TEXT_PUBLICATIONS'] as $flag) { define($flag, true); }
        define('FALUSS_FANS_SSO_CLIENT_ID', 'fans-test-client');
        define('FALUSS_FANS_SSO_CLIENT_SECRET', str_repeat('s', 43));
        $GLOBALS['wpdb'] = new TextPublicationDb();
        $GLOBALS['profile_options'] = [CreatorProfileSchema::OPTION => CreatorProfileSchema::VERSION];
        $GLOBALS['fans_sso_options'] = [FansSsoSchema::OPTION => FansSsoSchema::VERSION];
        $GLOBALS['text_options'] = [TextPublicationSchema::OPTION => TextPublicationSchema::VERSION];
        $GLOBALS['profile_admin'] = false;
        $GLOBALS['profile_linked'] = true;
        $GLOBALS['fans_sso_logged_in'] = true;
        $GLOBALS['fans_sso_current_user'] = 17;
        $GLOBALS['fans_sso_users'][17] = new \WP_User(17, ['subscriber']);
        $GLOBALS['wpdb']->profile = ['creator_id' => '11111111-1111-4111-8111-111111111111', 'wp_user_id' => 17,
            'status' => 'active', 'category' => 'arts', 'created_at' => '2026-09-25 00:00:00', 'updated_at' => '2026-09-25 00:00:00'];
        self::assertTrue(TextPublicationsModule::available());
    }
    private function create(): array
    {
        $row = TextPublicationService::create('Un texte de création.', TextPublicationService::CATEGORY);
        self::assertIsArray($row);
        return $row;
    }
    public function testModerationAndEveryEditHideTextUntilNewDecision(): void
    {
        $row = $this->create(); $id = $row['publication_id'];
        self::assertSame('pending', $row['state']);
        self::assertInstanceOf(\WP_Error::class, TextPublicationService::get($id));
        self::assertInstanceOf(\WP_Error::class, TextPublicationService::change($id, 1, 'approve', null, 'allowed_text'));
        $GLOBALS['profile_admin'] = true;
        self::assertIsArray(TextPublicationService::change($id, 1, 'approve', null, 'allowed_text'));
        self::assertSame(['publication_id', 'creator_id', 'revision', 'body', 'updated_at'], array_keys(TextPublicationService::get($id)));
        $GLOBALS['profile_admin'] = false;
        self::assertIsArray(TextPublicationService::change($id, 2, 'edit', 'Texte changé.'));
        self::assertInstanceOf(\WP_Error::class, TextPublicationService::get($id));
        self::assertCount(3, $GLOBALS['wpdb']->audit);
    }
    public function testStaleRevisionsAndDifferentOwnersCannotChangeOrReadPrivateText(): void
    {
        $id = $this->create()['publication_id'];
        self::assertIsArray(TextPublicationService::change($id, 1, 'edit', 'Première révision.'));
        self::assertSame('publication_revision_conflict', TextPublicationService::change($id, 1, 'edit', 'Révision périmée.')->get_error_code());
        $GLOBALS['wpdb']->profile['creator_id'] = '33333333-3333-4333-8333-333333333333';
        self::assertSame('publication_forbidden', TextPublicationService::change($id, 2, 'withdraw')->get_error_code());
        self::assertInstanceOf(\WP_Error::class, TextPublicationService::get($id, true));
        self::assertSame('Première révision.', $GLOBALS['wpdb']->publication['body']);
    }
    public function testAuditFailureRollsBackCreationAndApproval(): void
    {
        $GLOBALS['wpdb']->failAudit = true;
        self::assertInstanceOf(\WP_Error::class, TextPublicationService::create('Texte.', TextPublicationService::CATEGORY));
        self::assertNull($GLOBALS['wpdb']->publication);
        $GLOBALS['wpdb']->failAudit = false;
        $id = $this->create()['publication_id'];
        $GLOBALS['profile_admin'] = true;
        $GLOBALS['wpdb']->failAudit = true;
        self::assertInstanceOf(\WP_Error::class, TextPublicationService::change($id, 1, 'approve', null, 'allowed_text'));
        self::assertSame('pending', $GLOBALS['wpdb']->publication['state']);
        self::assertSame(1, $GLOBALS['wpdb']->publication['revision']);
        self::assertCount(1, $GLOBALS['wpdb']->audit);
        self::assertFalse($GLOBALS['wpdb']->suppressed);
        self::assertInstanceOf(\WP_Error::class, TextPublicationService::get($id));
    }
    public function testSuspensionHidesImmediatelyAndDoesNotPreventWithdrawal(): void
    {
        $id = $this->create()['publication_id']; $GLOBALS['profile_admin'] = true;
        TextPublicationService::change($id, 1, 'approve', null, 'allowed_text');
        $GLOBALS['profile_admin'] = false;
        $GLOBALS['wpdb']->profile['status'] = 'suspended';
        self::assertInstanceOf(\WP_Error::class, TextPublicationService::get($id));
        self::assertInstanceOf(\WP_Error::class, TextPublicationService::change($id, 2, 'edit', 'Texte.'));
        self::assertSame('withdrawn', TextPublicationService::change($id, 2, 'withdraw')['state']);
        self::assertSame('', $GLOBALS['wpdb']->publication['body']);
        self::assertInstanceOf(\WP_Error::class, TextPublicationService::change($id, 3, 'edit', 'Texte.'));
    }
    public function testRejectionPurgesTextAndKeepsOnlyDecisionDigest(): void
    {
        $row = $this->create(); $GLOBALS['profile_admin'] = true;
        $result = TextPublicationService::change($row['publication_id'], 1, 'reject', null, 'prohibited_content');
        self::assertSame('', $result['body']);
        self::assertSame(hash('sha256', $row['body']), $GLOBALS['wpdb']->audit[1][5]);
        self::assertNotContains($row['body'], $GLOBALS['wpdb']->audit[1]);
    }
    public function testAdminCanRevokeApprovedTextEvenAfterProfileSuspension(): void
    {
        $id = $this->create()['publication_id']; $GLOBALS['profile_admin'] = true;
        TextPublicationService::change($id, 1, 'approve', null, 'allowed_text');
        $GLOBALS['wpdb']->profile['status'] = 'suspended';
        self::assertSame('rejected', TextPublicationService::change($id, 2, 'reject', null, 'prohibited_content')['state']);
        $GLOBALS['wpdb']->profile['status'] = 'active';
        self::assertInstanceOf(\WP_Error::class, TextPublicationService::get($id));
    }
    public function testAdultCategoryInvalidTextUnlinkedAndPendingCreatorsAreRejected(): void
    {
        foreach (['external_adult_delivery_right', 'locked', null] as $category) {
            self::assertInstanceOf(\WP_Error::class, TextPublicationService::create('Texte.', $category));
        }
        foreach (['', '<img src="x">', "\0", str_repeat('é', 8001), "\xff"] as $text) {
            self::assertInstanceOf(\WP_Error::class, TextPublicationService::create($text, TextPublicationService::CATEGORY));
        }
        $GLOBALS['wpdb']->profile['status'] = 'pending';
        self::assertInstanceOf(\WP_Error::class, TextPublicationService::create('Texte.', TextPublicationService::CATEGORY));
        $GLOBALS['wpdb']->profile['status'] = 'active'; $GLOBALS['profile_linked'] = false;
        self::assertInstanceOf(\WP_Error::class, TextPublicationService::create('Texte.', TextPublicationService::CATEGORY));
        self::assertNull($GLOBALS['wpdb']->publication);
    }
    public function testNonceAndSchemaAreMandatory(): void
    {
        self::assertFalse(TextPublicationRest::memberPermission(new \WP_REST_Request()));
        self::assertTrue(TextPublicationRest::memberPermission(new \WP_REST_Request([], ['X-WP-Nonce' => 'valid-nonce'])));
        self::assertFalse(TextPublicationRest::adminPermission(new \WP_REST_Request([], ['X-WP-Nonce' => 'valid-nonce'])));
        $GLOBALS['text_options'] = [];
        self::assertFalse(TextPublicationRest::publicPermission());
        self::assertInstanceOf(\WP_Error::class, TextPublicationService::create('Texte.', TextPublicationService::CATEGORY));
    }
    /** 185 rows, ties in timestamps, three owners, including 60 newest but invisible texts. */
    private function paginationFixture(): void
    {
        $db = $GLOBALS['wpdb'];
        foreach ([1 => 'active', 2 => 'active', 3 => 'suspended'] as $n => $status) {
            $id = sprintf('%08d-1111-4111-8111-111111111111', $n);
            $db->profiles[$id] = [...$db->profile, 'creator_id' => $id, 'status' => $status];
        }
        $db->profile = array_values($db->profiles)[0];
        for ($i = 1; $i <= 185; ++$i) {
            $owner = $i <= 60 ? 3 : ($i <= 105 ? 1 + $i % 2 : ($i <= 175 ? 1 + $i % 3 : 1));
            $id = sprintf('%08d-2222-4222-8222-222222222222', $i);
            $day = $i <= 60 ? 26 : 25;
            $db->publications[$id] = ['publication_id' => $id, 'creator_id' => sprintf('%08d-1111-4111-8111-111111111111', $owner),
                'revision' => 1, 'body' => 'Texte synthétique.', 'category' => TextPublicationService::CATEGORY,
                'state' => $i <= 105 ? 'approved' : ($i <= 175 ? 'pending' : ($i % 2 ? 'withdrawn' : 'rejected')),
                'created_at' => '2026-09-24 00:00:00', 'updated_at' => sprintf('2026-09-%02d 00:00:%02d', $day, intdiv($i, 8))];
        }
    }

    private function collectPages(string $scope, int $size = 20): array
    {
        $cursor = null; $ids = []; $pages = 0;
        do {
            $page = TextPublicationService::listing($scope, (string) $size, $cursor);
            self::assertIsArray($page);
            self::assertSame(['items', 'next_cursor'], array_keys($page));
            self::assertLessThanOrEqual($size, count($page['items']));
            if ($page['next_cursor'] !== null) { self::assertCount($size, $page['items']); }
            foreach ($page['items'] as $item) {
                if ($scope === 'public') {
                    self::assertSame(['publication_id', 'creator_id', 'revision', 'body', 'updated_at'], array_keys($item));
                }
                $ids[] = $item['publication_id'];
            }
            $cursor = $page['next_cursor'];
            self::assertLessThan(20, ++$pages, 'Pagination must terminate');
        } while ($cursor !== null);
        self::assertSame($ids, array_values(array_unique($ids)), 'No duplicates across pages');
        return $ids;
    }

    private function expectedIds(string $scope): array
    {
        $db = $GLOBALS['wpdb'];
        $rows = array_values(array_filter($db->publications, static fn ($row) => match ($scope) {
            'public' => $row['state'] === 'approved' && $db->profiles[$row['creator_id']]['status'] === 'active',
            'queue' => $row['state'] === 'pending',
            'own' => $row['creator_id'] === $db->profile['creator_id'],
        }));
        usort($rows, static fn ($a, $b) => ($scope === 'queue' ? 1 : -1) * ([$a['updated_at'], $a['publication_id']] <=> [$b['updated_at'], $b['publication_id']]));
        return array_column($rows, 'publication_id');
    }

    public function testPublicPagesAreRecentFullAndSkipSuspendedCreatorsAcrossCandidateBatches(): void
    {
        $this->paginationFixture();
        $expected = $this->expectedIds('public');
        self::assertCount(45, $expected);
        self::assertSame($expected, $this->collectPages('public'));
        self::assertGreaterThan(3, $GLOBALS['wpdb']->pageQueries);
        $GLOBALS['wpdb']->publications[$expected[0]]['state'] = 'withdrawn';
        self::assertSame($this->expectedIds('public'), $this->collectPages('public'));
        // Suspend the second creator after an initial traversal, then start a fresh one.
        $second = array_keys($GLOBALS['wpdb']->profiles)[1];
        $GLOBALS['wpdb']->profiles[$second]['status'] = 'suspended';
        self::assertSame($this->expectedIds('public'), $this->collectPages('public'));
        // All invisible yields a genuinely empty page, not an endless continuation.
        $first = array_keys($GLOBALS['wpdb']->profiles)[0];
        $GLOBALS['wpdb']->profiles[$first]['status'] = 'suspended';
        self::assertSame(['items' => [], 'next_cursor' => null], TextPublicationService::listing('public'));
    }

    public function testEntireModerationQueueAndOwnHistoryAreReachableWithoutGaps(): void
    {
        $this->paginationFixture();
        self::assertGreaterThan(20, count($this->expectedIds('own')));
        self::assertSame($this->expectedIds('own'), $this->collectPages('own'));
        self::assertInstanceOf(\WP_Error::class, TextPublicationService::listing('queue'));
        $GLOBALS['profile_admin'] = true;
        self::assertCount(70, $this->expectedIds('queue'));
        self::assertSame($this->expectedIds('queue'), $this->collectPages('queue'));
        self::assertSame($this->expectedIds('queue'), $this->collectPages('queue', 7));
    }

    public function testPaginationBoundsCursorValidationAndPermissions(): void
    {
        $this->paginationFixture();
        foreach ([0, 21, 1000000, -1, 1.5, true, [], '01', '2e1', ''] as $size) {
            self::assertSame('invalid_publication_page', TextPublicationService::listing('public', $size)->get_error_code());
        }
        foreach (['', [], str_repeat('a', 1000), 'v2.public.2026-09-25T00:00:00.11111111-1111-4111-8111-111111111111',
            'v1.public.2026-02-31T00:00:00.11111111-1111-4111-8111-111111111111'] as $cursor) {
            self::assertSame('invalid_publication_page', TextPublicationService::listing('public', 20, $cursor)->get_error_code());
        }
        $cursor = TextPublicationService::listing('public', 1)['next_cursor'];
        self::assertNotNull($cursor);
        self::assertSame('invalid_publication_page', TextPublicationService::listing('own', 20, $cursor)->get_error_code());
        $GLOBALS['profile_linked'] = false;
        self::assertInstanceOf(\WP_Error::class, TextPublicationService::listing('own'));
    }

    public function testRestListsForwardPageSizeAndCursorAndKeepNoStore(): void
    {
        $this->paginationFixture();
        $GLOBALS['profile_admin'] = true;
        foreach (['public' => 'publicList', 'own' => 'ownList', 'queue' => 'queue'] as $scope => $callback) {
            $first = TextPublicationRest::$callback(new \WP_REST_Request(['per_page' => '3']));
            self::assertSame(200, $first->status);
            self::assertCount(3, $first->data['items']);
            self::assertSame('private, no-store, max-age=0', $first->headers['Cache-Control']);
            $next = TextPublicationRest::$callback(new \WP_REST_Request(['per_page' => '3', 'cursor' => $first->data['next_cursor']]));
            self::assertSame(array_slice($this->expectedIds($scope), 3, 3), array_column($next->data['items'], 'publication_id'));
            self::assertSame(400, TextPublicationRest::$callback(new \WP_REST_Request(['per_page' => '21']))->status);
            self::assertSame(400, TextPublicationRest::$callback(new \WP_REST_Request(['cursor' => 'invalid']))->status);
        }
    }

}
