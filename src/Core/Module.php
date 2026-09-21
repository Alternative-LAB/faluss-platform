<?php

declare(strict_types=1);

namespace Faluss\Platform\Core;

interface Module
{
    public function id(): string;

    /** @return list<SiteRole> */
    public function roles(): array;

    /** @return list<string> */
    public function dependencies(): array;

    public function boot(): void;
}
