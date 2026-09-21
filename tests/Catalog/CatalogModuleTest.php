<?php

declare(strict_types=1);

namespace Faluss\Platform\Catalog;

use Faluss\Platform\Core\SiteRole;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class CatalogModuleTest extends TestCase
{
    public function testModuleBootsCompatibilityFacadeAndAdminHooksOnlyForMe(): void
    {
        if (!defined('ABSPATH')) {
            define('ABSPATH', '/tmp/faluss-platform-tests/');
        }

        $GLOBALS['catalog_test_hooks'] = [];
        $module = new CatalogModule();
        $module->boot();

        self::assertSame('catalog', $module->id());
        self::assertSame([SiteRole::Me], $module->roles());
        self::assertSame(['admin-dashboard'], $module->dependencies());
        self::assertTrue(class_exists('Faluss_Catalog_Themes', false));
        self::assertSame([
            'admin_post_faluss_catalog_create_theme',
            'admin_post_faluss_catalog_update_theme',
            'admin_post_faluss_catalog_delete_theme',
            'admin_menu',
            'admin_enqueue_scripts',
        ], array_keys($GLOBALS['catalog_test_hooks']));
    }
}
