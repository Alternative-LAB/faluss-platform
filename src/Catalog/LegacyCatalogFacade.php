<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Faluss_Catalog_Themes
{
    public const OPTION = 'faluss_catalog_card_themes';
    public const VERSION_OPTION = 'faluss_catalog_version';
    public const VERSION = '2';
    public const SYSTEM_SLUG = 'faluss-default';
    public const LINK_SCOPE = 'faluss-link';

    /** @return array<string, mixed> */
    public static function system_theme(): array
    {
        return \Faluss\Platform\Catalog\CatalogThemeReader::systemTheme();
    }

    /** @return array<string, array<string, mixed>> */
    public static function all_for_scope(string $scope = self::LINK_SCOPE): array
    {
        return self::reader()->allForScope($scope);
    }

    /** @return array<string, array<string, mixed>> */
    public static function active_for_scope(string $scope = self::LINK_SCOPE): array
    {
        return self::reader()->activeForScope($scope);
    }

    /** @return array<string, mixed>|false */
    public static function get_theme(string $slug, string $scope = self::LINK_SCOPE): array|false
    {
        return self::reader()->getTheme($slug, $scope);
    }

    /** @return array<string, mixed>|false */
    public static function get_active_theme(string $slug, string $scope = self::LINK_SCOPE): array|false
    {
        return self::reader()->getActiveTheme($slug, $scope);
    }

    private static function reader(): \Faluss\Platform\Catalog\CatalogThemeReader
    {
        $stored = get_option(self::OPTION, []);

        return new \Faluss\Platform\Catalog\CatalogThemeReader(is_array($stored) ? $stored : []);
    }
}
