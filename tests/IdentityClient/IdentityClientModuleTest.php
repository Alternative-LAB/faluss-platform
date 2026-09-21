<?php

declare(strict_types=1);

namespace Faluss\Platform\IdentityClient;

use Faluss\Platform\Core\SiteRole;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class IdentityClientModuleTest extends TestCase
{
    public function testBootsOnlyForHubWithTheHistoricalContracts(): void
    {
        if (!defined('ABSPATH')) {
            define('ABSPATH', '/tmp/faluss-platform-tests/');
        }
        identity_client_test_reset();
        $module = new IdentityClientModule();

        $module->boot();

        self::assertSame('identity-client', $module->id());
        self::assertSame([SiteRole::Hub], $module->roles());
        self::assertSame(['admin-dashboard'], $module->dependencies());
        self::assertTrue(class_exists('Faluss_Identity_Client', false));
        self::assertTrue(class_exists('Faluss_Identity_Client_Schema', false));
        self::assertTrue(class_exists('Faluss_Identity_Client_Apps_Registry_Adapter', false));
        self::assertTrue(method_exists('Faluss_Identity_Client', 'member_app_projection'));
        self::assertSame(600, \Faluss_Identity_Client::TTL);
        self::assertSame(3600, \Faluss_Identity_Client::MEMBER_SESSION_TTL);
        self::assertArrayHasKey('admin_post_faluss_identity_client_start', $GLOBALS['identity_client_test_hooks']);
        self::assertArrayHasKey('admin_post_nopriv_faluss_identity_client_continue', $GLOBALS['identity_client_test_hooks']);
        self::assertArrayHasKey('template_redirect', $GLOBALS['identity_client_test_hooks']);
        self::assertArrayHasKey('auth_cookie_expiration', $GLOBALS['identity_client_test_filters']);
        self::assertArrayHasKey('faluss_identity_client_button', $GLOBALS['identity_client_test_shortcodes']);
    }
}
