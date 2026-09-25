<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Publications;

/** Pure policy. Inputs must come from server owners, never request parameters. */
final class PublicationAccessPolicy
{
    public static function originalAllowed(
        bool $creatorActive,
        string $publicationState,
        string $moderationDecision,
        string $contentCategory,
        string $access,
        bool $hasCurrentFansEntitlement,
    ): bool {
        return $creatorActive
            && $publicationState === 'published'
            && $moderationDecision === 'approved'
            && $contentCategory === 'hosted_allowed_content'
            && ($access === 'free' || ($access === 'locked' && $hasCurrentFansEntitlement));
    }
}
