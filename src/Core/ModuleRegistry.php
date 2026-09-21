<?php

declare(strict_types=1);

namespace Faluss\Platform\Core;

use LogicException;

final class ModuleRegistry
{
    /** @var array<string, Module> */
    private array $modules = [];

    /** @var array<string, bool> */
    private array $booted = [];

    private bool $started = false;

    public function __construct(private readonly SiteRole $role)
    {
    }

    public function register(Module $module): void
    {
        $id = $module->id();

        if (!preg_match('/^[a-z][a-z0-9-]*$/D', $id) || isset($this->modules[$id])) {
            throw new LogicException('Invalid or duplicate module identifier.');
        }

        if ($this->started) {
            throw new LogicException('Modules cannot be registered after boot.');
        }

        $this->modules[$id] = $module;
    }

    public function boot(): void
    {
        $order = $this->resolveOrder();
        $this->started = true;

        foreach ($order as $id) {
            if (isset($this->booted[$id])) {
                continue;
            }

            $this->modules[$id]->boot();
            $this->booted[$id] = true;
        }
    }

    /** @return list<string> */
    public function activeModuleIds(): array
    {
        return array_keys($this->booted);
    }

    /** @return list<string> */
    private function resolveOrder(): array
    {
        $active = [];

        foreach ($this->modules as $id => $module) {
            if (in_array($this->role, $module->roles(), true)) {
                $active[$id] = $module;
            }
        }

        $visiting = [];
        $visited = [];
        $order = [];

        $visit = function (string $id) use (&$visit, &$visiting, &$visited, &$order, $active): void {
            if (isset($visited[$id])) {
                return;
            }

            if (isset($visiting[$id])) {
                throw new LogicException('Circular module dependency.');
            }

            if (!isset($active[$id])) {
                throw new LogicException('Missing or unsupported module dependency.');
            }

            $visiting[$id] = true;

            foreach ($active[$id]->dependencies() as $dependency) {
                $visit($dependency);
            }

            unset($visiting[$id]);
            $visited[$id] = true;
            $order[] = $id;
        };

        foreach (array_keys($active) as $id) {
            $visit($id);
        }

        return $order;
    }
}
