<?php

declare(strict_types=1);

namespace Faluss\Platform\Identity;

use Faluss\Platform\Core\SiteRole;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class IdentityModuleTest extends TestCase
{
    public function testBootstrapRequiresAnExplicitMeOptInAndNoLoadedAuthority(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/faluss-platform.php');

        self::assertIsString($source);
        self::assertStringContainsString("defined('FALUSS_PLATFORM_IDENTITY')", $source);
        self::assertStringContainsString("constant('FALUSS_PLATFORM_IDENTITY') === true", $source);
        self::assertStringContainsString("!class_exists('Faluss_Identity_Schema', false)", $source);
        self::assertStringContainsString('[\\Faluss\\Platform\\Identity\\IdentityModule::class, \'activate\']', $source);
        self::assertStringContainsString('[\\Faluss\\Platform\\Identity\\IdentityModule::class, \'deactivate\']', $source);
    }

    public function testNormalBootNeverRunsPrivilegedSchemaMigrations(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Identity/IdentityModule.php');

        self::assertIsString($source);
        self::assertStringContainsString('private static function schemaIsReady()', $source);
        self::assertStringContainsString('Faluss_Identity_Schema::get_status()', $source);
        self::assertStringNotContainsString('Faluss_Identity_Schema::install_or_verify()', $source);
        self::assertStringNotContainsString('Faluss_Identity_Schema::migrate_fi02()', $source);
        self::assertStringNotContainsString('Faluss_Identity_Schema::migrate_fi06_sso()', $source);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnonymousBootGateAcceptsOnlyTheReadyCurrentSchemaWithoutWriting(): void
    {
        identity_test_reset();
        eval('namespace { function get_option($name, $default = false) { unset($name, $default); return $GLOBALS["identity_schema_version"]; } final class Faluss_Identity_Schema { public const OPTION_VERSION = "faluss_identity_schema_version"; public const VERSION = "6"; public static int $statusCalls = 0; public static function get_status(): array { ++self::$statusCalls; return $GLOBALS["identity_schema_status"]; } } }');
        $method = new \ReflectionMethod(IdentityModule::class, 'schemaIsReady');

        $GLOBALS['identity_schema_version'] = '6';
        $GLOBALS['identity_schema_status'] = ['ready' => true, 'code' => 'fi_schema_ready'];
        self::assertTrue($method->invoke(null));
        self::assertSame(1, \Faluss_Identity_Schema::$statusCalls);

        $GLOBALS['identity_schema_version'] = '5';
        self::assertFalse($method->invoke(null));
        self::assertSame(1, \Faluss_Identity_Schema::$statusCalls);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBootsTheHistoricalMeAuthorityAndPreservesItsSurfaces(): void
    {
        identity_test_reset();

        $module = new IdentityModule();
        $module->boot();

        self::assertSame('identity', $module->id());
        self::assertSame([SiteRole::Me], $module->roles());
        self::assertSame(['admin-dashboard'], $module->dependencies());
        self::assertSame('0.4.15', FALUSS_IDENTITY_VERSION);
        self::assertSame('6', \Faluss_Identity_Schema::VERSION);
        self::assertTrue(class_exists('Faluss_Identity_Registry', false));
        self::assertTrue(class_exists('Faluss_Identity_Passwordless', false));
        self::assertTrue(class_exists('Faluss_Identity_Authorization', false));
        self::assertArrayHasKey('init', $GLOBALS['identity_test_actions']);
        self::assertArrayHasKey('elementor/widgets/register', $GLOBALS['identity_test_actions']);
        self::assertArrayHasKey('auth_cookie_expiration', $GLOBALS['identity_test_filters']);
        self::assertArrayHasKey('show_admin_bar', $GLOBALS['identity_test_filters']);

        \Faluss_Identity_Passwordless::register_assets();
        self::assertSame(
            'https://faluss.me/wp-content/plugins/faluss-platform/assets/css/faluss-identity-passwordless.css',
            $GLOBALS['identity_test_styles']['faluss-identity-passwordless']['source']
        );
        self::assertSame(
            'https://faluss.me/wp-content/plugins/faluss-platform/assets/js/faluss-identity-passwordless-login.js',
            $GLOBALS['identity_test_scripts']['faluss-identity-passwordless-login']['source']
        );
    }
}
