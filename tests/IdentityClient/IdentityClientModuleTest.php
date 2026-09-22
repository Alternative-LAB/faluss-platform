<?php

declare(strict_types=1);

namespace Faluss\Platform\IdentityClient;

use Faluss\Platform\AppsRegistry\AppsRegistryService;
use Faluss\Platform\Core\SiteRole;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class IdentityClientModuleTest extends TestCase
{
    public function testRegistersRegistrySourceImmediatelyAfterPluginsLoadedFinished(): void
    {
        identity_client_test_reset();
        require_once dirname(__DIR__, 2) . '/src/AppsRegistry/LegacyAppsRegistryFacades.php';
        $booted = new \ReflectionProperty(IdentityClientAppsRegistryAdapter::class, 'booted');
        $booted->setValue(null, false);
        $sources = new \ReflectionProperty(AppsRegistryService::class, 'sources');
        $sources->setValue(null, []);
        $conflict = new \ReflectionProperty(AppsRegistryService::class, 'sourceConflict');
        $conflict->setValue(null, false);

        IdentityClientAppsRegistryAdapter::boot();
        IdentityClientAppsRegistryAdapter::boot();

        self::assertCount(1, $sources->getValue());
        self::assertFalse($conflict->getValue());
        self::assertArrayNotHasKey('plugins_loaded', $GLOBALS['identity_client_test_hooks']);
    }

    public function testDefersRegistrySourceWhilePluginsLoadedIsStillRunning(): void
    {
        identity_client_test_reset();
        $GLOBALS['identity_client_test_doing_plugins_loaded'] = true;
        $booted = new \ReflectionProperty(IdentityClientAppsRegistryAdapter::class, 'booted');
        $booted->setValue(null, false);
        require_once dirname(__DIR__, 2) . '/src/AppsRegistry/LegacyAppsRegistryFacades.php';
        $sources = new \ReflectionProperty(AppsRegistryService::class, 'sources');
        $sources->setValue(null, []);
        $conflict = new \ReflectionProperty(AppsRegistryService::class, 'sourceConflict');
        $conflict->setValue(null, false);

        IdentityClientAppsRegistryAdapter::boot();
        IdentityClientAppsRegistryAdapter::boot();

        self::assertSame([], $sources->getValue());
        self::assertSame(
            40,
            $GLOBALS['identity_client_test_hooks']['plugins_loaded']['priority']
        );
        self::assertSame(
            [IdentityClientAppsRegistryAdapter::class, 'registerSource'],
            $GLOBALS['identity_client_test_hooks']['plugins_loaded']['callback']
        );
        ($GLOBALS['identity_client_test_hooks']['plugins_loaded']['callback'])();
        self::assertCount(1, $sources->getValue());
        self::assertFalse($conflict->getValue());
    }

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
        self::assertTrue(method_exists('Faluss_Identity_Client', 'current_linked_subject'));
        self::assertSame(600, \Faluss_Identity_Client::TTL);
        self::assertSame(3600, \Faluss_Identity_Client::MEMBER_SESSION_TTL);
        self::assertArrayHasKey('admin_post_faluss_identity_client_start', $GLOBALS['identity_client_test_hooks']);
        self::assertArrayHasKey('admin_post_nopriv_faluss_identity_client_continue', $GLOBALS['identity_client_test_hooks']);
        self::assertArrayHasKey('template_redirect', $GLOBALS['identity_client_test_hooks']);
        self::assertArrayHasKey('auth_cookie_expiration', $GLOBALS['identity_client_test_filters']);
        self::assertArrayHasKey('faluss_identity_client_button', $GLOBALS['identity_client_test_shortcodes']);
    }
}
