<?php

declare(strict_types=1);

namespace Faluss\Platform\Admin;

use Faluss\Platform\Core\ModuleRegistry;
use Faluss\Platform\Core\SiteRole;
use PHPUnit\Framework\TestCase;

function add_action(string $hook, callable $callback): void
{
    $GLOBALS['faluss_test_hooks'][$hook] = $callback;
}

final class DashboardModuleTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['faluss_test_hooks'] = [];
    }

    public function testRegistersOnlyOnSupportedRolesWithoutDependencies(): void
    {
        foreach ([SiteRole::Me, SiteRole::Hub] as $role) {
            $registry = new ModuleRegistry($role);
            $module = new DashboardModule($role, $registry);
            $registry->register($module);
            $registry->boot();

            self::assertSame('admin-dashboard', $module->id());
            self::assertContains($role, $module->roles());
            self::assertSame([], $module->dependencies());
            self::assertSame(['admin-dashboard'], $registry->activeModuleIds());
            self::assertSame(['admin_menu', 'admin_enqueue_scripts'], array_keys($GLOBALS['faluss_test_hooks']));
        }
    }
}
