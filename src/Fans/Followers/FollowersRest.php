<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Followers;

use Faluss\Platform\Fans\Profiles\CreatorProfileRest;

final class FollowersRest
{
    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void
    {
        register_rest_route('faluss-fans/v1', '/creators/(?P<creator_id>[0-9a-f-]{36})/followers/count', [
            'methods' => 'GET',
            'callback' => [self::class, 'count'],
            'permission_callback' => static fn (): bool => true,
        ]);
        register_rest_route('faluss-fans/v1', '/creators/(?P<creator_id>[0-9a-f-]{36})/follow', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'ownFollowing'],
                'permission_callback' => [CreatorProfileRest::class, 'memberPermission'],
            ],
            [
                'methods' => 'POST',
                'callback' => [self::class, 'follow'],
                'permission_callback' => [CreatorProfileRest::class, 'memberPermission'],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [self::class, 'unfollow'],
                'permission_callback' => [CreatorProfileRest::class, 'memberPermission'],
            ],
        ]);
    }

    public static function follow(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $result = FollowersService::follow($request->get_param('creator_id'));

        return $result instanceof \WP_Error ? $result : new \WP_REST_Response($result, 200);
    }

    public static function ownFollowing(\WP_REST_Request $request): \WP_REST_Response
    {
        return \Faluss\Platform\Fans\Profiles\EditorialRest::response($request->get_query_params()!==[]||$request->get_body()!==''
            ||$request->get_body_params()!==[]||$request->get_file_params()!==[]
            ?new \WP_Error('invalid_follow_read','Action indisponible.',['status'=>400])
            :FollowersService::ownFollowing($request->get_param('creator_id')));
    }

    public static function unfollow(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $result = FollowersService::unfollow($request->get_param('creator_id'));

        return $result instanceof \WP_Error ? $result : new \WP_REST_Response($result, 200);
    }

    public static function count(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $result = FollowersService::publicCount($request->get_param('creator_id'));

        return $result instanceof \WP_Error ? $result : new \WP_REST_Response($result, 200);
    }
}
