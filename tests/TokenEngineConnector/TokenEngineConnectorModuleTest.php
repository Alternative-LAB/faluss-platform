<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngineConnector;

use Faluss\Platform\Core\SiteRole;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class TokenEngineConnectorModuleTest extends TestCase
{
    public function testModuleBootsTheLegacyFacadeAndPrivateAdminHooksForMe(): void
    {
        if (!defined('ABSPATH')) {
            define('ABSPATH', '/tmp/faluss-platform-tests/');
        }
        connector_test_reset();
        $module = new TokenEngineConnectorModule();

        $module->boot();

        self::assertSame('token-engine-connector', $module->id());
        self::assertSame([SiteRole::Me], $module->roles());
        self::assertSame(['admin-dashboard'], $module->dependencies());
        self::assertTrue(class_exists('Token_Engine_Connector_Service', false));
        foreach ([
            'configuration',
            'is_configured',
            'save_configuration',
            'migrate_legacy_site_url',
            'current_subject_id',
            'subject_diagnostic',
            'faluss_subject_diagnostic',
            'core_connection_test',
            'test_connection',
            'balance_for_current_subject',
            'entitlement_definitions',
            'subject_has_entitlement',
            'current_subject_has_entitlement',
            'entitlements_diagnostic',
            'daily_reward_status_for_current_subject',
            'daily_reward_offer',
            'claim_daily_reward_for_current_subject',
            'daily_reward_diagnostic',
        ] as $method) {
            self::assertTrue(method_exists('Token_Engine_Connector_Service', $method), $method);
        }
        self::assertSame('wallet.read', \Token_Engine_Connector_Service::PERMISSION_WALLET_READ);
        self::assertSame('reward.claim', \Token_Engine_Connector_Service::PERMISSION_REWARD_CLAIM);
        self::assertSame('entitlements.read', \Token_Engine_Connector_Service::PERMISSION_ENTITLEMENTS_READ);
        self::assertArrayHasKey('admin_post_token_engine_connector_save', $GLOBALS['connector_test_hooks']);
        self::assertArrayHasKey('admin_post_token_engine_connector_test_entitlements', $GLOBALS['connector_test_hooks']);
    }
}
