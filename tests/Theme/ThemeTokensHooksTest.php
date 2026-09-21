<?php

declare(strict_types=1);

namespace Faluss\Platform\Theme;

use PHPUnit\Framework\TestCase;

function add_action(string $hook, callable $callback): void
{
    $GLOBALS['faluss_theme_hooks'][$hook] = $callback;
}

function get_option(string $name, mixed $default): mixed
{
    $GLOBALS['faluss_theme_option_name'] = $name;

    return $GLOBALS['faluss_theme_option'] ?? $default;
}

function wp_register_style(string $handle, mixed $source, array $dependencies, mixed $version): void
{
    $GLOBALS['faluss_theme_registered_style'] = [$handle, $source, $dependencies, $version];
}

function wp_enqueue_style(string $handle): void
{
    $GLOBALS['faluss_theme_enqueued_style'] = $handle;
}

function wp_add_inline_style(string $handle, string $css): void
{
    $GLOBALS['faluss_theme_inline_style'] = [$handle, $css];
}

function register_setting(string $group, string $name, array $arguments): void
{
    $GLOBALS['faluss_theme_registered_setting'] = [$group, $name, $arguments];
}

final class ThemeTokensHooksTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['faluss_theme_hooks'] = [];
        $GLOBALS['faluss_theme_option'] = [];
    }

    public function testBootRegistersOnlyThemeHooks(): void
    {
        (new ThemeTokensModule())->boot();

        self::assertSame(
            ['wp_enqueue_scripts', 'admin_menu', 'admin_init', 'admin_enqueue_scripts'],
            array_keys($GLOBALS['faluss_theme_hooks'])
        );
    }

    public function testFrontEndUsesLegacyOptionAndStyleHandle(): void
    {
        $GLOBALS['faluss_theme_option'] = ['accent' => '#123456'];

        (new ThemeTokensModule())->enqueueTokens();

        self::assertSame('faluss_theme_tokens', $GLOBALS['faluss_theme_option_name']);
        self::assertSame(['faluss-theme-tokens', false, [], null], $GLOBALS['faluss_theme_registered_style']);
        self::assertSame('faluss-theme-tokens', $GLOBALS['faluss_theme_enqueued_style']);
        self::assertSame('faluss-theme-tokens', $GLOBALS['faluss_theme_inline_style'][0]);
        self::assertStringContainsString('--faluss-accent:#123456;', $GLOBALS['faluss_theme_inline_style'][1]);
    }

    public function testSettingsRegistrationKeepsExistingStorageKey(): void
    {
        (new ThemeTokensModule())->registerSettings();

        [$group, $name, $arguments] = $GLOBALS['faluss_theme_registered_setting'];
        self::assertSame('faluss_platform_theme', $group);
        self::assertSame('faluss_theme_tokens', $name);
        self::assertSame([ThemeTokenSet::class, 'sanitize'], $arguments['sanitize_callback']);
    }
}
