<?php

declare(strict_types=1);

namespace Faluss\Platform\Catalog;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class CatalogAdminPageTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['catalog_test_admin'] = true;
        $GLOBALS['catalog_test_option'] = [
            'evening' => ['name' => 'Evening', 'active' => 1],
        ];
        $GLOBALS['catalog_test_hooks'] = [];
        $GLOBALS['catalog_test_styles'] = [];
        $GLOBALS['catalog_test_scripts'] = [];
        $_GET = [];
    }

    public function testRendersExistingThemeAndGuardedForms(): void
    {
        $page = new CatalogAdminPage(new CatalogEntitlementProvider());
        ob_start();

        try {
            $page->render();
            $html = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertStringContainsString('Catalogue de thèmes', $html);
        self::assertStringContainsString('Evening', $html);
        self::assertStringContainsString('faluss_catalog_create_theme', $html);
        self::assertStringContainsString('faluss_catalog_update_theme', $html);
        self::assertStringContainsString('faluss_catalog_delete_theme', $html);
        self::assertSame(3, substr_count($html, 'name="faluss_catalog_nonce"'));
        self::assertStringContainsString('name="preview_attachment_id"', $html);
    }

    public function testRegistersPageAndLimitsAssetsToItsHook(): void
    {
        $page = new CatalogAdminPage(new CatalogEntitlementProvider());
        $page->boot();
        $page->registerMenu();
        $page->enqueueAssets('other-hook');

        self::assertSame(['faluss-platform', 'manage_options', 'faluss-platform-catalog'], $GLOBALS['catalog_test_menu']);
        self::assertSame([], $GLOBALS['catalog_test_styles']);

        $page->enqueueAssets('faluss-catalog-test-hook');

        self::assertSame(['faluss-platform-admin', 'faluss-platform-catalog'], $GLOBALS['catalog_test_styles']);
        self::assertSame(['faluss-platform-catalog'], $GLOBALS['catalog_test_scripts']);
        self::assertTrue($GLOBALS['catalog_test_media']);
    }
}
