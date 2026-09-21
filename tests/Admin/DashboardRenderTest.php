<?php

declare(strict_types=1);

namespace Faluss\Platform\Admin;

use Faluss\Platform\Core\ModuleRegistry;
use Faluss\Platform\Core\SiteRole;
use PHPUnit\Framework\TestCase;

function current_user_can(string $capability): bool
{
    return $capability === 'manage_options';
}

function get_option(string $name, mixed $default): mixed
{
    $GLOBALS['faluss_dashboard_option_name'] = $name;

    return $GLOBALS['faluss_dashboard_active_plugins'] ?? $default;
}

function esc_html__(string $message, string $domain): string
{
    return htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
}

function esc_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

final class DashboardRenderTest extends TestCase
{
    public function testDashboardRendersLocalLegacyInventory(): void
    {
        $GLOBALS['faluss_dashboard_active_plugins'] = [
            'faluss-theme/faluss-theme.php',
            'faluss-identity/faluss-identity.php',
        ];

        $module = new DashboardModule(SiteRole::Me, new ModuleRegistry(SiteRole::Me));
        ob_start();

        try {
            $module->render();
            $html = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame('active_plugins', $GLOBALS['faluss_dashboard_option_name']);
        self::assertStringContainsString('faluss.me', $html);
        self::assertStringContainsString('faluss-theme/faluss-theme.php', $html);
        self::assertStringContainsString('Faluss Theme', $html);
        self::assertStringContainsString('is-active', $html);
        self::assertStringContainsString('is-inactive', $html);
        self::assertStringNotContainsString('Faluss Portal', $html);
    }
}
