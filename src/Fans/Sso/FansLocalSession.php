<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Sso;

/** Local WordPress session only. Never calls Identity or another application's logout. */
final class FansLocalSession
{
    public const ACTION = 'faluss_fans_logout';
    public static function register(): void
    {
        add_filter('send_auth_cookies', [self::class, 'sendCookies'], PHP_INT_MAX, 6);
        add_action('admin_post_' . self::ACTION, [self::class, 'logout']);
        add_action('admin_post_nopriv_' . self::ACTION, [self::class, 'logout']);
        add_action('rest_api_init', [self::class, 'routes']);
    }
    public static function sendCookies(bool $send, int $expire, int $expiration, int $userId, string $scheme, string $token): bool
    {
        // Keep WordPress clearing, administrator cookies and earlier filter refusals untouched.
        if (!$send || $userId < 1 || !FansSsoService::linkedMember($userId)) { return $send; }
        if (!in_array($scheme, ['auth', 'secure_auth'], true) || $token === '' || $expiration <= time()) { return false; }
        unset($expire);
        // WordPress normally adds 12 hours to persistent browser cookies for its grace period.
        // These local members get the exact same absolute deadline in cookie, signature and token.
        $auth = wp_generate_auth_cookie($userId, $expiration, $scheme, $token);
        $logged = wp_generate_auth_cookie($userId, $expiration, 'logged_in', $token);
        $options = ['expires' => $expiration, 'domain' => (string) constant('COOKIE_DOMAIN'),
            'secure' => $scheme === 'secure_auth', 'httponly' => true, 'samesite' => 'Lax'];
        foreach (array_unique([constant('PLUGINS_COOKIE_PATH'), constant('ADMIN_COOKIE_PATH')]) as $path) {
            setcookie((string) constant($scheme === 'secure_auth' ? 'SECURE_AUTH_COOKIE' : 'AUTH_COOKIE'), $auth, $options + ['path' => $path]);
        }
        foreach (array_unique([constant('COOKIEPATH'), constant('SITECOOKIEPATH')]) as $path) {
            setcookie((string) constant('LOGGED_IN_COOKIE'), $logged, $options + ['path' => $path]);
        }
        return false;
    }
    public static function expires(): ?int
    {
        if (FansSsoService::currentLinkedSubject() === null) { return null; }
        $cookie = wp_parse_auth_cookie('', 'logged_in');
        if (!is_array($cookie) || !ctype_digit((string) $cookie['expiration'])) { return null; }
        $session = \WP_Session_Tokens::get_instance(get_current_user_id())->get($cookie['token']);
        return is_array($session) && is_numeric($session['expiration'] ?? null)
            ? min((int) $cookie['expiration'], (int) $session['expiration']) : null;
    }
    public static function routes(): void
    {
        register_rest_route('faluss-fans/v1', '/session', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => [self::class, 'status']]);
    }
    public static function status(): \WP_REST_Response
    {
        $expires = self::expires();
        $active = $expires !== null && $expires > time();
        $response = new \WP_REST_Response(['active' => $active, 'expires' => $active ? $expires : null], $active ? 200 : 401);
        $response->header('Cache-Control', 'private, no-store, max-age=0');
        $response->header('CDN-Cache-Control', 'no-store');
        return $response;
    }
    public static function logout(): never
    {
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0');
        $nonce = $_POST['fans_logout_nonce'] ?? null;
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_string($nonce)
            || wp_verify_nonce(wp_unslash($nonce), self::ACTION) === false
            || FansSsoService::currentLinkedSubject() === null) {
            wp_die('Déconnexion non autorisée. Rechargez votre espace.', '', ['response' => 403]);
        }
        wp_logout(); // Destroys the current server token and clears local WP authentication cookies.
        FansSsoService::clearCookie(); // A pending callback must not silently reopen this browser's session.
        $destination = add_query_arg('faluss_fans_session', 'closed', home_url('/app/fan/explorer'));
        if (($_POST['fans_logout_json'] ?? '') === '1') { wp_send_json_success(['redirect' => $destination]); }
        wp_safe_redirect($destination, 303);
        exit;
    }
    public static function renderControl(): void
    {
        if (FansSsoService::currentLinkedSubject() === null) { return; }
        echo '<form class="fu-logout" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-fans-logout>';
        echo '<input type="hidden" name="action" value="' . self::ACTION . '">'
            . wp_nonce_field(self::ACTION, 'fans_logout_nonce', false, false);
        echo '<button type="submit" class="fu-link" aria-label="Déconnexion de Fans" title="Déconnexion de Fans"><svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M10 4H4v16h6M9 12h11m-4-4 4 4-4 4"/></svg><span>Déconnexion</span></button></form>';
    }
}
