<?php

declare(strict_types=1);

namespace Faluss\Platform\Subscriptions;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class SubscriptionsContractTest extends TestCase
{
    public function testUnavailableAuthorityFailsClosed(): void
    {
        self::assertSame('unavailable', SubscriptionsContract::snapshot('11111111-1111-4111-8111-111111111111')['state']);
        self::assertSame([], SubscriptionsContract::plans());
        self::assertNull(SubscriptionsContract::createCustomerPortal('invalid'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testProjectsOnlyTheNarrowMemberDecisionAndDelegatesThePortal(): void
    {
        eval('namespace { final class Faluss_Subscriptions_Resolver { public static function resolve_for_faluss_id(): array { return ["level"=>"pro","state"=>"active","expires_at"=>"2026-12-31 23:59:59","effective_sources"=>[["source"=>"subscription","reference"=>"sub-1"]]]; } } final class Faluss_Subscriptions_Catalog { public static function plans(): array { return ["free"=>["public_name"=>"Faluss Gratuit"],"pro"=>["public_name"=>"Faluss Max","monthly_price"=>999]]; } } final class Faluss_Subscriptions_Repository { public static function subscriptions_for_faluss_id(): array { return [["subscription_uuid"=>"sub-1","billing_interval"=>"monthly"]]; } public static function customer_for_faluss_id(): array { return ["provider_customer_reference"=>"cus_test"]; } } final class Faluss_Subscriptions_Stripe_Config { public static function portal_configuration_id(): string { return "bpc_test"; } } final class Faluss_Subscriptions_Billing { public static function create_portal(): array { return ["url"=>"https://billing.stripe.com/p/session/test"]; } } }');

        $falussId = '11111111-1111-4111-8111-111111111111';
        $snapshot = SubscriptionsContract::snapshot($falussId);

        self::assertSame([
            'available' => true,
            'offer_name' => 'Faluss Max',
            'level' => 'pro',
            'state' => 'active',
            'expires_at' => '2026-12-31 23:59:59',
            'billing_interval' => 'monthly',
            'portal_available' => true,
        ], $snapshot);
        self::assertArrayNotHasKey('monthly_price', $snapshot);
        self::assertSame(999, SubscriptionsContract::plans()['pro']['monthly_price']);
        self::assertSame(
            ['url' => 'https://billing.stripe.com/p/session/test'],
            SubscriptionsContract::createCustomerPortal($falussId)
        );
    }
}
