<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Profiles;

use Faluss\Platform\Fans\Sso\FansSsoService;

final class CreatorProfileRest
{
    public const NAMESPACE = 'faluss-fans/v1';

    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void
    {
        register_rest_route(self::NAMESPACE, '/creators', [
            'methods' => 'GET',
            'callback' => [self::class, 'listPublic'],
            'permission_callback' => static fn (): bool => true,
        ]);
        register_rest_route(self::NAMESPACE, '/creators/me', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'own'],
                'permission_callback' => [self::class, 'memberPermission'],
            ],
            [
                'methods' => 'POST',
                'callback' => [self::class, 'create'],
                'permission_callback' => [self::class, 'memberPermission'],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/creators/(?P<creator_id>[0-9a-f-]{36})', [
            'methods' => 'GET',
            'callback' => [self::class, 'publicProfile'],
            'permission_callback' => static fn (): bool => true,
        ]);
        register_rest_route(self::NAMESPACE, '/creators/(?P<creator_id>[0-9a-f-]{36})/status', [
            'methods' => 'POST',
            'callback' => [self::class, 'setStatus'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);
    }

    public static function memberPermission(\WP_REST_Request $request): bool
    {
        return self::nonceValid($request) && FansSsoService::currentLinkedSubject() !== null;
    }

    public static function adminPermission(\WP_REST_Request $request): bool
    {
        return self::nonceValid($request) && current_user_can('manage_options');
    }

    public static function create(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $result = CreatorProfileService::create($request->get_param('category'));

        return $result instanceof \WP_Error ? $result : new \WP_REST_Response($result, 201);
    }

    public static function own(): \WP_REST_Response|\WP_Error
    {
        $profile = CreatorProfileService::own();

        return $profile === null
            ? new \WP_Error('profile_not_found', 'Profil introuvable.', ['status' => 404])
            : new \WP_REST_Response($profile, 200);
    }

    public static function publicProfile(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $profile = CreatorProfileService::publicById($request->get_param('creator_id'));

        return $profile === null
            ? new \WP_Error('profile_not_found', 'Profil introuvable.', ['status' => 404])
            : new \WP_REST_Response($profile, 200);
    }

    public static function listPublic(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $result = CreatorProfileService::publicList($request->get_param('category'));

        return $result instanceof \WP_Error ? $result : new \WP_REST_Response($result, 200);
    }

    public static function setStatus(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $result = CreatorProfileService::setStatus(
            $request->get_param('creator_id'),
            $request->get_param('status')
        );

        return $result instanceof \WP_Error ? $result : new \WP_REST_Response($result, 200);
    }

    private static function nonceValid(\WP_REST_Request $request): bool
    {
        $nonce = $request->get_header('X-WP-Nonce');

        return is_string($nonce) && $nonce !== '' && wp_verify_nonce($nonce, 'wp_rest') !== false;
    }
}
