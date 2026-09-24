<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Profiles;

use Faluss\Platform\Fans\Sso\FansSsoService;

final class CreatorProfileService
{
    public const CATEGORIES = ['arts', 'music', 'games', 'learning', 'lifestyle'];
    private const STATUS = ['pending', 'active', 'suspended'];

    /** @return array{creator_id:string,category:string,status:string,identity_verified:false,created_at:string,updated_at:string}|\WP_Error */
    public static function create(mixed $category): array|\WP_Error
    {
        if (!is_string($category) || !in_array($category, self::CATEGORIES, true)) {
            return self::error('invalid_category', 400);
        }
        $owner = self::currentOwner();
        if ($owner === null) {
            return self::error('sso_required', 403);
        }
        $existing = self::own();
        if ($existing !== null) {
            return $existing['category'] === $category ? $existing : self::error('category_conflict', 409);
        }
        $table = CreatorProfileSchema::table();
        if ($table === null || !CreatorProfileSchema::ready()) {
            return self::error('profiles_unavailable', 503);
        }
        global $wpdb;
        try {
            $creatorId = self::uuid(random_bytes(16));
        } catch (\Throwable) {
            return self::error('creator_id_unavailable', 503);
        }
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . self::quote($table)
                . ' (creator_id, wp_user_id, category, status, created_at, updated_at)'
                . ' VALUES (%s, %d, %s, %s, %s, %s)',
            $creatorId,
            $owner,
            $category,
            'pending',
            $now,
            $now
        ));
        if ($inserted !== 1) {
            // The unique owner index makes repeated POSTs safe across concurrent requests.
            $existing = self::own();

            return $existing !== null && $existing['category'] === $category
                ? $existing
                : self::error('profile_conflict', 409);
        }

        return [
            'creator_id' => $creatorId,
            'category' => $category,
            'status' => 'pending',
            'identity_verified' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @return array{creator_id:string,category:string,status:string,identity_verified:false,created_at:string,updated_at:string}|null */
    public static function own(): ?array
    {
        $owner = self::currentOwner();
        $table = CreatorProfileSchema::table();
        if ($owner === null || $table === null || !CreatorProfileSchema::ready()) {
            return null;
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT creator_id, category, status, created_at, updated_at FROM ' . self::quote($table)
                . ' WHERE wp_user_id = %d LIMIT 1',
            $owner
        ), 'ARRAY_A');

        return self::profile($row);
    }

    /** @return array{creator_id:string,category:string,status:string,identity_verified:false,created_at:string,updated_at:string}|null */
    public static function publicById(mixed $creatorId): ?array
    {
        $table = CreatorProfileSchema::table();
        if (!self::validId($creatorId) || $table === null || !CreatorProfileSchema::ready()) {
            return null;
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT creator_id, category, status, created_at, updated_at FROM ' . self::quote($table)
                . ' WHERE creator_id = %s AND status = %s LIMIT 1',
            $creatorId,
            'active'
        ), 'ARRAY_A');

        return self::profile($row);
    }

    /** @return list<array{creator_id:string,category:string,status:string,identity_verified:false,created_at:string,updated_at:string}>|\WP_Error */
    public static function publicList(mixed $category): array|\WP_Error
    {
        if ($category !== null && (!is_string($category) || !in_array($category, self::CATEGORIES, true))) {
            return self::error('invalid_category', 400);
        }
        $table = CreatorProfileSchema::table();
        if ($table === null || !CreatorProfileSchema::ready()) {
            return self::error('profiles_unavailable', 503);
        }
        global $wpdb;
        $sql = 'SELECT creator_id, category, status, created_at, updated_at FROM ' . self::quote($table)
            . ' WHERE status = %s';
        $sql = $category === null
            ? $wpdb->prepare($sql . ' ORDER BY creator_id ASC LIMIT 20', 'active')
            : $wpdb->prepare($sql . ' AND category = %s ORDER BY creator_id ASC LIMIT 20', 'active', $category);
        $rows = $wpdb->get_results($sql, 'ARRAY_A');
        if (!is_array($rows)) {
            return self::error('profiles_unavailable', 503);
        }
        $profiles = [];
        foreach ($rows as $row) {
            $profile = self::profile($row);
            if ($profile === null || $profile['status'] !== 'active') {
                return self::error('profiles_unavailable', 503);
            }
            $profiles[] = $profile;
        }

        return $profiles;
    }

    /** @return array{creator_id:string,category:string,status:string,identity_verified:false,created_at:string,updated_at:string}|\WP_Error */
    public static function setStatus(mixed $creatorId, mixed $status): array|\WP_Error
    {
        if (!current_user_can('manage_options')) {
            return self::error('admin_required', 403);
        }
        if (!self::validId($creatorId) || !in_array($status, ['active', 'suspended'], true)) {
            return self::error('invalid_status', 400);
        }
        $table = CreatorProfileSchema::table();
        if ($table === null || !CreatorProfileSchema::ready()) {
            return self::error('profiles_unavailable', 503);
        }
        global $wpdb;
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::quote($table) . ' SET status = %s, updated_at = %s'
                . ' WHERE creator_id = %s AND status <> %s',
            $status,
            gmdate('Y-m-d H:i:s'),
            $creatorId,
            $status
        ));
        if ($updated === false) {
            return self::error('profiles_unavailable', 503);
        }
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT creator_id, category, status, created_at, updated_at FROM ' . self::quote($table)
                . ' WHERE creator_id = %s LIMIT 1',
            $creatorId
        ), 'ARRAY_A');
        $profile = self::profile($row);

        return $profile ?? self::error('profile_not_found', 404);
    }

    private static function currentOwner(): ?int
    {
        return FansSsoService::currentLinkedSubject() !== null ? get_current_user_id() : null;
    }

    /** @return array{creator_id:string,category:string,status:string,identity_verified:false,created_at:string,updated_at:string}|null */
    private static function profile(mixed $row): ?array
    {
        if (!is_array($row)
            || !self::validId($row['creator_id'] ?? null)
            || !in_array($row['category'] ?? null, self::CATEGORIES, true)
            || !in_array($row['status'] ?? null, self::STATUS, true)
            || !is_string($row['created_at'] ?? null)
            || !is_string($row['updated_at'] ?? null)
        ) {
            return null;
        }

        return [
            'creator_id' => $row['creator_id'],
            'category' => $row['category'],
            'status' => $row['status'],
            'identity_verified' => false,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private static function validId(mixed $id): bool
    {
        return is_string($id)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) === 1;
    }

    private static function uuid(string $bytes): string
    {
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
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
