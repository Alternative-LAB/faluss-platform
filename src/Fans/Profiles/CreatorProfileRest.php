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
        register_rest_route(self::NAMESPACE, '/creators/moderation', [
            'methods' => 'GET', 'callback' => [self::class, 'moderation'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);
        register_rest_route(self::NAMESPACE, '/creators/(?P<creator_id>[0-9a-f-]{36})/private', [
            'methods' => 'GET', 'callback' => [self::class, 'privateProfile'],
            'permission_callback' => [self::class, 'adminPermission'],
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
            : EditorialRest::response($profile);
    }

    public static function publicProfile(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $profile = CreatorProfileService::publicById($request->get_param('creator_id'));

        return $profile === null
            ? new \WP_Error('profile_not_found', 'Profil introuvable.', ['status' => 404])
            : EditorialRest::response($profile + ['editorial' => EditorialService::publicById($profile['creator_id'])]);
    }

    public static function listPublic(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $result = CreatorProfileService::publicList($request->get_param('category'));

        return $result instanceof \WP_Error ? $result : EditorialRest::response(array_map(
            static fn (array $profile): array => $profile + ['editorial' => EditorialService::publicById($profile['creator_id'])], $result));
    }

    public static function setStatus(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $revision = $request->get_param('revision');
        if (!is_int($revision) || $revision < 0 || $revision >= 2147483646) {
            return new \WP_Error('invalid_status_revision', 'Relisez le statut courant avant de décider.', ['status' => 400]);
        }
        $result = CreatorProfileService::setStatus(
            $request->get_param('creator_id'),
            $request->get_param('status'),
            $revision
        );

        return $result instanceof \WP_Error ? $result : EditorialRest::response($result);
    }

    public static function moderation(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $result = CreatorStatusReview::queue($request->get_param('status') ?? 'pending', $request->get_param('cursor'));
        return $result instanceof \WP_Error ? $result : EditorialRest::response($result);
    }

    public static function privateProfile(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $result = CreatorStatusReview::detail($request->get_param('creator_id'));
        return $result instanceof \WP_Error ? $result : EditorialRest::response($result);
    }

    private static function nonceValid(\WP_REST_Request $request): bool
    {
        $nonce = $request->get_header('X-WP-Nonce');

        return is_string($nonce) && $nonce !== '' && wp_verify_nonce($nonce, 'wp_rest') !== false;
    }
}
