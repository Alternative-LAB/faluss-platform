<?php

declare(strict_types=1);

namespace {
    function get_option(string $name, mixed $default): mixed
    {
        return $GLOBALS['catalog_facade_option'] ?? $default;
    }

    function add_option(string $name, mixed $value, string $deprecated, bool $autoload): bool
    {
        $GLOBALS['catalog_facade_adds'][] = [$name, $value, $deprecated, $autoload];
        $GLOBALS['catalog_facade_option'] = $value;

        return true;
    }

    function update_option(string $name, mixed $value, bool $autoload): bool
    {
        $GLOBALS['catalog_facade_updates'][] = [$name, $value, $autoload];

        return true;
    }

    function is_admin(): bool
    {
        return $GLOBALS['catalog_facade_is_admin'] ?? false;
    }

    function add_action(string $hook, callable $callback): void
    {
        $GLOBALS['catalog_facade_hooks'][$hook] = $callback;
    }
}

namespace Faluss\Platform\Tests\Catalog {
    use PHPUnit\Framework\Attributes\RunInSeparateProcess;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/WordPressStubs.php';

    if (!defined('ABSPATH')) {
        define('ABSPATH', '/tmp/faluss-platform-tests/');
    }

    final class LegacyCatalogFacadeTest extends TestCase
    {
        #[RunInSeparateProcess]
        #[PreserveGlobalState(false)]
        public function testKeepsThePublicContractUsedByFalussLink(): void
        {
            require_once __DIR__ . '/../../src/Catalog/LegacyCatalogFacade.php';
            $GLOBALS['catalog_facade_option'] = [
                'evening' => ['name' => 'Evening', 'active' => 1],
                'archived' => ['name' => 'Archived', 'active' => 0],
            ];

            self::assertTrue(class_exists('Faluss_Catalog_Themes'));
            self::assertSame('faluss_catalog_card_themes', \Faluss_Catalog_Themes::OPTION);
            self::assertArrayHasKey('evening', \Faluss_Catalog_Themes::all_for_scope());
            self::assertArrayNotHasKey('archived', \Faluss_Catalog_Themes::active_for_scope());
            self::assertSame('Evening', \Faluss_Catalog_Themes::get_active_theme('evening')['name']);
            self::assertFalse(\Faluss_Catalog_Themes::get_active_theme('archived'));
            self::assertSame('faluss-default', \Faluss_Catalog_Themes::system_theme()['slug']);
        }

        #[RunInSeparateProcess]
        #[PreserveGlobalState(false)]
        public function testExposesEveryHistoricalPublicMethodAndConstant(): void
        {
            require_once __DIR__ . '/../../src/Catalog/LegacyCatalogFacade.php';

            foreach ([
                'boot', 'activate', 'system_theme', 'all_for_scope', 'active_for_scope',
                'get_theme', 'get_active_theme', 'menu', 'assets', 'create', 'update',
                'delete', 'page',
            ] as $method) {
                self::assertTrue(method_exists(\Faluss_Catalog_Themes::class, $method), $method);
            }

            self::assertSame('2', \Faluss_Catalog_Themes::VERSION);
            self::assertSame([
                '#BE79FF' => 'Rose',
                '#FFFFFF' => 'Blanc',
                '#000000' => 'Noir',
                '#82206B' => 'Prune',
            ], \Faluss_Catalog_Themes::NAME_COLORS);
            self::assertSame(['left' => 'Gauche', 'center' => 'Centré'], \Faluss_Catalog_Themes::ALIGNMENTS);
            self::assertSame(['outline' => 'Icônes contour', 'full' => 'Logos pleins'], \Faluss_Catalog_Themes::SOCIAL_VARIANTS);
            self::assertSame(['dark' => 'Sombre', 'light' => 'Clair', 'outline' => 'Contour'], \Faluss_Catalog_Themes::LINK_STYLES);
        }

        #[RunInSeparateProcess]
        #[PreserveGlobalState(false)]
        public function testActivationInitialisesOnlyTheMissingOptionAndRecordsTheVersion(): void
        {
            require_once __DIR__ . '/../../src/Catalog/LegacyCatalogFacade.php';
            $GLOBALS['catalog_facade_option'] = false;
            $GLOBALS['catalog_facade_adds'] = [];
            $GLOBALS['catalog_facade_updates'] = [];

            self::assertTrue(\Faluss_Catalog_Themes::activate());
            self::assertSame([
                ['faluss_catalog_card_themes', [], '', false],
            ], $GLOBALS['catalog_facade_adds']);
            self::assertSame([
                ['faluss_catalog_version', '2', false],
            ], $GLOBALS['catalog_facade_updates']);

            $GLOBALS['catalog_facade_option'] = ['existing' => ['name' => 'Existing']];
            $GLOBALS['catalog_facade_adds'] = [];

            self::assertTrue(\Faluss_Catalog_Themes::activate());
            self::assertSame([], $GLOBALS['catalog_facade_adds']);
        }

        #[RunInSeparateProcess]
        #[PreserveGlobalState(false)]
        public function testHistoricalBootRemainsAdminOnlyAndRegistersTheOriginalActions(): void
        {
            require_once __DIR__ . '/../../src/Catalog/LegacyCatalogFacade.php';
            $GLOBALS['catalog_facade_hooks'] = [];
            $GLOBALS['catalog_facade_is_admin'] = false;

            \Faluss_Catalog_Themes::boot();
            self::assertSame([], $GLOBALS['catalog_facade_hooks']);

            $GLOBALS['catalog_facade_is_admin'] = true;
            \Faluss_Catalog_Themes::boot();

            self::assertSame([
                'admin_menu',
                'admin_enqueue_scripts',
                'admin_post_faluss_catalog_create_theme',
                'admin_post_faluss_catalog_update_theme',
                'admin_post_faluss_catalog_delete_theme',
            ], array_keys($GLOBALS['catalog_facade_hooks']));
        }

        #[RunInSeparateProcess]
        #[PreserveGlobalState(false)]
        public function testHistoricalActionMethodsKeepTheLegacyAdminRoute(): void
        {
            require_once __DIR__ . '/../../src/Catalog/LegacyCatalogFacade.php';
            $GLOBALS['catalog_test_admin'] = true;
            $GLOBALS['catalog_test_option'] = [];
            $GLOBALS['catalog_test_writes'] = [];
            $_POST = [
                'faluss_catalog_nonce' => 'valid-nonce',
                'name' => 'Evening',
                'active' => '1',
                'sort_order' => '10',
                'page_background' => '#FFFDF5',
                'hero_transition_color' => '#FFFDF5',
                'name_color' => '#000000',
                'alignment' => 'left',
                'social_variant' => 'outline',
                'link_style' => 'dark',
                'entitlement_code' => '',
            ];

            try {
                \Faluss_Catalog_Themes::create();
                self::fail('Expected redirect.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('admin.php?page=faluss-catalog', $exception->getMessage());
                self::assertStringContainsString('faluss_catalog_notice=created', $exception->getMessage());
            }

            self::assertArrayHasKey('evening', $GLOBALS['catalog_test_option']);
        }
    }
}
