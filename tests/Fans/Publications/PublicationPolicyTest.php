<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Fans\Publications;

use Faluss\Platform\Fans\Publications\PublicationAccessPolicy;
use Faluss\Platform\Fans\Publications\TeaserProjection;
use PHPUnit\Framework\TestCase;

final class PublicationPolicyTest extends TestCase
{
    private const CREATOR = '11111111-1111-4111-8111-111111111111';

    public function testLockedOriginalRequiresAnActualFansEntitlement(): void
    {
        self::assertFalse(PublicationAccessPolicy::originalAllowed(true, 'published', 'approved', 'hosted_allowed_content', 'locked', false));
        self::assertTrue(PublicationAccessPolicy::originalAllowed(true, 'published', 'approved', 'hosted_allowed_content', 'locked', true));
        self::assertTrue(PublicationAccessPolicy::originalAllowed(true, 'published', 'approved', 'hosted_allowed_content', 'free', false));
        foreach ([
            [false, 'published', 'approved', 'hosted_allowed_content', 'free'],
            [true, 'withdrawn', 'approved', 'hosted_allowed_content', 'free'],
            [true, 'published', 'pending', 'hosted_allowed_content', 'free'],
            [true, 'published', 'rejected', 'hosted_allowed_content', 'free'],
            [true, 'published', 'approved', 'external_adult_delivery_right', 'free'],
            [true, 'published', 'approved', 'unknown', 'free'],
            [true, 'published', 'approved', 'hosted_allowed_content', 'unknown'],
        ] as $args) {
            self::assertFalse(PublicationAccessPolicy::originalAllowed(...[...$args, true]));
        }
    }

    public function testProjectionWhitelistsOnlyExplicitPublicTeasers(): void
    {
        $row = $this->row();
        $row['faluss_id'] = 'private';
        $row['original_url'] = 'private';
        $row['email'] = 'private';
        $result = TeaserProjection::build(self::CREATOR, true, 2, 1800000000, [$row]);
        self::assertNotNull($result);
        self::assertCount(1, $result['teasers']);
        self::assertSame(['teaser_id', 'label', 'preview_url', 'canonical_url'], array_keys($result['teasers'][0]));
        self::assertStringNotContainsString('private', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame(300, strtotime($result['expires_at']) - strtotime($result['generated_at']));
        self::assertSame([], TeaserProjection::build(self::CREATOR, true, 3, 1800000000, [])['teasers']);
    }

    public function testRevocationModerationAndOwnershipFailClosed(): void
    {
        foreach ([
            'creator_selected' => false,
            'preview_approved' => false,
            'creator_id' => '22222222-2222-4222-8222-222222222222',
            'category' => 'external_adult_delivery_right',
            'state' => 'withdrawn',
            'moderation' => 'pending',
            'public_preview_id' => $this->row()['publication_id'],
            'label' => '<script>test</script>',
        ] as $field => $value) {
            self::assertNull(TeaserProjection::build(self::CREATOR, true, 1, 1800000000, [array_replace($this->row(), [$field => $value])]));
        }
        self::assertNull(TeaserProjection::build(self::CREATOR, false, 1, 1800000000, [$this->row()]));
        self::assertNull(TeaserProjection::build(self::CREATOR, true, 1, 1800000000, [$this->row(), $this->row()]));
        self::assertNull(TeaserProjection::build(self::CREATOR, true, 1, 1800000000, ['unexpected' => $this->row()]));
        self::assertNull(TeaserProjection::build(self::CREATOR, true, 1, 1800000000, ['not an object']));
    }

    public function testFiveExplicitSelectionsAreAllowedButSixAreRejected(): void
    {
        $rows = [];
        for ($i = 1; $i <= 6; ++$i) {
            $rows[] = array_replace($this->row(), ['publication_id' => sprintf('22222222-2222-4222-8222-%012d', $i)]);
        }
        self::assertCount(5, TeaserProjection::build(self::CREATOR, true, 1, 1800000000, array_slice($rows, 0, 5))['teasers']);
        self::assertNull(TeaserProjection::build(self::CREATOR, true, 1, 1800000000, $rows));
    }

    /** @return array<string,mixed> */
    private function row(): array
    {
        return [
            'creator_id' => self::CREATOR,
            'publication_id' => '22222222-2222-4222-8222-222222222222',
            'public_preview_id' => '33333333-3333-4333-8333-333333333333',
            'creator_selected' => true,
            'preview_approved' => true,
            'state' => 'published',
            'moderation' => 'approved',
            'category' => 'hosted_allowed_content',
            'label' => 'Un aperçu autorisé',
        ];
    }
}
