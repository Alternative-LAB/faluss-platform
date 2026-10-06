<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedIntent;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingContext;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SignedEnvelope;

final class RankedFixtures
{
    public const ORIGIN = '77777777-7777-4777-8777-777777777777';
    public const EPOCH = '88888888-8888-4888-8888-888888888888';
    public const SESSION = '99999999-9999-4999-8999-999999999999';

    /** @return array<string,mixed> */
    public static function session(): array
    {
        return ['session_id' => self::SESSION,'rules_revision' => '2','rules_sha256' => str_repeat('e',64),
            'admission_revision' => '3','barrier_version' => '1','starts_at' => '2026-10-01 00:00:00.000000',
            'ends_at' => '2026-11-01 00:00:00.000000','admitted_at' => '2026-10-02 10:00:00.000001',
            'scope' => 'international','territory_policy_revision' => '', 'territory_admission_revision' => '0','country' => '', 'territory_ref' => ''];
    }

    /** @return array<string,mixed> */
    public static function context(): array
    { return ['origin_id' => self::ORIGIN,'policy_version' => '1.0.0','creator_category' => 'arts',
        'category_revision' => '2','country_policy_revision' => self::ORIGIN,'sessions' => [self::session()]]; }

    /** @return array<string,mixed> */
    public static function intent(): array
    { $context = self::context(); return ModelFixtures::intent() + ['ranking_context' => $context,'context_sha256' => RankingContext::fingerprint($context)]; }

    /** @return array<string,mixed> */
    public static function receipt(): array
    {
        $id = '66666666-6666-4666-8666-666666666666'; $intent = self::intent();
        return array_intersect_key($intent,array_flip(['attribution_id','member_faluss_id','creator_faluss_id','purchased_pf','policy_version','ranking_context','context_sha256']))
            + ['contract' => RankedIntent::CONTRACT,'kind' => SignedEnvelope::RANKING_RECEIPT,
                'issuer' => 'fixture.hub','audience' => 'fixture.fans','receipt_id' => $id,'revision' => '1',
                'reservation_id' => $id,'ledger_entry_uuid' => $id,'confirmed_at' => '2026-10-05 10:00:00.123456',
                'ordering_epoch' => self::EPOCH,'consumption_order' => '9007199254740991',
                'allocations' => [['allocation_id' => hash('sha256',$intent['attribution_id'] . '|' . $id),
                    'lot_id' => $id,'evidence_id' => $id,'source_revision' => '1','purchase_authority' => 'fixture.purchase',
                    'purchase_reference' => 'synthetic.pack.1','purchased_pf' => '40']]];
    }
}
