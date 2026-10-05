<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\PurchaseEvidence;

final class ModelFixtures
{
    public const MEMBER = '11111111-1111-4111-8111-111111111111';
    public const CREATOR = '22222222-2222-4222-8222-222222222222';

    /** @return array<string,mixed> */
    public static function evidence(): array
    {
        return ['contract' => PurchaseEvidence::CONTRACT, 'authority_id' => 'fixture.purchase',
            'purchase_id' => 'synthetic.pack.1', 'source_revision' => '1',
            'evidence_id' => '33333333-3333-4333-8333-333333333333', 'member_faluss_id' => self::MEMBER,
            'purchased_pf' => '100', 'bonus_pf' => '50', 'cancelled_purchased_pf_cumulative' => '0',
            'state' => 'confirmed', 'confirmed_at' => '2026-10-01T10:00:00Z',
            'observed_at' => '2026-10-05T10:00:00Z', 'policy_version' => '1.0.0'];
    }

    /** @return array<string,mixed> */
    public static function intent(): array
    {
        return ['attribution_id' => '44444444-4444-4444-8444-444444444444',
            'client_authority' => 'fixture.fans', 'member_faluss_id' => self::MEMBER,
            'creator_faluss_id' => self::CREATOR, 'purchased_pf' => '40', 'policy_version' => '1.0.0'];
    }
}
