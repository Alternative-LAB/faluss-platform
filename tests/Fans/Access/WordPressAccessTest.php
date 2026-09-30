<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Access;

use Faluss\Platform\Core\SiteRole;
use PHPUnit\Framework\TestCase;

function add_filter(string $hook, callable $callback, int $priority): void { $GLOBALS['access_hooks'][$hook] = [$callback, $priority]; }
function add_action(string $hook, callable $callback, int $priority): void { add_filter($hook, $callback, $priority); }
function is_user_logged_in(): bool { return $GLOBALS['access_logged']; }
function is_admin(): bool { return $GLOBALS['access_is_admin']; }
function wp_get_current_user(): object { return (object) ['roles' => $GLOBALS['access_roles']]; }
function current_user_can(string $capability): bool { return $GLOBALS['access_manage']; }
function is_multisite(): bool { return $GLOBALS['access_multisite']; }
function is_super_admin(): bool { return $GLOBALS['access_super']; }
function wp_doing_ajax(): bool { return $GLOBALS['access_ajax']; }
function home_url(string $path): string { return 'https://fans.example.test/subdirectory' . $path; }
function nocache_headers(): void { $GLOBALS['access_nocache'] = true; }
function wp_safe_redirect(string $url, int $status, string $by): never { throw new AccessRedirect($url, $status); }
final class AccessRedirect extends \RuntimeException {}

final class WordPressAccessTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['access_hooks'] = [];
        $GLOBALS['access_roles'] = ['subscriber'];
        $GLOBALS['access_logged'] = true;
        $GLOBALS['access_is_admin'] = true;
        foreach (['manage', 'multisite', 'super', 'ajax', 'nocache'] as $flag) { $GLOBALS['access_' . $flag] = false; }
        $GLOBALS['pagenow'] = 'index.php';
    }

    public function testSiteRoleOwnsPolicyWithoutAnUiOrSsoFlag(): void
    {
        foreach ([SiteRole::Me, SiteRole::Hub] as $role) { WordPressAccess::register($role); }
        self::assertSame([], $GLOBALS['access_hooks']);
        WordPressAccess::register(SiteRole::Fans);
        self::assertSame([[WordPressAccess::class, 'adminBarVisible'], PHP_INT_MAX], $GLOBALS['access_hooks']['show_admin_bar']);
        self::assertSame([[WordPressAccess::class, 'protectAdmin'], 0], $GLOBALS['access_hooks']['init']);
        $bootstrap = file_get_contents(dirname(__DIR__, 3) . '/faluss-platform.php');
        self::assertLessThan(strpos($bootstrap, "&& defined('FALUSS_PLATFORM_FANS_SSO')"), strpos($bootstrap, 'WordPressAccess::register($role)'));
    }

    public function testEveryNonAdministratorLosesToolbarRegardlessOfPreferenceOrUrl(): void
    {
        foreach (['subscriber', 'contributor', 'author', 'editor', 'custom_manager'] as $role) {
            $GLOBALS['access_roles'] = [$role];
            $GLOBALS['access_manage'] = true; // A delegated capability is not the administrator role.
            foreach (['/', '/landing-elementor/', '/faluss-fans/fan/hof', '/?p=42'] as $path) {
                $_SERVER['REQUEST_URI'] = $path;
                self::assertFalse(WordPressAccess::adminBarVisible(true));
                self::assertFalse(WordPressAccess::adminBarVisible(false));
            }
        }
        $GLOBALS['access_logged'] = false;
        self::assertFalse(WordPressAccess::adminBarVisible(true));
        WordPressAccess::protectAdmin(); // Guest remains subject to WordPress authentication.
        self::assertFalse($GLOBALS['access_nocache']);
    }

    public function testAdministratorsAndMultisiteSuperAdminsRetainTools(): void
    {
        $GLOBALS['access_roles'] = ['administrator'];
        $GLOBALS['access_manage'] = true;
        self::assertTrue(WordPressAccess::adminBarVisible(false));
        WordPressAccess::protectAdmin();
        $GLOBALS['access_roles'] = [];
        $GLOBALS['access_manage'] = false;
        $GLOBALS['access_multisite'] = $GLOBALS['access_super'] = true;
        self::assertTrue(WordPressAccess::adminBarVisible(false));
        WordPressAccess::protectAdmin();
        self::assertFalse($GLOBALS['access_nocache']);
    }

    public function testMemberAdminScreensRedirectToPublicHomeWithoutTrustingQueryParameters(): void
    {
        $_GET = ['redirect_to' => 'https://evil.example', 'action' => 'admin-post.php'];
        foreach (['index.php', 'profile.php', 'edit.php', 'options.php', 'admin.php'] as $screen) {
            $GLOBALS['pagenow'] = $screen;
            try { WordPressAccess::protectAdmin(); self::fail('Missing redirect'); }
            catch (AccessRedirect $redirect) {
                self::assertSame(home_url('/'), $redirect->getMessage());
                self::assertSame(302, $redirect->getCode());
                self::assertTrue($GLOBALS['access_nocache']);
            }
        }
    }

    public function testSsoPostAndAjaxKeepTheirExistingPermissions(): void
    {
        foreach (['admin-post.php', 'admin-ajax.php'] as $endpoint) {
            $GLOBALS['pagenow'] = $endpoint;
            WordPressAccess::protectAdmin();
        }
        $GLOBALS['pagenow'] = 'index.php';
        $GLOBALS['access_ajax'] = true;
        WordPressAccess::protectAdmin();
        self::assertFalse($GLOBALS['access_nocache']);
    }

    public function testFrontendRestAndCallbackNeverReceiveAnAdminRedirect(): void
    {
        $GLOBALS['access_is_admin'] = false;
        foreach (['/landing/', '/wp-json/', '/faluss-fans/sso/callback'] as $path) {
            $_SERVER['REQUEST_URI'] = $path;
            WordPressAccess::protectAdmin();
        }
        self::assertFalse($GLOBALS['access_nocache']);
    }
}
