<?php

declare(strict_types=1);

namespace Faluss\Platform\Link;

final class LinkCatalogAdapter
{
    public static function available(): bool
    {
        return self::hasMethod('get_active_theme')
            && self::hasMethod('active_for_scope')
            && self::hasMethod('all_for_scope');
    }

    /** @return array<string, mixed>|false */
    public static function activeTheme(string $slug): array|false
    {
        if (!self::available()) {
            return false;
        }

        $theme = self::call('get_active_theme', [$slug, 'faluss-link']);

        return is_array($theme) ? $theme : false;
    }

    /** @return array<string, array<string, mixed>> */
    public static function themes(bool $activeOnly): array
    {
        $method = $activeOnly ? 'active_for_scope' : 'all_for_scope';
        if (!self::available()) {
            return [];
        }

        $themes = self::call($method, ['faluss-link']);

        return is_array($themes) ? $themes : [];
    }

    private static function hasMethod(string $method): bool
    {
        return class_exists('Faluss_Catalog_Themes') && method_exists('Faluss_Catalog_Themes', $method);
    }

    /** @param list<mixed> $arguments */
    private static function call(string $method, array $arguments): mixed
    {
        $class = 'Faluss_Catalog_Themes';
        if (!self::hasMethod($method)) {
            return null;
        }

        return $class::$method(...$arguments);
    }
}
