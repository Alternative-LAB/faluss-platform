<?php

declare(strict_types=1);

namespace Faluss\Platform\Federation;

use Faluss\Platform\Core\SiteRole;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class FederationModuleTest extends TestCase
{
    public function testBootstrapRequiresAnExplicitOptInAndNoLoadedRuntime(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/faluss-platform.php');

        self::assertIsString($source);
        self::assertStringContainsString("defined('FALUSS_PLATFORM_FEDERATION')", $source);
        self::assertStringContainsString("constant('FALUSS_PLATFORM_FEDERATION') === true", $source);
        self::assertStringContainsString("!class_exists('Faluss_Federation_Crypto', false)", $source);
        self::assertStringContainsString(
            "[\\Faluss\\Platform\\Federation\\FederationModule::class, 'activate']",
            $source
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBootsTheHistoricalTransportForBothSiteRoles(): void
    {
        federation_test_reset();

        $module = new FederationModule();
        $module->boot();

        self::assertSame('federation', $module->id());
        self::assertSame([SiteRole::Me, SiteRole::Hub], $module->roles());
        self::assertSame(['admin-dashboard'], $module->dependencies());
        self::assertSame('0.3.0', FALUSS_FEDERATION_VERSION);
        self::assertSame('1', FALUSS_FEDERATION_SCHEMA_VERSION);
        self::assertTrue(class_exists('Faluss_Federation', false));
        self::assertTrue(class_exists('Faluss_Federation_Crypto', false));
        self::assertTrue(class_exists('Faluss_Federation_Policy', false));
        self::assertTrue(class_exists('Faluss_Federation_Providers', false));
        self::assertTrue(class_exists('Faluss_Federation_Schema', false));
        self::assertTrue(class_exists('Faluss_Federation_Server', false));
        self::assertSame(1, $GLOBALS['federation_test_fired']['faluss_federation_ready']);
        self::assertArrayHasKey('rest_api_init', $GLOBALS['federation_test_actions']);
        self::assertArrayHasKey('rest_pre_serve_request', $GLOBALS['federation_test_filters']);
        self::assertArrayHasKey('admin_menu', $GLOBALS['federation_test_actions']);
        self::assertArrayHasKey('admin_post_faluss_federation_manage', $GLOBALS['federation_test_actions']);
    }
}
