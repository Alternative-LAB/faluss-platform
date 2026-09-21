<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngineConnector;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class ConnectorServiceTest extends TestCase
{
    protected function setUp(): void
    {
        connector_test_reset();
    }

    public function testCanonicalSiteUrlMigrationRejectsUnsafeInput(): void
    {
        self::assertSame(
            'https://www.faluss.com/subdir',
            ConnectorService::migrateLegacySiteUrl('https://www.faluss.com/subdir/wp-json/token-engine/v1/')
        );
        self::assertSame(
            'https://faluss.com',
            ConnectorService::migrateLegacySiteUrl('https://faluss.com/index.php?rest_route=/token-engine/v1/')
        );
        self::assertSame('', ConnectorService::migrateLegacySiteUrl('http://faluss.com/wp-json/token-engine/v1/'));
        self::assertSame('', ConnectorService::migrateLegacySiteUrl('https://user:pass@faluss.com'));
        self::assertSame('', ConnectorService::migrateLegacySiteUrl('https://user@faluss.com'));
    }

    public function testConfigurationProtectsAndPreservesTheSecret(): void
    {
        $saved = ConnectorService::saveConfiguration([
            'core_site_url' => 'https://www.faluss.com',
            'client_id' => 'tec_12345678901234567890',
            'project_key' => 'faluss-link',
            'client_secret' => 'a-local-test-secret',
        ]);

        self::assertIsArray($saved);
        self::assertTrue($saved['secret_configured']);
        self::assertArrayNotHasKey('secret_protected', $saved);
        $protected = $GLOBALS['connector_test_options'][ConnectorService::OPTION]['secret_protected'];
        self::assertNotSame('a-local-test-secret', $protected);

        $resaved = ConnectorService::saveConfiguration([
            'core_site_url' => 'https://www.faluss.com',
            'client_id' => 'tec_12345678901234567890',
            'project_key' => 'faluss-link',
            'client_secret' => '',
        ]);

        self::assertIsArray($resaved);
        self::assertSame($protected, $GLOBALS['connector_test_options'][ConnectorService::OPTION]['secret_protected']);
    }

    public function testBearerCacheExpiresAndRenewsAfterARejectedCachedToken(): void
    {
        $this->configure();
        $GLOBALS['connector_test_remote_queue'] = [
            connector_test_response($this->token('a')),
            connector_test_response($this->diagnostic()),
            connector_test_response($this->diagnostic()),
            connector_test_response(['code' => 'connector_token_rejected'], 401),
            connector_test_response($this->token('b')),
            connector_test_response($this->diagnostic()),
        ];

        self::assertIsArray(ConnectorService::coreConnectionTest());
        self::assertIsArray(ConnectorService::coreConnectionTest());
        self::assertCount(3, $GLOBALS['connector_test_remote_calls']);

        self::assertIsArray(ConnectorService::coreConnectionTest());
        self::assertCount(6, $GLOBALS['connector_test_remote_calls']);

        $GLOBALS['connector_test_remote_queue'] = [
            connector_test_response($this->token('c')),
            connector_test_response($this->diagnostic()),
        ];
        $GLOBALS['connector_test_time'] += 271;

        self::assertIsArray(ConnectorService::coreConnectionTest());
        self::assertCount(8, $GLOBALS['connector_test_remote_calls']);
    }

    public function testMalformedTokenResponseFailsClosed(): void
    {
        $this->configure();
        $GLOBALS['connector_test_remote_queue'] = [
            connector_test_response([
                'access_token' => 'short',
                'token_type' => 'Basic',
                'expires_in' => 300,
                'permissions' => ['wallet.read'],
                'protocol_version' => '1',
            ]),
        ];

        $result = ConnectorService::coreConnectionTest();

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('connector_token_rejected', $result->get_error_code());
    }

    public function testMissingPermissionFailsBeforeTheProtectedRoute(): void
    {
        $this->configure();
        $token = $this->token('p');
        $token['permissions'] = ['reward.claim'];
        $GLOBALS['connector_test_remote_queue'] = [connector_test_response($token)];

        $result = ConnectorService::coreConnectionTest();

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('connector_permission_wallet_read_missing', $result->get_error_code());
        self::assertCount(1, $GLOBALS['connector_test_remote_calls']);
    }

    public function testNetworkFailureIsReducedToANonSensitiveClosedError(): void
    {
        $this->configure();
        $GLOBALS['connector_test_remote_queue'] = [new \WP_Error('transport-details', 'private')];

        $result = ConnectorService::coreConnectionTest();

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('connector_core_inaccessible', $result->get_error_code());
    }

    public function testEntitlementDecisionRequiresAnExactBooleanSchema(): void
    {
        $this->configure();
        $GLOBALS['connector_test_remote_queue'] = [
            connector_test_response($this->token('e')),
            connector_test_response([
                'project_key' => 'faluss-link',
                'entitlement_code' => 'theme.premium',
                'granted' => 1,
            ]),
        ];

        $result = ConnectorService::subjectHasEntitlement(
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'theme.premium'
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('connector_entitlements_unavailable', $result->get_error_code());
    }

    private function configure(): void
    {
        $protected = ConnectorCrypto::encrypt('a-local-test-secret');
        self::assertIsString($protected);
        $GLOBALS['connector_test_options'][ConnectorService::OPTION] = [
            'core_site_url' => 'https://www.faluss.com',
            'client_id' => 'tec_12345678901234567890',
            'project_key' => 'faluss-link',
            'secret_protected' => $protected,
        ];
    }

    /** @return array<string, mixed> */
    private function token(string $character): array
    {
        return [
            'access_token' => str_repeat($character, 32),
            'token_type' => 'Bearer',
            'expires_in' => 300,
            'permissions' => ['wallet.read', 'reward.claim', 'entitlements.read'],
            'protocol_version' => '1',
            'diagnostic_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        ];
    }

    /** @return array<string, mixed> */
    private function diagnostic(): array
    {
        return [
            'engine' => 'token-engine',
            'protocol_version' => '1',
            'connected' => true,
            'project_key' => 'faluss-link',
            'permissions' => ['wallet.read', 'reward.claim', 'entitlements.read'],
            'diagnostic_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        ];
    }
}
