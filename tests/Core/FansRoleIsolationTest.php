<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Core;

use Faluss\Platform\Analytics\AnalyticsModule;
use Faluss\Platform\AppsRegistry\AppsRegistryModule;
use Faluss\Platform\Catalog\CatalogModule;
use Faluss\Platform\Core\Module;
use Faluss\Platform\Core\ModuleRegistry;
use Faluss\Platform\Core\SiteRole;
use Faluss\Platform\Events\EventsModule;
use Faluss\Platform\Federation\FederationModule;
use Faluss\Platform\Fans\Sso\FansSsoModule;
use Faluss\Platform\Fans\Profiles\CreatorProfilesModule;
use Faluss\Platform\Fans\Followers\FollowersModule;
use Faluss\Platform\Fans\Store\StoreCatalogModule;
use Faluss\Platform\Identity\IdentityModule;
use Faluss\Platform\IdentityClient\IdentityClientModule;
use Faluss\Platform\Link\LinkModule;
use Faluss\Platform\MeStudio\MeStudioModule;
use Faluss\Platform\Portal\PortalModule;
use Faluss\Platform\Subscriptions\SubscriptionsModule;
use Faluss\Platform\Theme\ThemeTokensModule;
use Faluss\Platform\TokenEngine\TokenEngineModule;
use Faluss\Platform\TokenEngineConnector\TokenEngineConnectorModule;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class FansRoleIsolationTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTextActivationCannotInstallOnMeWithAccidentalFlags(): void
    {
        $this->assertTextModuleIsolated('me');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTextActivationCannotInstallOnHubWithAccidentalFlags(): void
    {
        $this->assertTextModuleIsolated('hub');
    }

    private function assertTextModuleIsolated(string $role): void
    {
        define('FALUSS_PLATFORM_ROLE', $role);
        foreach (['FALUSS_PLATFORM_FANS_SSO', 'FALUSS_PLATFORM_FANS_CREATOR_PROFILES', 'FALUSS_PLATFORM_FANS_TEXT_PUBLICATIONS', 'FALUSS_PLATFORM_FANS_IMAGES'] as $flag) { define($flag, true); }
        // No WordPress database exists here: touching schema installation would fail the test.
        $module = new \Faluss\Platform\Fans\Publications\TextPublicationsModule();
        self::assertSame([SiteRole::Fans], $module->roles());
        self::assertSame(['fans-creator-profiles'], $module->dependencies());
        self::assertFalse($module::enabled());
        $module::activate();
        $images = new \Faluss\Platform\Fans\Images\ImagesModule();
        self::assertFalse($images::enabled());
        $images::activate();
    }

    public function testOnlyTheSiteAdministrationCanBootWhenEveryExistingModuleIsRegistered(): void
    {
        $booted = [];
        $registry = new ModuleRegistry(SiteRole::Fans);
        $registry->register(new class ($booted) implements Module {
            /** @param list<string> $booted */
            public function __construct(private array &$booted) {}
            public function id(): string { return 'admin-dashboard'; }
            public function roles(): array { return [SiteRole::Me, SiteRole::Hub, SiteRole::Fans]; }
            public function dependencies(): array { return []; }
            public function boot(): void { $this->booted[] = 'admin-dashboard'; }
        });

        foreach (self::existingModules() as $module) {
            self::assertNotContains(SiteRole::Fans, $module->roles(), $module->id());
            $registry->register($module);
        }

        $registry->boot();
        self::assertSame(['admin-dashboard'], $registry->activeModuleIds());
        self::assertSame(['admin-dashboard'], $booted);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAccidentalModuleFlagsCannotRunActivationHooksOnFans(): void
    {
        require_once __DIR__ . '/fixtures/FansBootstrapWordPressStubs.php';
        define('FALUSS_PLATFORM_ROLE', 'fans');
        foreach ([
            'FALUSS_PLATFORM_SUBSCRIPTIONS',
            'FALUSS_PLATFORM_TOKEN_ENGINE',
            'FALUSS_PLATFORM_IDENTITY',
            'FALUSS_PLATFORM_FEDERATION',
            'FALUSS_PLATFORM_EVENTS',
            'FALUSS_PLATFORM_ANALYTICS',
            'FALUSS_PLATFORM_LINK',
        ] as $flag) {
            define($flag, true);
        }

        require dirname(__DIR__, 2) . '/faluss-platform.php';
        self::assertCount(13, $GLOBALS['fans_test_activation_hooks']);
        self::assertSame([
            SubscriptionsModule::class,
            TokenEngineModule::class,
            IdentityModule::class,
            FederationModule::class,
            EventsModule::class,
            AnalyticsModule::class,
            LinkModule::class,
            FansSsoModule::class,
            CreatorProfilesModule::class,
            FollowersModule::class,
            StoreCatalogModule::class,
            \Faluss\Platform\Fans\Publications\TextPublicationsModule::class,
            \Faluss\Platform\Fans\Images\ImagesModule::class,
        ], array_map(static fn (array $callback): string => $callback[0], $GLOBALS['fans_test_activation_hooks']));
        self::assertCount(5, $GLOBALS['fans_test_deactivation_hooks']);
        self::assertCount(1, $GLOBALS['fans_test_actions']['plugins_loaded']);
        ($GLOBALS['fans_test_actions']['plugins_loaded'][0])();

        foreach ($GLOBALS['fans_test_activation_hooks'] as $callback) {
            $callback();
        }
        foreach ($GLOBALS['fans_test_deactivation_hooks'] as $callback) {
            $callback();
        }

        foreach ([
            'Faluss_Subscriptions_Schema',
            'Token_Engine_Schema',
            'Faluss_Identity_Schema',
            'Faluss_Federation_Schema',
            'Faluss_Events_Schema',
            'Faluss_Analytics_Schema',
            'Faluss_Link_Schema',
        ] as $schema) {
            self::assertFalse(class_exists($schema, false), $schema . ' must not load on Fans.');
        }
    }

    /** @return list<Module> */
    private static function existingModules(): array
    {
        return [
            new FederationModule(), new ThemeTokensModule(), new CatalogModule(),
            new TokenEngineConnectorModule(), new IdentityModule(), new EventsModule(),
            new AnalyticsModule(), new LinkModule(), new AppsRegistryModule(),
            new MeStudioModule(), new IdentityClientModule(), new SubscriptionsModule(),
            new TokenEngineModule(), new PortalModule(),
        ];
    }
}
