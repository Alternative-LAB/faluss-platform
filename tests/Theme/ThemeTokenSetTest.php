<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Theme;

use Faluss\Platform\Theme\ThemeTokenSet;
use PHPUnit\Framework\TestCase;

final class ThemeTokenSetTest extends TestCase
{
    public function testDefaultsMatchExistingThemeTokens(): void
    {
        self::assertSame('faluss_theme_tokens', ThemeTokenSet::OPTION);
        self::assertSame('#FFFDF5', ThemeTokenSet::defaults()['canvas']);
        self::assertSame('24px', ThemeTokenSet::defaults()['card_radius']);
        self::assertSame('soft', ThemeTokenSet::defaults()['shadow']);
        self::assertCount(15, ThemeTokenSet::defaults());
    }

    public function testSanitizationPreservesLegacyRules(): void
    {
        $values = ThemeTokenSet::sanitize([
            'canvas' => '#aabbcc',
            'accent' => 'red; color: blue',
            'border_opacity' => '150',
            'shadow' => 'lifted',
            'card_radius' => '12px',
            'pill_radius' => '1000px',
        ]);

        self::assertSame('#AABBCC', $values['canvas']);
        self::assertSame('#FF3D16', $values['accent']);
        self::assertSame('100', $values['border_opacity']);
        self::assertSame('lifted', $values['shadow']);
        self::assertSame('12px', $values['card_radius']);
        self::assertSame('999px', $values['pill_radius']);
    }

    public function testReadsLegacyBorderWithoutChangingStorage(): void
    {
        $values = ThemeTokenSet::fromStorage(['border' => '1px solid red']);

        self::assertSame('#080808', $values['border_color']);
        self::assertSame('12', $values['border_opacity']);
    }

    public function testCssUsesExistingVariableNamesAndSafeValues(): void
    {
        $css = ThemeTokenSet::css(['accent' => '#123456', 'border_opacity' => '25']);

        self::assertStringContainsString('--faluss-accent:#123456;', $css);
        self::assertStringContainsString('--faluss-border:rgba(8,8,8,0.25);', $css);
        self::assertStringContainsString('--faluss-card_radius:24px;', $css);
        self::assertStringContainsString('--faluss-shadow:0 12px 30px rgba(8, 8, 8, 0.06);', $css);
        self::assertStringNotContainsString('color: blue', $css);
    }

    public function testPreviewCssUsesSanitizedValues(): void
    {
        $css = ThemeTokenSet::previewCss(['canvas' => '#123456', 'shadow' => 'unknown']);

        self::assertStringContainsString('background:#123456;', $css);
        self::assertStringContainsString('--ft-shadow:0 12px 30px rgba(8, 8, 8, 0.06);', $css);
    }
}
