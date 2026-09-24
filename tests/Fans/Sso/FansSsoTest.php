<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Sso;

use Faluss\Platform\Core\SiteRole;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionMethod;

require_once __DIR__ . '/WordPressStubs.php';

final class FansSsoTest extends TestCase
{
    private const ID = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        fans_sso_reset();
        if (!defined('FALUSS_FANS_SSO_CLIENT_ID')) {
            define('FALUSS_FANS_SSO_CLIENT_ID', 'fans-test-client');
            define('FALUSS_FANS_SSO_CLIENT_SECRET', str_repeat('s', 43));
        }
    }

    public function testOwnNamesAndRoleNeverAdmitHubOrMe(): void
    {
        $module = new FansSsoModule();
        self::assertSame([SiteRole::Fans], $module->roles());
        self::assertSame(['admin-dashboard'], $module->dependencies());
        self::assertSame([
            'links' => 'wp_faluss_fans_identity_links',
            'states' => 'wp_faluss_fans_sso_states',
        ], FansSsoSchema::tables());
        self::assertSame('faluss_fans_sso_schema_version', FansSsoSchema::OPTION);
        self::assertSame('faluss_fans_sso_start', FansSsoService::START_ACTION);
        self::assertSame('faluss_fans_sso_state', FansSsoService::COOKIE);
        self::assertSame('https://fans.example.test/faluss-fans/sso/callback', FansSsoService::callbackUrl());
        self::assertFalse(FansSsoSchema::ready());
        self::assertSame([], $GLOBALS['fans_sso_hooks']);
    }

    public function testSchemaRequiresTwoSeparateInnoDbTablesAndNullableLoginUser(): void
    {
        $schema = FansSsoSchema::schema();
        self::assertSame(['links', 'states'], array_keys($schema));
        self::assertTrue($schema['links']['indexes']['wp_user_id_unique'][0]);
        self::assertTrue($schema['links']['indexes']['faluss_id_unique'][0]);
        self::assertTrue($schema['states']['indexes']['state_hash_unique'][0]);
        self::assertTrue($schema['states']['columns']['wp_user_id']['null']);
        self::assertArrayNotHasKey('redirect_url', $schema['states']['columns']);
    }

    public function testPartialSchemaFailsClosedWithoutCreatingAnotherTable(): void
    {
        $db = $GLOBALS['wpdb'];
        $db->presentTables = ['wp_faluss_fans_identity_links'];
        self::assertFalse(FansSsoSchema::installOrVerify());
        self::assertSame([], $db->queries);
        self::assertSame([], $GLOBALS['fans_sso_options']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testActivationOnHubCannotInstallFansSchemaEvenWithFlag(): void
    {
        define('FALUSS_PLATFORM_ROLE', 'hub');
        define('FALUSS_PLATFORM_FANS_SSO', true);
        FansSsoModule::activate();
        self::assertSame([], $GLOBALS['wpdb']->queries);
        self::assertSame([], $GLOBALS['fans_sso_options']);
    }

    public function testConfigurationRequiresHttpsAndExactIndependentCallback(): void
    {
        self::assertTrue(FansSsoService::configured());
        $GLOBALS['fans_sso_home'] = 'http://fans.example.test';
        self::assertFalse(FansSsoService::configured());
        $GLOBALS['fans_sso_home'] = 'https://faluss.me';
        self::assertFalse(FansSsoService::configured());
        $GLOBALS['fans_sso_home'] = 'https://fans.example.test?next=evil';
        self::assertFalse(FansSsoService::configured());
    }

    public function testPkceHashesEncodedVerifierWithS256(): void
    {
        $bytes = str_repeat('v', 32);
        $encoded = self::encode($bytes);
        self::assertSame(self::encode(hash('sha256', $encoded, true)), self::method('pkceChallenge')->invoke(null, $bytes));
    }

    public function testStateIsBoundToBrowserAndConsumedOnce(): void
    {
        $state = str_repeat('s', 32);
        $verifier = str_repeat('v', 32);
        $browser = str_repeat('b', 32);
        $_COOKIE[FansSsoService::COOKIE] = self::encode($state . $verifier . $browser);
        $db = $GLOBALS['wpdb'];
        $db->stateRow = [
            'id' => 9, 'flow_mode' => 'login', 'wp_user_id' => null,
            'expires_at' => '2099-01-01 00:00:00', 'consumed_at' => null,
        ];

        self::assertNull(self::method('consumeState')->invoke(null, self::encode(str_repeat('x', 32))));
        self::assertSame([], $db->queries);
        $pending = self::method('consumeState')->invoke(null, self::encode($state));
        self::assertSame(self::encode($verifier), $pending['verifier']);
        self::assertSame('login', $pending['flow_mode']);
        self::assertContains('START TRANSACTION', $db->queries);
        self::assertContains('COMMIT', $db->queries);
        self::assertStringContainsString('FOR UPDATE', $db->prepared[0]['query']);
        self::assertSame(hash_hmac('sha256', $browser, wp_salt('faluss_fans_sso_state')), $db->prepared[0]['args'][1]);

        $db->stateRow['consumed_at'] = '2026-09-24 00:00:00';
        self::assertNull(self::method('consumeState')->invoke(null, self::encode($state)));
        self::assertContains('ROLLBACK', $db->queries);
        $db->stateRow['consumed_at'] = null;
        $db->stateRow['expires_at'] = '2020-01-01 00:00:00';
        self::assertNull(self::method('consumeState')->invoke(null, self::encode($state)));
    }

    public function testTokenExchangeSendsSecretOnlyInServerPostAndRejectsWrongClaims(): void
    {
        $valid = ['faluss_id' => self::ID, 'scope' => 'identity.basic identity.email', 'email' => 'member@example.test', 'apps' => ['hub' => 'ignored']];
        $GLOBALS['fans_sso_remote_queue'][] = self::response($valid);
        $claims = self::method('exchange')->invoke(null, str_repeat('c', 43), str_repeat('p', 43), 'login');
        self::assertSame(['faluss_id' => self::ID, 'scope' => 'identity.basic identity.email', 'email' => 'member@example.test'], $claims);
        $call = $GLOBALS['fans_sso_remote_calls'][0];
        self::assertSame('https://faluss.me/oauth/token', $call['url']);
        self::assertSame(str_repeat('s', 43), $call['args']['body']['client_secret']);
        self::assertSame(FansSsoService::callbackUrl(), $call['args']['body']['redirect_uri']);
        self::assertSame(0, $call['args']['redirection']);
        self::assertTrue($call['args']['sslverify']);
        foreach ([
            ['faluss_id' => 'invalid', 'scope' => 'identity.basic identity.email', 'email' => 'member@example.test'],
            ['faluss_id' => self::ID, 'scope' => 'identity.basic', 'email' => 'member@example.test'],
            ['faluss_id' => self::ID, 'scope' => 'identity.basic identity.email', 'email' => 'bad'],
        ] as $invalid) {
            $GLOBALS['fans_sso_remote_queue'][] = self::response($invalid);
            self::assertNull(self::method('exchange')->invoke(null, str_repeat('c', 43), str_repeat('p', 43), 'login'));
        }
    }

    public function testEmailCollisionCannotImplicitlyLinkAndPrivilegedAccountCannotLogin(): void
    {
        $GLOBALS['fans_sso_email_users']['member@example.test'] = new \WP_User(27);
        $claims = ['faluss_id' => self::ID, 'scope' => 'identity.basic identity.email', 'email' => 'member@example.test'];
        $pending = ['verifier' => str_repeat('v', 43), 'flow_mode' => 'login', 'wp_user_id' => 0];
        self::assertNull(self::method('resolveUser')->invoke(null, $claims, $pending));
        self::assertSame([], $GLOBALS['fans_sso_insert_calls']);

        $GLOBALS['fans_sso_email_users'] = [];
        $GLOBALS['wpdb']->linkedId = 27;
        $GLOBALS['fans_sso_users'][27] = new \WP_User(27, ['administrator']);
        self::assertNull(self::method('resolveUser')->invoke(null, $claims, $pending));
        self::assertSame([], $GLOBALS['fans_sso_insert_calls']);
    }

    public function testNewSubscriberIsLocalAndSessionIsLimitedToLinkedSubscriber(): void
    {
        $claims = ['faluss_id' => self::ID, 'scope' => 'identity.basic identity.email', 'email' => 'new@example.test'];
        $pending = ['verifier' => str_repeat('v', 43), 'flow_mode' => 'login', 'wp_user_id' => 0];
        $user = self::method('resolveUser')->invoke(null, $claims, $pending);
        self::assertInstanceOf(\WP_User::class, $user);
        self::assertSame(['subscriber'], $user->roles);
        self::assertSame('subscriber', $GLOBALS['fans_sso_insert_calls'][0]['role']);
        self::assertSame(1, count(array_filter($GLOBALS['wpdb']->queries, static fn (string $query): bool => str_starts_with($query, 'INSERT INTO'))));
        $GLOBALS['wpdb']->linkedId = 51;
        self::assertSame(3600, FansSsoService::cookieExpiration(172800, 51, false));
        $GLOBALS['fans_sso_users'][52] = new \WP_User(52, ['administrator']);
        self::assertSame(172800, FansSsoService::cookieExpiration(172800, 52, false));
    }

    public function testFailedLinkRollsBackCreatedSubscriberAndAllowsRetry(): void
    {
        $claims = ['faluss_id' => self::ID, 'scope' => 'identity.basic identity.email', 'email' => 'retry@example.test'];
        $pending = ['verifier' => str_repeat('v', 43), 'flow_mode' => 'login', 'wp_user_id' => 0];
        $db = $GLOBALS['wpdb'];
        $db->insertResult = 0;

        self::assertNull(self::method('resolveUser')->invoke(null, $claims, $pending));
        self::assertArrayNotHasKey(51, $GLOBALS['fans_sso_users']);
        self::assertArrayNotHasKey('retry@example.test', $GLOBALS['fans_sso_email_users']);
        self::assertContains('ROLLBACK', $db->queries);
        self::assertSame(1, count(array_filter($db->queries, static fn (string $query): bool => $query === 'START TRANSACTION')));

        $db->insertResult = 1;
        self::assertInstanceOf(\WP_User::class, self::method('resolveUser')->invoke(null, $claims, $pending));
        self::assertCount(1, $GLOBALS['fans_sso_users']);
        self::assertCount(2, $GLOBALS['fans_sso_insert_calls']);
    }

    public function testFailedExistingAccountLinkNeverRemovesThatAccount(): void
    {
        $claims = ['faluss_id' => self::ID, 'scope' => 'identity.basic'];
        $pending = ['verifier' => str_repeat('v', 43), 'flow_mode' => 'link', 'wp_user_id' => 17];
        $existing = new \WP_User(17);
        $GLOBALS['fans_sso_users'][17] = $existing;
        $GLOBALS['fans_sso_logged_in'] = true;
        $GLOBALS['fans_sso_current_user'] = 17;
        $GLOBALS['wpdb']->insertResult = 0;

        self::assertNull(self::method('resolveUser')->invoke(null, $claims, $pending));
        self::assertSame($existing, $GLOBALS['fans_sso_users'][17]);
        self::assertSame([], $GLOBALS['fans_sso_insert_calls']);
        self::assertContains('ROLLBACK', $GLOBALS['wpdb']->queries);
    }

    public function testConcurrentRequestsFailClosedOrReuseTheCommittedLink(): void
    {
        $claims = ['faluss_id' => self::ID, 'scope' => 'identity.basic identity.email', 'email' => 'race@example.test'];
        $pending = ['verifier' => str_repeat('v', 43), 'flow_mode' => 'login', 'wp_user_id' => 0];
        $db = $GLOBALS['wpdb'];
        $db->lockResults = [0];
        self::assertNull(self::method('resolveUser')->invoke(null, $claims, $pending));
        self::assertSame([], $GLOBALS['fans_sso_insert_calls']);

        // Another request commits the same subject while this request waits on GET_LOCK.
        $db->onLock = static function () use ($db): void {
            $db->linkedId = 51;
            $GLOBALS['fans_sso_users'][51] = new \WP_User(51);
        };
        $user = self::method('resolveUser')->invoke(null, $claims, $pending);
        self::assertSame(51, $user?->ID);
        self::assertSame([], $GLOBALS['fans_sso_insert_calls']);
        self::assertContains('ROLLBACK', $db->queries);
        self::assertSame(3, count(array_filter($db->prepared, static fn (array $item): bool => str_contains($item['query'], 'GET_LOCK'))));
    }

    public function testCreationFailsClosedWhenWordPressUserTablesAreNotInnoDb(): void
    {
        $GLOBALS['wpdb']->coreEngine = 'MyISAM';
        $claims = ['faluss_id' => self::ID, 'scope' => 'identity.basic identity.email', 'email' => 'member@example.test'];
        $pending = ['verifier' => str_repeat('v', 43), 'flow_mode' => 'login', 'wp_user_id' => 0];
        self::assertNull(self::method('resolveUser')->invoke(null, $claims, $pending));
        self::assertSame([], $GLOBALS['fans_sso_insert_calls']);
    }

    public function testExplicitLinkRequiresSameLoggedInSubscriber(): void
    {
        $claims = ['faluss_id' => self::ID, 'scope' => 'identity.basic'];
        $pending = ['verifier' => str_repeat('v', 43), 'flow_mode' => 'link', 'wp_user_id' => 17];
        $GLOBALS['fans_sso_users'][17] = new \WP_User(17);
        self::assertNull(self::method('resolveUser')->invoke(null, $claims, $pending));
        $GLOBALS['fans_sso_logged_in'] = true;
        $GLOBALS['fans_sso_current_user'] = 18;
        self::assertNull(self::method('resolveUser')->invoke(null, $claims, $pending));
        $GLOBALS['fans_sso_current_user'] = 17;
        self::assertInstanceOf(\WP_User::class, self::method('resolveUser')->invoke(null, $claims, $pending));
    }

    private static function method(string $name): ReflectionMethod
    {
        return new ReflectionMethod(FansSsoService::class, $name);
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function response(array $body): array
    {
        return ['code' => 200, 'body' => (string) json_encode($body)];
    }
}
