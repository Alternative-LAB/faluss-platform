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
    public const NAME_COLORS = [
        '#BE79FF' => 'Rose',
        '#FFFFFF' => 'Blanc',
        '#000000' => 'Noir',
        '#82206B' => 'Prune',
    ];
    public const ALIGNMENTS = ['left' => 'Gauche', 'center' => 'Centré'];
    public const SOCIAL_VARIANTS = ['outline' => 'Icônes contour', 'full' => 'Logos pleins'];
    public const LINK_STYLES = ['dark' => 'Sombre', 'light' => 'Clair', 'outline' => 'Contour'];

    public static function boot(): void
    {
        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('admin_post_faluss_catalog_create_theme', [self::class, 'create']);
        add_action('admin_post_faluss_catalog_update_theme', [self::class, 'update']);
        add_action('admin_post_faluss_catalog_delete_theme', [self::class, 'delete']);
    }

    public static function activate(): bool
    {
        if (get_option(self::OPTION, false) === false) {
            add_option(self::OPTION, [], '', false);
        }

        update_option(self::VERSION_OPTION, self::VERSION, false);

        return true;
    }

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

    public static function menu(): void
    {
        add_menu_page(
            'Catalogue Faluss',
            'Catalogue Faluss',
            'manage_options',
            'faluss-catalog',
            [self::class, 'page'],
            'dashicons-art',
            58
        );
    }

    public static function assets(string $hook): void
    {
        if ($hook !== 'toplevel_page_faluss-catalog' || !current_user_can('manage_options')) {
            return;
        }

        $pluginFile = dirname(__DIR__, 2) . '/faluss-platform.php';
        wp_enqueue_style('faluss-platform-admin', plugins_url('assets/admin.css', $pluginFile), [], '0.1.0');
        wp_enqueue_style('faluss-platform-catalog', plugins_url('assets/catalog.css', $pluginFile), ['faluss-platform-admin'], '0.1.0');
        wp_enqueue_media();
        wp_enqueue_script('faluss-platform-catalog', plugins_url('assets/catalog.js', $pluginFile), ['jquery', 'media-views'], '0.1.0', true);
    }

    public static function create(): void
    {
        (new \Faluss\Platform\Catalog\CatalogAdminActions(self::entitlements(), 'faluss-catalog'))->create();
    }

    public static function update(): void
    {
        (new \Faluss\Platform\Catalog\CatalogAdminActions(self::entitlements(), 'faluss-catalog'))->update();
    }

    public static function delete(): void
    {
        (new \Faluss\Platform\Catalog\CatalogAdminActions(self::entitlements(), 'faluss-catalog'))->delete();
    }

    public static function page(): void
    {
        (new \Faluss\Platform\Catalog\CatalogAdminPage(self::entitlements()))->render();
    }

    private static function reader(): \Faluss\Platform\Catalog\CatalogThemeReader
    {
        $stored = get_option(self::OPTION, []);

        return new \Faluss\Platform\Catalog\CatalogThemeReader(is_array($stored) ? $stored : []);
    }

    private static function entitlements(): \Faluss\Platform\Catalog\CatalogEntitlementProvider
    {
        return new \Faluss\Platform\Catalog\CatalogEntitlementProvider();
    }
}
