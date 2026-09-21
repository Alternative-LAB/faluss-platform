<?php

declare(strict_types=1);

namespace Faluss\Platform\Catalog;

use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/WordPressStubs.php';

function add_action(string $hook, callable $callback): void
{
    $GLOBALS['catalog_test_hooks'][$hook] = $callback;
}

function current_user_can(string $capability): bool
{
    return $capability === 'manage_options' && $GLOBALS['catalog_test_admin'];
}

function wp_verify_nonce(string $nonce, string $action): bool
{
    return $nonce === 'valid-nonce' && str_starts_with($action, 'faluss_catalog_');
}

function wp_unslash(mixed $value): mixed
{
    return $value;
}

function esc_html__(string $text, string $domain): string
{
    return $text;
}

function wp_die(string $message): never
{
    throw new RuntimeException($message);
}

function get_option(string $name, mixed $default): mixed
{
    return $GLOBALS['catalog_test_option'] ?? $default;
}

function update_option(string $name, mixed $value, bool $autoload): void
{
    $GLOBALS['catalog_test_option'] = $value;
    $GLOBALS['catalog_test_writes'][] = $name;
}

function do_action(string $hook, string $slug): void
{
    $GLOBALS['catalog_test_events'][] = [$hook, $slug];
}

function sanitize_key(string $value): string
{
    return $value;
}

function admin_url(string $path): string
{
    return '/wp-admin/' . $path;
}

function add_query_arg(string $name, string $value, string $url): string
{
    return $url . '&' . $name . '=' . $value;
}

function wp_safe_redirect(string $url): never
{
    throw new RuntimeException($url);
}

final class CatalogAdminActionsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalPost;

    protected function setUp(): void
    {
        $this->originalPost = $_POST;
        $_POST = [];
        $GLOBALS['catalog_test_admin'] = true;
        $GLOBALS['catalog_test_option'] = [];
        $GLOBALS['catalog_test_writes'] = [];
        $GLOBALS['catalog_test_events'] = [];
        $GLOBALS['catalog_test_hooks'] = [];
    }

    protected function tearDown(): void
    {
        $_POST = $this->originalPost;
    }

    public function testRegistersTheLegacyActionNames(): void
    {
        (new CatalogAdminActions(new CatalogEntitlementProvider()))->boot();

        self::assertSame([
            'admin_post_faluss_catalog_create_theme',
            'admin_post_faluss_catalog_update_theme',
            'admin_post_faluss_catalog_delete_theme',
        ], array_keys($GLOBALS['catalog_test_hooks']));
    }

    public function testCreatesThemeAfterCapabilityAndNonceChecks(): void
    {
        $_POST = $this->input();

        try {
            (new CatalogAdminActions(new CatalogEntitlementProvider()))->create();
            self::fail('Expected redirect.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('faluss_catalog_notice=created', $exception->getMessage());
        }

        self::assertSame([CatalogThemeReader::OPTION], $GLOBALS['catalog_test_writes']);
        self::assertArrayHasKey('evening', $GLOBALS['catalog_test_option']);
        self::assertSame([], $GLOBALS['catalog_test_events']);
    }

    public function testDeactivationEmitsTheLegacyEvent(): void
    {
        $GLOBALS['catalog_test_option'] = ['evening' => ['name' => 'Evening', 'active' => 1]];
        $_POST = $this->input(['action' => 'faluss_catalog_update_theme', 'theme_slug' => 'evening', 'active' => '0']);

        try {
            (new CatalogAdminActions(new CatalogEntitlementProvider()))->update();
            self::fail('Expected redirect.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('faluss_catalog_notice=updated', $exception->getMessage());
        }

        self::assertSame([['faluss_catalog_theme_deactivated', 'evening']], $GLOBALS['catalog_test_events']);
    }

    public function testBadNoncePreventsAnyWrite(): void
    {
        $_POST = $this->input(['faluss_catalog_nonce' => 'invalid']);

        $this->expectExceptionMessage('Accès refusé.');

        try {
            (new CatalogAdminActions(new CatalogEntitlementProvider()))->create();
        } finally {
            self::assertSame([], $GLOBALS['catalog_test_writes']);
        }
    }

    public function testDeleteEmitsTheLegacyEvent(): void
    {
        $GLOBALS['catalog_test_option'] = ['evening' => ['name' => 'Evening', 'active' => 0]];
        $_POST = [
            'faluss_catalog_nonce' => 'valid-nonce',
            'theme_slug' => 'evening',
        ];

        try {
            (new CatalogAdminActions(new CatalogEntitlementProvider()))->delete();
            self::fail('Expected redirect.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('faluss_catalog_notice=deleted', $exception->getMessage());
        }

        self::assertSame([], $GLOBALS['catalog_test_option']);
        self::assertSame([['faluss_catalog_theme_deactivated', 'evening']], $GLOBALS['catalog_test_events']);
    }

    public function testMissingCapabilityPreventsAnyWrite(): void
    {
        $GLOBALS['catalog_test_admin'] = false;
        $_POST = $this->input();

        $this->expectExceptionMessage('Accès refusé.');

        try {
            (new CatalogAdminActions(new CatalogEntitlementProvider()))->create();
        } finally {
            self::assertSame([], $GLOBALS['catalog_test_writes']);
        }
    }

    /** @param array<string, mixed> $changes
     *  @return array<string, mixed>
     */
    private function input(array $changes = []): array
    {
        return array_replace([
            'faluss_catalog_nonce' => 'valid-nonce',
            'name' => 'Evening',
            'active' => '1',
            'sort_order' => '10',
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
