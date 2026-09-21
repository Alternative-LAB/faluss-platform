<?php

declare(strict_types=1);

namespace Faluss\Platform\Catalog;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class CatalogThemeEditorTest extends TestCase
{
    public function testCreatesUniqueSlugAndPreservesLegacyStorageSchema(): void
    {
        $editor = new CatalogThemeEditor(['evening' => ['name' => 'Evening']], []);
        $themes = $editor->create($this->input(['name' => 'Evening', 'preview_attachment_id' => 17]));

        self::assertIsArray($themes);
        self::assertArrayHasKey('evening-2', $themes);
        self::assertSame('evening-2', $themes['evening-2']['slug']);
        self::assertSame(17, $themes['evening-2']['preview_attachment_id']);
        self::assertSame('faluss-link', $themes['evening-2']['scope']);
        self::assertFalse($themes['evening-2']['system']);
    }

    public function testRejectsInvalidFieldsAndUnauthorizedEntitlement(): void
    {
        $editor = new CatalogThemeEditor([], ['theme.gold' => 'Gold']);

        self::assertFalse($editor->create($this->input(['page_background' => 'red'])));
        self::assertFalse($editor->create($this->input(['entitlement_code' => 'theme.other'])));
        self::assertFalse($editor->create($this->input(['alignment' => 'right'])));
        self::assertFalse($editor->create($this->input(['name' => ''])));

        $themes = $editor->create($this->input(['entitlement_code' => 'THEME.GOLD']));
        self::assertIsArray($themes);
        self::assertSame('theme.gold', $themes['evening']['entitlement_code']);
    }

    public function testUnavailableConnectorAllowsOnlyExistingEntitlement(): void
    {
        $stored = ['evening' => ['name' => 'Evening', 'entitlement_code' => 'theme.gold']];
        $editor = new CatalogThemeEditor($stored, false);

        self::assertFalse($editor->update('evening', $this->input(['entitlement_code' => 'theme.other'])));
        $themes = $editor->update('evening', $this->input(['entitlement_code' => 'theme.gold', 'active' => '0']));
        self::assertIsArray($themes);
        self::assertSame('theme.gold', $themes['evening']['entitlement_code']);
        self::assertSame(0, $themes['evening']['active']);
    }

    public function testSystemThemeCannotBeChangedOrDeleted(): void
    {
        $editor = new CatalogThemeEditor(['evening' => ['name' => 'Evening']], []);

        self::assertFalse($editor->update('faluss-default', $this->input()));
        self::assertFalse($editor->delete('faluss-default'));
        self::assertFalse($editor->delete('missing'));

        $themes = $editor->delete('evening');
        self::assertSame([], $themes);
    }

    /** @param array<string, mixed> $changes
     *  @return array<string, mixed>
     */
    private function input(array $changes = []): array
    {
        return array_replace([
            'name' => 'Evening',
            'active' => '1',
            'sort_order' => '10',
            'preview_attachment_id' => 0,
            'page_background' => '#FFFDF5',
            'hero_transition_color' => '#FFFDF5',
            'name_color' => '#000000',
            'alignment' => 'left',
            'social_variant' => 'outline',
            'link_style' => 'dark',
            'entitlement_code' => '',
        ], $changes);
    }
}
