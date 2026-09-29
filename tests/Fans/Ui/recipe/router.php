<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Sso {
    final class FansSsoService
    {
        public static function button(array $attributes): string
        {
            if (($_COOKIE['fans_ui_role'] ?? '') === 'admin') { return ''; }
            return '<form method="post" action="/fixture-sso-start"><input type="hidden" name="faluss_fans_return_to" value="'
                . htmlspecialchars($attributes['return_to'], ENT_QUOTES, 'UTF-8') . '"><button type="submit">Continuer avec Faluss</button></form>';
        }
        public static function currentLinkedSubject(): ?array
        {
            return in_array($_COOKIE['fans_ui_role'] ?? '', ['fan', 'creator'], true) ? ['faluss_id' => 'fixture'] : null;
        }
    }
}

namespace Faluss\Platform\Fans\Profiles {
    final class CreatorProfileSchema { public static function ready(): bool { return ($_COOKIE['fans_ui_profiles_ready'] ?? '') !== 'no'; } }
    final class CreatorProfileService
    {
        public const CATEGORIES = ['arts', 'music', 'games', 'learning', 'lifestyle'];
        public static function own(): ?array
        {
            if (($_COOKIE['fans_ui_admission'] ?? '') === 'open') {
                if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
                if (isset($_SESSION['admission'])) { return $_SESSION['admission']; }
            }
            return ($_COOKIE['fans_ui_role'] ?? '') === 'creator'
                ? ['creator_id' => \Faluss\Platform\Fans\Ui\FIXTURE_ID, 'category' => 'arts', 'status' => $_COOKIE['fans_ui_profile'] ?? 'active'] : null;
        }
        public static function publicById(mixed $creatorId): ?array
        {
            if (in_array($_COOKIE['fans_ui_public_state'] ?? '', ['suspended', 'withdrawn'], true)) { return null; }
            return in_array($creatorId, [\Faluss\Platform\Fans\Ui\FIXTURE_ID, \Faluss\Platform\Fans\Ui\SECOND_ID], true)
                ? ['creator_id' => $creatorId] : null;
        }
    }
}

namespace Faluss\Platform\Fans\Images {
    final class ImageDisplayDerivative { public static function enabled(): bool { return ($_COOKIE['fans_ui_images'] ?? '') === 'open'; } }
}

namespace Faluss\Platform\Fans\Publications {
    final class TextPublicationsModule { public static function available(): bool { return ($_COOKIE['fans_ui_author'] ?? '') === 'open'; } }
    final class TextPublicationService {
        public static function validId(mixed $id): bool { return is_string($id) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) === 1; }
    }
}

namespace {
    final class WP_REST_Response {
        public function __construct(private mixed $data, private int $status = 200) {}
        public function get_data(): mixed { return $this->data; }
        public function get_status(): int { return $this->status; }
    }
    final class WP_REST_Request {
        public array $headers = [];
        public array $data = [];
        public function __construct(public string $method, public string $route) {}
        public function set_header(string $key, string $value): void { $this->headers[$key] = $value; }
        public function set_query_params(array $data): void { $this->data = $data; }
        public function set_body(string $data): void { $this->data = json_decode($data, true); }
    }
}

namespace Faluss\Platform\Fans\Ui {
    const FIXTURE_ID = '123e4567-e89b-42d3-a456-426614174000';
    const SECOND_ID = '123e4567-e89b-42d3-a456-426614174001';

    function wp_generate_uuid4(): string { $hex = bin2hex(random_bytes(16)); return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3) . '-8' . substr($hex, 17, 3) . '-' . substr($hex, 20); }
    function wp_unslash(string $value): string { return $value; }
    function wp_create_nonce(string $action): string { return 'fixture-' . $action; }
    function wp_verify_nonce(string $nonce, string $action): int|false { return $nonce === 'fixture-' . $action ? 1 : false; }
    function wp_nonce_field(string $action, string $name, bool $referer, bool $echo): string { return '<input type="hidden" name="' . $name . '" value="fixture-' . $action . '">'; }
    function wp_json_encode(array $data): string { return (string) json_encode($data); }
    function rest_do_request(\WP_REST_Request $request): \WP_REST_Response {
        if (!in_array($_COOKIE['fans_ui_role'] ?? '', ['fan', 'creator'], true) || ($request->headers['X-WP-Nonce'] ?? '') !== 'fixture-wp_rest') { return new \WP_REST_Response([], 403); }
        if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
        if ($request->route === '/faluss-fans/v1/creators/me') {
            if (($_COOKIE['fans_ui_admission'] ?? '') !== 'open') { return new \WP_REST_Response(['code' => 'rest_no_route'], 404); }
            if ($request->method === 'GET') {
                $profile = \Faluss\Platform\Fans\Profiles\CreatorProfileService::own();
                return new \WP_REST_Response($profile === null ? ['code' => 'profile_not_found'] : $profile + ['identity_verified' => false], $profile === null ? 404 : 200);
            }
            if (($_COOKIE['fans_ui_admission_error'] ?? '') !== '') { return new \WP_REST_Response([], (int) $_COOKIE['fans_ui_admission_error']); }
            if (array_keys($request->data) !== ['category']) { return new \WP_REST_Response([], 400); }
            $category = $request->data['category'];
            if (isset($_SESSION['admission']) && $_SESSION['admission']['category'] !== $category) { return new \WP_REST_Response([], 409); }
            $_SESSION['admission'] ??= ['creator_id' => FIXTURE_ID, 'category' => $category, 'status' => 'pending', 'identity_verified' => false];
            return new \WP_REST_Response($_SESSION['admission'], 201);
        }
        if (($_COOKIE['fans_ui_role'] ?? '') !== 'creator') { return new \WP_REST_Response([], 403); }
        $_SESSION['texts'] ??= [];
        $_SESSION['keys'] ??= [];
        $route = str_replace('/faluss-fans/v1/text-publications', '', $request->route);
        $data = $request->data;
        if ($request->method === 'GET' && $route === '/mine') { return new \WP_REST_Response(['items' => array_values($_SESSION['texts']), 'next_cursor' => null]); }
        if ($request->method === 'GET') { $id = explode('/', trim($route, '/'))[0]; return new \WP_REST_Response($_SESSION['texts'][$id] ?? [], isset($_SESSION['texts'][$id]) ? 200 : 404); }
        if (($_COOKIE['fans_ui_write_error'] ?? '') !== '') { return new \WP_REST_Response([], (int) $_COOKIE['fans_ui_write_error']); }
        if ($route === '') {
            $key = $request->headers['Idempotency-Key'] ?? '';
            if (!\Faluss\Platform\Fans\Publications\TextPublicationService::validId($key)) { return new \WP_REST_Response([], 400); }
            if (isset($_SESSION['keys'][$key])) {
                [$id, $original] = $_SESSION['keys'][$key];
                return new \WP_REST_Response($_SESSION['texts'][$id], $original === $data ? 201 : 409);
            }
            if (($_COOKIE['fans_ui_profile'] ?? 'active') !== 'active') { return new \WP_REST_Response([], 403); }
            $id = wp_generate_uuid4();
            $row = ['publication_id' => $id, 'revision' => '1', 'state' => 'pending', 'body' => $data['text']];
            $_SESSION['texts'][$id] = $row;
            $_SESSION['keys'][$key] = [$id, $data];
            return new \WP_REST_Response($row, 201);
        }
        [$id, $action] = explode('/', trim($route, '/'));
        $row = $_SESSION['texts'][$id] ?? null;
        if ($row === null) { return new \WP_REST_Response([], 404); }
        if ((int) $row['revision'] !== $data['revision'] || $row['state'] === 'withdrawn') { return new \WP_REST_Response([], 409); }
        if ($action === 'edit' && ($_COOKIE['fans_ui_profile'] ?? 'active') !== 'active') { return new \WP_REST_Response([], 403); }
        $row['revision'] = (int) $row['revision'] + 1;
        $row['body'] = $action === 'edit' ? $data['text'] : '';
        $row['state'] = $action === 'edit' ? 'pending' : 'withdrawn';
        $_SESSION['texts'][$id] = $row;
        return new \WP_REST_Response($row);
    }

    function home_url(string $path): string { return 'http://127.0.0.1:8765' . $path; }
    function rest_url(string $path): string { return home_url('/wp-json/' . $path); }
    function plugins_url(string $path, string $plugin): string { return home_url('/' . $path); }
    function wp_enqueue_style(string $handle, string $url, array $deps, string $version): void { $GLOBALS['fixture_style'] = $url; }
    function wp_enqueue_script(string $handle, string $url, array $deps, string $version, bool $footer): void { $GLOBALS['fixture_scripts'][] = $url; }
    function wp_head(): void { echo '<link rel="stylesheet" href="' . esc_url($GLOBALS['fixture_style']) . '">'; }
    function wp_footer(): void { foreach ($GLOBALS['fixture_scripts'] as $url) { echo '<script src="' . esc_url($url) . '"></script>'; } }
    function status_header(int $status): void { http_response_code($status); }
    function nocache_headers(): void { header('Cache-Control: no-store'); }
    function get_query_var(string $name): mixed { return $GLOBALS['fixture_query'][$name] ?? ''; }
    function wp_die(string $message, string $title, array $args): never
    {
        http_response_code((int) $args['response']);
        echo '<!doctype html><html lang="fr"><meta charset="utf-8"><title>Accès refusé</title><p>' . esc_html($message) . '</p></html>';
        exit;
    }
    function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
    function esc_attr(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
    function esc_url(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }

    $root = dirname(__DIR__, 4);
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_file($root . $path)) {
        return false;
    }
    if ($path === '/wp-json/faluss-fans/v1/text-publications') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $mode = $_COOKIE['fans_ui_texts'] ?? 'available';
        if ($mode === 'closed' || $mode === 'error') { http_response_code($mode === 'closed' ? 404 : 503); echo '{}'; return true; }
        $items = $mode === 'empty' ? [] : [[
            'publication_id' => FIXTURE_ID, 'creator_id' => FIXTURE_ID, 'revision' => '1',
            'body' => isset($_GET['cursor']) ? 'Fixture de recette — seconde page.' : "Fixture de recette — texte approuvé.\nLes publications sont rendues comme du texte, sans données de personne.",
            'updated_at' => '2026-09-29 00:00:00',
        ]];
        echo json_encode(['items' => $items, 'next_cursor' => $mode !== 'empty' && !isset($_GET['cursor']) ? 'fixture-next' : null]);
        return true;
    }
    if (str_starts_with($path, '/wp-json/faluss-fans/v1/creators')) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (($_COOKIE['fans_ui_data'] ?? '') === 'error') {
            http_response_code(503);
            echo json_encode(['code' => 'profiles_unavailable']);
            return true;
        }
        $items = [
            ['creator_id' => FIXTURE_ID, 'category' => 'arts', 'status' => 'active', 'identity_verified' => false],
            ['creator_id' => SECOND_ID, 'category' => 'music', 'status' => 'active', 'identity_verified' => false],
        ];
        if (($_COOKIE['fans_ui_data'] ?? '') === 'empty') { $items = []; }
        if (preg_match('~^/wp-json/faluss-fans/v1/creators/([0-9a-f-]{36})$~', $path, $matches) === 1) {
            $item = array_values(array_filter($items, static fn (array $entry): bool => $entry['creator_id'] === $matches[1]))[0] ?? null;
            http_response_code($item === null ? 404 : 200);
            echo json_encode($item ?? ['code' => 'profile_not_found']);
            return true;
        }
        $category = $_GET['category'] ?? null;
        if (is_string($category)) { $items = array_values(array_filter($items, static fn (array $entry): bool => $entry['category'] === $category)); }
        echo json_encode($items);
        return true;
    }

    if (!defined('FALUSS_PLATFORM_VERSION')) { define('FALUSS_PLATFORM_VERSION', 'test'); }
    if (!defined('FALUSS_PLATFORM_FANS_CREATOR_PROFILES')) { define('FALUSS_PLATFORM_FANS_CREATOR_PROFILES', true); }
    require_once $root . '/src/Fans/Ui/FansUiRoutes.php';
    require_once $root . '/src/Fans/Ui/FansUiView.php';
    require_once $root . '/src/Fans/Ui/FansUiReading.php';
    require_once $root . '/src/Fans/Ui/FansUiAuthor.php';
    require_once $root . '/src/Fans/Ui/FansUiAuthorView.php';
    require_once $root . '/src/Fans/Ui/FansUiAdmission.php';
    $GLOBALS['fixture_query'] = [];
    if (preg_match('~^/faluss-fans/creators/([0-9a-f-]{36})/?$~', $path, $matches) === 1) {
        $GLOBALS['fixture_query'][FansUiRoutes::VIEW_VAR] = 'public-profile';
        $GLOBALS['fixture_query'][FansUiRoutes::CREATOR_VAR] = $matches[1];
    } elseif (preg_match('~^/faluss-fans/(fan|creator)/([a-z/-]+)/?$~', $path, $matches) === 1) {
        $GLOBALS['fixture_query'][FansUiRoutes::ROLE_VAR] = $matches[1];
        $GLOBALS['fixture_query'][FansUiRoutes::VIEW_VAR] = $matches[2];
    } else {
        http_response_code(404);
        echo 'Page introuvable.';
        return true;
    }
    FansUiRoutes::handle();
}
