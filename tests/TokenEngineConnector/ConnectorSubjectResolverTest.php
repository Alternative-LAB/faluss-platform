<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngineConnector;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class ConnectorIdentityRegistryStub
{
    public static function get_active_for_wp_user(int $userId): string
    {
        return $userId === 17 ? 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' : '';
    }

    public static function is_valid_faluss_id(mixed $value): bool
    {
        return $value === 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    }
}

final class ConnectorSubjectResolverTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testResolvesOnlyTheCurrentWordPressSessionsActiveIdentity(): void
    {
        connector_test_reset();
        class_alias(ConnectorIdentityRegistryStub::class, 'Faluss_Identity_Registry');
        $GLOBALS['connector_test_user'] = new class () {
            public int $ID = 17;

            public function exists(): bool
            {
                return true;
            }
        };

        self::assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', ConnectorSubjectResolver::current());
        $diagnostic = ConnectorSubjectResolver::diagnostic();
        self::assertTrue($diagnostic['signed_in']);
        self::assertTrue($diagnostic['active_identity_profile']);
        self::assertTrue($diagnostic['subject_available']);
        self::assertSame(12, strlen($diagnostic['subject_fingerprint']));

        $GLOBALS['connector_test_did_plugins_loaded'] = false;
        self::assertSame('', ConnectorSubjectResolver::current());
    }
}
