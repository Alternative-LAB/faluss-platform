<?php

declare(strict_types=1);

namespace Faluss\Platform\Events;

use Faluss\Platform\Core\SiteRole;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class EventsModuleTest extends TestCase
{
    public function testBootstrapRequiresAnExplicitOptInAndNoLoadedRuntime(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/faluss-platform.php');

        self::assertIsString($source);
        self::assertStringContainsString("defined('FALUSS_PLATFORM_EVENTS')", $source);
        self::assertStringContainsString("constant('FALUSS_PLATFORM_EVENTS') === true", $source);
        self::assertStringContainsString("!class_exists('Faluss_Events_Engine', false)", $source);
        self::assertStringContainsString('[\\Faluss\\Platform\\Events\\EventsModule::class, \'activate\']', $source);
        self::assertStringContainsString('[\\Faluss\\Platform\\Events\\EventsModule::class, \'deactivate\']', $source);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBootsTheHistoricalRuntimeForBothSiteRoles(): void
    {
        events_test_reset();

        $module = new EventsModule();
        $module->boot();

        self::assertSame('events', $module->id());
        self::assertSame([SiteRole::Me, SiteRole::Hub], $module->roles());
        self::assertSame(['admin-dashboard'], $module->dependencies());
        self::assertSame('0.3.1', FALUSS_EVENTS_VERSION);
        self::assertSame('2', FALUSS_EVENTS_SCHEMA_VERSION);
        self::assertSame('2', \Faluss_Events_Schema::VERSION);
        self::assertTrue(class_exists('Faluss_Events_Catalog_Validator', false));
        self::assertTrue(class_exists('Faluss_Events_Engine', false));
        self::assertTrue(class_exists('Faluss_Events_Retention', false));
        self::assertArrayHasKey('faluss_federation_ready', $GLOBALS['events_test_actions']);
        self::assertArrayHasKey(\Faluss_Events_Workers::OUTBOX_HOOK, $GLOBALS['events_test_actions']);
        self::assertArrayHasKey(\Faluss_Events_Workers::CONSUMER_HOOK, $GLOBALS['events_test_actions']);
        self::assertArrayHasKey(\Faluss_Events_Retention::HOOK, $GLOBALS['events_test_actions']);
        self::assertArrayHasKey('cron_schedules', $GLOBALS['events_test_filters']);
    }
}
