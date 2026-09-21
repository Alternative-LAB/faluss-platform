<?php

declare(strict_types=1);

namespace Faluss\Platform\Catalog;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class CatalogThemeReaderTest extends TestCase
{
    public function testSystemThemeIsImmutableBaseline(): void
    {
        $reader = new CatalogThemeReader([]);

        self::assertSame('faluss_catalog_card_themes', CatalogThemeReader::OPTION);
        self::assertSame(['faluss-default'], array_keys($reader->allForScope()));
        self::assertSame('#FFFDF5', $reader->getActiveTheme('faluss-default')['page_background']);
        self::assertSame(1, $reader->getActiveTheme('faluss-default')['active']);
    }

    public function testNormalizesStoredThemesAndKeepsLegacyKeys(): void
    {
        $reader = new CatalogThemeReader([
            'evening' => [
                'name' => '  <b>Evening</b>  ',
                'active' => 1,
                'sort_order' => 10,
                'preview_attachment_id' => 17,
                'page_background' => '#abcdef',
                'hero_transition_color' => '#123456',
                'name_color' => '#be79ff',
                'alignment' => 'center',
                'social_variant' => 'full',
                'link_style' => 'light',
                'entitlement_code' => 'THEME.PREMIUM',
            ],
        ]);

        $theme = $reader->getActiveTheme('evening');
        self::assertIsArray($theme);
        self::assertSame('Evening', $theme['name']);
        self::assertSame('#ABCDEF', $theme['page_background']);
        self::assertSame('#BE79FF', $theme['name_color']);
        self::assertSame('theme.premium', $theme['entitlement_code']);
        self::assertSame(17, $theme['preview_attachment_id']);
        self::assertSame('faluss-link', $theme['scope']);
        self::assertFalse($theme['system']);
    }

    public function testInactiveAndInvalidThemesCannotBeResolvedAsActive(): void
    {
        $reader = new CatalogThemeReader([
            'archived' => ['name' => 'Archived', 'active' => 0],
            'invalid' => ['name' => ''],
            'faluss-default' => ['name' => 'Override', 'active' => 1],
        ]);

        self::assertCount(2, $reader->allForScope());
        self::assertSame(['faluss-default'], array_keys($reader->activeForScope()));
        self::assertFalse($reader->getActiveTheme('archived'));
        self::assertFalse($reader->getTheme('invalid'));
        self::assertSame('Faluss par défaut', $reader->getTheme('faluss-default')['name']);
    }

    public function testSortsByOrderThenNameAndRejectsOutOfScopeCustomThemes(): void
    {
        $reader = new CatalogThemeReader([
            'z' => ['name' => 'Zulu', 'sort_order' => 10],
            'a' => ['name' => 'Alpha', 'sort_order' => 10],
            'first' => ['name' => 'First', 'sort_order' => 1],
        ]);

        self::assertSame(['faluss-default', 'first', 'a', 'z'], array_keys($reader->allForScope()));
        self::assertSame(['faluss-default'], array_keys($reader->allForScope('other-scope')));
    }

    public function testMatchesLegacyPluginSnapshotForRepresentativeRecords(): void
    {
        $reader = new CatalogThemeReader([
            'evening' => [
                'name' => 'Evening', 'active' => 1, 'sort_order' => 10,
                'page_background' => '#123456', 'hero_transition_color' => '#ABCDEF',
                'name_color' => '#BE79FF', 'alignment' => 'center',
                'social_variant' => 'full', 'link_style' => 'light',
                'entitlement_code' => 'theme.gold',
            ],
            'archived' => ['name' => 'Archived', 'active' => 0, 'sort_order' => 1],
            'invalid' => ['name' => ''],
        ]);

        $expectedEvening = [
            'name' => 'Evening', 'slug' => 'evening', 'active' => 1,
            'sort_order' => 10, 'preview_attachment_id' => 0, 'scope' => 'faluss-link',
            'page_background' => '#123456', 'hero_transition_color' => '#ABCDEF',
            'name_color' => '#BE79FF', 'alignment' => 'center',
            'social_variant' => 'full', 'link_style' => 'light',
            'entitlement_code' => 'theme.gold', 'system' => false,
        ];
        $expectedArchived = array_replace($expectedEvening, [
            'name' => 'Archived', 'slug' => 'archived', 'active' => 0,
            'sort_order' => 1, 'page_background' => '#FFFDF5',
            'hero_transition_color' => '#FFFDF5', 'name_color' => '#000000',
            'alignment' => 'left', 'social_variant' => 'outline',
            'link_style' => 'dark', 'entitlement_code' => '',
        ]);

        self::assertSame([
            'faluss-default' => CatalogThemeReader::systemTheme(),
            'archived' => $expectedArchived,
            'evening' => $expectedEvening,
        ], $reader->allForScope());
        self::assertSame(['faluss-default', 'evening'], array_keys($reader->activeForScope()));
        self::assertSame($expectedEvening, $reader->getActiveTheme('evening'));
    }
}
