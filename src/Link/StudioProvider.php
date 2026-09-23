<?php

declare(strict_types=1);

namespace Faluss\Platform\Link;

interface StudioProvider
{
    public function id(): string;

    /** @param callable(): string $fallback */
    public function renderStudio(callable $fallback): string;

    /** @param callable(): string $fallback */
    public function renderOnboarding(callable $fallback): string;

    /** @param array<string, mixed> $state */
    public function renderStudioExtension(array $state): string;

    public function enqueueCardAssets(): void;
}
