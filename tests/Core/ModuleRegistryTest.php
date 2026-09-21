<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Core;

use Faluss\Platform\Core\Module;
use Faluss\Platform\Core\ModuleRegistry;
use Faluss\Platform\Core\SiteRole;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ModuleRegistryTest extends TestCase
{
    public function testBootsDependenciesBeforeConsumersOnlyForSupportedRole(): void
    {
        $calls = [];
        $registry = new ModuleRegistry(SiteRole::Me);
        $registry->register($this->module('profile', [SiteRole::Me], ['identity'], $calls));
        $registry->register($this->module('identity', [SiteRole::Me], [], $calls));
        $registry->register($this->module('billing', [SiteRole::Hub], [], $calls));

        $registry->boot();
        $registry->boot();

        self::assertSame(['identity', 'profile'], $calls);
        self::assertSame(['identity', 'profile'], $registry->activeModuleIds());
    }

    public function testRejectsMissingDependenciesBeforeAnyModuleBoots(): void
    {
        $calls = [];
        $registry = new ModuleRegistry(SiteRole::Hub);
        $registry->register($this->module('portal', [SiteRole::Hub], ['identity'], $calls));

        $this->expectException(LogicException::class);
        $registry->boot();
    }

    public function testRejectsDependencyCycles(): void
    {
        $calls = [];
        $registry = new ModuleRegistry(SiteRole::Me);
        $registry->register($this->module('first', [SiteRole::Me], ['second'], $calls));
        $registry->register($this->module('second', [SiteRole::Me], ['first'], $calls));

        $this->expectException(LogicException::class);
        $registry->boot();
    }

    public function testRejectsDuplicateModuleIds(): void
    {
        $calls = [];
        $registry = new ModuleRegistry(SiteRole::Me);
        $registry->register($this->module('identity', [SiteRole::Me], [], $calls));

        $this->expectException(LogicException::class);
        $registry->register($this->module('identity', [SiteRole::Me], [], $calls));
    }

    public function testCannotRegisterAfterEmptyBoot(): void
    {
        $calls = [];
        $registry = new ModuleRegistry(SiteRole::Me);
        $registry->boot();

        $this->expectException(LogicException::class);
        $registry->register($this->module('identity', [SiteRole::Me], [], $calls));
    }

    /** @param list<SiteRole> $roles
     *  @param list<string> $dependencies
     *  @param list<string> $calls
     */
    private function module(string $id, array $roles, array $dependencies, array &$calls): Module
    {
        return new class ($id, $roles, $dependencies, $calls) implements Module {
            /** @param list<SiteRole> $roles
             *  @param list<string> $dependencies
             *  @param list<string> $calls
             */
            public function __construct(
                private readonly string $moduleId,
                private readonly array $supportedRoles,
                private readonly array $requiredModules,
                private array &$calls
            ) {
            }

            public function id(): string
            {
                return $this->moduleId;
            }

            public function roles(): array
            {
                return $this->supportedRoles;
            }

            public function dependencies(): array
            {
                return $this->requiredModules;
            }

            public function boot(): void
            {
                $this->calls[] = $this->moduleId;
            }
        };
    }
}
