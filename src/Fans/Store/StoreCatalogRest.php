<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Store;

use Faluss\Platform\Fans\Profiles\CreatorProfileRest;

final class StoreCatalogRest
{
    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void
    {
        foreach (['/store/admin/archive' => 'archive', '/store/admin/archive/(?P<product_id>[0-9a-f-]{36})' => 'archivedProduct'] as $route => $callback) {
            register_rest_route('faluss-fans/v1', $route, [
                'methods' => 'GET',
                'callback' => [self::class, $callback],
                'permission_callback' => [CreatorProfileRest::class, 'adminPermission'],
            ]);
        }
        register_rest_route('faluss-fans/v1', '/store/categories', [
            'methods' => 'GET',
            'callback' => [self::class, 'categories'],
            'permission_callback' => static fn (): bool => true,
        ]);
        register_rest_route('faluss-fans/v1', '/store/products', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'products'],
                'permission_callback' => static fn (): bool => true,
            ],
            [
                'methods' => 'POST',
                'callback' => [self::class, 'create'],
                'permission_callback' => [CreatorProfileRest::class, 'adminPermission'],
            ],
        ]);
        register_rest_route('faluss-fans/v1', '/store/products/(?P<product_id>[0-9a-f-]{36})', [
            'methods' => 'GET',
            'callback' => [self::class, 'product'],
            'permission_callback' => static fn (): bool => true,
        ]);
        register_rest_route('faluss-fans/v1', '/store/products/(?P<product_id>[0-9a-f-]{36})/purchase', [
            'methods' => 'POST',
            'callback' => [self::class, 'purchase'],
            'permission_callback' => static fn (): bool => true,
        ]);
    }

    public static function archive(\WP_REST_Request $request): \WP_REST_Response
    {
        return self::privateResult(StoreCatalogService::archiveList($request->get_param('cursor')));
    }

    public static function archivedProduct(\WP_REST_Request $request): \WP_REST_Response
    {
        return self::privateResult(StoreCatalogService::archivedById($request->get_param('product_id')));
    }

    /** @param array<string,mixed>|\WP_Error $result */
    private static function privateResult(array|\WP_Error $result): \WP_REST_Response
    {
        $response = $result instanceof \WP_Error ? rest_convert_error_to_response($result) : new \WP_REST_Response($result, 200);
        $response->header('Cache-Control', 'private, no-store, max-age=0');
        $response->header('Vary', 'Cookie, X-WP-Nonce');
        return $response;
    }

    public static function categories(): \WP_REST_Response
    {
        return new \WP_REST_Response([
            ['category' => PurchaseGate::HOSTED, 'label' => PurchaseGate::categoryLabel(PurchaseGate::HOSTED), 'purchasable' => false],
        ], 200);
    }

    public static function products(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $result = StoreCatalogService::publicList($request->get_param('category'));

        return $result instanceof \WP_Error ? $result : new \WP_REST_Response($result, 200);
    }

    public static function product(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $result = StoreCatalogService::publicById($request->get_param('product_id'));

        return $result === null
            ? new \WP_Error('product_not_found', 'Produit introuvable.', ['status' => 404])
            : new \WP_REST_Response($result, 200);
    }

    public static function create(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $result = StoreCatalogService::create(
            $request->get_param('creator_id'),
            $request->get_param('category'),
            $request->get_header('Idempotency-Key')
        );

        return $result instanceof \WP_Error ? $result : new \WP_REST_Response($result, 201);
    }

    public static function purchase(\WP_REST_Request $request): \WP_Error
    {
        return StoreCatalogService::purchase($request->get_param('product_id'));
    }
}
