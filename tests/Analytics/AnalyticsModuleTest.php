<?php

declare(strict_types=1);

namespace Faluss\Platform\Analytics;

use Faluss\Platform\Core\SiteRole;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class AnalyticsModuleTest extends TestCase
{
    public function testBootstrapRequiresHubOptInAndNoLoadedRuntime(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/faluss-platform.php');

        self::assertIsString($source);
        self::assertStringContainsString("defined('FALUSS_PLATFORM_ANALYTICS')", $source);
        self::assertStringContainsString("constant('FALUSS_PLATFORM_ANALYTICS') === true", $source);
        self::assertStringContainsString("!class_exists('Faluss_Analytics_Consumer', false)", $source);
        self::assertStringContainsString('[\\Faluss\\Platform\\Analytics\\AnalyticsModule::class, \'activate\']', $source);
        self::assertStringContainsString('[\\Faluss\\Platform\\Analytics\\AnalyticsModule::class, \'deactivate\']', $source);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBootsTheHistoricalHubConsumerAndRetentionRuntime(): void
    {
        if (!function_exists('add_action')) {
            eval('namespace { function add_action($hook, $callback, $priority = 10) { $GLOBALS["analytics_test_actions"][$hook][$priority][] = $callback; } }');
        }
        analytics_test_reset();

        $module = new AnalyticsModule();
        $module->boot();

        self::assertSame('analytics', $module->id());
        self::assertSame([SiteRole::Hub], $module->roles());
        self::assertSame(['admin-dashboard', 'events'], $module->dependencies());
        self::assertSame('0.1.1', FALUSS_ANALYTICS_VERSION);
        self::assertSame('1', FALUSS_ANALYTICS_SCHEMA_VERSION);
        self::assertSame('1', \Faluss_Analytics_Schema::VERSION);
        self::assertTrue(class_exists('Faluss_Analytics_Event_Validator', false));
        self::assertTrue(class_exists('Faluss_Analytics_Consumer', false));
        self::assertTrue(class_exists('Faluss_Analytics_Read_Model', false));
        self::assertArrayHasKey('plugins_loaded', $GLOBALS['analytics_test_actions']);
        self::assertArrayHasKey('faluss_federation_ready', $GLOBALS['analytics_test_actions']);
        self::assertArrayHasKey(\Faluss_Analytics_Retention::HOOK, $GLOBALS['analytics_test_actions']);
    }
}
