<?php

declare(strict_types=1);

namespace Faluss\Platform\AppsRegistry;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class AppsRegistryServiceTest extends TestCase
{
    protected function setUp(): void
    {
        apps_registry_test_reset();
    }

    public function testResolvesTheTwoOwnersWithoutLeakingTheMemberOrInventingCapabilities(): void
    {
        self::assertTrue(AppsRegistryService::registerSource([
            'app_key' => 'faluss-hub',
            'source_type' => 'local_owner',
            'document_type' => AppsRegistryService::DOCUMENT_TYPE,
            'contract_version' => AppsRegistryService::CONTRACT_VERSION,
            'requested_manifest_version' => AppsRegistryService::CONTRACT_VERSION,
            'owner' => 'faluss-hub',
            'relationship_resolver' => static fn (string $falussId): string => $falussId !== '' ? 'active' : 'inactive',
            'capability_resolver' => static fn (string $falussId, string $key): array => AppsRegistryFixtures::ownerCapability(),
            'manifest_resolver' => [AppsRegistryFixtures::class, 'hubManifest'],
        ]));
        self::assertTrue(AppsRegistryService::registerSource([
            'app_key' => 'faluss-me',
            'source_type' => 'federated_peer',
            'document_type' => AppsRegistryService::DOCUMENT_TYPE,
            'contract_version' => AppsRegistryService::CONTRACT_VERSION,
            'requested_manifest_version' => AppsRegistryService::CONTRACT_VERSION,
            'owner' => 'faluss-me',
            'relationship_resolver' => static fn (string $falussId): string => $falussId !== '' ? 'not_linked' : 'inactive',
            'capability_resolver' => null,
            'peer_node_id' => 'me-node',
            'peer_app_key' => 'faluss-me',
        ]));

        $falussId = '11111111-1111-4111-8111-111111111111';
        $document = AppsRegistryService::readForMember($falussId, 'portal', '1.0.0');

        self::assertIsArray($document);
        self::assertTrue(ReadModelValidator::validate($document));
        self::assertSame(['faluss-hub', 'faluss-me'], array_column($document['applications'], 'app_key'));
        self::assertSame('not_linked', $document['applications'][1]['member_relationship']);
        self::assertSame([], $document['applications'][1]['capabilities']);
        self::assertStringNotContainsString($falussId, (string) json_encode($document));
        self::assertSame(1, \Faluss_Federation_Client::$calls);
        self::assertLessThanOrEqual(300, array_values($GLOBALS['apps_registry_test_transient_ttls'])[0]);
        self::assertStringNotContainsString(
            $falussId,
            (string) json_encode($GLOBALS['apps_registry_test_transients'])
        );

        $cached = AppsRegistryService::readForMember($falussId, 'portal', '1.0.0');
        self::assertIsArray($cached);
        self::assertSame(1, \Faluss_Federation_Client::$calls);

        \Faluss_Federation_Policy::$keyId = 'me-key-0002';
        $afterRotation = AppsRegistryService::readForMember($falussId, 'portal', '1.0.0');
        self::assertIsArray($afterRotation);
        self::assertSame(2, \Faluss_Federation_Client::$calls);
    }

    public function testFailsClosedForBadInputsDuplicateSourcesAndWrongAuthority(): void
    {
        self::assertInstanceOf(
            \WP_Error::class,
            AppsRegistryService::registerSource(['app_key' => 'from-browser'])
        );
        self::assertInstanceOf(
            \WP_Error::class,
            AppsRegistryService::readForMember('not-a-uuid', 'portal', '1.0.0')
        );

        apps_registry_test_reset();
        $source = [
            'app_key' => 'faluss-me',
            'source_type' => 'federated_peer',
            'document_type' => AppsRegistryService::DOCUMENT_TYPE,
            'contract_version' => AppsRegistryService::CONTRACT_VERSION,
            'requested_manifest_version' => AppsRegistryService::CONTRACT_VERSION,
            'owner' => 'faluss-me',
            'relationship_resolver' => static fn (): string => 'active',
            'capability_resolver' => null,
            'peer_node_id' => 'me-node',
            'peer_app_key' => 'faluss-me',
        ];
        self::assertTrue(AppsRegistryService::registerSource($source));
        self::assertInstanceOf(\WP_Error::class, AppsRegistryService::registerSource($source));
        self::assertInstanceOf(
            \WP_Error::class,
            AppsRegistryService::readForMember('11111111-1111-4111-8111-111111111111', 'portal', '1.0.0')
        );

        apps_registry_test_reset();
        \Faluss_Federation_Crypto::$identity = [
            'node_id' => 'me-node',
            'app_key' => 'faluss-me',
            'origin' => 'https://faluss.me',
            'key_id' => 'me-key-0001',
        ];
        self::assertInstanceOf(
            \WP_Error::class,
            AppsRegistryService::readForMember('11111111-1111-4111-8111-111111111111', 'portal', '1.0.0')
        );
    }
}
