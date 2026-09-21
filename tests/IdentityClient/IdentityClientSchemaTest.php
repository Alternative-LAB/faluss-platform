<?php

declare(strict_types=1);

namespace Faluss\Platform\IdentityClient;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class IdentityClientSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        identity_client_test_reset();
    }

    public function testPreservesTheTwoInnoDbTableContractsAndUniqueMappings(): void
    {
        $schema = IdentityClientSchema::schema();

        self::assertSame(['links', 'states'], array_keys($schema));
        self::assertTrue($schema['links']['indexes']['wp_user_id_unique'][0]);
        self::assertTrue($schema['links']['indexes']['faluss_id_unique'][0]);
        self::assertSame('char(64)', $schema['states']['columns']['state_hash']['type']);
        self::assertTrue($schema['states']['indexes']['state_hash_unique'][0]);
        self::assertTrue($schema['states']['columns']['wp_user_id']['null']);
        self::assertSame([
            'links' => 'wp_faluss_identity_links',
            'states' => 'wp_faluss_identity_client_state',
        ], IdentityClientSchema::tables());
    }
}
