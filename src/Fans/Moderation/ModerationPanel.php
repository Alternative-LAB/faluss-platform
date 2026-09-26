<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Moderation;

use Faluss\Platform\Fans\Publications\TextPublicationsModule;
use Faluss\Platform\Fans\Publications\TextPublicationService;

/** WordPress adapter only: every operation dispatches through the existing REST permissions. */
final class ModerationPanel
{
    public const PAGE = 'faluss-fans-moderation';
    private static ?\WP_REST_Response $result = null;

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_faluss_fans_preview', [self::class, 'preview']);
    }

    public static function allowed(): bool
    { return TextPublicationsModule::available() && current_user_can('manage_options'); }

    public static function menu(): void
    {
        if (!self::allowed()) { return; }
        $hook = add_submenu_page('faluss-platform', 'Modération Fans', 'Modération Fans', 'manage_options', self::PAGE, [self::class, 'render']);
        add_action('load-' . $hook, [self::class, 'load']);
        add_action('admin_enqueue_scripts', static function (string $current) use ($hook): void {
            if ($current === $hook && self::allowed()) {
                wp_enqueue_style('faluss-fans-moderation', plugins_url('assets/fans-moderation.css', dirname(__DIR__, 3) . '/faluss-platform.php'), [], (string) constant('FALUSS_PLATFORM_VERSION'));
                wp_enqueue_script('faluss-fans-moderation', plugins_url('assets/fans-moderation.js', dirname(__DIR__, 3) . '/faluss-platform.php'), [], (string) constant('FALUSS_PLATFORM_VERSION'), true);
            }
        });
    }

    private static function guard(): void
    {
        self::noStore();
        if (!self::allowed()) { wp_die('Accès non autorisé.', '', ['response' => 403]); }
    }

    public static function noStore(): void
    {
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0, must-revalidate');
        header('CDN-Cache-Control: no-store');
        header('Surrogate-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
    }

    /** @param array<string,mixed>|null $data */
    public static function request(string $method, string $route, ?array $data = null): \WP_REST_Response
    {
        $request = new \WP_REST_Request($method, '/faluss-fans/v1/' . $route);
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        if ($data !== null) {
            if ($method === 'GET') { $request->set_query_params($data); }
            else { $request->set_header('Content-Type', 'application/json'); $request->set_body((string) wp_json_encode($data)); }
        }
        return rest_do_request($request);
    }

    public static function load(): void
    {
        self::guard();
        self::$result = null;
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { return; }
        check_admin_referer('fans_moderation');
        $id = self::field('item_id', $_POST);
        $kind = self::field('kind', $_POST);
        $revision = self::field('revision', $_POST);
        $reason = self::field('reason', $_POST);
        if (!TextPublicationService::validId($id) || !in_array($kind, ['text-publications', 'images'], true)
            || preg_match('/^[1-9][0-9]{0,9}$/D', $revision) !== 1
            || !in_array($reason, [$kind === 'images' ? 'allowed_image' : 'allowed_text', 'prohibited_content', 'needs_revision'], true)) {
            self::$result = new \WP_REST_Response(['code' => 'invalid_panel_input'], 400);
        } else {
            self::$result = self::request('POST', $kind . '/' . $id . '/moderate', ['revision' => (int) $revision,
                'decision' => str_starts_with($reason, 'allowed_') ? 'approve' : 'reject', 'reason' => $reason]);
        }
        status_header(self::$result->get_status());
    }

    public static function preview(): void
    {
        self::guard();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_die('POST requis.', '', ['response' => 405]); }
        check_admin_referer('fans_preview');
        $id = self::field('image_id', $_POST);
        $publication = self::field('publication_id', $_POST);
        if (!TextPublicationService::validId($id)) { wp_die('Image indisponible.', '', ['response' => 400]); }
        if ($publication !== '') {
            if (!TextPublicationService::validId($publication)) { wp_die('Publication indisponible.', '', ['response' => 400]); }
            $response = self::request('GET', 'text-publications/' . $publication . '/private');
            $row = $response->get_data();
            if ($response->get_status() !== 200 || !is_array($row) || ($row['image_id'] ?? null) !== $id
                || (string) ($row['revision'] ?? '') !== self::field('revision', $_POST)) {
                wp_die('Association modifiée ou indisponible. Rechargez la file.', '', ['response' => 409]);
            }
        }
        $response = self::request('GET', 'images/' . $id . '/bytes');
        $bytes = $response->get_data();
        if ($response->get_status() !== 200 || !is_string($bytes) || ($response->get_headers()['Content-Type'] ?? '') !== 'image/png') {
            wp_die('Image indisponible. Rechargez la file.', '', ['response' => $response->get_status()]);
        }
        header('Content-Type: image/png');
        header('Content-Disposition: inline; filename="quarantine.png"');
        header("Content-Security-Policy: sandbox; default-src 'none'");
        header('Cross-Origin-Resource-Policy: same-origin');
        echo $bytes; // Authenticated PNG from the Images public moderation contract, never a path.
        exit;
    }

    /** @param array<string,mixed> $source */
    public static function field(string $key, array $source): string
    { return isset($source[$key]) && is_string($source[$key]) ? wp_unslash($source[$key]) : ''; }

    public static function render(): void
    {
        if (!self::allowed()) { wp_die('Accès non autorisé.', '', ['response' => 403]); }
        $images = self::field('view', $_GET) === 'images';
        $cursor = self::field('cursor', $_GET);
        $item = self::field('item', $_GET);
        if (!$images && $item !== '') {
            $response = TextPublicationService::validId($item) ? self::request('GET', 'text-publications/' . $item . '/private') : new \WP_REST_Response([], 400);
            if ($response->get_status() === 200) { $response = new \WP_REST_Response(['items' => [$response->get_data()], 'next_cursor' => null]); }
            ModerationView::render(false, $response, self::$result, true);
            return;
        }
        $route = $images ? 'images' : 'text-publications/moderation';
        $response = self::request('GET', $route, $cursor !== '' ? ['cursor' => $cursor] : []);
        ModerationView::render($images, $response, self::$result);
    }
}
