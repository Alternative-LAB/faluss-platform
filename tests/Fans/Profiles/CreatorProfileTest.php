<?php

declare(strict_types=1);

namespace {
    if (!class_exists('WP_Error')) {
        final class WP_Error
        {
            public function __construct(
                public string $code = '',
                public string $message = '',
                public array $data = []
            ) {}
            public function get_error_code(): string { return $this->code; }
        }
    }
    if (!class_exists('WP_REST_Request')) {
        final class WP_REST_Request
        {
            public function __construct(private array $params = [], private array $headers = []) {}
            public function get_param(string $key): mixed { return $this->params[$key] ?? null; }
            public function get_header(string $key): string { return $this->headers[$key] ?? ''; }
        }
    }
    if (!class_exists('WP_REST_Response')) {
        final class WP_REST_Response
        {
            public function __construct(public mixed $data, public int $status = 200) {}
        }
    }
}

namespace Faluss\Platform\Fans\Profiles {
    use PHPUnit\Framework\TestCase;
    use Faluss\Platform\Core\SiteRole;
    use Faluss\Platform\Fans\Sso\FansSsoSchema;

    require_once dirname(__DIR__) . '/Sso/WordPressStubs.php';

    function get_option(string $key, mixed $default = false): mixed
    {
        return $GLOBALS['profile_options'][$key] ?? $default;
    }
    function update_option(string $key, mixed $value, bool $autoload = false): bool
    {
        $GLOBALS['profile_options'][$key] = $value;
        return true;
    }
    function get_current_user_id(): int { return $GLOBALS['fans_sso_current_user']; }
    function current_user_can(string $capability): bool { return $capability === 'manage_options' && $GLOBALS['profile_admin']; }
    function wp_verify_nonce(string $nonce, string $action): int|false
    {
        return $nonce === 'valid-nonce' && $action === 'wp_rest' ? 1 : false;
    }
    function add_action(string $hook, callable $callback): void { $GLOBALS['profile_hooks'][$hook] = $callback; }
    function register_rest_route(string $namespace, string $route, array $args): void
    {
        $GLOBALS['profile_routes'][$namespace . $route] = $args;
    }

    class CreatorProfileDb
    {
        public string $prefix = 'wp_';
        public string $last_error = '';
        /** @var list<array{query:string,args:list<mixed>}> */
        public array $prepared = [];
        /** @var array<string,mixed>|null */
        public ?array $profile = null;
        public bool $failInsert = false;

        public function prepare(string $query, mixed ...$args): string
        {
            $this->prepared[] = ['query' => $query, 'args' => $args];
            return $query;
        }
        public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4'; }
        public function get_var(string $query): mixed { return null; }
        public function get_row(string $query, mixed $output = null): ?array
        {
            if (str_starts_with($query, 'SHOW TABLE STATUS')) {
                return ['Engine' => 'InnoDB'];
            }
            if (str_contains($query, 'wp_faluss_fans_identity_links')) {
                return $GLOBALS['profile_linked']
                    ? ['faluss_id' => '11111111-1111-4111-8111-111111111111', 'created_at' => '2026-09-24 00:00:00', 'last_proved_at' => '2026-09-24 00:00:00']
                    : null;
            }
            if ($this->profile === null) {
                return null;
            }
            $last = end($this->prepared);
            if (str_contains($query, 'WHERE wp_user_id') && ($last['args'][0] ?? null) !== $this->profile['wp_user_id']) {
                return null;
            }
            if (str_contains($query, 'WHERE creator_id') && ($last['args'][0] ?? null) !== $this->profile['creator_id']) {
                return null;
            }
            if (str_contains($query, 'AND status =') && $this->profile['status'] !== 'active') {
                return null;
            }
            return $this->profile;
        }
        public function get_results(string $query, mixed $output = null): array
        {
            if (str_starts_with($query, 'SHOW FULL COLUMNS')) {
                $result = [];
                foreach (CreatorProfileSchema::columns() as $name => $column) {
                    $result[] = [
                        'Field' => $name, 'Type' => $column['type'],
                        'Null' => $column['null'] ? 'YES' : 'NO',
                        'Extra' => !empty($column['auto']) ? 'auto_increment' : '',
                    ];
                }
                return $result;
            }
            if (str_starts_with($query, 'SHOW INDEX')) {
                $result = [];
                foreach ([
                    'PRIMARY' => [true, ['id']],
                    'creator_id_unique' => [true, ['creator_id']],
                    'wp_user_id_unique' => [true, ['wp_user_id']],
                    'status_category' => [false, ['status', 'category']],
                ] as $name => [$unique, $columns]) {
                    foreach ($columns as $offset => $column) {
                        $result[] = [
                            'Key_name' => $name, 'Non_unique' => $unique ? '0' : '1',
                            'Seq_in_index' => $offset + 1, 'Column_name' => $column,
                        ];
                    }
                }
                return $result;
            }
            $last = end($this->prepared);
            return $this->profile !== null
                && $this->profile['status'] === 'active'
                && (!str_contains($query, 'AND category =') || ($last['args'][1] ?? null) === $this->profile['category'])
                ? [$this->profile]
                : [];
        }
        public function query(string $query): int|false
        {
            $last = end($this->prepared);
            if (str_starts_with($query, 'INSERT INTO')) {
                if ($this->failInsert || $this->profile !== null) {
                    return false;
                }
                [$creatorId, $owner, $category, $status, $created, $updated] = $last['args'];
                $this->profile = [
                    'creator_id' => $creatorId, 'wp_user_id' => $owner,
                    'category' => $category, 'status' => $status,
                    'created_at' => $created, 'updated_at' => $updated,
                ];
                return 1;
            }
            if (str_starts_with($query, 'UPDATE ')) {
                if ($this->profile === null || $this->profile['creator_id'] !== ($last['args'][2] ?? null)) {
                    return 0;
                }
                $this->profile['status'] = $last['args'][0];
                $this->profile['updated_at'] = $last['args'][1];
                return 1;
            }
            return 1;
        }
    }

    final class CreatorProfileTest extends TestCase
    {
        protected function setUp(): void
        {
            \Faluss\Platform\Fans\Sso\fans_sso_reset();
            $GLOBALS['wpdb'] = new CreatorProfileDb();
            $GLOBALS['profile_options'] = [CreatorProfileSchema::OPTION => CreatorProfileSchema::VERSION];
            $GLOBALS['profile_admin'] = false;
            $GLOBALS['profile_linked'] = true;
            $GLOBALS['profile_routes'] = [];
            $GLOBALS['profile_hooks'] = [];
            $GLOBALS['fans_sso_logged_in'] = true;
            $GLOBALS['fans_sso_current_user'] = 17;
            $GLOBALS['fans_sso_users'][17] = new \WP_User(17, ['subscriber']);
        }

        public function testModuleAndNamesAreIsolatedFromHub(): void
        {
            $module = new CreatorProfilesModule();
            self::assertSame([SiteRole::Fans], $module->roles());
            self::assertSame(['fans-sso'], $module->dependencies());
            self::assertSame('wp_faluss_fans_creator_profiles', CreatorProfileSchema::table());
            self::assertSame(['id', 'creator_id', 'wp_user_id', 'category', 'status', 'created_at', 'updated_at'], array_keys(CreatorProfileSchema::columns()));
            self::assertTrue(CreatorProfileSchema::ready());
            $GLOBALS['profile_options'] = [];
            self::assertFalse(CreatorProfileSchema::ready());
        }

        public function testOnlyLinkedSubscriberMayCreateOneIdempotentStructuredProfile(): void
        {
            $created = CreatorProfileService::create('arts');
            self::assertIsArray($created);
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $created['creator_id']);
            self::assertSame('pending', $created['status']);
            self::assertFalse($created['identity_verified']);
            self::assertSame($created, CreatorProfileService::create('arts'));
            self::assertInstanceOf(\WP_Error::class, CreatorProfileService::create('music'));
            self::assertArrayNotHasKey('wp_user_id', $created);
            self::assertArrayNotHasKey('bio', $GLOBALS['wpdb']->profile);
            self::assertArrayNotHasKey('media', $GLOBALS['wpdb']->profile);
        }

        public function testUnlinkedAndPrivilegedUsersAndFreeTextAreRejected(): void
        {
            self::assertInstanceOf(\WP_Error::class, CreatorProfileService::create('adult'));
            $GLOBALS['profile_linked'] = false;
            self::assertInstanceOf(\WP_Error::class, CreatorProfileService::create('arts'));
            $GLOBALS['profile_linked'] = true;
            $GLOBALS['fans_sso_users'][17] = new \WP_User(17, ['administrator']);
            self::assertInstanceOf(\WP_Error::class, CreatorProfileService::create('arts'));
            self::assertNull($GLOBALS['wpdb']->profile);
        }

        public function testPublicReadRequiresApprovalAndSuspensionHidesProfile(): void
        {
            $created = CreatorProfileService::create('music');
            self::assertIsArray($created);
            self::assertNull(CreatorProfileService::publicById($created['creator_id']));
            self::assertSame([], CreatorProfileService::publicList(null));
            self::assertInstanceOf(\WP_Error::class, CreatorProfileService::setStatus($created['creator_id'], 'active'));
            $GLOBALS['profile_admin'] = true;
            $approved = CreatorProfileService::setStatus($created['creator_id'], 'active');
            self::assertIsArray($approved);
            self::assertFalse($approved['identity_verified']);
            self::assertSame('active', CreatorProfileService::publicById($created['creator_id'])['status']);
            self::assertFalse(CreatorProfileService::publicById($created['creator_id'])['identity_verified']);
            $listed = CreatorProfileService::publicList('music');
            self::assertCount(1, $listed);
            self::assertFalse($listed[0]['identity_verified']);
            self::assertSame([], CreatorProfileService::publicList('arts'));
            CreatorProfileService::setStatus($created['creator_id'], 'suspended');
            self::assertNull(CreatorProfileService::publicById($created['creator_id']));
            self::assertSame([], CreatorProfileService::publicList(null));
        }

        public function testRestMutationsRequireNonceAndScopedPermission(): void
        {
            self::assertFalse(CreatorProfileRest::memberPermission(new \WP_REST_Request(['category' => 'arts'])));
            self::assertTrue(CreatorProfileRest::memberPermission(new \WP_REST_Request([], ['X-WP-Nonce' => 'valid-nonce'])));
            self::assertFalse(CreatorProfileRest::adminPermission(new \WP_REST_Request([], ['X-WP-Nonce' => 'valid-nonce'])));
            $GLOBALS['profile_admin'] = true;
            self::assertTrue(CreatorProfileRest::adminPermission(new \WP_REST_Request([], ['X-WP-Nonce' => 'valid-nonce'])));
            CreatorProfileRest::routes();
            self::assertCount(4, $GLOBALS['profile_routes']);
            self::assertArrayHasKey('faluss-fans/v1/creators/me', $GLOBALS['profile_routes']);
        }
    }
}
