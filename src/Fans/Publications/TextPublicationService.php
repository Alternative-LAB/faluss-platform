<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Publications;

use Faluss\Platform\Fans\Images\PublicationImageReference;
use Faluss\Platform\Fans\Profiles\CreatorProfileService;

final class TextPublicationService
{
    public const CATEGORY = 'hosted_allowed_content';

    public static function validId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) === 1;
    }

    private static function validText(mixed $text): bool
    {
        return is_string($text) && strlen($text) <= 32000 && preg_match('//u', $text) === 1
            && preg_match_all('/./us', $text) <= 8000 && trim($text) !== ''
            && preg_match('/[<>\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $text) === 0;
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function create(mixed $text, mixed $category, mixed $key = null): array|\WP_Error
    {
        if (!TextPublicationsModule::available()) { return self::error('publications_unavailable', 503); }
        $profile = CreatorProfileService::own();
        if ($profile === null) { return self::error('active_creator_required', 403); }
        if ($category !== self::CATEGORY || !self::validText($text)) { return self::error('invalid_text_publication', 400); }
        if (!self::validId($key)) { return self::error('invalid_idempotency_key', 400); }
        global $wpdb;
        // WordPress otherwise logs failing SQL verbatim, including private text.
        $lock = TextPublicationIntake::lock($profile['creator_id']);
        if ($lock === null) { return self::error('publication_intake_unavailable', 503); }
        $previousSuppress = $wpdb->suppress_errors(true);
        try {
            if ($wpdb->query('START TRANSACTION') === false) { return self::error('publication_write_failed', 503); }
            $requestHash = hash('sha256', $category . "\0" . $text);
            $existing = $wpdb->get_row($wpdb->prepare('SELECT request_hash,publication_id FROM `' . TextPublicationSchema::table('requests')
                . '` WHERE creator_id=%s AND key_hash=%s', $profile['creator_id'], hash('sha256', $key)), 'ARRAY_A');
            if ($wpdb->last_error !== '') { return self::error('publication_write_failed', 503); }
            if (is_array($existing)) {
                if (!hash_equals((string) $existing['request_hash'], $requestHash)) { return self::error('idempotency_conflict', 409); }
                $replay = self::read((string) $existing['publication_id']);
                return $replay !== null && $replay['creator_id'] === $profile['creator_id'] ? $replay : self::error('publication_write_failed', 503);
            }
            if ($profile['status'] !== 'active') { return self::error('active_creator_required', 403); }
            $quota = TextPublicationIntake::check($profile['creator_id']);
            if ($quota instanceof \WP_Error) { return $quota; }
            $id = wp_generate_uuid4();
            if (!self::validId($id)) { return self::error('publication_id_unavailable', 503); }
            $now = gmdate('Y-m-d H:i:s');
            $row = ['publication_id' => $id, 'creator_id' => $profile['creator_id'], 'revision' => 1,
                'body' => $text, 'state' => 'pending', 'category' => self::CATEGORY, 'created_at' => $now, 'updated_at' => $now];
            $ok = $wpdb->query($wpdb->prepare('INSERT INTO `' . TextPublicationSchema::table() . '`'
                . ' (publication_id,creator_id,revision,body,state,category,created_at,updated_at) VALUES (%s,%s,%d,%s,%s,%s,%s,%s)',
                $id, $profile['creator_id'], 1, $text, 'pending', self::CATEGORY, $now, $now));
            if ($ok !== 1 || !self::audit($row, 'create', 'awaiting_review', $text)
                || $wpdb->query($wpdb->prepare('INSERT INTO `' . TextPublicationSchema::table('requests')
                    . '` (creator_id,key_hash,request_hash,publication_id) VALUES (%s,%s,%s,%s)',
                    $profile['creator_id'], hash('sha256', $key), $requestHash, $id)) !== 1
                || $wpdb->query('COMMIT') === false) {
                return self::error('publication_write_failed', 503);
            }
            return $row;
        } finally {
            $wpdb->query('ROLLBACK');
            $wpdb->suppress_errors($previousSuppress);
            TextPublicationIntake::release($lock);
        }
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function change(mixed $id, mixed $revision, string $action, mixed $text = null, mixed $reason = null, mixed $imageId = null, mixed $imageRevision = null): array|\WP_Error
    {
        if (!TextPublicationsModule::available()) { return self::error('publications_unavailable', 503); }
        if (!self::validId($id) || !is_int($revision) || $revision < 1 || $revision >= 2147483647
            || !in_array($action, ['edit', 'image', 'withdraw', 'approve', 'reject'], true)
            || ($action === 'image' && !(($imageId === null && $imageRevision === null)
                || (self::validId($imageId) && is_int($imageRevision) && $imageRevision > 0 && $imageRevision < 2147483647)))
            || ($action === 'edit' && !self::validText($text))
            || ($action === 'approve' && $reason !== 'allowed_text')
            || ($action === 'reject' && !in_array($reason, ['prohibited_content', 'needs_revision'], true))
        ) { return self::error('invalid_publication_change', 400); }
        $moderation = in_array($action, ['approve', 'reject'], true);
        $profile = $moderation ? null : CreatorProfileService::own();
        if (($moderation && !current_user_can('manage_options')) || (!$moderation && $profile === null)) {
            return self::error('publication_forbidden', 403);
        }
        global $wpdb;
        $admission = in_array($action, ['edit', 'image'], true);
        $lock = $admission ? TextPublicationIntake::lock($profile['creator_id']) : null;
        if ($admission && $lock === null) { return self::error('publication_intake_unavailable', 503); }
        $previousSuppress = $wpdb->suppress_errors(true);
        try {
            if ($wpdb->query('START TRANSACTION') === false) { return self::error('publication_write_failed', 503); }
            $row = self::read($id, true);
            if ($row === null) { return self::error('publication_not_found', 404); }
            if (!$moderation && $row['creator_id'] !== $profile['creator_id']) {
                return self::error('publication_forbidden', 403);
            }
            if ((int) $row['revision'] !== $revision) { return self::error('publication_revision_conflict', 409); }
            if (!in_array($row['state'], ['pending', 'approved', 'rejected'], true)
                || ($action === 'approve' && $row['state'] !== 'pending')
                || ($action === 'reject' && !in_array($row['state'], ['pending', 'approved'], true))) {
                return self::error('publication_state_conflict', 409);
            }
            // Withdrawal remains possible after profile suspension. Every exposure checks the profile again.
            if (in_array($action, ['edit', 'image', 'approve'], true) && CreatorProfileService::publicById($row['creator_id']) === null) {
                return self::error('active_creator_required', 403);
            }
            if ($admission) {
                // Use the locked persisted state, never a client-provided pending count or state.
                $quota = TextPublicationIntake::check($profile['creator_id'], $row['state'] !== 'pending');
                if ($quota instanceof \WP_Error) { return $quota; }
            }
            if ($action === 'image') {
                if (!self::validText($row['body'])) { return self::error('invalid_text_publication', 400); }
                if ($imageId !== null && !PublicationImageReference::eligible($imageId, $row['creator_id'], $imageRevision, true)) {
                    return self::error('publication_image_unavailable', 409);
                }
                if ($wpdb->query($wpdb->prepare('INSERT INTO `' . TextPublicationSchema::table('images')
                    . '` (publication_id,revision,image_id,image_revision) VALUES (%s,%d,%s,%d)',
                    $id, $revision + 1, $imageId ?? '', $imageRevision ?? 0)) !== 1) { return self::error('publication_write_failed', 503); }
            }
            $oldText = $row['body'];
            $row['body'] = $action === 'edit' ? $text : (in_array($action, ['withdraw', 'reject'], true) ? '' : $oldText);
            $row['state'] = match ($action) { 'edit', 'image' => 'pending', 'approve' => 'approved', 'reject' => 'rejected', default => 'withdrawn' };
            $row['revision'] = $revision + 1;
            $row['updated_at'] = gmdate('Y-m-d H:i:s');
            if ($wpdb->query($wpdb->prepare('UPDATE `' . TextPublicationSchema::table() . '` SET body=%s,state=%s,revision=%d,updated_at=%s'
                . ' WHERE publication_id=%s AND revision=%d', $row['body'], $row['state'], $row['revision'], $row['updated_at'], $id, $revision)) !== 1
                || !self::audit($row, $action === 'image' ? 'edit' : $action, $action === 'image' ? 'image_association' : ($moderation ? $reason : ($action === 'edit' ? 'awaiting_review' : 'creator_withdrawal')), $action === 'edit' ? $text : $oldText)
                || $wpdb->query('COMMIT') === false
            ) { return self::error('publication_write_failed', 503); }
            return $row;
        } finally {
            $wpdb->query('ROLLBACK');
            $wpdb->suppress_errors($previousSuppress);
            if ($lock !== null) { TextPublicationIntake::release($lock); }
        }
    }

    /** @return array<string,mixed>|null */
    private static function read(string $id, bool $lock = false): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . TextPublicationSchema::table() . '` WHERE publication_id=%s' . ($lock ? ' FOR UPDATE' : ''), $id), 'ARRAY_A');
        return is_array($row) && self::validId($row['creator_id'] ?? null)
            && self::validId($row['publication_id'] ?? null) && ($row['category'] ?? null) === self::CATEGORY
            && is_string($row['body'] ?? null) && isset($row['revision'], $row['state'], $row['created_at'], $row['updated_at']) ? $row : null;
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function get(mixed $id, bool $private = false): array|\WP_Error
    {
        if (!TextPublicationsModule::available()) { return self::error('publications_unavailable', 503); }
        if (!self::validId($id) || ($row = self::read($id)) === null) { return self::error('publication_not_found', 404); }
        if ($private) {
            $profile = CreatorProfileService::own();
            if (!current_user_can('manage_options') && ($profile === null || $profile['creator_id'] !== $row['creator_id'])) {
                return self::error('publication_forbidden', 403);
            }
            $row['image_id'] = self::imageReference($row);
            return $row;
        }
        if (!PublicationAccessPolicy::originalAllowed(CreatorProfileService::publicById($row['creator_id']) !== null,
            $row['state'] === 'approved' ? 'published' : $row['state'],
            $row['state'] === 'approved' ? 'approved' : 'pending', $row['category'], 'free', false)
            || !self::validText($row['body'])
        ) { return self::error('publication_not_found', 404); }
        return array_intersect_key($row, array_flip(['publication_id', 'creator_id', 'revision', 'body', 'updated_at']));
    }

    /** Private projection only; revalidate every reference through the image owner contract.
     * @param array<string,mixed> $row
     */
    private static function imageReference(array $row): ?string
    {
        if (!in_array($row['state'], ['pending', 'approved'], true)) { return null; }
        global $wpdb;
        $reference = $wpdb->get_row($wpdb->prepare('SELECT image_id,image_revision FROM `' . TextPublicationSchema::table('images')
            . '` WHERE publication_id=%s AND revision<=%d ORDER BY revision DESC LIMIT 1', $row['publication_id'], $row['revision']), 'ARRAY_A');
        if (!is_array($reference) || $wpdb->last_error !== '' || !self::validId($reference['image_id'] ?? null)) { return null; }
        return PublicationImageReference::eligible($reference['image_id'], $row['creator_id'], (int) $reference['image_revision'])
            ? $reference['image_id'] : null;
    }

    /** @return array{items:list<array<string,mixed>>,next_cursor:?string}|\WP_Error */
    public static function listing(string $scope, mixed $perPage = 20, mixed $cursor = null): array|\WP_Error
    {
        if (!TextPublicationsModule::available()) { return self::error('publications_unavailable', 503); }
        if (!in_array($scope, ['public', 'own', 'queue'], true)
            || (!is_int($perPage) && !(is_string($perPage) && preg_match('/^[1-9][0-9]?$/D', $perPage) === 1))
            || (int) $perPage < 1 || (int) $perPage > 20
        ) { return self::error('invalid_publication_page', 400); }
        $position = self::pagePosition($cursor, $scope);
        if ($position === false) { return self::error('invalid_publication_page', 400); }
        global $wpdb;
        $table = TextPublicationSchema::table();
        if ($scope === 'queue') {
            if (!current_user_can('manage_options')) { return self::error('publication_forbidden', 403); }
            $filter = 'state=%s'; $args = ['pending'];
        } elseif ($scope === 'own') {
            $profile = CreatorProfileService::own();
            if ($profile === null) { return self::error('publication_forbidden', 403); }
            $filter = 'creator_id=%s'; $args = [$profile['creator_id']];
        } else {
            $filter = 'state=%s'; $args = ['approved'];
        }
        $direction = $scope === 'queue' ? 'ASC' : 'DESC';
        $operator = $scope === 'queue' ? '>' : '<';
        $result = []; $lastVisible = null;
        // Filter through the profile owner's public contract, not its private tables.
        // Bounded batches and a visible lookahead fill pages even across suspended creators.
        while (true) {
            $where = $filter; $parameters = $args;
            if ($position !== null) {
                $where .= ' AND (updated_at ' . $operator . ' %s OR (updated_at=%s AND publication_id ' . $operator . ' %s))';
                array_push($parameters, $position['updated_at'], $position['updated_at'], $position['publication_id']);
            }
            $rows = $wpdb->get_results($wpdb->prepare('SELECT publication_id,updated_at FROM `' . $table . '` WHERE '
                . $where . ' ORDER BY updated_at ' . $direction . ',publication_id ' . $direction . ' LIMIT 50', ...$parameters), 'ARRAY_A');
            if (!is_array($rows)) { return self::error('publications_unavailable', 503); }
            foreach ($rows as $item) {
                if (!self::validId($item['publication_id'] ?? null) || !is_string($item['updated_at'] ?? null)) {
                    return self::error('publications_unavailable', 503);
                }
                $position = ['updated_at' => $item['updated_at'], 'publication_id' => $item['publication_id']];
                $value = self::get($item['publication_id'], $scope !== 'public');
                if ($value instanceof \WP_Error) {
                    if (($value->get_error_data()['status'] ?? null) === 503) { return $value; }
                    continue;
                }
                // A row can leave the queue between the candidate query and its fresh read.
                if ($scope === 'queue' && $value['state'] !== 'pending') { continue; }
                if (count($result) === (int) $perPage) {
                    return ['items' => $result, 'next_cursor' => $lastVisible];
                }
                $result[] = $value;
                $lastVisible = 'v1.' . $scope . '.' . str_replace(' ', 'T', $position['updated_at']) . '.' . $position['publication_id'];
            }
            if (count($rows) < 50) { return ['items' => $result, 'next_cursor' => null]; }
        }
    }

    /** @return array{updated_at:string,publication_id:string}|null|false */
    private static function pagePosition(mixed $cursor, string $scope): array|null|false
    {
        if ($cursor === null) { return null; }
        if (!is_string($cursor) || strlen($cursor) > 90
            || preg_match('/^v1\.(public|own|queue)\.([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2})\.([0-9a-f-]{36})$/D', $cursor, $matches) !== 1
            || $matches[1] !== $scope || !self::validId($matches[3])
        ) { return false; }
        $date = str_replace('T', ' ', $matches[2]);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date, new \DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $date) { return false; }
        return ['updated_at' => $date, 'publication_id' => $matches[3]];
    }

    /** @return list<array<string,mixed>>|\WP_Error */
    public static function decisions(mixed $id): array|\WP_Error
    {
        if (!TextPublicationsModule::available()) { return self::error('publications_unavailable', 503); }
        if (!current_user_can('manage_options') || !self::validId($id)) { return self::error('publication_forbidden', 403); }
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM `' . TextPublicationSchema::table(true) . '` WHERE publication_id=%s ORDER BY revision DESC LIMIT 100', $id), 'ARRAY_A');
        return is_array($rows) ? array_values($rows) : self::error('publications_unavailable', 503);
    }

    /** @param array<string,mixed> $row */
    private static function audit(array $row, string $action, string $reason, string $text): bool
    {
        global $wpdb;
        return $wpdb->query($wpdb->prepare('INSERT INTO `' . TextPublicationSchema::table(true) . '`'
            . ' (publication_id,revision,actor_id,action,reason,text_hash,occurred_at) VALUES (%s,%d,%d,%s,%s,%s,UTC_TIMESTAMP())',
            $row['publication_id'], $row['revision'], get_current_user_id(), $action, $reason, hash('sha256', $text))) === 1;
    }

    private static function error(string $code, int $status): \WP_Error
    {
        return new \WP_Error($code, 'Publication indisponible.', ['status' => $status]);
    }
}
