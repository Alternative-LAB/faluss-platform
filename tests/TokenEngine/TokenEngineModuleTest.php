<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine;

use Faluss\Platform\Core\SiteRole;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class TokenEngineModuleTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBootsTheHistoricalHubCoreWithoutCreatingData(): void
    {
        token_engine_test_reset();

        $module = new TokenEngineModule();
        $module->boot();

        self::assertSame('token-engine', $module->id());
        self::assertSame([SiteRole::Hub], $module->roles());
        self::assertSame(['admin-dashboard'], $module->dependencies());
        self::assertSame('0.4.1', TOKEN_ENGINE_VERSION);
        self::assertSame('5', \Token_Engine_Schema::VERSION);
        self::assertSame('token_engine_schema_version', \Token_Engine_Schema::OPTION);
        self::assertTrue(class_exists('Token_Engine_Service', false));
        self::assertTrue(class_exists('Token_Engine_Points_Service', false));
        self::assertTrue(class_exists('Token_Engine_Entitlements', false));
        self::assertTrue(class_exists('Token_Engine_Connector_Access', false));
        self::assertArrayHasKey('rest_api_init', $GLOBALS['token_engine_test_actions']);
        self::assertArrayHasKey('admin_post_token_engine_adjust', $GLOBALS['token_engine_test_actions']);
        self::assertArrayHasKey('admin_post_token_engine_create_entitlement_grant', $GLOBALS['token_engine_test_actions']);
        self::assertArrayNotHasKey('wp_ajax_nopriv_token_engine', $GLOBALS['token_engine_test_actions']);

        \Token_Engine_Admin::enqueue_assets('toplevel_page_token-engine');
        self::assertSame(
            'https://faluss.com/wp-content/plugins/faluss-platform/assets/css/token-engine-admin.css',
            $GLOBALS['token_engine_test_styles']['token-engine-admin']['source']
        );
        self::assertSame(
            'https://faluss.com/wp-content/plugins/faluss-platform/assets/js/token-engine-admin.js',
            $GLOBALS['token_engine_test_scripts']['token-engine-admin']['source']
        );
    }
}
