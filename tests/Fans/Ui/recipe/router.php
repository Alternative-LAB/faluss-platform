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
    final class CreatorProfileSchema { public static function ready(): bool { return true; } }
    final class CreatorProfileService
    {
        public static function own(): ?array
        {
            return ($_COOKIE['fans_ui_role'] ?? '') === 'creator' ? ['creator_id' => 'fixture'] : null;
        }
        public static function publicById(mixed $creatorId): ?array
        {
            return in_array($creatorId, [\Faluss\Platform\Fans\Ui\FIXTURE_ID, \Faluss\Platform\Fans\Ui\SECOND_ID], true)
                ? ['creator_id' => $creatorId] : null;
        }
    }
}

namespace Faluss\Platform\Fans\Ui {
    const FIXTURE_ID = '123e4567-e89b-42d3-a456-426614174000';
    const SECOND_ID = '123e4567-e89b-42d3-a456-426614174001';

    function home_url(string $path): string { return 'http://127.0.0.1:8765' . $path; }
    function rest_url(string $path): string { return home_url('/wp-json/' . $path); }
    function plugins_url(string $path, string $plugin): string { return home_url('/' . $path); }
    function wp_enqueue_style(string $handle, string $url, array $deps, string $version): void { $GLOBALS['fixture_style'] = $url; }
    function wp_enqueue_script(string $handle, string $url, array $deps, string $version, bool $footer): void { $GLOBALS['fixture_script'] = $url; }
    function wp_head(): void { echo '<link rel="stylesheet" href="' . esc_url($GLOBALS['fixture_style']) . '">'; }
    function wp_footer(): void { echo '<script src="' . esc_url($GLOBALS['fixture_script']) . '"></script>'; }
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
