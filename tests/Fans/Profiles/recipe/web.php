<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Sso {
    // Local UI HTTP does not exercise TLS/SSO. Configuration adapter only.
    function home_url(string $path = ''): string { return 'https://editorial.example.test' . $path; }
}
namespace {
    require __DIR__ . '/bootstrap.php';
    function wp_unslash(string $value): string { return $value; }
    // No WordPress authentication cookies in this adapter; real expiry/logout has its own WP recipe.
    function wp_parse_auth_cookie(string $cookie = '', string $scheme = ''): false { return false; }
    function wp_safe_redirect(string $url, int $status = 302): void { header('Location: ' . $url, true, $status); }
    function wp_create_nonce(string $action): string { return 'fixture-' . $action; }
    function wp_nonce_field(string $action, string $name = '_wpnonce', bool $referer = true, bool $echo = true): string {
        $html = '<input type="hidden" name="' . esc_attr($name) . '" value="fixture-' . esc_attr($action) . '">';
        if ($echo) { echo $html; } return $html;
    }
    function check_admin_referer(string $action): void { if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', $action)) { wp_die('Nonce invalide', '', ['response' => 403]); } }
    function wp_json_encode(mixed $data): string { return json_encode($data); }
    function rest_url(string $path): string { return home_url('/wp-json/' . $path); }
    function admin_url(string $path): string { return home_url('/' . $path); }
    function plugins_url(string $path, string $plugin): string { return home_url('/' . $path); }
    function wp_enqueue_style(string $handle, string $url, array $deps, string $version): void { $GLOBALS['styles'][] = $url; }
    function wp_enqueue_script(string $handle, string $url, array $deps, string $version, bool $footer): void { $GLOBALS['scripts'][] = $url; }
    function wp_head(): void { foreach ($GLOBALS['styles'] ?? [] as $url) { echo '<link rel="stylesheet" href="' . esc_url($url) . '">'; } }
    function wp_footer(): void { foreach ($GLOBALS['scripts'] ?? [] as $url) { echo '<script src="' . esc_url($url) . '"></script>'; } }
    function status_header(int $status): void { http_response_code($status); }
    function nocache_headers(): void { header('Cache-Control: private, no-store'); }
    function wp_die(string $message, string $title, array $args): never { http_response_code($args['response']); echo esc_html($message); exit; }
    function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
    function esc_html__(string $value, string $domain): string { return esc_html($value); }
    function get_query_var(string $key): mixed { return $GLOBALS['fixtureQuery'][$key] ?? ''; }
    function esc_attr(string $value): string { return esc_html($value); }
    function esc_textarea(string $value): string { return esc_html($value); }
    function esc_url(string $value): string { return esc_html($value); }
    function add_query_arg(mixed $key, mixed $value, ?string $url = null): string {
        $args = is_array($key) ? $key : [$key => $value]; $url = is_array($key) ? $value : $url;
        $base = explode('?', $url)[0]; parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $current);
        return $base . '?' . http_build_query(array_merge($current, $args));
    }
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (str_starts_with($path, '/assets/')) {
        $file = realpath(ABSPATH . $path);
        if ($file === false || !str_starts_with($file, ABSPATH . '/assets/')) { http_response_code(404); exit; }
        header('Content-Type: ' . match (pathinfo($file, PATHINFO_EXTENSION)) { 'css' => 'text/css', 'js' => 'text/javascript', 'ttf' => 'font/ttf', default => 'application/octet-stream' });
        readfile($file); exit;
    }
    \Faluss\Platform\Fans\Moderation\ModerationPanel::noStore();
    if (str_starts_with($path, '/wp-json/')) {
        $r = new WP_REST_Request($_SERVER['REQUEST_METHOD'], substr($path, strlen('/wp-json')));
        $r->set_query_params($_GET); $r->set_header('X-WP-Nonce', $_SERVER['HTTP_X_WP_NONCE'] ?? '');
        $r->set_header('Idempotency-Key', $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) { $r->set_body(file_get_contents('php://input')); }
            else { $r->set_file_params($_FILES); $r->body = $_POST; }
        }
        $response = rest_do_request($r); http_response_code($response->get_status());
        foreach ($response->get_headers() as $name => $value) { header($name . ': ' . $value); }
        if (!\Faluss\Platform\Fans\Profiles\EditorialRest::serve(false, $response, $r)
            && !\Faluss\Platform\Fans\Images\ImageRest::serve(false, $response, $r)
            && !\Faluss\Platform\Fans\Publications\PublicationImageRest::serve(false, $response, $r)) {
            header('Content-Type: application/json'); echo json_encode($response->get_data());
        }
        exit;
    }
    if ($path === '/admin-post.php') { \Faluss\Platform\Fans\Moderation\ModerationPanel::preview(); }
    if ($path === '/admin.php') {
        \Faluss\Platform\Fans\Moderation\ModerationPanel::load();
        echo '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/fans-moderation.css"><body>';
        \Faluss\Platform\Fans\Moderation\ModerationPanel::render();
        echo '<script src="/assets/fans-moderation.js"></script></body></html>'; exit;
    }
    if (preg_match('#^/app/(fan|creator)/([a-z/-]+)/?$#D', $path, $matches)) {
        $GLOBALS['fixtureQuery'] = ['faluss_fans_ui_role' => $matches[1], 'faluss_fans_ui_view' => $matches[2]];
    } elseif (preg_match('#^/app/creators/([0-9a-f-]{36})/?$#D', $path, $matches)) {
        $GLOBALS['fixtureQuery'] = ['faluss_fans_ui_view' => 'public-profile', 'faluss_fans_ui_creator' => $matches[1]];
    }
    \Faluss\Platform\Fans\Ui\FansUiRoutes::handle();
    http_response_code(404);
}
