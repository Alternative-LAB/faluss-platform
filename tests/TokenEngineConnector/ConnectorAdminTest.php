<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngineConnector;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class ConnectorAdminTest extends TestCase
{
    protected function setUp(): void
    {
        connector_test_reset();
        $_POST = [];
    }

    public function testRegistersEveryHistoricalPrivateAction(): void
    {
        ConnectorAdmin::boot();

        self::assertArrayHasKey('admin_post_token_engine_connector_save', $GLOBALS['connector_test_hooks']);
        self::assertArrayHasKey('admin_post_token_engine_connector_test', $GLOBALS['connector_test_hooks']);
        self::assertArrayHasKey('admin_post_token_engine_connector_test_core', $GLOBALS['connector_test_hooks']);
        self::assertArrayHasKey('admin_post_token_engine_connector_test_subject', $GLOBALS['connector_test_hooks']);
        self::assertArrayHasKey('admin_post_token_engine_connector_test_daily_reward', $GLOBALS['connector_test_hooks']);
        self::assertArrayHasKey('admin_post_token_engine_connector_test_entitlements', $GLOBALS['connector_test_hooks']);
    }

    public function testSaveRejectsAnInvalidNonceBeforeReadingConfiguration(): void
    {
        $_POST['token_engine_connector_nonce'] = 'invalid';
        $GLOBALS['connector_test_nonce_valid'] = false;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Accès refusé.');

        ConnectorAdmin::save();
    }
}
