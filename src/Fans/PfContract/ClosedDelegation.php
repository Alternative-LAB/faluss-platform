<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\PfContract;

use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\Fans\Sso\FansLocalSession;
use Faluss\Platform\Fans\Sso\FansSsoService;
use Faluss\Platform\TokenEngine\PurchasedPf\AttributionIntent;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;

/** Fans resolves both identities server-side. A browser can never delegate arbitrary UUIDs. */
final class ClosedDelegation
{
    /** @param array<string,mixed> $input */
    public static function resolve(\wpdb $database, array $input): AttributionIntent
    {
        ClosedEnvironment::assertIsolated($database, 'fans');
        ModelValues::exactKeys($input, ['attribution_id','creator_profile_id','purchased_pf','policy_version']);
        $member = FansSsoService::currentLinkedSubject();
        $expires = FansLocalSession::expires();
        if ($member === null || $expires === null || $expires <= time()) {
            throw new ModelViolation('pf_linked_session_required');
        }
        $creatorOwner = CreatorProfileService::activeOwner($input['creator_profile_id']);
        $creatorIdentity = $creatorOwner !== null ? FansSsoService::linkedIdentity($creatorOwner) : null;
        if ($creatorIdentity === null) {
            throw new ModelViolation('pf_active_creator_required');
        }
        return AttributionIntent::fromArray(['attribution_id' => $input['attribution_id'],
            'client_authority' => 'fixture.fans', 'member_faluss_id' => $member['faluss_id'],
            'creator_faluss_id' => $creatorIdentity, 'purchased_pf' => $input['purchased_pf'], 'policy_version' => $input['policy_version']]);
    }
}
