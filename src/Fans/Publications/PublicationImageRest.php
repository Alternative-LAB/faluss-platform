<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Publications;

use Faluss\Platform\Fans\Images\ImageDisplayDerivative;

/** Public Fans-only display adapter; quarantine routes remain administrator-only. */
final class PublicationImageRest
{
    private const ROUTE = '#^/faluss-fans/v1/text-publications/[0-9a-f-]{36}/image/[1-9][0-9]{0,9}$#D';

    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
        add_filter('rest_pre_serve_request', [self::class, 'serve'], 20, 3);
    }

    public static function routes(): void
    {
        if (!self::permission()) { return; }
        register_rest_route('faluss-fans/v1', '/text-publications/(?P<publication_id>[0-9a-f-]{36})/image/(?P<revision>[1-9][0-9]{0,9})', [
            'methods' => 'GET', 'callback' => [self::class, 'bytes'], 'permission_callback' => [self::class, 'permission'],
        ]);
    }

    public static function permission(): bool { return TextPublicationsModule::available() && ImageDisplayDerivative::enabled(); }

    public static function bytes(\WP_REST_Request $r): \WP_REST_Response
    {
        $data = $r->get_query_params() !== [] || (string) $r->get_body() !== '' || $r->get_file_params() !== []
            || (string) $r->get_header('Range') !== '' || (string) $r->get_header('If-Range') !== ''
            ? new \WP_Error('invalid_display_request', 'Image indisponible.', ['status' => 400])
            : TextPublicationService::displayImage($r->get_param('publication_id'), (int) $r->get_param('revision'));
        $status = 200;
        if ($data instanceof \WP_Error) {
            $status = (int) ($data->get_error_data()['status'] ?? 503);
            $data = ['code' => $data->get_error_code()];
        }
        $response = new \WP_REST_Response($data, $status);
        foreach (self::headers() as $key => $value) { $response->header($key, $value); }
        if (is_string($data)) {
            $response->header('Content-Type', 'image/jpeg');
            $response->header('Content-Disposition', 'inline; filename="display.jpg"');
            $response->header('Content-Security-Policy', "sandbox; default-src 'none'");
        }
        return $response;
    }

    /** @return array<string,string> */
    public static function headers(): array
    {
        return ['Cache-Control' => 'private, no-store, max-age=0, must-revalidate', 'CDN-Cache-Control' => 'no-store',
            'Surrogate-Control' => 'no-store', 'Pragma' => 'no-cache', 'Expires' => '0',
            'X-Content-Type-Options' => 'nosniff', 'Cross-Origin-Resource-Policy' => 'same-origin'];
    }

    public static function serve(bool $served, \WP_HTTP_Response $response, \WP_REST_Request $request): bool
    {
        if (preg_match(self::ROUTE, $request->get_route()) !== 1) { return $served; }
        // Override WordPress's permissive default CORS and cache headers even for route errors.
        header_remove('Access-Control-Allow-Origin'); header_remove('Access-Control-Allow-Credentials');
        header_remove('ETag'); header_remove('Last-Modified');
        foreach (self::headers() as $key => $value) { header($key . ': ' . $value); }
        if (!$served && $response->get_status() === 200 && is_string($response->get_data())
            && ($response->get_headers()['Content-Type'] ?? '') === 'image/jpeg') {
            if ($request->get_method() !== 'HEAD') { echo $response->get_data(); }
            return true;
        }
        return $served;
    }
}
