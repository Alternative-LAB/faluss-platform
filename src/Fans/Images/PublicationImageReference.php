<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Images;

use Faluss\Platform\Fans\Profiles\CreatorProfileService;

/** Public server contract: eligibility only, never bytes, paths or image metadata. */
final class PublicationImageReference
{
    /** A locking call belongs to the caller's InnoDB transaction; no nested transaction. */
    public static function eligible(string $id, string $creatorId, int $revision, bool $lock = false): bool
    {
        if (!ImagesModule::available() || !ImageStorage::validId($id) || $revision < 1
            || CreatorProfileService::publicById($creatorId) === null) { return false; }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT creator_id,state,revision FROM `' . ImageSchema::table()
            . '` WHERE image_id=%s' . ($lock ? ' FOR UPDATE' : ''), $id), 'ARRAY_A');
        return is_array($row) && $wpdb->last_error === '' && $row['creator_id'] === $creatorId
            && $row['state'] === 'approved' && (int) $row['revision'] === $revision;
    }
}
