<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Publications;

/** Builds a bounded public projection from a future trusted Fans repository. */
final class TeaserProjection
{
    public const CONTRACT = 'fans.creator-teasers';
    public const VERSION = '1.0.0';

    /**
     * No transport, authority authentication or persistence is provided here.
     * public_preview_id references a separately approved public derivative;
     * it must never be an original file ID or an entitlement-bearing URL.
     *
     * @param array<mixed> $selected Untrusted shape of a server repository snapshot.
     * @return array<string,mixed>|null
     */
    public static function build(
        string $creatorId,
        bool $creatorActive,
        int $revision,
        int $now,
        array $selected,
    ): ?array {
        if (!self::uuid($creatorId) || !$creatorActive || $revision < 1 || $now < 1
            || !array_is_list($selected) || count($selected) > 5
        ) {
            return null;
        }
        $items = [];
        $seen = [];
        foreach ($selected as $row) {
            if (!is_array($row)
                || ($row['creator_id'] ?? null) !== $creatorId
                || ($row['creator_selected'] ?? null) !== true
                || ($row['preview_approved'] ?? null) !== true
                || !PublicationAccessPolicy::originalAllowed(
                    $creatorActive,
                    is_string($row['state'] ?? null) ? $row['state'] : '',
                    is_string($row['moderation'] ?? null) ? $row['moderation'] : '',
                    is_string($row['category'] ?? null) ? $row['category'] : '',
                    'free', // Applies to the approved teaser, never its locked original.
                    false,
                )
                || !is_string($row['publication_id'] ?? null) || !self::uuid($row['publication_id'])
                || !is_string($row['public_preview_id'] ?? null) || !self::uuid($row['public_preview_id'])
                || $row['public_preview_id'] === $row['publication_id']
                || !is_string($row['label'] ?? null) || $row['label'] === ''
                || strlen($row['label']) > 160 || preg_match('/[<>\x00-\x1f\x7f]/', $row['label']) !== 0
                || isset($seen[$row['publication_id']])
            ) {
                return null;
            }
            $seen[$row['publication_id']] = true;
            $items[] = [
                'teaser_id' => $row['publication_id'],
                'label' => $row['label'],
                // Reserved future endpoints, not registered by this foundation.
                'preview_url' => 'https://fans.faluss.me/teasers/' . $row['public_preview_id'],
                'canonical_url' => 'https://fans.faluss.me/publications/' . $row['publication_id'],
            ];
        }

        return [
            'contract' => self::CONTRACT,
            'version' => self::VERSION,
            'creator_id' => $creatorId,
            'revision' => $revision,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $now + 300),
            'teasers' => $items,
        ];
    }

    private static function uuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }
}
