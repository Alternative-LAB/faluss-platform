<?php

declare(strict_types=1);

namespace {
    if (!class_exists('WP_Error')) {
        final class WP_Error
        {
            public function __construct(private readonly string $code = '')
            {
            }

            public function get_error_code(): string
            {
                return $this->code;
            }
        }
    }

    if (!class_exists('WP_Role')) {
        final class WP_Role
        {
        }
    }

    if (!class_exists('WP_User')) {
        final class WP_User
        {
            /** @param list<string> $roles */
            public function __construct(
                public int $ID,
                public array $roles = ['subscriber'],
                public string $user_login = 'member',
                public string $user_email = 'member@example.test'
            ) {
            }

            public function exists(): bool
            {
                return $this->ID > 0;
            }
        }
    }
}

namespace Faluss\Platform\IdentityClient {
    function identity_client_test_reset(): void
    {
        $GLOBALS['identity_client_test_options'] = [IdentityClientSchema::OPTION => IdentityClientSchema::VERSION];
        $GLOBALS['identity_client_test_hooks'] = [];
        $GLOBALS['identity_client_test_filters'] = [];
        $GLOBALS['identity_client_test_shortcodes'] = [];
        $GLOBALS['identity_client_test_home'] = 'https://faluss.com';
        $GLOBALS['identity_client_test_admin'] = false;
        $GLOBALS['identity_client_test_logged_in'] = false;
        $GLOBALS['identity_client_test_current_user'] = null;
        $GLOBALS['identity_client_test_users'] = [];
        $GLOBALS['identity_client_test_email_users'] = [];
        $GLOBALS['identity_client_test_insert_calls'] = [];
        $GLOBALS['identity_client_test_user_meta'] = [];
        $GLOBALS['identity_client_test_remote_queue'] = [];
        $GLOBALS['identity_client_test_remote_calls'] = [];
        $GLOBALS['identity_client_test_did_plugins_loaded'] = true;
        $GLOBALS['identity_client_test_doing_plugins_loaded'] = false;
        $GLOBALS['wpdb'] = new IdentityClientWpdbStub();
        $_COOKIE = [];
        $_GET = [];
        $_POST = [];
    }

    final class IdentityClientWpdbStub
    {
        public string $prefix = 'wp_';
        public string $last_error = '';
        /** @var list<string> */
        public array $queries = [];
        /** @var list<array{query:string,args:list<mixed>}> */
        public array $prepared = [];
        /** @var array<string, mixed>|null */
        public ?array $stateRow = null;
        public ?string $expectedBrowserHash = null;
        public mixed $linkedUserId = null;
        public mixed $existingLinkId = null;
        /** @var array<string, mixed>|null */
        public ?array $subjectRow = null;
        public int $updateResult = 1;
        public int $insertResult = 1;

        public function prepare(string $query, mixed ...$args): string
        {
            $this->prepared[] = ['query' => $query, 'args' => $args];

            return $query;
        }

        public function query(string $query): int|false
        {
            $this->queries[] = $query;
            if (str_starts_with($query, 'UPDATE ')) {
                return $this->updateResult;
            }
            if (str_starts_with($query, 'INSERT INTO')) {
                return $this->insertResult;
            }

            return 1;
        }

        /** @return array<string, mixed>|null */
        public function get_row(string $query, mixed $output = null): ?array
        {
            unset($output);
            if (str_contains($query, 'SELECT faluss_id, created_at, last_proved_at')) {
                return $this->subjectRow;
            }
            $last = end($this->prepared);
            if (str_contains($query, 'state_hash')
                && $this->expectedBrowserHash !== null
                && (!is_array($last) || ($last['args'][1] ?? null) !== $this->expectedBrowserHash)
            ) {
                return null;
            }

            return $this->stateRow;
        }

        public function get_var(string $query): mixed
        {
            if (str_contains($query, 'WHERE faluss_id')) {
                return $this->linkedUserId;
            }
            if (str_contains($query, 'WHERE wp_user_id =') && str_contains($query, 'SELECT faluss_id')) {
                return $this->linkedUserId === null ? null : '11111111-1111-4111-8111-111111111111';
            }
            if (str_contains($query, 'WHERE wp_user_id =') || str_contains($query, 'SELECT id FROM')) {
                return $this->existingLinkId;
            }

            return null;
        }
    }

    function get_option(string $name, mixed $default = false): mixed
    {
        return $GLOBALS['identity_client_test_options'][$name] ?? $default;
    }

    function update_option(string $name, mixed $value, bool $autoload = false): bool
    {
        $GLOBALS['identity_client_test_options'][$name] = $value;

        return true;
    }

    /** @param array<string, mixed> $defaults
     *  @return array<string, mixed>
     */
    function wp_parse_args(mixed $args, array $defaults = []): array
    {
        return array_merge($defaults, is_array($args) ? $args : []);
    }

    function home_url(string $path = ''): string
    {
        return rtrim((string) $GLOBALS['identity_client_test_home'], '/') . '/' . ltrim($path, '/');
    }

    /** @return array<string, int|string>|false */
    function wp_parse_url(string $url): array|false
    {
        return parse_url($url);
    }

    function sanitize_text_field(mixed $value): string
    {
        return is_scalar($value) ? trim(strip_tags((string) $value)) : '';
    }

    function sanitize_key(mixed $value): string
    {
        return preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $value)) ?? '';
    }

    function wp_unslash(mixed $value): mixed
    {
        return $value;
    }

    function is_email(mixed $value): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    function wp_salt(string $scheme = 'auth'): string
    {
        return 'identity-client-test-salt-' . $scheme;
    }

    function is_wp_error(mixed $value): bool
    {
        return $value instanceof \WP_Error;
    }

    function add_action(string $hook, callable $callback, int $priority = 10): void
    {
        $GLOBALS['identity_client_test_hooks'][$hook] = ['callback' => $callback, 'priority' => $priority];
    }

    function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        $GLOBALS['identity_client_test_filters'][$hook] = compact('callback', 'priority', 'acceptedArgs');
    }

    function add_shortcode(string $tag, callable $callback): void
    {
        $GLOBALS['identity_client_test_shortcodes'][$tag] = $callback;
    }

    function did_action(string $hook): int
    {
        return $hook === 'plugins_loaded' && !empty($GLOBALS['identity_client_test_did_plugins_loaded']) ? 1 : 0;
    }

    function doing_action(?string $hook = null): bool
    {
        return $hook === 'plugins_loaded' && !empty($GLOBALS['identity_client_test_doing_plugins_loaded']);
    }

    function is_admin(): bool
    {
        return !empty($GLOBALS['identity_client_test_admin']);
    }

    function load_plugin_textdomain(string $domain, bool $deprecated = false, string $path = ''): bool
    {
        return true;
    }

    function is_user_logged_in(): bool
    {
        return !empty($GLOBALS['identity_client_test_logged_in']);
    }

    function get_current_user_id(): int
    {
        $user = $GLOBALS['identity_client_test_current_user'];

        return $user instanceof \WP_User ? $user->ID : 0;
    }

    function wp_get_current_user(): \WP_User
    {
        $user = $GLOBALS['identity_client_test_current_user'];

        return $user instanceof \WP_User ? $user : new \WP_User(0, []);
    }

    function get_userdata(int $userId): \WP_User|false
    {
        return $GLOBALS['identity_client_test_users'][$userId] ?? false;
    }

    function get_user_by(string $field, mixed $value): \WP_User|false
    {
        if ($field === 'id') {
            return $GLOBALS['identity_client_test_users'][(int) $value] ?? false;
        }
        if ($field === 'email') {
            return $GLOBALS['identity_client_test_email_users'][(string) $value] ?? false;
        }

        return false;
    }

    function get_role(string $role): ?\WP_Role
    {
        return $role === 'subscriber' ? new \WP_Role() : null;
    }

    /** @param array<string, mixed> $data */
    function wp_insert_user(array $data): int|\WP_Error
    {
        $GLOBALS['identity_client_test_insert_calls'][] = $data;
        $user = new \WP_User(51, [(string) ($data['role'] ?? '')], (string) $data['user_login'], (string) $data['user_email']);
        $GLOBALS['identity_client_test_users'][51] = $user;

        return 51;
    }

    function get_user_meta(int $userId, string $key, bool $single = false): mixed
    {
        return $GLOBALS['identity_client_test_user_meta'][$userId][$key] ?? '';
    }

    function update_user_meta(int $userId, string $key, mixed $value): int|bool
    {
        $GLOBALS['identity_client_test_user_meta'][$userId][$key] = $value;

        return true;
    }

    function delete_user_meta(int $userId, string $key): bool
    {
        unset($GLOBALS['identity_client_test_user_meta'][$userId][$key]);

        return true;
    }

    /** @param array<string, mixed> $args */
    function wp_remote_post(string $url, array $args = []): mixed
    {
        $GLOBALS['identity_client_test_remote_calls'][] = ['url' => $url, 'args' => $args];

        return array_shift($GLOBALS['identity_client_test_remote_queue']);
    }

    function wp_remote_retrieve_response_code(mixed $response): int
    {
        return is_array($response) ? (int) ($response['code'] ?? 0) : 0;
    }

    function wp_remote_retrieve_body(mixed $response): string
    {
        return is_array($response) ? (string) ($response['body'] ?? '') : '';
    }

    /** @param array<string, mixed> $body
     *  @return array{code:int,body:string}
     */
    function identity_client_test_response(array $body, int $code = 200): array
    {
        return ['code' => $code, 'body' => (string) json_encode($body)];
    }
}
