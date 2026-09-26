<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Publications;

use Faluss\Platform\Fans\Profiles\CreatorProfileRest;

final class TextPublicationRest
{
    public static function register(): void { add_action('rest_api_init', [self::class, 'routes']); }
    public static function routes(): void
    {
        $id = '/text-publications/(?P<publication_id>[0-9a-f-]{36})';
        foreach ([
            ['/text-publications', 'GET', 'publicList', 'publicPermission'],
            ['/text-publications', 'POST', 'create', 'memberPermission'],
            ['/text-publications/mine', 'GET', 'ownList', 'memberPermission'],
            ['/text-publications/moderation', 'GET', 'queue', 'adminPermission'],
            [$id, 'GET', 'publicGet', 'publicPermission'],
            [$id . '/private', 'GET', 'privateGet', 'privatePermission'],
            [$id . '/image', 'POST', 'image', 'memberPermission'],
            [$id . '/edit', 'POST', 'edit', 'memberPermission'],
            [$id . '/withdraw', 'POST', 'withdraw', 'memberPermission'],
            [$id . '/moderate', 'POST', 'moderate', 'adminPermission'],
            [$id . '/decisions', 'GET', 'decisions', 'adminPermission'],
        ] as [$route, $method, $callback, $permission]) {
            register_rest_route('faluss-fans/v1', $route, [
                'methods' => $method, 'callback' => [self::class, $callback],
                'permission_callback' => [self::class, $permission],
            ]);
        }
    }
    public static function publicPermission(): bool { return TextPublicationsModule::available(); }
    public static function memberPermission(\WP_REST_Request $r): bool
    { return self::publicPermission() && CreatorProfileRest::memberPermission($r); }
    public static function adminPermission(\WP_REST_Request $r): bool
    { return self::publicPermission() && CreatorProfileRest::adminPermission($r); }
    public static function privatePermission(\WP_REST_Request $r): bool
    { return self::adminPermission($r) || self::memberPermission($r); }

    public static function create(\WP_REST_Request $r): \WP_REST_Response
    {
        $data = self::input($r, ['text', 'category']);
        return self::response($data === null ? self::badInput() : TextPublicationService::create($data['text'], $data['category'], $r->get_header('Idempotency-Key')), 201);
    }
    public static function edit(\WP_REST_Request $r): \WP_REST_Response
    {
        $data = self::input($r, ['text', 'revision']);
        return self::response($data === null ? self::badInput() : TextPublicationService::change($r->get_param('publication_id'), $data['revision'], 'edit', $data['text']));
    }
    public static function image(\WP_REST_Request $r): \WP_REST_Response
    {
        $data = self::input($r, ['revision', 'image_id', 'image_revision']);
        return self::response($data === null ? self::badInput() : TextPublicationService::change($r->get_param('publication_id'),
            $data['revision'], 'image', null, null, $data['image_id'], $data['image_revision']));
    }
    public static function withdraw(\WP_REST_Request $r): \WP_REST_Response
    {
        $data = self::input($r, ['revision']);
        return self::response($data === null ? self::badInput() : TextPublicationService::change($r->get_param('publication_id'), $data['revision'], 'withdraw'));
    }
    public static function moderate(\WP_REST_Request $r): \WP_REST_Response
    {
        $data = self::input($r, ['revision', 'decision', 'reason']);
        if ($data === null || !in_array($data['decision'], ['approve', 'reject'], true)) { return self::response(self::badInput()); }
        return self::response(TextPublicationService::change($r->get_param('publication_id'), $data['revision'], $data['decision'], null, $data['reason']));
    }
    public static function publicList(\WP_REST_Request $r): \WP_REST_Response { return self::page($r, 'public'); }
    public static function ownList(\WP_REST_Request $r): \WP_REST_Response { return self::page($r, 'own'); }
    public static function queue(\WP_REST_Request $r): \WP_REST_Response { return self::page($r, 'queue'); }
    public static function publicGet(\WP_REST_Request $r): \WP_REST_Response { return self::response(TextPublicationService::get($r->get_param('publication_id'))); }
    public static function privateGet(\WP_REST_Request $r): \WP_REST_Response { return self::response(TextPublicationService::get($r->get_param('publication_id'), true)); }
    public static function decisions(\WP_REST_Request $r): \WP_REST_Response { return self::response(TextPublicationService::decisions($r->get_param('publication_id'))); }

    private static function page(\WP_REST_Request $r, string $scope): \WP_REST_Response
    {
        return self::response(TextPublicationService::listing($scope, $r->get_param('per_page') ?? 20, $r->get_param('cursor')));
    }

    /**
     * @param list<string> $keys
     * @return array<string,mixed>|null
     */
    private static function input(\WP_REST_Request $r, array $keys): ?array
    {
        /** @var mixed $data WordPress can return null or a JSON scalar. */
        $data = $r->get_json_params();
        if (!is_array($data) || count($data) !== count($keys) || array_diff($keys, array_keys($data)) !== []
            || $r->get_file_params() !== [] || $r->get_query_params() !== []
        ) { return null; }
        return $data;
    }
    private static function badInput(): \WP_Error { return new \WP_Error('invalid_publication_input', 'Paramètres invalides.', ['status' => 400]); }
    /** @param array<mixed>|\WP_Error $data */
    private static function response(array|\WP_Error $data, int $status = 200): \WP_REST_Response
    {
        if ($data instanceof \WP_Error) {
            $status = (int) ($data->get_error_data()['status'] ?? 500);
            $data = ['code' => $data->get_error_code(), 'message' => 'Publication indisponible.', 'data' => ['status' => $status]];
        }
        $response = new \WP_REST_Response($data, $status);
        $response->header('Cache-Control', 'private, no-store, max-age=0');
        $response->header('X-Content-Type-Options', 'nosniff');
        return $response;
    }
}
