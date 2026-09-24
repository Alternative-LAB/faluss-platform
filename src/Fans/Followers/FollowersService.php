<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Followers;

use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\Fans\Sso\FansSsoService;

final class FollowersService
{
    /** @return array{creator_id:string,following:bool}|\WP_Error */
    public static function follow(mixed $creatorId): array|\WP_Error
    {
        $member = self::member();
        if ($member === null) {
            return self::error('sso_required', 403);
        }
        if (!self::validId($creatorId)) {
            return self::error('invalid_creator', 400);
        }
        $own = CreatorProfileService::own();
        if ($own !== null && $own['creator_id'] === $creatorId) {
            return self::error('self_follow', 403);
        }
        if (CreatorProfileService::publicById($creatorId) === null) {
            return self::error('creator_not_found', 404);
        }
        $table = FollowersSchema::table();
        if ($table === null || !FollowersSchema::ready()) {
            return self::error('followers_unavailable', 503);
        }
        if (self::exists($table, $member, $creatorId)) {
            return ['creator_id' => $creatorId, 'following' => true];
        }
        global $wpdb;
        $inserted = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . self::quote($table) . ' (follower_user_id, creator_id, created_at) VALUES (%d, %s, %s)',
            $member,
            $creatorId,
            gmdate('Y-m-d H:i:s')
        ));
        if ($inserted !== 1 && !self::exists($table, $member, $creatorId)) {
            return self::error('followers_unavailable', 503);
        }

        return ['creator_id' => $creatorId, 'following' => true];
    }

    /** @return array{creator_id:string,following:bool}|\WP_Error */
    public static function unfollow(mixed $creatorId): array|\WP_Error
    {
        $member = self::member();
        if ($member === null) {
            return self::error('sso_required', 403);
        }
        if (!self::validId($creatorId)) {
            return self::error('invalid_creator', 400);
        }
        $table = FollowersSchema::table();
        if ($table === null || !FollowersSchema::ready()) {
            return self::error('followers_unavailable', 503);
        }
        global $wpdb;
        $deleted = $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . self::quote($table) . ' WHERE follower_user_id = %d AND creator_id = %s',
            $member,
            $creatorId
        ));
        if ($deleted === false) {
            return self::error('followers_unavailable', 503);
        }

        return ['creator_id' => $creatorId, 'following' => false];
    }

    /** @return array{creator_id:string,count:int}|\WP_Error */
    public static function publicCount(mixed $creatorId): array|\WP_Error
    {
        if (!self::validId($creatorId) || CreatorProfileService::publicById($creatorId) === null) {
            return self::error('creator_not_found', 404);
        }
        $table = FollowersSchema::table();
        if ($table === null || !FollowersSchema::ready()) {
            return self::error('followers_unavailable', 503);
        }
        global $wpdb;
        $count = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::quote($table) . ' WHERE creator_id = %s',
            $creatorId
        ));
        if (!is_numeric($count) || (int) $count < 0) {
            return self::error('followers_unavailable', 503);
        }

        return ['creator_id' => $creatorId, 'count' => (int) $count];
    }

    private static function member(): ?int
    {
        return FansSsoService::currentLinkedSubject() !== null ? get_current_user_id() : null;
    }

    /** @phpstan-impure Database state may change between concurrent requests. */
    private static function exists(string $table, int $member, string $creatorId): bool
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM ' . self::quote($table) . ' WHERE follower_user_id = %d AND creator_id = %s LIMIT 1',
            $member,
            $creatorId
        )) !== null;
    }

    private static function validId(mixed $id): bool
    {
        return is_string($id)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) === 1;
    }

    private static function error(string $code, int $status): \WP_Error
    {
        return new \WP_Error($code, 'Action indisponible.', ['status' => $status]);
    }

    private static function quote(string $table): string
    {
        return '`' . str_replace('`', '``', $table) . '`';
    }
}
