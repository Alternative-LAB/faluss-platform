<?php

declare(strict_types=1);

namespace {
    function get_option(string $name, mixed $default): mixed
    {
        return $GLOBALS['catalog_facade_option'] ?? $default;
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
    }
}
