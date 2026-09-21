<?php

declare(strict_types=1);

namespace Faluss\Platform\AppsRegistry;

use Faluss\Platform\Core\SiteRole;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class AppsRegistryModuleTest extends TestCase
{
    public function testBootsForBothRolesWithTheHistoricalFacade(): void
    {
        apps_registry_test_reset();
        $module = new AppsRegistryModule();

        $module->boot();

        self::assertSame('apps-registry', $module->id());
        self::assertSame([SiteRole::Me, SiteRole::Hub], $module->roles());
        self::assertSame(['admin-dashboard'], $module->dependencies());
        self::assertTrue(class_exists('Faluss_Apps_Registry', false));
        self::assertTrue(class_exists('Faluss_Apps_Registry_Manifest_Validator', false));
        self::assertTrue(class_exists('Faluss_Apps_Registry_Read_Model_Validator', false));
        self::assertSame('1.0.0', \Faluss_Apps_Registry::CONTRACT_VERSION);
        self::assertSame(1, \Faluss_Federation_Providers::$registrations);
    }
}
