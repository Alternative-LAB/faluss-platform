<?php

declare(strict_types=1);

namespace Faluss\Platform\Portal;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class PortalAdaptersIntegrationTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAppsRegistryIsReadExactlyOnceWithTheClosedConsumerTuple(): void
    {
        eval('namespace { final class Faluss_Apps_Registry { public static int $calls = 0; public static array $args = []; public static function read_for_member(...$args): array { ++self::$calls; self::$args = $args; return ["applications" => []]; } } }');
        $falussId = '11111111-1111-4111-8111-111111111111';

        self::assertSame(['applications' => []], PortalAppsRegistryAdapter::readForMember($falussId));
        self::assertSame(1, \Faluss_Apps_Registry::$calls);
        self::assertSame([$falussId, 'portal', '1.0.0'], \Faluss_Apps_Registry::$args);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTokenEngineOwnsTheRewardAmountProjectedByPortal(): void
    {
        define('TOKEN_ENGINE_VERSION', '0.4.1');
        eval('namespace { final class Token_Engine_Schema { public const VERSION = 5; } final class Token_Engine_Points_Service { public static function daily_status(): array { return ["state"=>"claimable","owner"=>"faluss-hub","reward_key"=>"hub.daily_accrual","amount_pf"=>37,"economic_class"=>"earned","category"=>"daily_accrual","logical_date"=>"2026-09-21"]; } public static function claim_hub_daily(): array { return ["state"=>"claimed","owner"=>"faluss-hub","reward_key"=>"hub.daily_accrual","amount_pf"=>37,"economic_class"=>"earned","category"=>"daily_accrual","logical_date"=>"2026-09-21"]; } } }');
        $falussId = '11111111-1111-4111-8111-111111111111';

        $status = PortalTokenEngineAdapter::dailyStatus($falussId);
        $claim = PortalTokenEngineAdapter::claim($falussId);

        self::assertSame(37, $status['reward']['amount_pf']);
        self::assertSame('37 PF', $status['reward']['label']);
        self::assertSame('claimable', $status['status']);
        self::assertSame('claimed', $claim['status']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSubscriptionsStayBehindTheAdapter(): void
    {
        eval('namespace { final class Faluss_Subscriptions_Resolver { public static function resolve_for_faluss_id(): array { return ["level"=>"pro","state"=>"active","expires_at"=>"2026-12-31 23:59:59","effective_sources"=>[["source"=>"subscription","reference"=>"sub-1"]]]; } } final class Faluss_Subscriptions_Catalog { public static function plans(): array { return ["pro"=>["public_name"=>"Faluss Pro","monthly_price"=>999]]; } } final class Faluss_Subscriptions_Repository { public static function subscriptions_for_faluss_id(): array { return [["subscription_uuid"=>"sub-1","billing_interval"=>"monthly"]]; } public static function customer_for_faluss_id(): array { return ["customer_id"=>"cus_test"]; } } final class Faluss_Subscriptions_Stripe_Config { public static function portal_configuration_id(): string { return "bpc_test"; } } final class Faluss_Subscriptions_Billing { public static function create_portal(): array { return ["url"=>"https://billing.stripe.com/p/session/test"]; } } }');

        $snapshot = PortalSubscriptionsAdapter::snapshot('11111111-1111-4111-8111-111111111111');

        self::assertSame('Faluss Pro', $snapshot['offer_name']);
        self::assertSame('active', $snapshot['state']);
        self::assertSame('monthly', $snapshot['billing_interval']);
        self::assertTrue($snapshot['portal_available']);
        self::assertArrayNotHasKey('monthly_price', $snapshot);
        self::assertSame(
            ['url' => 'https://billing.stripe.com/p/session/test'],
            PortalSubscriptionsAdapter::createCustomerPortal('11111111-1111-4111-8111-111111111111')
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnalyticsRouteRequiresTheExactReadyState(): void
    {
        eval('namespace { final class Faluss_Analytics { public static function register_runtime(): bool { return true; } public static function runtime_state(): array { return ["local_hub"=>true,"schema_ready"=>true,"validators_registered"=>true,"consumer_registered"=>true,"conflict"=>false]; } } }');

        self::assertTrue(PortalAnalyticsAdapter::registerRuntime());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testHistoricalManifestCatalogAndClosedRouteRemainExact(): void
    {
        (new PortalModule())->boot();

        $manifest = \Faluss_Portal_Manifest::manifest();
        self::assertSame('1.0.0', $manifest['manifest_version']);
        self::assertSame('faluss-hub', $manifest['app_key']);
        self::assertSame(
            ['faluss-hub.daily-reward', 'faluss-hub.events'],
            array_column($manifest['capabilities'], 'capability_key')
        );

        $catalog = \Faluss_Portal_Events_Catalog::catalog();
        self::assertSame('faluss.event-source-catalog', $catalog['document_type']);
        self::assertSame('faluss-hub.events', $catalog['capability_key']);
        self::assertSame(
            ['faluss-hub.portal.viewed', 'faluss-hub.app.opened', 'faluss-hub.daily-reward.claimed'],
            array_column($catalog['event_types'], 'event_type')
        );
        self::assertSame(
            [
                'source_node_id' => 'hub-node',
                'source_app_key' => 'faluss-hub',
                'source_capability_key' => 'faluss-hub.events',
                'catalog_version' => '1.0.0',
                'destination' => 'analytics.events',
                'mode' => 'local',
                'target_node_id' => 'hub-node',
                'target_app_key' => 'faluss-hub',
            ],
            \Faluss_Portal_Events_Runtime::route_descriptor()
        );
        self::assertSame(['route_registered' => false, 'conflict' => false], \Faluss_Portal_Events_Runtime::runtime_state());

        self::assertInstanceOf(\WP_Error::class, \Faluss_Portal_Apps_Registry_Adapter::relationship('invalid'));
        self::assertSame(
            'active',
            \Faluss_Portal_Apps_Registry_Adapter::relationship('11111111-1111-4111-8111-111111111111')
        );
    }

    public function testPortalServiceHasNoDirectDataOrEconomicDependency(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Portal/LegacyPortalService.php');
        self::assertIsString($source);
        foreach ([
            'Faluss_Identity_Client_Schema::',
            'Faluss_Subscriptions_Resolver::',
            'Faluss_Subscriptions_Catalog::',
            'Faluss_Subscriptions_Repository::',
            'Faluss_Subscriptions_Billing::',
            'Token_Engine_Points_Service::',
            'Faluss_Analytics::',
            'Faluss_Apps_Registry::read_for_member',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
        self::assertStringContainsString('PortalIdentityAdapter::currentLinkedSubject', $source);
        self::assertStringContainsString('PortalAppsRegistryAdapter::readForMember', $source);
        self::assertStringContainsString('PortalSubscriptionsAdapter::snapshot', $source);
        self::assertStringContainsString('PortalTokenEngineAdapter::dailyStatus', $source);
    }
}
