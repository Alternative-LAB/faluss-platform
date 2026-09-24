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
    public function testActivationStillReachesTheLinkSchemaOnMeWithOptIn(): void
    {
        link_test_reset();
        define('FALUSS_PLATFORM_ROLE', 'me');
        define('FALUSS_PLATFORM_LINK', true);
        $GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];

        LinkModule::activate();

        self::assertTrue(class_exists('Faluss_Link_Schema', false));
        self::assertSame([], $GLOBALS['link_test_option_updates']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testActivationRejectsAnAccidentalLinkFlagOnHub(): void
    {
        define('FALUSS_PLATFORM_ROLE', 'hub');
        define('FALUSS_PLATFORM_LINK', true);

        LinkModule::activate();

        self::assertFalse(class_exists('Faluss_Link_Schema', false));
    }

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
        self::assertSame('4', \Faluss_Link_Schema::VERSION);
        self::assertSame('faluss_link_schema_version', \Faluss_Link_Schema::OPTION);
        self::assertSame('0.4.0', LinkModule::VERSION);
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

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLegacyCompositionBackfillNormalisesHistoricalValuesIntoTheStrictV2Document(): void
    {
        link_test_reset();
        (new LinkModule())->boot();

        $legacy = new \ReflectionMethod('Faluss_Link_Schema', 'legacy_composition');
        $valid = new \ReflectionMethod('Faluss_Link_Schema', 'valid_composition');
        $document = $legacy->invoke(null, json_encode([
            'avatar_border' => true,
            'name_font' => '../remote-font',
            'social_selected' => ['instagram', 'instagram', 'bad network', 'x'],
            'alignment' => 'outside',
            'page_background' => '#a748b5',
            'button_color' => 'javascript:red',
            'hero_transition_color' => '#fffdf5',
            'hero_transition_intensity' => 140,
            'hero_transition_position' => 2,
            'name_color' => '#be79ff',
            'social_variant' => 'unknown',
            'selected_theme' => '../theme',
            'theme_overrides' => ['page_background', 'page_background', 'php'],
        ], JSON_THROW_ON_ERROR));

        self::assertIsArray($document);
        self::assertTrue($valid->invoke(null, $document));
        self::assertSame('outfit', $document['presentation']['name_font']);
        self::assertSame(['instagram', 'x'], $document['presentation']['social_selected']);
        self::assertSame('center', $document['presentation']['alignment']);
        self::assertSame('#A748B5', $document['presentation']['page_background']);
        self::assertSame('#080808', $document['presentation']['button_color']);
        self::assertSame(100, $document['presentation']['hero_transition_intensity']);
        self::assertSame(35, $document['presentation']['hero_transition_position']);
        self::assertSame('faluss-default', $document['presentation']['selected_theme']);
        self::assertSame(['page_background'], $document['presentation']['theme_overrides']);

        $document['presentation']['theme_overrides'] = ['php'];
        self::assertFalse($valid->invoke(null, $document));
    }
}
