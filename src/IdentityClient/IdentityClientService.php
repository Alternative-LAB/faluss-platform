<?php

declare(strict_types=1);

namespace Faluss\Platform\IdentityClient;

/** Local Authorization Code + PKCE client. WordPress sessions remain local. */
final class IdentityClientService
{
    public const SETTINGS = 'faluss_identity_client_settings';
    public const COOKIE = 'faluss_identity_client_state';
    public const TTL = 600;
    public const MEMBER_SESSION_TTL = 3600;
    public const STYLE = 'faluss-identity-client-button';
    public const START_ACTION = 'faluss_identity_client_start';
    public const CONTINUE_ACTION = 'faluss_identity_client_continue';
    public const MEMBER_APPS_META = '_faluss_identity_client_member_apps_v1';

    public static function register(): void
    {
        add_shortcode('faluss_identity_client_button', [self::class, 'shortcode']);
        add_action('admin_post_nopriv_' . self::START_ACTION, [self::class, 'start']);
        add_action('admin_post_' . self::START_ACTION, [self::class, 'start']);
        add_action('admin_post_nopriv_' . self::CONTINUE_ACTION, [self::class, 'continueSession']);
        add_action('admin_post_' . self::CONTINUE_ACTION, [self::class, 'continueSession']);
        add_action('template_redirect', [self::class, 'callback'], 0);
        add_action('init', [self::class, 'rewrite'], 20);
        add_filter('query_vars', [self::class, 'queryVars']);
        add_filter('auth_cookie_expiration', [self::class, 'memberCookieExpiration'], PHP_INT_MAX, 3);
    }

    /** @param list<string> $variables
     *  @return list<string>
     */
    public static function queryVars(array $variables): array
    {
        $variables[] = 'faluss_identity_client_callback';

        return $variables;
    }

    public static function rewrite(): void
    {
        add_rewrite_rule(
            '^faluss-identity/callback/?$',
            'index.php?faluss_identity_client_callback=1',
            'top'
        );
    }

    public static function assets(): void
    {
        wp_register_style(
            self::STYLE,
            plugins_url('assets/identity-client.css', dirname(__DIR__, 2) . '/faluss-platform.php'),
            [],
            '0.1.0'
        );
    }

    /** @return array{enabled:bool,authority:string,client_id:string,return_urls:list<string>} */
    public static function config(): array
    {
        $stored = get_option(self::SETTINGS, []);
        $stored = is_array($stored) ? $stored : [];
        $merged = wp_parse_args($stored, [
            'enabled' => false,
            'authority' => 'https://faluss.me',
            'client_id' => '',
            'return_urls' => [],
        ]);

        return [
            'enabled' => !empty($merged['enabled']),
            'authority' => is_string($merged['authority']) ? $merged['authority'] : 'https://faluss.me',
            'client_id' => is_string($merged['client_id']) ? $merged['client_id'] : '',
            'return_urls' => array_values(array_filter(
                is_array($merged['return_urls']) ? $merged['return_urls'] : [],
                'is_string'
            )),
        ];
    }

    public static function enabled(): bool
    {
        return self::config()['enabled'];
    }

    public static function shortcode(mixed $attributes = []): string
    {
        return self::button(is_array($attributes) ? $attributes : []);
    }

    /** @param array<string, mixed> $attributes */
    public static function button(array $attributes = [], bool $inline = true): string
    {
        if (!self::enabled()) {
            return '';
        }
        if (!wp_style_is(self::STYLE, 'registered')) {
            self::assets();
        }
        wp_enqueue_style(self::STYLE);
        $attributes = wp_parse_args($attributes, [
            'label' => __('Continuer avec Faluss', 'faluss-platform'),
            'redirect_url' => home_url('/'),
            'accent_color' => '#FF3D16',
            'text_color' => '#080808',
            'surface_color' => '#FFFFFF',
            'radius' => '999',
        ]);
        $redirect = self::allowedReturn($attributes['redirect_url'] ?? '');
        $style = $inline
            ? ' style="--fic-accent:' . esc_attr(self::hex($attributes['accent_color'] ?? '', '#FF3D16'))
                . ';--fic-ink:' . esc_attr(self::hex($attributes['text_color'] ?? '', '#080808'))
                . ';--fic-surface:' . esc_attr(self::hex($attributes['surface_color'] ?? '', '#FFFFFF'))
                . ';--fic-radius:' . max(0, min(48, (int) ($attributes['radius'] ?? 999))) . 'px;"'
            : '';

        return '<div class="faluss-identity-client-button"' . $style
            . '><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::CONTINUE_ACTION) . '">'
            . '<input type="hidden" name="redirect_to" value="' . esc_attr($redirect) . '">'
            . wp_nonce_field(self::CONTINUE_ACTION, 'faluss_identity_client_nonce', false, false)
            . '<button class="faluss-identity-client-button__action" type="submit">'
            . esc_html(is_scalar($attributes['label'] ?? null) ? (string) $attributes['label'] : '')
            . '</button></form></div>';
    }

    public static function start(): never
    {
        self::begin(self::START_ACTION);
    }

    public static function continueSession(): never
    {
        self::begin(self::CONTINUE_ACTION);
    }

    private static function begin(string $nonceAction): never
    {
        if (!self::enabled() || !self::validPostNonce($nonceAction)) {
            self::localNotice('invalid');
        }
        $config = self::config();
        $redirect = self::allowedReturn(
            isset($_POST['redirect_to']) && is_string($_POST['redirect_to'])
                ? wp_unslash($_POST['redirect_to'])
                : ''
        );
        if ($config['client_id'] === '' || !self::authority($config['authority'])) {
            self::localNotice('invalid', $redirect);
        }
        if (self::isLocalMemberSession()) {
            self::redirectToLocal($redirect);
        }

        $mode = is_user_logged_in() ? 'link' : 'login';
        $userId = $mode === 'link' ? get_current_user_id() : null;
        try {
            $state = random_bytes(32);
            $verifier = random_bytes(32);
            $browser = random_bytes(32);
        } catch (\Throwable) {
            self::localNotice('invalid', $redirect);
        }

        $browserHash = self::hmac($browser);
        if (!self::storeState(hash('sha256', $state), $browserHash, $redirect, $mode, $userId)
        ) {
            self::localNotice('invalid', $redirect);
        }

        $cookie = self::base64urlEncode($state . $verifier . $browser);
        setcookie(self::COOKIE, $cookie, self::cookieOptions(time() + self::TTL));
        $_COOKIE[self::COOKIE] = $cookie;
        $query = [
            'response_type' => 'code',
            'client_id' => $config['client_id'],
            'redirect_uri' => self::callbackUri(),
            'scope' => $mode === 'link' ? 'identity.basic' : 'identity.basic identity.email',
            'state' => self::base64urlEncode($state),
            'code_challenge' => self::pkceChallenge($verifier),
            'code_challenge_method' => 'S256',
        ];
        wp_redirect(
            add_query_arg($query, rtrim($config['authority'], '/') . '/oauth/authorize'),
            302,
            'Faluss Identity Client'
        );
        exit;
    }

    public static function callback(): void
    {
        if ((string) get_query_var('faluss_identity_client_callback') !== '1') {
            return;
        }
        if (!self::enabled()) {
            self::localNotice('invalid');
        }

        $state = isset($_GET['state']) && is_string($_GET['state']) ? wp_unslash($_GET['state']) : '';
        $code = isset($_GET['code']) && is_string($_GET['code']) ? wp_unslash($_GET['code']) : '';
        $row = self::consumeState($state);
        self::clearCookie();
        if ($row === null || !self::isOpaque($code)) {
            self::localNotice('invalid');
        }

        $claims = self::exchange($code, (string) $row['verifier']);
        if ($claims === null) {
            self::localNotice('invalid', (string) $row['redirect_url']);
        }
        $user = self::resolveUser($claims, $row);
        if (!$user instanceof \WP_User) {
            self::localNotice('link_required', (string) $row['redirect_url']);
        }
        if (!self::synchronizeMemberAppProjections($user->ID, $claims)) {
            self::localNotice('invalid', (string) $row['redirect_url']);
        }

        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, false, is_ssl());
        do_action('wp_login', $user->user_login, $user);
        self::redirectToLocal((string) $row['redirect_url']);
    }

    public static function memberCookieExpiration(int $expiration, int $userId, bool $remember): int
    {
        unset($remember);

        return self::isLinkedNormalMember($userId) ? self::MEMBER_SESSION_TTL : $expiration;
    }

    /** @return array{contract_version:string,publication_status:string,canonical_url:string}|null */
    public static function memberAppProjection(mixed $falussId, mixed $appKey): ?array
    {
        if (!self::isUuid($falussId) || $appKey !== 'me') {
            return null;
        }
        global $wpdb;
        $tables = IdentityClientSchema::tables();
        if (empty($tables['links'])) {
            return null;
        }
        $userId = $wpdb->get_var($wpdb->prepare(
            'SELECT wp_user_id FROM ' . self::quoteIdentifier($tables['links'])
                . ' WHERE faluss_id = %s LIMIT 1',
            strtolower($falussId)
        ));
        if (!$userId) {
            return null;
        }
        $apps = get_user_meta((int) $userId, self::MEMBER_APPS_META, true);

        return self::validatedMeProjection(is_array($apps) ? ($apps['me'] ?? null) : null);
    }

    public static function callbackUrl(): string
    {
        return self::callbackUri();
    }

    private static function isLocalMemberSession(): bool
    {
        return is_user_logged_in() && self::isLinkedNormalMember(get_current_user_id());
    }

    private static function isLinkedNormalMember(int $userId): bool
    {
        $user = get_userdata($userId);
        if (!$user instanceof \WP_User || array_values((array) $user->roles) !== ['subscriber']) {
            return false;
        }
        global $wpdb;
        $tables = IdentityClientSchema::tables();
        if (empty($tables['links'])) {
            return false;
        }

        return $wpdb->get_var($wpdb->prepare(
            'SELECT faluss_id FROM ' . self::quoteIdentifier($tables['links']) . ' WHERE wp_user_id = %d',
            $user->ID
        )) !== null;
    }

    private static function validPostNonce(string $action): bool
    {
        return isset($_POST['faluss_identity_client_nonce'])
            && is_string($_POST['faluss_identity_client_nonce'])
            && wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST['faluss_identity_client_nonce'])),
                $action
            ) !== false;
    }

    /** @return array<string, mixed>|null */
    private static function consumeState(mixed $state): ?array
    {
        $raw = self::cookieState();
        if ($raw === null || !self::isOpaque($state) || !hash_equals(self::base64urlEncode($raw['state']), $state)) {
            return null;
        }
        global $wpdb;
        $tables = IdentityClientSchema::tables();
        $browserHash = self::hmac($raw['browser']);
        if (empty($tables['states']) || $wpdb->query('START TRANSACTION') === false) {
            return null;
        }

        try {
            $row = $wpdb->get_row($wpdb->prepare(
                'SELECT id, redirect_url, flow_mode, wp_user_id, expires_at, consumed_at FROM '
                    . self::quoteIdentifier($tables['states'])
                    . ' WHERE state_hash = %s AND browser_hash = %s FOR UPDATE',
                hash('sha256', $raw['state']),
                $browserHash
            ), 'ARRAY_A');
            if (!is_array($row)
                || $row['consumed_at'] !== null
                || !self::future($row['expires_at'] ?? '')
                || $wpdb->query($wpdb->prepare(
                    'UPDATE ' . self::quoteIdentifier($tables['states'])
                        . ' SET consumed_at = %s WHERE id = %d AND consumed_at IS NULL',
                    gmdate('Y-m-d H:i:s'),
                    $row['id']
                )) !== 1
                || $wpdb->query('COMMIT') === false
            ) {
                $wpdb->query('ROLLBACK');

                return null;
            }
            $row['verifier'] = self::base64urlEncode($raw['verifier']);

            return $row;
        } catch (\Throwable) {
            $wpdb->query('ROLLBACK');

            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private static function exchange(string $code, string $verifier): ?array
    {
        if (!self::isOpaque($code) || !self::isOpaque($verifier)) {
            return null;
        }
        $config = self::config();
        $body = [
            'grant_type' => 'authorization_code',
            'client_id' => $config['client_id'],
            'redirect_uri' => self::callbackUri(),
            'code' => $code,
            'code_verifier' => $verifier,
        ];
        $secret = defined('FALUSS_IDENTITY_CLIENT_SECRET') ? constant('FALUSS_IDENTITY_CLIENT_SECRET') : '';
        if (is_string($secret) && $secret !== '') {
            $body['client_secret'] = $secret;
        }
        $response = wp_remote_post(rtrim($config['authority'], '/') . '/oauth/token', [
            'timeout' => 15,
            'redirection' => 0,
            'sslverify' => true,
            'headers' => ['Accept' => 'application/json'],
            'body' => $body,
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $claims = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($claims) || !self::isUuid($claims['faluss_id'] ?? '')) {
            return null;
        }
        $scopes = self::scopes($claims['scope'] ?? '');
        if ($scopes === null || !in_array('identity.basic', $scopes, true)) {
            return null;
        }
        if (isset($claims['email'])
            && (!in_array('identity.email', $scopes, true) || !is_email($claims['email']))
        ) {
            return null;
        }

        return $claims;
    }

    /** @param array<string, mixed> $claims
     *  @param array<string, mixed> $state
     */
    private static function resolveUser(array $claims, array $state): ?\WP_User
    {
        global $wpdb;
        $tables = IdentityClientSchema::tables();
        if (empty($tables['links'])) {
            return null;
        }
        $linked = $wpdb->get_var($wpdb->prepare(
            'SELECT wp_user_id FROM ' . self::quoteIdentifier($tables['links']) . ' WHERE faluss_id = %s',
            $claims['faluss_id']
        ));
        if (($state['flow_mode'] ?? '') === 'link') {
            if ($linked || !is_user_logged_in() || (int) ($state['wp_user_id'] ?? 0) !== get_current_user_id()) {
                return null;
            }

            return self::link((int) $state['wp_user_id'], (string) $claims['faluss_id'])
                ? self::userById((int) $state['wp_user_id'])
                : null;
        }
        if ($linked) {
            return self::userById((int) $linked);
        }
        if (empty($claims['email']) || !is_string($claims['email'])) {
            return null;
        }
        if (get_user_by('email', $claims['email']) instanceof \WP_User || !get_role('subscriber') instanceof \WP_Role) {
            return null;
        }

        try {
            $userId = wp_insert_user([
                'user_login' => 'faluss_' . bin2hex(random_bytes(10)),
                'user_pass' => bin2hex(random_bytes(32)),
                'user_email' => $claims['email'],
                'role' => 'subscriber',
            ]);
        } catch (\Throwable) {
            return null;
        }
        if (is_wp_error($userId)) {
            return null;
        }

        return self::link((int) $userId, (string) $claims['faluss_id'])
            ? self::userById((int) $userId)
            : null;
    }

    private static function userById(int $userId): ?\WP_User
    {
        $user = get_user_by('id', $userId);

        return $user instanceof \WP_User ? $user : null;
    }

    private static function link(int $userId, string $falussId): bool
    {
        global $wpdb;
        $tables = IdentityClientSchema::tables();
        if (empty($tables['links']) || $wpdb->query('START TRANSACTION') === false) {
            return false;
        }
        try {
            $existing = $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM ' . self::quoteIdentifier($tables['links'])
                    . ' WHERE wp_user_id = %d OR faluss_id = %s FOR UPDATE',
                $userId,
                $falussId
            ));
            $now = gmdate('Y-m-d H:i:s');
            if ($existing
                || $wpdb->query($wpdb->prepare(
                    'INSERT INTO ' . self::quoteIdentifier($tables['links'])
                        . ' (wp_user_id, faluss_id, created_at, last_proved_at) VALUES (%d, %s, %s, %s)',
                    $userId,
                    $falussId,
                    $now,
                    $now
                )) !== 1
                || $wpdb->query('COMMIT') === false
            ) {
                $wpdb->query('ROLLBACK');

                return false;
            }

            return true;
        } catch (\Throwable) {
            $wpdb->query('ROLLBACK');

            return false;
        }
    }

    /** @param array<string, mixed> $claims */
    private static function synchronizeMemberAppProjections(int $userId, array $claims): bool
    {
        $projection = self::validatedMeProjection(
            is_array($claims['apps'] ?? null) ? ($claims['apps']['me'] ?? null) : null
        );
        if ($projection === null) {
            $current = get_user_meta($userId, self::MEMBER_APPS_META, true);

            return empty($current) || delete_user_meta($userId, self::MEMBER_APPS_META);
        }
        $apps = ['me' => $projection];
        $current = get_user_meta($userId, self::MEMBER_APPS_META, true);

        return $apps === $current || update_user_meta($userId, self::MEMBER_APPS_META, $apps) !== false;
    }

    /** @return array{contract_version:string,publication_status:string,canonical_url:string}|null */
    private static function validatedMeProjection(mixed $projection): ?array
    {
        if (!is_array($projection)
            || ($projection['contract_version'] ?? null) !== '1'
            || ($projection['publication_status'] ?? null) !== 'published'
            || !is_string($projection['canonical_url'] ?? null)
        ) {
            return null;
        }
        $expected = rtrim(self::config()['authority'], '/') . '/mon-faluss';

        return hash_equals($expected, $projection['canonical_url'])
            ? ['contract_version' => '1', 'publication_status' => 'published', 'canonical_url' => $expected]
            : null;
    }

    private static function storeState(
        string $stateHash,
        string $browserHash,
        string $url,
        string $mode,
        ?int $userId
    ): bool {
        global $wpdb;
        $tables = IdentityClientSchema::tables();

        return !empty($tables['states'])
            && $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . self::quoteIdentifier($tables['states'])
                    . ' (state_hash, browser_hash, redirect_url, flow_mode, wp_user_id, expires_at, created_at)'
                    . ' VALUES (%s, %s, %s, %s, %d, %s, %s)',
                $stateHash,
                $browserHash,
                $url,
                $mode,
                $userId,
                gmdate('Y-m-d H:i:s', time() + self::TTL),
                gmdate('Y-m-d H:i:s')
            )) === 1;
    }

    /** @return array{state:string,verifier:string,browser:string}|null */
    private static function cookieState(): ?array
    {
        if (empty($_COOKIE[self::COOKIE]) || !is_string($_COOKIE[self::COOKIE])) {
            return null;
        }
        $encoded = strtr($_COOKIE[self::COOKIE], '-_', '+/');
        $raw = base64_decode($encoded . str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);

        return is_string($raw) && strlen($raw) === 96
            ? ['state' => substr($raw, 0, 32), 'verifier' => substr($raw, 32, 32), 'browser' => substr($raw, 64, 32)]
            : null;
    }

    private static function allowedReturn(mixed $url): string
    {
        $home = home_url('/');
        $allowed = array_unique(array_merge([$home], self::config()['return_urls']));

        return is_string($url) && in_array($url, $allowed, true) && self::isLocalUrl($url) ? $url : $home;
    }

    private static function isLocalUrl(string $url): bool
    {
        $candidate = wp_parse_url($url);
        $home = wp_parse_url(home_url('/'));
        if (!is_array($candidate)
            || !is_array($home)
            || !isset($candidate['scheme'], $candidate['host'], $home['scheme'], $home['host'])
            || isset($candidate['user'])
            || isset($candidate['pass'])
            || isset($candidate['fragment'])
        ) {
            return false;
        }
        $candidatePort = isset($candidate['port'])
            ? (int) $candidate['port']
            : (strtolower((string) $candidate['scheme']) === 'https' ? 443 : 80);
        $homePort = isset($home['port'])
            ? (int) $home['port']
            : (strtolower((string) $home['scheme']) === 'https' ? 443 : 80);

        return strcasecmp((string) $candidate['scheme'], (string) $home['scheme']) === 0
            && strcasecmp((string) $candidate['host'], (string) $home['host']) === 0
            && $candidatePort === $homePort;
    }

    private static function authority(mixed $authority): bool
    {
        return rtrim(is_string($authority) ? $authority : '', '/') === 'https://faluss.me';
    }

    private static function callbackUri(): string
    {
        return home_url('/faluss-identity/callback');
    }

    private static function hmac(string $value): string
    {
        return hash_hmac('sha256', $value, wp_salt('faluss_identity_client_state'));
    }

    private static function pkceChallenge(string $verifier): string
    {
        return self::base64urlEncode(hash('sha256', self::base64urlEncode($verifier), true));
    }

    private static function base64urlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function isOpaque(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{43}$/D', $value) === 1;
    }

    private static function isUuid(mixed $value): bool
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

        return $scopes;
    }

    private static function hex(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^#[A-Fa-f0-9]{6}$/D', $value) === 1 ? $value : $fallback;
    }

    /** @return array{expires:int,path:string,domain:string,secure:bool,httponly:bool,samesite:'Lax'} */
    private static function cookieOptions(int $expires): array
    {
        return [
            'expires' => $expires,
            'path' => '/',
            'domain' => defined('COOKIE_DOMAIN') ? (string) constant('COOKIE_DOMAIN') : '',
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

    private static function redirectToLocal(mixed $url): never
    {
        wp_safe_redirect(self::allowedReturn($url));
        exit;
    }

    private static function localNotice(string $notice = 'invalid', mixed $url = null): never
    {
        wp_safe_redirect(add_query_arg(
            'faluss_identity_client_notice',
            sanitize_key($notice),
            self::allowedReturn($url)
        ));
        exit;
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private static function future(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) === 1
            && $value > gmdate('Y-m-d H:i:s');
    }
}
