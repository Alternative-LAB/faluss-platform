<?php

declare(strict_types=1);

namespace Faluss\Platform\MeStudio;

interface StudioBlockProvider
{
    /** @return array<string, mixed> */
    public function descriptor(): array;

    /** @return array<string, mixed>|\WP_Error */
    public function readModel(): array|\WP_Error;
}
