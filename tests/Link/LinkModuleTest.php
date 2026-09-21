<?php

declare(strict_types=1);

namespace Faluss\Platform\Link;

use Faluss\Platform\Core\SiteRole;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class LinkModuleTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBootsTheHistoricalMeSurfaceBehindExplicitPlatformDependencies(): void
    {
        link_test_reset();

        $module = new LinkModule();
        $module->boot();

        self::assertSame('link', $module->id());
        self::assertSame([SiteRole::Me], $module->roles());
        self::assertSame(['admin-dashboard', 'catalog', 'token-engine-connector'], $module->dependencies());
        self::assertTrue(class_exists('Faluss_Link', false));
        self::assertTrue(class_exists('Faluss_Link_Schema', false));
        self::assertTrue(class_exists('Faluss_Link_Admin', false));
        self::assertTrue(class_exists('Faluss_Link_Manifest', false));
        self::assertTrue(class_exists('Faluss_Link_Events_Catalog', false));
        self::assertTrue(class_exists('Faluss_Link_Events_Runtime', false));
        self::assertSame('3', \Faluss_Link_Schema::VERSION);
        self::assertSame('faluss_link_schema_version', \Faluss_Link_Schema::OPTION);
        self::assertSame('0.3.21', LinkModule::VERSION);
        $moduleSource = file_get_contents(dirname(__DIR__, 2) . '/src/Link/LinkModule.php');
        self::assertIsString($moduleSource);
        self::assertStringContainsString('Faluss_Link_Schema::maybe_install();', $moduleSource);
        self::assertSame(
            [
                'faluss_link_card',
                'faluss_link_appearance',
                'faluss_link_studio',
                'faluss_link_daily_reward',
                'faluss_link_discoveries',
            ],
            array_keys($GLOBALS['link_test_shortcodes'])
        );
        \Faluss_Link::assets();
        self::assertSame(
            'https://faluss.me/wp-content/plugins/faluss-platform/assets/link/css/faluss-link.css',
            $GLOBALS['link_test_styles']['faluss-link-card']['source']
        );
        self::assertSame(
            'https://faluss.me/wp-content/plugins/faluss-platform/assets/link/js/faluss-link-editor.js',
            $GLOBALS['link_test_scripts']['faluss-link-editor']['source']
        );
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Link/LegacyLinkService.php');
        self::assertIsString($source);
        self::assertStringContainsString("add_action( 'wp_ajax_faluss_link_daily_reward_claim'", $source);
        self::assertStringNotContainsString('wp_ajax_nopriv_faluss_link_daily_reward_claim', $source);
    }
}
