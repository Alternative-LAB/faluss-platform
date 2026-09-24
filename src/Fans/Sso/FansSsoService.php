<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Sso;

/** Local client of the Faluss Me authorization-code authority. */
final class FansSsoService
{
    public const COOKIE = 'faluss_fans_sso_state';
    public const START_ACTION = 'faluss_fans_sso_start';
    public const CALLBACK_QUERY_VAR = 'faluss_fans_sso_callback';
    public const STATE_TTL = 600;
    public const SESSION_TTL = 3600;

    public static function configured(): bool
    {
        $clientId = defined('FALUSS_FANS_SSO_CLIENT_ID') ? constant('FALUSS_FANS_SSO_CLIENT_ID') : null;
        $secret = defined('FALUSS_FANS_SSO_CLIENT_SECRET') ? constant('FALUSS_FANS_SSO_CLIENT_SECRET') : null;
        $home = wp_parse_url(home_url('/'));

        return is_string($clientId)
            && preg_match('/^[A-Za-z0-9._-]{1,191}$/D', $clientId) === 1
            && is_string($secret)
            && preg_match('/^[A-Za-z0-9_-]{43}$/D', $secret) === 1
            && is_array($home)
            && ($home['scheme'] ?? null) === 'https'
            && is_string($home['host'] ?? null)
            && $home['host'] !== ''
            && !in_array(strtolower($home['host']), ['faluss.me', 'www.faluss.me', 'faluss.com', 'www.faluss.com'], true)
            && !isset($home['user'])
            && !isset($home['pass'])
            && !isset($home['query'])
            && !isset($home['fragment']);
    }

    public static function register(): void
    {
        add_shortcode('faluss_fans_sso_button', [self::class, 'button']);
        add_action('admin_post_nopriv_' . self::START_ACTION, [self::class, 'start']);
        add_action('admin_post_' . self::START_ACTION, [self::class, 'start']);
        add_action('template_redirect', [self::class, 'callback'], 0);
        add_action('init', [self::class, 'rewrite'], 20);
        add_filter('query_vars', [self::class, 'queryVars']);
        add_filter('auth_cookie_expiration', [self::class, 'cookieExpiration'], PHP_INT_MAX, 3);
    }

    /** @param list<string> $variables
     *  @return list<string>
     */
    public static function queryVars(array $variables): array
    {
        $variables[] = self::CALLBACK_QUERY_VAR;

        return $variables;
    }

    public static function rewrite(): void
    {
        add_rewrite_rule(
            '^faluss-fans/sso/callback/?$',
            'index.php?' . self::CALLBACK_QUERY_VAR . '=1',
            'top'
        );
    }

    public static function button(mixed $attributes = []): string
    {
        unset($attributes);
        if (!self::configured()) {
            return '';
        }

        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::START_ACTION) . '">'
            . wp_nonce_field(self::START_ACTION, 'faluss_fans_sso_nonce', false, false)
            . '<button type="submit">' . esc_html__('Continuer avec Faluss', 'faluss-platform')
            . '</button></form>';
    }

    public static function start(): never
    {
        if (!self::configured()
            || strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST'
            || !self::validPostNonce()
        ) {
            self::localNotice();
        }

        $mode = is_user_logged_in() ? 'link' : 'login';
        $userId = $mode === 'link' ? get_current_user_id() : null;
        if ($mode === 'link' && (!is_int($userId) || !self::normalUser($userId) || self::isLinked($userId))) {
            self::localNotice();
        }

        try {
            $state = random_bytes(32);
            $verifier = random_bytes(32);
            $browser = random_bytes(32);
        } catch (\Throwable) {
            self::localNotice();
        }

        if (!self::storeState($state, $browser, $mode, $userId)) {
            self::localNotice();
        }

        $cookie = self::encode($state . $verifier . $browser);
        setcookie(self::COOKIE, $cookie, self::cookieOptions(time() + self::STATE_TTL));
        $_COOKIE[self::COOKIE] = $cookie;
        wp_redirect(add_query_arg([
            'response_type' => 'code',
            'client_id' => (string) constant('FALUSS_FANS_SSO_CLIENT_ID'),
            'redirect_uri' => self::callbackUri(),
            'scope' => $mode === 'link' ? 'identity.basic' : 'identity.basic identity.email',
            'state' => self::encode($state),
            'code_challenge' => self::pkceChallenge($verifier),
            'code_challenge_method' => 'S256',
        ], 'https://faluss.me/oauth/authorize'), 302, 'Faluss Fans SSO');
        exit;
    }

    public static function callback(): void
    {
        if ((string) get_query_var(self::CALLBACK_QUERY_VAR) !== '1') {
            return;
        }
        if (!self::configured()) {
            self::localNotice();
        }

        $state = isset($_GET['state']) && is_string($_GET['state']) ? wp_unslash($_GET['state']) : '';
        $code = isset($_GET['code']) && is_string($_GET['code']) ? wp_unslash($_GET['code']) : '';
        $pending = self::consumeState($state);
        self::clearCookie();
        if ($pending === null || !self::opaque($code)) {
            self::localNotice();
        }

        $claims = self::exchange($code, $pending['verifier'], $pending['flow_mode']);
        if ($claims === null) {
            self::localNotice();
        }

        $user = self::resolveUser($claims, $pending);
        if (!$user instanceof \WP_User || !self::markProved($user->ID, $claims['faluss_id'])) {
            self::localNotice();
        }

        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, false, is_ssl());
        do_action('wp_login', $user->user_login, $user);
        wp_safe_redirect(home_url('/'));
        exit;
    }

    public static function cookieExpiration(int $expiration, int $userId, bool $remember): int
    {
        unset($remember);

        return self::normalUser($userId) && self::isLinked($userId)
            ? self::SESSION_TTL
            : $expiration;
    }

    /** @return array{faluss_id:string,created_at:string,last_proved_at:string}|null */
    public static function currentLinkedSubject(): ?array
    {
        if (!is_user_logged_in() || !self::normalUser(get_current_user_id())) {
            return null;
        }
        global $wpdb;
        $table = FansSsoSchema::tables()['links'] ?? null;
        if (!is_string($table)) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT faluss_id, created_at, last_proved_at FROM ' . self::quote($table)
                . ' WHERE wp_user_id = %d LIMIT 1',
            get_current_user_id()
        ), 'ARRAY_A');

        return is_array($row) && self::uuid($row['faluss_id'] ?? null)
            ? [
                'faluss_id' => strtolower((string) $row['faluss_id']),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'last_proved_at' => (string) ($row['last_proved_at'] ?? ''),
            ]
            : null;
    }

    public static function callbackUrl(): string
    {
        return self::callbackUri();
    }

    private static function validPostNonce(): bool
    {
        return isset($_POST['faluss_fans_sso_nonce'])
            && is_string($_POST['faluss_fans_sso_nonce'])
            && wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST['faluss_fans_sso_nonce'])),
                self::START_ACTION
            ) !== false;
    }

    private static function storeState(string $state, string $browser, string $mode, ?int $userId): bool
    {
        global $wpdb;
        $table = FansSsoSchema::tables()['states'] ?? null;
        if (!is_string($table)) {
            return false;
        }

        return $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . self::quote($table)
                . ' (state_hash, browser_hash, flow_mode, wp_user_id, expires_at, created_at)'
                . ' VALUES (%s, %s, %s, NULLIF(%d, 0), %s, %s)',
            hash('sha256', $state),
            self::browserHash($browser),
            $mode,
            $userId ?? 0,
            gmdate('Y-m-d H:i:s', time() + self::STATE_TTL),
            gmdate('Y-m-d H:i:s')
        )) === 1;
    }

    /** @return array{verifier:string,flow_mode:string,wp_user_id:int}|null */
    private static function consumeState(mixed $state): ?array
    {
        $raw = self::cookieState();
        if ($raw === null || !self::opaque($state) || !hash_equals(self::encode($raw['state']), $state)) {
            return null;
        }
        global $wpdb;
        $table = FansSsoSchema::tables()['states'] ?? null;
        if (!is_string($table) || $wpdb->query('START TRANSACTION') === false) {
            return null;
        }

        try {
            $row = $wpdb->get_row($wpdb->prepare(
                'SELECT id, flow_mode, wp_user_id, expires_at, consumed_at FROM ' . self::quote($table)
                    . ' WHERE state_hash = %s AND browser_hash = %s FOR UPDATE',
                hash('sha256', $raw['state']),
                self::browserHash($raw['browser'])
            ), 'ARRAY_A');
            if (!is_array($row)
                || !in_array($row['flow_mode'] ?? null, ['login', 'link'], true)
                || $row['consumed_at'] !== null
                || !self::future($row['expires_at'] ?? null)
                || ($row['flow_mode'] === 'link' && (int) ($row['wp_user_id'] ?? 0) < 1)
                || $wpdb->query($wpdb->prepare(
                    'UPDATE ' . self::quote($table)
                        . ' SET consumed_at = %s WHERE id = %d AND consumed_at IS NULL',
                    gmdate('Y-m-d H:i:s'),
                    $row['id']
                )) !== 1
                || $wpdb->query('COMMIT') === false
            ) {
                $wpdb->query('ROLLBACK');

                return null;
            }

            return [
                'verifier' => self::encode($raw['verifier']),
                'flow_mode' => $row['flow_mode'],
                'wp_user_id' => (int) ($row['wp_user_id'] ?? 0),
            ];
        } catch (\Throwable) {
            $wpdb->query('ROLLBACK');

            return null;
        }
    }

    /** @return array{faluss_id:string,scope:string,email?:string}|null */
    private static function exchange(string $code, string $verifier, string $mode): ?array
    {
        if (!self::configured() || !self::opaque($code) || !self::opaque($verifier)) {
            return null;
        }
        $response = wp_remote_post('https://faluss.me/oauth/token', [
            'timeout' => 15,
            'redirection' => 0,
            'sslverify' => true,
            'limit_response_size' => 8192,
            'headers' => ['Accept' => 'application/json'],
            'body' => [
                'grant_type' => 'authorization_code',
                'client_id' => (string) constant('FALUSS_FANS_SSO_CLIENT_ID'),
                'client_secret' => (string) constant('FALUSS_FANS_SSO_CLIENT_SECRET'),
                'redirect_uri' => self::callbackUri(),
                'code' => $code,
                'code_verifier' => $verifier,
            ],
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $body = wp_remote_retrieve_body($response);
        if (strlen($body) > 8192) {
            return null;
        }
        $claims = json_decode($body, true);
        if (!is_array($claims) || !self::uuid($claims['faluss_id'] ?? null)) {
            return null;
        }
        $scopes = self::scopes($claims['scope'] ?? null);
        if ($scopes === null
            || $scopes !== ($mode === 'link' ? ['identity.basic'] : ['identity.basic', 'identity.email'])
            || ($mode === 'login' && !is_email($claims['email'] ?? null))
            || ($mode === 'link' && isset($claims['email']))
        ) {
            return null;
        }

        $result = [
            'faluss_id' => strtolower($claims['faluss_id']),
            'scope' => implode(' ', $scopes),
        ];
        if ($mode === 'login') {
            $result['email'] = $claims['email'];
        }

        return $result;
    }

    /** @param array{faluss_id:string,scope:string,email?:string} $claims
     *  @param array{verifier:string,flow_mode:string,wp_user_id:int} $pending
     */
    private static function resolveUser(array $claims, array $pending): ?\WP_User
    {
        global $wpdb;
        $table = FansSsoSchema::tables()['links'] ?? null;
        if (!is_string($table)) {
            return null;
        }
        $linkedId = $wpdb->get_var($wpdb->prepare(
            'SELECT wp_user_id FROM ' . self::quote($table) . ' WHERE faluss_id = %s LIMIT 1',
            $claims['faluss_id']
        ));

        if ($pending['flow_mode'] === 'link') {
            $userId = $pending['wp_user_id'];
            if ($linkedId !== null
                || !is_user_logged_in()
                || $userId !== get_current_user_id()
                || !self::normalUser($userId)
                || self::isLinked($userId)
            ) {
                return null;
            }

            return self::link($userId, $claims['faluss_id']) ? self::user($userId) : null;
        }

        if ($linkedId !== null) {
            $user = self::user((int) $linkedId);

            return $user instanceof \WP_User && self::normalUser($user->ID) ? $user : null;
        }

        if (!isset($claims['email'])) {
            return null;
        }

        return self::createAndLink($claims['email'], $claims['faluss_id']);
    }

    private static function createAndLink(string $email, string $falussId): ?\WP_User
    {
        global $wpdb;
        $table = FansSsoSchema::tables()['links'] ?? null;
        if (!is_string($table) || !self::coreUserTablesTransactional()) {
            return null;
        }

        // Both locks are connection scoped. They serialize a subject and an email before
        // WordPress checks its non-unique user_email index and inserts a local user.
        $locks = [
            'fans_subject_' . substr(hash('sha256', $falussId), 0, 40),
            'fans_email_' . substr(hash('sha256', strtolower($email)), 0, 40),
        ];
        $acquired = [];
        try {
            foreach ($locks as $lock) {
                if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock, 5)) !== 1) {
                    return null;
                }
                $acquired[] = $lock;
            }
            if ($wpdb->query('START TRANSACTION') === false) {
                return null;
            }

            $linkedId = $wpdb->get_var($wpdb->prepare(
                'SELECT wp_user_id FROM ' . self::quote($table) . ' WHERE faluss_id = %s LIMIT 1',
                $falussId
            ));
            if ($linkedId !== null) {
                $wpdb->query('ROLLBACK');
                $user = self::user((int) $linkedId);

                return $user instanceof \WP_User && self::normalUser($user->ID) ? $user : null;
            }
            if (get_user_by('email', $email) instanceof \WP_User || !get_role('subscriber') instanceof \WP_Role) {
                $wpdb->query('ROLLBACK');

                return null;
            }

            $created = wp_insert_user([
                'user_login' => 'fans_' . bin2hex(random_bytes(10)),
                'user_pass' => bin2hex(random_bytes(32)),
                'user_email' => $email,
                'role' => 'subscriber',
            ]);
            if (is_wp_error($created) || $created < 1
                || !self::insertLink($created, $falussId)
            ) {
                $wpdb->query('ROLLBACK');

                return null;
            }
            if ($wpdb->query('COMMIT') === false) {
                $wpdb->query('ROLLBACK');

                return null;
            }

            return self::user($created);
        } catch (\Throwable) {
            $wpdb->query('ROLLBACK');

            return null;
        } finally {
            foreach (array_reverse($acquired) as $lock) {
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
        }
    }

    private static function coreUserTablesTransactional(): bool
    {
        global $wpdb;
        foreach ([$wpdb->users, $wpdb->usermeta] as $table) {
            $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name = %s', $table), 'ARRAY_A');
            if (!is_array($status) || strcasecmp((string) ($status['Engine'] ?? ''), 'InnoDB') !== 0) {
                return false;
            }
        }

        return true;
    }

    private static function link(int $userId, string $falussId): bool
    {
        global $wpdb;
        $table = FansSsoSchema::tables()['links'] ?? null;
        if (!is_string($table) || $wpdb->query('START TRANSACTION') === false) {
            return false;
        }
        try {
            if (!self::insertLink($userId, $falussId) || $wpdb->query('COMMIT') === false) {
                $wpdb->query('ROLLBACK');

                return false;
            }

            return true;
        } catch (\Throwable) {
            $wpdb->query('ROLLBACK');

            return false;
        }
    }

    private static function insertLink(int $userId, string $falussId): bool
    {
        global $wpdb;
        $table = FansSsoSchema::tables()['links'] ?? null;
        if (!is_string($table)) {
            return false;
        }
        $existing = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::quote($table)
                . ' WHERE wp_user_id = %d OR faluss_id = %s FOR UPDATE',
            $userId,
            $falussId
        ));
        if ($existing !== null) {
            return false;
        }
        $now = gmdate('Y-m-d H:i:s');

        return $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . self::quote($table)
                . ' (wp_user_id, faluss_id, created_at, last_proved_at) VALUES (%d, %s, %s, %s)',
            $userId,
            $falussId,
            $now,
            $now
        )) === 1;
    }

    private static function markProved(int $userId, string $falussId): bool
    {
        global $wpdb;
        $table = FansSsoSchema::tables()['links'] ?? null;
        if (!is_string($table)) {
            return false;
        }
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::quote($table)
                . ' SET last_proved_at = %s WHERE wp_user_id = %d AND faluss_id = %s',
            gmdate('Y-m-d H:i:s'),
            $userId,
            $falussId
        ));
        if ($updated === 1) {
            return true;
        }

        return $updated === 0 && $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::quote($table)
                . ' WHERE wp_user_id = %d AND faluss_id = %s LIMIT 1',
            $userId,
            $falussId
        )) !== null;
    }

    private static function user(int $userId): ?\WP_User
    {
        $user = get_user_by('id', $userId);

        return $user instanceof \WP_User ? $user : null;
    }

    private static function normalUser(int $userId): bool
    {
        $user = get_userdata($userId);

        return $user instanceof \WP_User && array_values((array) $user->roles) === ['subscriber'];
    }

    private static function isLinked(int $userId): bool
    {
        global $wpdb;
        $table = FansSsoSchema::tables()['links'] ?? null;

        return is_string($table)
            && self::uuid($wpdb->get_var($wpdb->prepare(
                'SELECT faluss_id FROM ' . self::quote($table) . ' WHERE wp_user_id = %d LIMIT 1',
                $userId
            )));
    }

    /** @return array{state:string,verifier:string,browser:string}|null */
    private static function cookieState(): ?array
    {
        $cookie = $_COOKIE[self::COOKIE] ?? null;
        if (!is_string($cookie) || preg_match('/^[A-Za-z0-9_-]{128}$/D', $cookie) !== 1) {
            return null;
        }
        $raw = base64_decode(strtr($cookie, '-_', '+/'), true);

        return is_string($raw) && strlen($raw) === 96 && hash_equals(self::encode($raw), $cookie)
            ? [
                'state' => substr($raw, 0, 32),
                'verifier' => substr($raw, 32, 32),
                'browser' => substr($raw, 64, 32),
            ]
            : null;
    }

    private static function browserHash(string $browser): string
    {
        return hash_hmac('sha256', $browser, wp_salt('faluss_fans_sso_state'));
    }

    private static function callbackUri(): string
    {
        return home_url('/faluss-fans/sso/callback');
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function pkceChallenge(string $verifier): string
    {
        return self::encode(hash('sha256', self::encode($verifier), true));
    }

    private static function opaque(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{43}$/D', $value) === 1;
    }

    private static function uuid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }

    /** @return list<string>|null */
    private static function scopes(mixed $value): ?array
    {
        if (!is_string($value)) {
            return null;
        }
        $scopes = array_values(array_unique(array_filter(explode(' ', trim($value)))));
        if ($scopes === [] || array_diff($scopes, ['identity.basic', 'identity.email']) !== []) {
            return null;
        }
        sort($scopes);

        return $scopes;
    }

    private static function future(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) === 1
            && $value > gmdate('Y-m-d H:i:s');
    }

    /** @return array{expires:int,path:string,domain:string,secure:bool,httponly:bool,samesite:'Lax'} */
    private static function cookieOptions(int $expires): array
    {
        return [
            'expires' => $expires,
            'path' => '/',
            'domain' => '',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    private static function clearCookie(): void
    {
        setcookie(self::COOKIE, '', self::cookieOptions(time() - 3600));
        unset($_COOKIE[self::COOKIE]);
    }

    private static function localNotice(): never
    {
        wp_safe_redirect(add_query_arg('faluss_fans_sso', 'invalid', home_url('/')));
        exit;
    }

    private static function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
