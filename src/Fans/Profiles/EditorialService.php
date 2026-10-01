<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Profiles;

use Faluss\Platform\Fans\Images\ImageDisplayDerivative;
use Faluss\Platform\Fans\Images\PublicationImageReference;

/** Revisioned Fans content, never an identity claim or account attribute. */
final class EditorialService
{
    public static function error(string $code, int $status = 503): \WP_Error
    { return new \WP_Error($code, 'Présentation indisponible.', ['status' => $status]); }

    public static function validId(mixed $id): bool
    { return is_string($id) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) === 1; }

    /** @return array<string,mixed>|\WP_Error */
    public static function own(): array|\WP_Error
    {
        $profile = CreatorProfileService::own();
        if ($profile === null) { return self::error('editorial_forbidden', 403); }
        return self::transaction(static function () use ($profile): array|\WP_Error {
            $row = self::row($profile['creator_id']);
            if ($row instanceof \WP_Error) { return $row; }
            $published = self::row($profile['creator_id'], true);
            if ($published instanceof \WP_Error) { return $published; }
            return ($row ?? ['creator_id' => $profile['creator_id'], 'revision' => 0, 'state' => 'absent',
                'public_name' => '', 'bio' => '', 'portrait_id' => '', 'portrait_revision' => 0]) + ['published' => $published];
        });
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function submit(mixed $revision, mixed $name, mixed $bio, mixed $portrait, mixed $portraitRevision): array|\WP_Error
    {
        if (!is_int($revision) || $revision < 0 || $revision >= 2147483646 || !self::text($name, 80, false)
            || trim($name) === '' || !self::text($bio, 1000, true) || !is_int($portraitRevision)
            || !is_string($portrait) || ($portrait === '' ? $portraitRevision !== 0
                : (!self::validId($portrait) || $portraitRevision < 1 || $portraitRevision >= 2147483647))) {
            return self::error('invalid_editorial', 400);
        }
        $profile = CreatorProfileService::own();
        if ($profile === null || $profile['status'] !== 'active') { return self::error('active_creator_required', 403); }
        return self::transaction(static function () use ($profile, $revision, $name, $bio, $portrait, $portraitRevision): array|\WP_Error {
            global $wpdb;
            $id = $profile['creator_id'];
            if (CreatorProfileService::publicById($id, true) === null) { return self::error('active_creator_required', 403); }
            $row = self::row($id);
            if ($row instanceof \WP_Error) { return $row; }
            if (($row === null ? 0 : (int) $row['revision']) !== $revision) { return self::error('editorial_revision_conflict', 409); }
            if ($portrait !== '' && !PublicationImageReference::eligible($portrait, $id, $portraitRevision, true)) {
                return self::error('portrait_not_eligible', 409);
            }
            $count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `' . EditorialSchema::table(true)
                . '` WHERE creator_id=%s AND action=%s AND occurred_at > UTC_TIMESTAMP()-INTERVAL 1 HOUR', $id, 'submit'));
            if ($wpdb->last_error !== '' || !is_numeric($count)) { return self::error('editorial_unavailable'); }
            if ((int) $count >= 20) { return self::error('editorial_rate_limit', 429); }
            $data = ['creator_id' => $id, 'revision' => $revision + 1, 'state' => 'pending',
                'public_name' => trim($name), 'bio' => trim(str_replace("\r\n", "\n", $bio)),
                'portrait_id' => $portrait, 'portrait_revision' => $portraitRevision];
            $ok = $row === null
                ? $wpdb->query($wpdb->prepare('INSERT INTO `' . EditorialSchema::table()
                    . '` (creator_id,revision,state,public_name,bio,portrait_id,portrait_revision,updated_at) VALUES (%s,%d,%s,%s,%s,%s,%d,UTC_TIMESTAMP())', ...array_values($data)))
                : $wpdb->query($wpdb->prepare('UPDATE `' . EditorialSchema::table()
                    . '` SET revision=%d,state=%s,public_name=%s,bio=%s,portrait_id=%s,portrait_revision=%d,updated_at=UTC_TIMESTAMP() WHERE creator_id=%s AND revision=%d',
                    $revision + 1, 'pending', $data['public_name'], $data['bio'], $portrait, $portraitRevision, $id, $revision));
            if ($ok !== 1 || !self::audit($id, $revision + 1, 'submit', 'awaiting_review')) { return self::error('editorial_write_failed'); }
            return $data;
        });
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function decide(string $id, int $revision, string $action, string $reason): array|\WP_Error
    {
        if (!self::validId($id) || $revision < 1 || $revision >= 2147483646
            || !in_array($action, ['approve', 'reject', 'revoke', 'withdraw'], true)
            || ($action === 'approve' && $reason !== 'allowed_editorial')
            || (in_array($action, ['reject', 'revoke'], true) && !in_array($reason, ['needs_revision', 'prohibited_content'], true))) {
            return self::error('invalid_editorial_decision', 400);
        }
        $owner = $action === 'withdraw' ? CreatorProfileService::own() : null;
        if ($action === 'withdraw' ? ($owner === null || $owner['creator_id'] !== $id) : !current_user_can('manage_options')) {
            return self::error('editorial_forbidden', 403);
        }
        return self::transaction(static function () use ($id, $revision, $action, $reason): array|\WP_Error {
            global $wpdb;
            if ($action === 'approve' && CreatorProfileService::publicById($id, true) === null) { return self::error('active_creator_required', 409); }
            $row = self::row($id);
            if ($row instanceof \WP_Error) { return $row; }
            if ($row === null) { return self::error('editorial_not_found', 404); }
            $published = self::row($id, true);
            if ($published instanceof \WP_Error) { return $published; }
            $removal = in_array($action, ['withdraw', 'revoke'], true) && $published !== null;
            if ((int) $row['revision'] !== $revision || (!$removal && !in_array($row['state'], ['pending', 'approved'], true))
                || ($action === 'revoke' && $published === null)
                || ($action === 'approve' && $row['state'] !== 'pending')) { return self::error('editorial_revision_conflict', 409); }
            if ($action === 'approve' && $row['portrait_id'] !== ''
                && !PublicationImageReference::eligible($row['portrait_id'], $id, (int) $row['portrait_revision'], true)) {
                return self::error('portrait_not_eligible', 409);
            }
            $state = match ($action) { 'approve' => 'approved', 'reject', 'revoke' => 'rejected', default => 'withdrawn' };
            $purge = $action !== 'approve' ? ",public_name='',bio='',portrait_id='',portrait_revision=0" : '';
            if ($wpdb->query($wpdb->prepare('UPDATE `' . EditorialSchema::table() . '` SET state=%s,revision=revision+1,updated_at=UTC_TIMESTAMP()'
                . $purge . ' WHERE creator_id=%s AND revision=%d', $state, $id, $revision)) !== 1
                || !self::audit($id, $revision + 1, $action, $action === 'withdraw' ? 'creator_withdrawal' : $reason)) {
                return self::error('editorial_write_failed');
            }
            if ($action === 'approve') {
                if ($wpdb->query($wpdb->prepare('REPLACE INTO `' . EditorialSchema::publishedTable()
                    . '` SELECT * FROM `' . EditorialSchema::table() . '` WHERE creator_id=%s', $id)) === false) { return self::error('editorial_write_failed'); }
            } elseif ($action !== 'reject' || $row['state'] === 'approved') {
                if ($wpdb->query($wpdb->prepare('DELETE FROM `' . EditorialSchema::publishedTable() . '` WHERE creator_id=%s', $id)) === false) { return self::error('editorial_write_failed'); }
            }
            return ['creator_id' => $id, 'revision' => $revision + 1, 'state' => $state];
        });
    }

    /**
     * Approved projection only; absence/errors never expose pending fields.
     * @return array<string,mixed>|null
     */
    public static function publicById(string $id): ?array
    {
        if (!self::validId($id)) { return null; }
        $data = self::transaction(static function () use ($id): array|\WP_Error {
            if (CreatorProfileService::publicById($id, true) === null) { return self::error('editorial_not_found', 404); }
            $row = self::row($id, true);
            if ($row instanceof \WP_Error) { return $row; }
            if ($row === null || $row['state'] !== 'approved') { return self::error('editorial_not_found', 404); }
            return ['public_name' => $row['public_name'], 'bio' => $row['bio'], 'revision' => (int) $row['revision'],
                'portrait' => $row['portrait_id'] !== '' && ImageDisplayDerivative::enabled()
                    && PublicationImageReference::eligible($row['portrait_id'], $id, (int) $row['portrait_revision'], true)];
        });
        return is_array($data) ? $data : null;
    }

    public static function portrait(string $id, int $revision): string|\WP_Error
    {
        if (!self::validId($id) || $revision < 1 || !ImageDisplayDerivative::enabled()) { return self::error('portrait_not_found', 404); }
        return self::transaction(static function () use ($id, $revision): string|\WP_Error {
            if (CreatorProfileService::publicById($id, true) === null) { return self::error('portrait_not_found', 404); }
            $row = self::row($id, true);
            if ($row instanceof \WP_Error) { return $row; }
            if ($row === null || $row['state'] !== 'approved' || (int) $row['revision'] !== $revision || $row['portrait_id'] === '') {
                return self::error('portrait_not_found', 404);
            }
            return ImageDisplayDerivative::forPublication($row['portrait_id'], $id, (int) $row['portrait_revision']);
        });
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function moderation(string $cursor = '', string $state = 'pending'): array|\WP_Error
    {
        if (!current_user_can('manage_options')) { return self::error('editorial_forbidden', 403); }
        if (($cursor !== '' && !self::validId($cursor)) || !in_array($state, ['pending', 'approved', 'rejected', 'withdrawn'], true)) { return self::error('invalid_editorial_cursor', 400); }
        return self::transaction(static function () use ($cursor, $state): array|\WP_Error {
            global $wpdb;
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM `' . EditorialSchema::table()
                . '` WHERE state=%s AND creator_id>%s ORDER BY creator_id LIMIT 21', $state, $cursor), 'ARRAY_A');
            if (!is_array($rows) || $wpdb->last_error !== '') { return self::error('editorial_unavailable'); }
            $more = count($rows) > 20; $rows = array_slice($rows, 0, 20);
            foreach ($rows as &$item) {
                $item['published'] = self::row($item['creator_id'], true);
                if ($item['published'] instanceof \WP_Error) { return $item['published']; }
            }
            unset($item);
            return ['items' => $rows, 'next_cursor' => $more ? $rows[19]['creator_id'] : null];
        });
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function inspect(string $id): array|\WP_Error
    {
        if (!current_user_can('manage_options')) { return self::error('editorial_forbidden', 403); }
        if (!self::validId($id)) { return self::error('editorial_not_found', 404); }
        return self::transaction(static function () use ($id): array|\WP_Error {
            global $wpdb;
            $row = self::row($id);
            if ($row instanceof \WP_Error) { return $row; }
            if ($row === null) { return self::error('editorial_not_found', 404); }
            $journal = $wpdb->get_results($wpdb->prepare('SELECT revision,actor_id,action,reason,occurred_at FROM `'
                . EditorialSchema::table(true) . '` WHERE creator_id=%s ORDER BY revision DESC LIMIT 100', $id), 'ARRAY_A');
            if (!is_array($journal) || $wpdb->last_error !== '') { return self::error('editorial_unavailable'); }
            $published = self::row($id, true);
            return $published instanceof \WP_Error ? $published : $row + ['journal' => $journal, 'published' => $published];
        });
    }

    private static function text(mixed $value, int $max, bool $multiline): bool
    {
        if (!is_string($value) || strlen($value) > $max * 4 || preg_match('//u', $value) !== 1
            || preg_match($multiline ? '/[<>\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u' : '/[<>\x00-\x1f\x7f]/u', $value)) { return false; }
        return preg_match_all('/./us', $value) <= $max;
    }

    /** @return array<string,mixed>|\WP_Error|null */
    private static function row(string $id, bool $published = false): array|\WP_Error|null
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . ($published ? EditorialSchema::publishedTable() : EditorialSchema::table()) . '` WHERE creator_id=%s FOR UPDATE', $id), 'ARRAY_A');
        return $wpdb->last_error !== '' ? self::error('editorial_unavailable') : (is_array($row) ? $row : null);
    }

    private static function audit(string $id, int $revision, string $action, string $reason): bool
    {
        global $wpdb;
        return $wpdb->query($wpdb->prepare('INSERT INTO `' . EditorialSchema::table(true)
            . '` (creator_id,revision,actor_id,action,reason,occurred_at) VALUES (%s,%d,%d,%s,%s,UTC_TIMESTAMP())',
            $id, $revision, get_current_user_id(), $action, $reason)) === 1;
    }

    /**
     * @template T
     * @param callable():T $operation
     * @return T|\WP_Error
     */
    private static function transaction(callable $operation): mixed
    {
        if (!EditorialModule::available()) { return self::error('editorial_unavailable'); }
        global $wpdb;
        $lock = 'fans_editorial_' . substr(hash('sha256', (string) EditorialSchema::table()), 0, 40);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)', $lock)) !== 1) { return self::error('editorial_busy'); }
        $suppress = $wpdb->suppress_errors(true);
        try {
            if ($wpdb->query('START TRANSACTION') === false) { return self::error('editorial_unavailable'); }
            $result = $operation();
            if ($result instanceof \WP_Error) { return $result; }
            return $wpdb->query('COMMIT') === false ? self::error('editorial_write_failed') : $result;
        } finally {
            $wpdb->query('ROLLBACK'); $wpdb->suppress_errors($suppress);
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }
}
