<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Profiles;

/** Admission only. Serializes on the existing profile row before journaling a decision. */
final class CreatorStatusReview
{
    /** @return array<string,mixed>|\WP_Error */
    public static function decide(mixed $id, mixed $status, mixed $revision = null): array|\WP_Error
    {
        if (!current_user_can('manage_options') || get_current_user_id() < 1) { return self::error('admin_required', 403); }
        if (!EditorialService::validId($id) || !in_array($status, ['active', 'suspended'], true)
            || ($revision !== null && (!is_int($revision) || $revision < 0 || $revision >= 2147483646))) {
            return self::error('invalid_status', 400);
        }
        return self::transaction(static function () use ($id, $status, $revision): array|\WP_Error {
            global $wpdb;
            $row = self::row($id);
            if ($row instanceof \WP_Error) { return $row; }
            if ($revision !== null && $revision !== $row['status_revision']) { return self::error('status_revision_conflict', 409); }
            if ($status === 'active' && !CreatorProfileService::hasLinkedOwner($id)) { return self::error('creator_link_missing', 409); }
            // Legacy server callers may omit the expected revision; no-op still creates no decision.
            if ($row['status'] === $status) { return $row; }
            if ($row['status_revision'] >= 2147483646) { return self::error('status_revision_exhausted', 503); }
            $now = gmdate('Y-m-d H:i:s');
            if ($wpdb->query($wpdb->prepare('UPDATE `' . CreatorProfileSchema::table()
                . '` SET status=%s,updated_at=%s WHERE creator_id=%s AND status=%s', $status, $now, $id, $row['status'])) !== 1
                || $wpdb->query($wpdb->prepare('INSERT INTO `' . CreatorStatusSchema::table()
                . '` (creator_id,revision,actor_id,previous_status,status,occurred_at) VALUES (%s,%d,%d,%s,%s,%s)',
                    $id, $row['status_revision'] + 1, get_current_user_id(), $row['status'], $status, $now)) !== 1) {
                return self::error('status_write_failed', 503);
            }
            return array_replace($row, ['status' => $status, 'updated_at' => $now, 'status_revision' => $row['status_revision'] + 1]);
        });
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function detail(mixed $id): array|\WP_Error
    {
        if (!current_user_can('manage_options')) { return self::error('admin_required', 403); }
        if (!EditorialService::validId($id)) { return self::error('invalid_creator_id', 400); }
        return self::transaction(static function () use ($id): array|\WP_Error {
            global $wpdb;
            $row = self::row($id);
            if ($row instanceof \WP_Error) { return $row; }
            $journal = $wpdb->get_results($wpdb->prepare('SELECT revision,actor_id,previous_status,status,occurred_at FROM `'
                . CreatorStatusSchema::table() . '` WHERE creator_id=%s ORDER BY revision DESC LIMIT 20', $id), 'ARRAY_A');
            return is_array($journal) && !self::databaseError()
                ? $row + ['journal' => $journal] : self::error('status_unavailable', 503);
        });
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function queue(mixed $status, mixed $cursor): array|\WP_Error
    {
        if (!current_user_can('manage_options')) { return self::error('admin_required', 403); }
        if (!in_array($status, ['pending', 'active', 'suspended'], true)
            || ($cursor !== null && !EditorialService::validId($cursor))) { return self::error('invalid_status_filter', 400); }
        if (!CreatorProfileSchema::ready() || !CreatorStatusSchema::ready()) { return self::error('status_unavailable', 503); }
        global $wpdb;
        $sql = 'SELECT creator_id,category,status,created_at,updated_at FROM `' . CreatorProfileSchema::table()
            . '` WHERE status=%s' . ($cursor !== null ? ' AND creator_id>%s' : '') . ' ORDER BY creator_id ASC LIMIT 21';
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...($cursor !== null ? [$status, $cursor] : [$status])), 'ARRAY_A');
        if (!is_array($rows)) { return self::error('status_unavailable', 503); }
        $more = count($rows) === 21;
        $rows = array_slice($rows, 0, 20);
        return ['items' => $rows, 'next_cursor' => $more ? $rows[19]['creator_id'] : null];
    }

    /** @return array<string,mixed>|\WP_Error */
    private static function row(string $id): array|\WP_Error
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT creator_id,category,status,created_at,updated_at FROM `'
            . CreatorProfileSchema::table() . '` WHERE creator_id=%s LIMIT 1 FOR UPDATE', $id), 'ARRAY_A');
        if (self::databaseError()) { return self::error('status_unavailable', 503); }
        if (!is_array($row)) { return self::error('profile_not_found', 404); }
        if (!in_array($row['status'] ?? null, ['pending', 'active', 'suspended'], true)
            || !in_array($row['category'] ?? null, CreatorProfileService::CATEGORIES, true)) {
            return self::error('status_unavailable', 503);
        }
        // The profile lock serializes all writers. A nonlocking first snapshot read avoids
        // gap-lock deadlocks between decisions on different creators with an empty journal.
        $revision = $wpdb->get_var($wpdb->prepare('SELECT revision FROM `' . CreatorStatusSchema::table()
            . '` WHERE creator_id=%s ORDER BY revision DESC LIMIT 1', $id));
        if (self::databaseError() || ($revision !== null && (!is_numeric($revision) || (int) $revision < 1))) {
            return self::error('status_unavailable', 503);
        }
        return $row + ['status_revision' => $revision === null ? 0 : (int) $revision, 'identity_verified' => false];
    }

    /** @param callable(): (array<string,mixed>|\WP_Error) $operation
     * @return array<string,mixed>|\WP_Error */
    private static function transaction(callable $operation): array|\WP_Error
    {
        if (!CreatorProfileSchema::ready() || !CreatorStatusSchema::ready()) { return self::error('status_unavailable', 503); }
        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) { return self::error('status_unavailable', 503); }
        try {
            $result = $operation();
            if ($result instanceof \WP_Error) { $wpdb->query('ROLLBACK'); return $result; }
            if ($wpdb->query('COMMIT') === false) { $wpdb->query('ROLLBACK'); return self::error('status_commit_failed', 503); }
            return $result;
        } catch (\Throwable) {
            $wpdb->query('ROLLBACK');
            return self::error('status_unavailable', 503);
        }
    }

    /** @phpstan-impure Reads the error of the most recent database operation. */
    private static function databaseError(): bool
    { global $wpdb; return $wpdb->last_error !== ''; }

    private static function error(string $code, int $status): \WP_Error
    { return new \WP_Error($code, 'Décision de profil indisponible.', ['status' => $status]); }
}
