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

if (! function_exists(__NAMESPACE__ . '\\add_action')) {
    function add_action(string $hook, callable $callback): void
    {
        $GLOBALS['faluss_dashboard_hooks'][$hook] = $callback;
    }
}

function esc_html__(string $message, string $domain): string
{
    return htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
}

function esc_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function esc_url(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function admin_url(string $path): string
{
    return 'https://example.test/wp-admin/' . $path;
}

function wp_nonce_field(string $action): void
{
    echo '<input type="hidden" name="_wpnonce" value="' . esc_html($action) . '">';
}

function sanitize_key(string $value): string
{
    return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)) ?? '';
}

function wp_unslash(string $value): string
{
    return $value;
}

final class DashboardRenderTest extends TestCase
{
    public function testDashboardRendersModuleCardsWithoutLegacyInventory(): void
    {
        $registry = new ModuleRegistry(SiteRole::Me);
        $module = new DashboardModule(SiteRole::Me, $registry);
        $registry->register($module);
        $registry->boot();
        ob_start();

        try {
            $module->render();
            $html = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertStringContainsString('faluss.me', $html);
        self::assertStringContainsString('Administration Faluss', $html);
        self::assertStringContainsString('Vue d’ensemble, diagnostics et réglages communs du site.', $html);
        self::assertStringContainsString('faluss-admin__module-cards', $html);
        self::assertStringContainsString('is-active', $html);
        self::assertStringNotContainsString('Inventaire des plugins historiques', $html);
        self::assertStringNotContainsString('faluss-theme/faluss-theme.php', $html);
        self::assertStringContainsString('Mises à jour privées', $html);
        self::assertStringContainsString('Licence absente', $html);
        self::assertStringContainsString('updates.faluss.com', $html);
        self::assertStringNotContainsString('Faluss Portal', $html);
    }

    public function testDashboardNamesFansWithoutBorrowingTheMeOrHubSite(): void
    {
        $registry = new ModuleRegistry(SiteRole::Fans);
        $module = new DashboardModule(SiteRole::Fans, $registry);
        $registry->register($module);
        $registry->boot();
        ob_start();

        try {
            $module->render();
            $html = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertStringContainsString('Faluss Fans', $html);
        self::assertStringNotContainsString('class="faluss-admin__value">faluss.me', $html);
        self::assertStringNotContainsString('class="faluss-admin__value">faluss.com', $html);
        self::assertStringContainsString('Administration Faluss', $html);
    }

    public function testDashboardStillNamesTheHubSite(): void
    {
        $registry = new ModuleRegistry(SiteRole::Hub);
        $module = new DashboardModule(SiteRole::Hub, $registry);
        $registry->register($module);
        $registry->boot();
        ob_start();

        try {
            $module->render();
            $html = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertStringContainsString('class="faluss-admin__value">faluss.com', $html);
    }
}
