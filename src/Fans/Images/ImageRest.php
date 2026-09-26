<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Images;

use Faluss\Platform\Fans\Profiles\CreatorProfileRest;

final class ImageRest
{
    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
        add_filter('rest_pre_serve_request', [self::class, 'serve'], 10, 3);
    }
    public static function routes(): void
    {
        $id = '/images/(?P<image_id>[0-9a-f-]{36})';
        foreach ([['/images', 'POST', 'submit', 'member'], ['/images', 'GET', 'listing', 'privatePermission'],
            [$id . '/bytes', 'GET', 'bytes', 'admin'], [$id . '/decisions', 'GET', 'decisions', 'admin'],
            [$id . '/moderate', 'POST', 'moderate', 'admin'], [$id . '/withdraw', 'POST', 'withdraw', 'member'],
            ['/images/cleanup', 'POST', 'cleanup', 'admin']] as [$route, $method, $callback, $permission]) {
            register_rest_route('faluss-fans/v1', $route, ['methods' => $method, 'callback' => [self::class, $callback], 'permission_callback' => [self::class, $permission]]);
        }
    }
    public static function member(\WP_REST_Request $r): bool { return ImagesModule::available() && CreatorProfileRest::memberPermission($r); }
    public static function admin(\WP_REST_Request $r): bool { return ImagesModule::available() && CreatorProfileRest::adminPermission($r); }
    public static function privatePermission(\WP_REST_Request $r): bool { return self::admin($r) || self::member($r); }
    public static function submit(\WP_REST_Request $r): \WP_REST_Response
    {
        /** @var mixed $files Multipart structures can include nested arrays. */
        $files = $r->get_file_params();
        /** @var mixed $json */
        $json = $r->get_json_params();
        if (!is_array($files) || array_keys($files) !== ['image'] || !is_array($files['image']) || $r->get_body_params() !== [] || $r->get_query_params() !== [] || $json !== null) {
            return self::response(ImageService::error('invalid_image_input', 400));
        }
        return self::response(ImageService::submit($files['image']), 201);
    }
    public static function listing(\WP_REST_Request $r): \WP_REST_Response
    { return self::response(ImageService::listing(is_string($r->get_param('cursor')) ? $r->get_param('cursor') : '')); }
    public static function bytes(\WP_REST_Request $r): \WP_REST_Response
    {
        $data = ImageService::bytes((string) $r->get_param('image_id'));
        $response = self::response($data);
        if (is_string($data)) {
            $response->header('Content-Type', 'image/png');
            $response->header('Content-Disposition', 'attachment; filename="quarantine.png"');
            $response->header('Content-Security-Policy', "sandbox; default-src 'none'");
        }
        return $response;
    }
    public static function serve(bool $served, \WP_HTTP_Response $response, \WP_REST_Request $request): bool
    {
        if (!$served && preg_match('#^/faluss-fans/v1/images/[0-9a-f-]{36}/bytes$#D', $request->get_route()) === 1
            && $response->get_status() === 200 && is_string($response->get_data()) && ($response->get_headers()['Content-Type'] ?? '') === 'image/png') {
            echo $response->get_data(); // Validated PNG only, through the authenticated no-store adapter.
            return true;
        }
        return $served;
    }
    public static function decisions(\WP_REST_Request $r): \WP_REST_Response
    { return self::response(ImageService::decisions((string) $r->get_param('image_id'))); }
    public static function moderate(\WP_REST_Request $r): \WP_REST_Response
    {
        $data = self::input($r, ['revision', 'decision', 'reason']);
        return self::response($data === null || !is_int($data['revision']) || !is_string($data['reason']) || !in_array($data['decision'], ['approve', 'reject'], true)
            ? ImageService::error('invalid_image_decision', 400) : ImageService::decide((string) $r->get_param('image_id'), $data['revision'], $data['decision'], $data['reason']));
    }
    public static function withdraw(\WP_REST_Request $r): \WP_REST_Response
    {
        $data = self::input($r, ['revision']);
        return self::response($data === null || !is_int($data['revision']) ? ImageService::error('invalid_image_decision', 400)
            : ImageService::decide((string) $r->get_param('image_id'), $data['revision'], 'withdraw', 'creator_withdrawal'));
    }
    public static function cleanup(\WP_REST_Request $r): \WP_REST_Response
    { return self::response(self::input($r, []) === null ? ImageService::error('invalid_image_input', 400) : ImageService::cleanup()); }
    /**
     * @param list<string> $keys
     * @return array<string,mixed>|null
     */
    private static function input(\WP_REST_Request $r, array $keys): ?array
    {
        /** @var mixed $data */
        $data = $r->get_json_params();
        return is_array($data) && count($data) === count($keys) && array_diff($keys, array_keys($data)) === [] && $r->get_query_params() === [] && $r->get_file_params() === [] ? $data : null;
    }
    private static function response(mixed $data, int $status = 200): \WP_REST_Response
    {
        if ($data instanceof \WP_Error) { $status = (int) ($data->get_error_data()['status'] ?? 500); $data = ['code' => $data->get_error_code()]; }
        $response = new \WP_REST_Response($data, $status);
        $response->header('Cache-Control', 'private, no-store, max-age=0');
        $response->header('X-Content-Type-Options', 'nosniff');
        return $response;
    }
}
