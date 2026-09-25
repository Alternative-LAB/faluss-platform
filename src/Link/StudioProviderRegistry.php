<?php

declare(strict_types=1);

namespace Faluss\Platform\Link;

use LogicException;
use Throwable;

/** Selects exactly one Studio provider while preserving Link's internal fallback. */
final class StudioProviderRegistry
{
    private static ?StudioProvider $active = null;

    public static function register(StudioProvider $provider): void
    {
        if (self::$active !== null) {
            throw new LogicException('A Faluss Studio provider is already active.');
        }
        self::$active = $provider;
    }

    public static function activeId(): string
    {
        return self::$active?->id() ?? 'link-internal';
    }

    /** @param callable(): string $fallback */
    public static function renderStudio(callable $fallback): string
    {
        $v3 = defined('FALUSS_PLATFORM_ONBOARDING_V3') && constant('FALUSS_PLATFORM_ONBOARDING_V3') === true;
        if (self::$active === null) {
            return $v3 ? self::v3Unavailable() : $fallback();
        }
        try {
            return self::$active->renderStudio($fallback);
        } catch (Throwable) {
            return $v3 ? self::v3Unavailable() : $fallback();
        }
    }

    /** @param callable(): string $fallback */
    public static function renderOnboarding(callable $fallback): string
    {
        $v3 = defined('FALUSS_PLATFORM_ONBOARDING_V3') && constant('FALUSS_PLATFORM_ONBOARDING_V3') === true;
        if (self::$active === null) {
            return $v3 ? self::v3Unavailable() : $fallback();
        }
        try {
            return self::$active->renderOnboarding($fallback);
        } catch (Throwable) {
            return $v3 ? self::v3Unavailable() : $fallback();
        }
    }

    private static function v3Unavailable(): string
    {
        return '<section class="faluss-onboarding-v3__unavailable" role="alert"><h1>Création temporairement indisponible</h1><p>Votre brouillon est conservé. Réessayez dans un instant.</p><a href="">Réessayer</a></section>';
    }

    public static function enqueueCardAssets(): void
    {
        if (self::$active === null) {
            return;
        }
        try {
            self::$active->enqueueCardAssets();
        } catch (Throwable) {
            // Link's base card assets are already enqueued by the caller.
        }
    }

    /** @param array<string, mixed> $state */
    public static function renderStudioExtension(array $state): string
    {
        if (self::$active === null) {
            return '';
        }
        try {
            return self::$active->renderStudioExtension($state);
        } catch (Throwable) {
            return '';
        }
    }

    /** Test-only reset; production code never swaps a provider during a request. */
    public static function resetForTests(): void
    {
        self::$active = null;
    }
}
