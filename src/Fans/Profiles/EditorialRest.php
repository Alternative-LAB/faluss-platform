<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Profiles;

/** Thin HTTP adapter; no public access to the private editorial revision. */
final class EditorialRest
{
    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
        add_filter('rest_pre_serve_request', [self::class, 'serve'], 10, 3);
    }

    public static function routes(): void
    {
        $id = '/editorial/(?P<creator_id>[0-9a-f-]{36})';
        foreach ([['/creators/me/editorial', 'GET', 'own', 'member'],
            ['/creators/me/editorial', 'POST', 'submit', 'member'],
            [$id . '/withdraw', 'POST', 'withdraw', 'member'],
            ['/editorial/moderation', 'GET', 'moderation', 'admin'],
            [$id . '/private', 'GET', 'inspect', 'admin'],
            [$id . '/moderate', 'POST', 'moderate', 'admin'],
            ['/creators/(?P<creator_id>[0-9a-f-]{36})/portrait/(?P<revision>[1-9][0-9]{0,9})', 'GET', 'portrait', 'publicPermission']]
            as [$route, $method, $callback, $permission]) {
            register_rest_route(CreatorProfileRest::NAMESPACE, $route, ['methods' => $method,
                'callback' => [self::class, $callback], 'permission_callback' => [self::class, $permission]]);
        }
    }

    public static function member(\WP_REST_Request $r): bool
    { return EditorialModule::available() && CreatorProfileRest::memberPermission($r); }
    public static function admin(\WP_REST_Request $r): bool
    { return EditorialModule::available() && CreatorProfileRest::adminPermission($r); }
    public static function publicPermission(): bool { return EditorialModule::available(); }
    public static function own(): \WP_REST_Response { return self::response(EditorialService::own()); }
    public static function inspect(\WP_REST_Request $r): \WP_REST_Response
    { return self::response(EditorialService::inspect((string) $r->get_param('creator_id'))); }
    public static function moderation(\WP_REST_Request $r): \WP_REST_Response
    {
        $cursor = $r->get_param('cursor');
        $state = $r->get_param('state');
        return self::response(($cursor !== null && !is_string($cursor)) || ($state !== null && !is_string($state)) ? EditorialService::error('invalid_editorial_cursor', 400)
            : EditorialService::moderation($cursor ?? '', $state ?? 'pending'));
    }
    public static function submit(\WP_REST_Request $r): \WP_REST_Response
    {
        $data = self::input($r, ['revision', 'public_name', 'bio', 'portrait_id', 'portrait_revision']);
        return self::response($data === null ? EditorialService::error('invalid_editorial', 400)
            : EditorialService::submit($data['revision'], $data['public_name'], $data['bio'], $data['portrait_id'], $data['portrait_revision']));
    }
    public static function withdraw(\WP_REST_Request $r): \WP_REST_Response
    {
        $data = self::input($r, ['revision']);
        return self::response($data === null || !is_int($data['revision']) ? EditorialService::error('invalid_editorial_decision', 400)
            : EditorialService::decide((string) $r->get_param('creator_id'), $data['revision'], 'withdraw', 'creator_withdrawal'));
    }
    public static function moderate(\WP_REST_Request $r): \WP_REST_Response
    {
        $data = self::input($r, ['revision', 'decision', 'reason']);
        return self::response($data === null || !is_int($data['revision']) || !is_string($data['reason'])
            || !in_array($data['decision'], ['approve', 'reject', 'revoke'], true) ? EditorialService::error('invalid_editorial_decision', 400)
            : EditorialService::decide((string) $r->get_param('creator_id'), $data['revision'], $data['decision'], $data['reason']));
    }
    public static function portrait(\WP_REST_Request $r): \WP_REST_Response
    {
        $data = EditorialService::portrait((string) $r->get_param('creator_id'), (int) $r->get_param('revision'));
        $response = self::response($data);
        if (is_string($data)) {
            $response->header('Content-Type', 'image/jpeg');
            $response->header('Content-Disposition', 'inline; filename="portrait.jpg"');
            $response->header('Content-Security-Policy', "sandbox; default-src 'none'");
            $response->header('Cross-Origin-Resource-Policy', 'same-origin');
        }
        return $response;
    }
    public static function serve(bool $served, \WP_HTTP_Response $response, \WP_REST_Request $request): bool
    {
        if (!$served && preg_match('#^/faluss-fans/v1/creators/[0-9a-f-]{36}/portrait/[1-9][0-9]{0,9}$#D', $request->get_route()) === 1
            && $response->get_status() === 200 && is_string($response->get_data()) && ($response->get_headers()['Content-Type'] ?? '') === 'image/jpeg') {
            echo $response->get_data(); // Fresh bounded derivative, never quarantine bytes.
            return true;
        }
        return $served;
    }
    /**
     * @param list<string> $keys
     * @return array<string,mixed>|null
     */
    private static function input(\WP_REST_Request $r, array $keys): ?array
    {
        /** @var mixed $data */
        $data = $r->get_json_params();
        return is_array($data) && count($data) === count($keys) && array_diff($keys, array_keys($data)) === []
            && $r->get_query_params() === [] && $r->get_file_params() === [] && $r->get_body_params() === [] ? $data : null;
    }
    public static function response(mixed $data): \WP_REST_Response
    {
        $status = 200;
        if ($data instanceof \WP_Error) { $status = (int) ($data->get_error_data()['status'] ?? 500); $data = ['code' => $data->get_error_code()]; }
        $response = new \WP_REST_Response($data, $status);
        $response->header('Cache-Control', 'private, no-store, max-age=0');
        $response->header('CDN-Cache-Control', 'no-store');
        $response->header('X-Content-Type-Options', 'nosniff');
        return $response;
    }
}
