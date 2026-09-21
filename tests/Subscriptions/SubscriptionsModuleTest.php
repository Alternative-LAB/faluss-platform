<?php

declare(strict_types=1);

namespace Faluss\Platform\Subscriptions;

use Faluss\Platform\Core\SiteRole;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class SubscriptionsModuleTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBootsTheHistoricalHubAuthorityBehindAnExplicitOptIn(): void
    {
        subscriptions_test_reset();

        $module = new SubscriptionsModule();
        $module->boot();

        self::assertSame('subscriptions', $module->id());
        self::assertSame([SiteRole::Hub], $module->roles());
        self::assertSame(['admin-dashboard'], $module->dependencies());
        self::assertSame('0.2.7', SubscriptionsModule::VERSION);
        self::assertSame('0.2.7', FALUSS_SUBSCRIPTIONS_VERSION);
        self::assertSame(dirname(__DIR__, 2) . '/', FALUSS_SUBSCRIPTIONS_DIR);
        self::assertTrue(class_exists('Faluss_Subscriptions_Schema', false));
        self::assertTrue(class_exists('Faluss_Subscriptions_Billing', false));
        self::assertTrue(class_exists('Faluss_Subscriptions_Webhooks', false));
        self::assertTrue(class_exists('Faluss_Subscriptions_Admin', false));
        self::assertSame('2', \Faluss_Subscriptions_Schema::VERSION);
        self::assertSame('faluss_subscriptions_schema_version', \Faluss_Subscriptions_Schema::OPTION);
        self::assertSame('21.3.0', \Faluss_Subscriptions_Stripe_Sdk::SDK_VERSION);
        self::assertTrue(\Faluss_Subscriptions_Stripe_Sdk::load());
        self::assertTrue(is_wp_error(\Faluss_Subscriptions_Stripe_Config::secret_key()));
        self::assertTrue(is_wp_error(\Faluss_Subscriptions_Stripe_Config::webhook_secret()));
        self::assertArrayHasKey('admin_post_faluss_subscriptions_admin', $GLOBALS['subscriptions_test_actions']);
        self::assertArrayHasKey('admin_post_faluss_subscriptions_sandbox_checkout', $GLOBALS['subscriptions_test_actions']);
        self::assertArrayHasKey('rest_api_init', $GLOBALS['subscriptions_test_actions']);
        self::assertArrayHasKey('faluss_subscriptions_daily', $GLOBALS['subscriptions_test_actions']);
        self::assertArrayHasKey('init', $GLOBALS['subscriptions_test_actions']);
        self::assertArrayHasKey('template_redirect', $GLOBALS['subscriptions_test_actions']);

        \Faluss_Subscriptions_Admin::assets('toplevel_page_faluss-subscriptions');
        self::assertSame(
            'https://faluss.com/wp-content/plugins/faluss-platform/assets/css/faluss-subscriptions-admin.css',
            $GLOBALS['subscriptions_test_styles']['faluss-subscriptions-admin']['source']
        );

        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Subscriptions/SubscriptionsModule.php');
        self::assertIsString($source);
        self::assertStringContainsString('Faluss_Subscriptions_Schema::maybe_install();', $source);
    }
}
