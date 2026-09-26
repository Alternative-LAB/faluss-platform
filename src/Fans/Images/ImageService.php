<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Images;

use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\Fans\Publications\PublicationAccessPolicy;

final class ImageService
{
    public static function error(string $code, int $status = 503): \WP_Error
    { return new \WP_Error($code, 'Image indisponible.', ['status' => $status]); }

    /** @return mixed */
    private static function locked(callable $operation): mixed
    {
        if (!ImagesModule::available()) { return self::error('images_unavailable'); }
        global $wpdb;
        $lock = 'fans_images_' . substr(hash('sha256', (string) ImageSchema::table()), 0, 40);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)', $lock)) !== 1) { return self::error('image_busy'); }
        $suppress = $wpdb->suppress_errors(true);
        try { return $operation(); }
        finally { $wpdb->query('ROLLBACK'); $wpdb->suppress_errors($suppress); $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
    }

    /**
     * @param array<string,mixed> $upload
     * @return array<string,mixed>|\WP_Error
     */
    public static function submit(array $upload): array|\WP_Error
    {
        $profile = CreatorProfileService::own();
        if ($profile === null || $profile['status'] !== 'active') { return self::error('active_creator_required', 403); }
        // tmp_name is populated exclusively from PHP multipart data by the REST adapter.
        $tmp = $upload['tmp_name'] ?? null;
        if (!is_string($tmp) || !is_uploaded_file($tmp) || ($upload['error'] ?? null) !== UPLOAD_ERR_OK) { return self::error('invalid_image_upload', 400); }
        try {
            return self::locked(static function () use ($profile, $tmp): array|\WP_Error {
                global $wpdb;
                $root = ImageStorage::root();
                if ($root === null) { return self::error('private_storage_required'); }
                $files = ImageStorage::files($root);
                if ($files === null) { return self::error('private_storage_unavailable'); }
                if (count($files) >= 100) { return self::error('image_site_quota', 429); }
                if ($wpdb->query('START TRANSACTION') === false) { return self::error('image_write_failed'); }
                $table = ImageSchema::table();
                $live = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE creator_id=%s AND state IN (%s,%s)', $profile['creator_id'], 'pending', 'approved'));
                if ($wpdb->last_error !== '' || !is_numeric($live)) { return self::error('image_write_failed'); }
                if ((int) $live >= 5) { return self::error('image_creator_quota', 429); }
                $rates = $wpdb->get_row($wpdb->prepare('SELECT COUNT(*) AS daily_count, COALESCE(SUM(d.occurred_at > UTC_TIMESTAMP()-INTERVAL 1 HOUR),0) AS hourly_count FROM `'
                    . ImageSchema::table(true) . '` d INNER JOIN `' . $table . '` i ON i.image_id=d.image_id WHERE i.creator_id=%s AND d.action=%s AND d.occurred_at > UTC_TIMESTAMP()-INTERVAL 24 HOUR', $profile['creator_id'], 'submit'), 'ARRAY_A');
                if (!is_array($rates) || !is_numeric($rates['daily_count'] ?? null) || !is_numeric($rates['hourly_count'] ?? null)) { return self::error('image_write_failed'); }
                if ((int) $rates['hourly_count'] >= 20 || (int) $rates['daily_count'] >= 60) { return self::error('image_rate_quota', 429); }
                $raw = @file_get_contents($tmp, false, null, 0, ImageStorage::INPUT_LIMIT + 1);
                if (!is_string($raw) || strlen($raw) > ImageStorage::INPUT_LIMIT) { return self::error('image_too_large', 413); }
                $bytes = ImageStorage::normalize($raw);
                if ($bytes === null) { return self::error('invalid_image', 415); }
                $id = wp_generate_uuid4();
                $hash = hash('sha256', $bytes);
                if (!ImageStorage::put($root, $id, $bytes)) { return self::error('image_storage_failed'); }
                $committed = false;
                try {
                    $ok = $wpdb->query($wpdb->prepare('INSERT INTO `' . $table . '` (image_id,creator_id,revision,file_hash,bytes,state,created_at,updated_at) VALUES (%s,%s,1,%s,%d,%s,UTC_TIMESTAMP(),UTC_TIMESTAMP())', $id, $profile['creator_id'], $hash, strlen($bytes), 'pending'));
                    if ($ok !== 1 || !self::audit($id, 1, 'submit', 'awaiting_review', $hash) || $wpdb->query('COMMIT') === false) { return self::error('image_write_failed'); }
                    $committed = true;
                    return ['image_id' => $id, 'revision' => 1, 'state' => 'pending'];
                } finally { if (!$committed) { ImageStorage::remove($root, $id); } }
            });
        } finally { @unlink($tmp); }
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function decide(string $id, int $revision, string $action, string $reason): array|\WP_Error
    {
        if (!ImageStorage::validId($id) || $revision < 1 || $revision >= 2147483647 || !in_array($action, ['approve', 'reject', 'withdraw'], true)
            || ($action === 'approve' && $reason !== 'allowed_image') || ($action === 'reject' && !in_array($reason, ['prohibited_content', 'needs_revision'], true))) { return self::error('invalid_image_decision', 400); }
        $profile = $action === 'withdraw' ? CreatorProfileService::own() : null;
        if (($action !== 'withdraw' && !current_user_can('manage_options')) || ($action === 'withdraw' && $profile === null)) { return self::error('image_forbidden', 403); }
        return self::locked(static function () use ($id, $revision, $action, $reason, $profile): array|\WP_Error {
            global $wpdb;
            if ($wpdb->query('START TRANSACTION') === false) { return self::error('image_write_failed'); }
            $row = self::row($id);
            if ($row === null) { return self::error('image_not_found', 404); }
            if ($action === 'withdraw' && $row['creator_id'] !== $profile['creator_id']) { return self::error('image_forbidden', 403); }
            if ((int) $row['revision'] !== $revision || !in_array($row['state'], ['pending', 'approved'], true) || ($action === 'approve' && $row['state'] !== 'pending')) { return self::error('image_revision_conflict', 409); }
            $root = ImageStorage::root();
            if ($action === 'approve' && ($root === null || CreatorProfileService::publicById($row['creator_id']) === null || ImageStorage::read($root, $id, $row['file_hash']) === null)) { return self::error('image_unavailable'); }
            $state = match ($action) { 'approve' => 'approved', 'reject' => 'rejected', default => 'withdrawn' };
            if ($wpdb->query($wpdb->prepare('UPDATE `' . ImageSchema::table() . '` SET state=%s,revision=revision+1,updated_at=UTC_TIMESTAMP() WHERE image_id=%s AND revision=%d', $state, $id, $revision)) !== 1
                || !self::audit($id, $revision + 1, $action, $action === 'withdraw' ? 'creator_withdrawal' : $reason, $row['file_hash']) || $wpdb->query('COMMIT') === false) { return self::error('image_write_failed'); }
            // Revoke access durably BEFORE unlink. A failed unlink can never reopen bytes.
            if ($action !== 'approve' && ($root === null || !ImageStorage::remove($root, $id))) { return self::error('image_cleanup_required'); }
            return ['image_id' => $id, 'revision' => $revision + 1, 'state' => $state];
        });
    }

    /** @return string|\WP_Error */
    public static function bytes(string $id): string|\WP_Error
    {
        if (!current_user_can('manage_options')) { return self::error('image_forbidden', 403); }
        return self::locked(static function () use ($id): string|\WP_Error {
            $row = ImageStorage::validId($id) ? self::row($id) : null;
            if ($row === null || !in_array($row['state'], ['pending', 'approved'], true) || CreatorProfileService::publicById($row['creator_id']) === null) { return self::error('image_not_found', 404); }
            $root = ImageStorage::root();
            $data = $root === null ? null : ImageStorage::read($root, $id, $row['file_hash']);
            return $data ?? self::error('image_storage_unavailable');
        });
    }

    /** Public distribution is deliberately absent, including after approval. */
    public static function publicAllowed(): bool
    { return PublicationAccessPolicy::originalAllowed(true, 'quarantined', 'approved', 'hosted_allowed_content', 'free', false); }

    /** @return array<string,mixed>|\WP_Error */
    public static function listing(string $cursor = ''): array|\WP_Error
    {
        $admin = current_user_can('manage_options');
        $profile = $admin ? null : CreatorProfileService::own();
        if (!$admin && $profile === null) { return self::error('image_forbidden', 403); }
        if ($cursor !== '' && !ImageStorage::validId($cursor)) { return self::error('invalid_image_cursor', 400); }
        return self::locked(static function () use ($admin, $profile, $cursor): array|\WP_Error {
            global $wpdb;
            $sql = 'SELECT image_id,revision,state FROM `' . ImageSchema::table() . '` WHERE image_id>%s';
            $args = [$cursor];
            if (!$admin) { $sql .= ' AND creator_id=%s'; $args[] = $profile['creator_id']; }
            $rows = $wpdb->get_results($wpdb->prepare($sql . ' ORDER BY image_id LIMIT 21', ...$args), 'ARRAY_A');
            if (!is_array($rows) || $wpdb->last_error !== '') { return self::error('image_read_failed'); }
            $more = count($rows) > 20; $rows = array_slice($rows, 0, 20);
            return ['items' => $rows, 'next_cursor' => $more ? $rows[19]['image_id'] : null];
        });
    }

    /** @return array<mixed>|\WP_Error */
    public static function decisions(string $id): array|\WP_Error
    {
        if (!current_user_can('manage_options')) { return self::error('image_forbidden', 403); }
        return self::locked(static function () use ($id): array|\WP_Error {
            global $wpdb;
            if (!ImageStorage::validId($id) || self::row($id) === null) { return self::error('image_not_found', 404); }
            $rows = $wpdb->get_results($wpdb->prepare('SELECT revision,actor_id,action,reason,occurred_at FROM `' . ImageSchema::table(true) . '` WHERE image_id=%s ORDER BY revision', $id), 'ARRAY_A');
            return is_array($rows) && $wpdb->last_error === '' ? $rows : self::error('image_read_failed');
        });
    }

    /**
     * Explicit administrative retry/reconciliation, never removes live images.
     * @return array<string,int>|\WP_Error
     */
    public static function cleanup(): array|\WP_Error
    {
        if (!current_user_can('manage_options')) { return self::error('image_forbidden', 403); }
        return self::locked(static function (): array|\WP_Error {
            global $wpdb;
            $root = ImageStorage::root();
            $files = $root === null ? null : ImageStorage::files($root);
            if ($files === null) { return self::error('private_storage_required'); }
            $removed = 0;
            foreach ($files as $file) {
                $id = substr($file, 0, -4); $row = self::row($id);
                if ($wpdb->last_error !== '') { return self::error('image_read_failed'); }
                if ($row === null || in_array($row['state'], ['withdrawn', 'rejected'], true)) {
                    if (!ImageStorage::remove($root, $id)) { return self::error('image_cleanup_required'); }
                    $removed++;
                }
            }
            return ['removed' => $removed];
        });
    }

    /** @return array<string,mixed>|null */
    private static function row(string $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . ImageSchema::table() . '` WHERE image_id=%s', $id), 'ARRAY_A');
        return is_array($row) ? $row : null;
    }
    private static function audit(string $id, int $revision, string $action, string $reason, string $hash): bool
    {
        global $wpdb;
        return $wpdb->query($wpdb->prepare('INSERT INTO `' . ImageSchema::table(true) . '` (image_id,revision,actor_id,action,reason,file_hash,occurred_at) VALUES (%s,%d,%d,%s,%s,%s,UTC_TIMESTAMP())', $id, $revision, get_current_user_id(), $action, $reason, $hash)) === 1;
    }
}
