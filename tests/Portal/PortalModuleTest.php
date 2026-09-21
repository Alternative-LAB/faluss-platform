<?php

declare(strict_types=1);

namespace Faluss\Platform\Portal;

use Faluss\Platform\Core\SiteRole;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class PortalModuleTest extends TestCase
{
    public function testBootsTheHistoricalPortalSurfaceBehindExplicitDependencies(): void
    {
        portal_test_reset();

        $module = new PortalModule();
        $module->boot();

        self::assertSame('portal', $module->id());
        self::assertSame([SiteRole::Hub], $module->roles());
        self::assertSame(['admin-dashboard', 'apps-registry', 'identity-client'], $module->dependencies());
        self::assertTrue(class_exists('Faluss_Portal', false));
        self::assertTrue(class_exists('Faluss_Portal_Manifest', false));
        self::assertTrue(class_exists('Faluss_Portal_Apps_Registry_Adapter', false));
        self::assertTrue(class_exists('Faluss_Portal_Events_Catalog', false));
        self::assertTrue(class_exists('Faluss_Portal_Events_Runtime', false));
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Portal/LegacyPortalService.php');
        self::assertIsString($source);
        self::assertStringContainsString("add_action( 'wp_ajax_' . self::HUB_DAILY_ACTION", $source);
        self::assertStringNotContainsString('wp_ajax_nopriv_', $source);
        self::assertStringContainsString('add_shortcode( self::SHORTCODE', $source);
    }

    public function testIdentityContractReturnsOnlyTheCurrentLinkedSubscriberProjection(): void
    {
        portal_test_reset();
        $GLOBALS['identity_client_test_logged_in'] = true;
        $GLOBALS['identity_client_test_current_user'] = new \WP_User(7, ['subscriber']);
        $GLOBALS['identity_client_test_users'][7] = $GLOBALS['identity_client_test_current_user'];
        $GLOBALS['wpdb']->linkedUserId = 7;
        $GLOBALS['wpdb']->subjectRow = [
            'faluss_id' => '11111111-1111-4111-8111-111111111111',
            'created_at' => '2026-01-02 03:04:05',
            'last_proved_at' => '2026-02-03 04:05:06',
        ];

        self::assertSame(
            [
                'faluss_id' => '11111111-1111-4111-8111-111111111111',
                'created_at' => '2026-01-02 03:04:05',
                'last_proved_at' => '2026-02-03 04:05:06',
            ],
            \Faluss\Platform\IdentityClient\IdentityClientService::currentLinkedSubject()
        );

        $GLOBALS['identity_client_test_current_user'] = new \WP_User(7, ['administrator']);
        $GLOBALS['identity_client_test_users'][7] = $GLOBALS['identity_client_test_current_user'];
        self::assertNull(\Faluss\Platform\IdentityClient\IdentityClientService::currentLinkedSubject());
    }
}
