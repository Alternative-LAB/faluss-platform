<?php

declare(strict_types=1);

namespace Faluss\Platform\IdentityClient;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once __DIR__ . '/WordPressStubs.php';

final class IdentityClientSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        identity_client_test_reset();
        $GLOBALS['identity_client_test_options'][IdentityClientService::SETTINGS] = [
            'enabled' => true,
            'authority' => 'https://faluss.me',
            'client_id' => 'faluss-hub',
            'return_urls' => ['https://faluss.com/mon-faluss/'],
        ];
    }

    public function testReturnTargetsStayExactAndSameOrigin(): void
    {
        $allowed = self::privateMethod('allowedReturn');

        self::assertSame('https://faluss.com/mon-faluss/', $allowed->invoke(null, 'https://faluss.com/mon-faluss/'));
        self::assertSame('https://faluss.com/', $allowed->invoke(null, 'https://faluss.com/mon-faluss/?next=evil'));
        self::assertSame('https://faluss.com/', $allowed->invoke(null, 'https://attacker.invalid/mon-faluss/'));
    }

    public function testPkceUsesTheEncodedVerifierAndS256(): void
    {
        $verifierBytes = str_repeat("v", 32);
        $encoded = rtrim(strtr(base64_encode($verifierBytes), '+/', '-_'), '=');
        $expected = rtrim(strtr(base64_encode(hash('sha256', $encoded, true)), '+/', '-_'), '=');

        self::assertSame($expected, self::privateMethod('pkceChallenge')->invoke(null, $verifierBytes));
        self::assertSame(43, strlen($expected));
    }

    public function testStateIsBrowserBoundAndConsumedOnlyOnceInATransaction(): void
    {
        $state = str_repeat('s', 32);
        $verifier = str_repeat('v', 32);
        $browser = str_repeat('b', 32);
        $_COOKIE[IdentityClientService::COOKIE] = self::base64url($state . $verifier . $browser);
        $database = $GLOBALS['wpdb'];
        self::assertInstanceOf(IdentityClientWpdbStub::class, $database);
        $database->expectedBrowserHash = hash_hmac('sha256', $browser, wp_salt('faluss_identity_client_state'));
        $database->stateRow = [
            'id' => 9,
            'redirect_url' => 'https://faluss.com/mon-faluss/',
            'flow_mode' => 'login',
            'wp_user_id' => null,
            'expires_at' => '2099-01-01 00:00:00',
            'consumed_at' => null,
        ];

        $consumed = self::privateMethod('consumeState')->invoke(null, self::base64url($state));

        self::assertIsArray($consumed);
        self::assertSame(self::base64url($verifier), $consumed['verifier']);
        self::assertContains('START TRANSACTION', $database->queries);
        self::assertStringContainsString('FOR UPDATE', $database->prepared[0]['query']);
        self::assertStringContainsString('consumed_at IS NULL', $database->prepared[1]['query']);
        self::assertContains('COMMIT', $database->queries);

        $database->queries = [];
        $database->stateRow['consumed_at'] = '2026-09-21 00:00:00';
        self::assertNull(self::privateMethod('consumeState')->invoke(null, self::base64url($state)));
        self::assertContains('ROLLBACK', $database->queries);
    }

    public function testTokenExchangeAcceptsOnlyBoundedIdentityClaims(): void
    {
        $GLOBALS['identity_client_test_remote_queue'] = [identity_client_test_response([
            'faluss_id' => '11111111-1111-4111-8111-111111111111',
            'scope' => 'identity.basic identity.email',
            'email' => 'member@example.test',
            'apps' => ['me' => [
                'contract_version' => '1',
                'publication_status' => 'published',
                'canonical_url' => 'https://faluss.me/mon-faluss',
            ]],
        ])];

        $claims = self::privateMethod('exchange')->invoke(null, str_repeat('c', 43), str_repeat('p', 43));

        self::assertIsArray($claims);
        self::assertSame('11111111-1111-4111-8111-111111111111', $claims['faluss_id']);
        self::assertSame('authorization_code', $GLOBALS['identity_client_test_remote_calls'][0]['args']['body']['grant_type']);

        $GLOBALS['identity_client_test_remote_queue'] = [identity_client_test_response([
            'faluss_id' => 'not-a-uuid',
            'scope' => 'identity.basic',
        ])];
        self::assertNull(self::privateMethod('exchange')->invoke(null, str_repeat('c', 43), str_repeat('p', 43)));
    }

    public function testExistingEmailIsNeverUsedForImplicitLinking(): void
    {
        $existing = new \WP_User(27, ['subscriber'], 'existing', 'member@example.test');
        $GLOBALS['identity_client_test_email_users']['member@example.test'] = $existing;

        $result = self::privateMethod('resolveUser')->invoke(null, [
            'faluss_id' => '11111111-1111-4111-8111-111111111111',
            'scope' => 'identity.basic identity.email',
            'email' => 'member@example.test',
        ], ['flow_mode' => 'login', 'wp_user_id' => null]);

        self::assertNull($result);
        self::assertSame([], $GLOBALS['identity_client_test_insert_calls']);
    }

    public function testOnlyLinkedSubscribersReceiveTheOneHourSession(): void
    {
        $database = $GLOBALS['wpdb'];
        self::assertInstanceOf(IdentityClientWpdbStub::class, $database);
        $database->linkedUserId = 10;
        $GLOBALS['identity_client_test_users'][10] = new \WP_User(10, ['subscriber']);
        $GLOBALS['identity_client_test_users'][11] = new \WP_User(11, ['administrator']);

        self::assertSame(3600, IdentityClientService::memberCookieExpiration(172800, 10, false));
        self::assertSame(172800, IdentityClientService::memberCookieExpiration(172800, 11, false));
    }

    public function testAppsRegistryRelationshipUsesOnlyTheValidatedStoredProjection(): void
    {
        $database = $GLOBALS['wpdb'];
        self::assertInstanceOf(IdentityClientWpdbStub::class, $database);
        $database->linkedUserId = 10;
        $GLOBALS['identity_client_test_user_meta'][10][IdentityClientService::MEMBER_APPS_META] = [
            'me' => [
                'contract_version' => '1',
                'publication_status' => 'published',
                'canonical_url' => 'https://faluss.me/mon-faluss',
            ],
        ];
        $falussId = '22222222-2222-4222-8222-222222222222';

        self::assertSame('active', IdentityClientAppsRegistryAdapter::relationship($falussId));
        self::assertSame(
            'https://faluss.me/mon-faluss',
            IdentityClientAppsRegistryAdapter::canonicalDestination($falussId)
        );

        $database->linkedUserId = null;
        self::assertSame(
            'not_linked',
            IdentityClientAppsRegistryAdapter::relationship('33333333-3333-4333-8333-333333333333')
        );
        self::assertInstanceOf(
            \WP_Error::class,
            IdentityClientAppsRegistryAdapter::relationship('not-a-faluss-id')
        );
    }

    private static function privateMethod(string $name): ReflectionMethod
    {
        $method = new ReflectionMethod(IdentityClientService::class, $name);
        $method->setAccessible(true);

        return $method;
    }

    private static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
