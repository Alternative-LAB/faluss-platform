<?php

declare(strict_types=1);

namespace {
    if (!class_exists('WP_Role')) {
        final class WP_Role {}
    }
    if (!class_exists('WP_User')) {
        final class WP_User
        {
            /** @param list<string> $roles */
            public function __construct(
                public int $ID,
                public array $roles = ['subscriber'],
                public string $user_login = 'fans_member',
                public string $user_email = 'member@example.test'
            ) {}
        }
    }
    if (!class_exists('WP_Error')) {
        final class WP_Error {}
    }
}

namespace Faluss\Platform\Fans\Sso {
    function fans_sso_reset(): void
    {
        $GLOBALS['fans_sso_home'] = 'https://fans.example.test';
        $GLOBALS['fans_sso_users'] = [];
        $GLOBALS['fans_sso_email_users'] = [];
        $GLOBALS['fans_sso_insert_calls'] = [];
        $GLOBALS['fans_sso_insert_error'] = false;
        $GLOBALS['fans_sso_remote_queue'] = [];
        $GLOBALS['fans_sso_remote_calls'] = [];
        $GLOBALS['fans_sso_logged_in'] = false;
        $GLOBALS['fans_sso_current_user'] = 0;
        $GLOBALS['fans_sso_hooks'] = [];
        $GLOBALS['fans_sso_shortcodes'] = [];
        $GLOBALS['fans_sso_filters'] = [];
        $GLOBALS['fans_sso_options'] = [];
        $GLOBALS['wpdb'] = new FansSsoWpdbStub();
        $_COOKIE = [];
        $_POST = [];
        $_GET = [];
    }

    final class FansSsoWpdbStub
    {
        public string $prefix = 'wp_';
        public string $users = 'wp_users';
        public string $usermeta = 'wp_usermeta';
        public string $coreEngine = 'InnoDB';
        public string $last_error = '';
        /** @var list<string> */
        public array $queries = [];
        /** @var list<array{query:string,args:list<mixed>}> */
        public array $prepared = [];
        /** @var array<string,mixed>|null */
        public ?array $stateRow = null;
        public mixed $linkedId = null;
        public mixed $existingLinkId = null;
        /** @var list<string> */
        public array $presentTables = [];
        public int $insertResult = 1;
        public int $updateResult = 1;
        /** @var list<int> */
        public array $lockResults = [];
        public ?\Closure $onLock = null;
        /** @var array{users:array<int,\WP_User>,emails:array<string,\WP_User>}|null */
        private ?array $transactionSnapshot = null;

        public function prepare(string $query, mixed ...$args): string
        {
            $this->prepared[] = ['query' => $query, 'args' => $args];
            return $query;
        }

        public function query(string $query): int|false
        {
            $this->queries[] = $query;
            if ($query === 'START TRANSACTION') {
                $this->transactionSnapshot = [
                    'users' => $GLOBALS['fans_sso_users'],
                    'emails' => $GLOBALS['fans_sso_email_users'],
                ];
            }
            if ($query === 'ROLLBACK' && $this->transactionSnapshot !== null) {
                $GLOBALS['fans_sso_users'] = $this->transactionSnapshot['users'];
                $GLOBALS['fans_sso_email_users'] = $this->transactionSnapshot['emails'];
                $this->transactionSnapshot = null;
            }
            if ($query === 'COMMIT') {
                $this->transactionSnapshot = null;
            }
            if (str_starts_with($query, 'INSERT INTO')) {
                return $this->insertResult;
            }
            if (str_starts_with($query, 'UPDATE ')) {
                return $this->updateResult;
            }
            return 1;
        }

        /** @return array<string,mixed>|null */
        public function get_row(string $query, mixed $output = null): ?array
        {
            if (str_starts_with($query, 'SHOW TABLE STATUS')) {
                return ['Engine' => $this->coreEngine];
            }
            return $this->stateRow;
        }

        public function get_var(string $query): mixed
        {
            if (str_starts_with($query, 'SELECT GET_LOCK')) {
                if ($this->onLock instanceof \Closure) {
                    ($this->onLock)();
                    $this->onLock = null;
                }
                return array_shift($this->lockResults) ?? 1;
            }
            if (str_starts_with($query, 'SELECT RELEASE_LOCK')) {
                return 1;
            }
            if (str_starts_with($query, 'SHOW TABLES LIKE')) {
                $last = end($this->prepared);
                $table = $last['args'][0] ?? null;
                return in_array($table, $this->presentTables, true) ? $table : null;
            }
            if (str_contains($query, 'SELECT wp_user_id')) {
                return $this->linkedId;
            }
            if (str_contains($query, 'SELECT faluss_id')) {
                return $this->linkedId === null ? null : '11111111-1111-4111-8111-111111111111';
            }
            return $this->existingLinkId;
        }

        public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4'; }
        public function get_results(string $query, mixed $output = null): array { return []; }
    }

    function home_url(string $path = ''): string
    {
        return rtrim($GLOBALS['fans_sso_home'], '/') . '/' . ltrim($path, '/');
    }
    function wp_parse_url(string $url): array|false { return parse_url($url); }
    function get_option(string $name, mixed $default = false): mixed { return $GLOBALS['fans_sso_options'][$name] ?? $default; }
    function update_option(string $name, mixed $value, bool $autoload = false): bool
    {
        $GLOBALS['fans_sso_options'][$name] = $value;
        return true;
    }
    function wp_salt(string $scheme = 'auth'): string { return 'fans-sso-test-salt-' . $scheme; }
    function is_user_logged_in(): bool { return $GLOBALS['fans_sso_logged_in']; }
    function get_current_user_id(): int { return $GLOBALS['fans_sso_current_user']; }
    function get_userdata(int $id): \WP_User|false { return $GLOBALS['fans_sso_users'][$id] ?? false; }
    function get_user_by(string $field, mixed $value): \WP_User|false
    {
        return $field === 'email'
            ? ($GLOBALS['fans_sso_email_users'][$value] ?? false)
            : ($GLOBALS['fans_sso_users'][(int) $value] ?? false);
    }
    function get_role(string $role): ?\WP_Role { return $role === 'subscriber' ? new \WP_Role() : null; }
    function wp_insert_user(array $data): int|\WP_Error
    {
        $GLOBALS['fans_sso_insert_calls'][] = $data;
        if ($GLOBALS['fans_sso_insert_error']) {
            return new \WP_Error();
        }
        $GLOBALS['fans_sso_users'][51] = new \WP_User(51, [$data['role']], $data['user_login'], $data['user_email']);
        $GLOBALS['fans_sso_email_users'][$data['user_email']] = $GLOBALS['fans_sso_users'][51];
        return 51;
    }
    function is_email(mixed $email): bool { return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false; }
    function is_wp_error(mixed $value): bool { return $value instanceof \WP_Error; }
    function wp_remote_post(string $url, array $args): mixed
    {
        $GLOBALS['fans_sso_remote_calls'][] = ['url' => $url, 'args' => $args];
        return array_shift($GLOBALS['fans_sso_remote_queue']);
    }
    function wp_remote_retrieve_response_code(mixed $response): int { return $response['code'] ?? 0; }
    function wp_remote_retrieve_body(mixed $response): string { return $response['body'] ?? ''; }
    function add_action(string $hook, callable $callback, int $priority = 10): void { $GLOBALS['fans_sso_hooks'][$hook] = $callback; }
    function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void { $GLOBALS['fans_sso_filters'][$hook] = $callback; }
    function add_shortcode(string $tag, callable $callback): void { $GLOBALS['fans_sso_shortcodes'][$tag] = $callback; }
}
