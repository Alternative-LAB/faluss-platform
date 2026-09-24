<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Core;

use Faluss\Platform\Core\UpdateClient;
use PHPUnit\Framework\TestCase;

final class UpdateClientTest extends TestCase
{
    public function testAcceptsOnlyBoundedOpaqueLicenseKeys(): void
    {
        self::assertSame('abc_DEF-0123456789xyz', UpdateClient::sanitize(' abc_DEF-0123456789xyz '));
        self::assertSame('', UpdateClient::sanitize('too-short'));
        self::assertSame('', UpdateClient::sanitize('invalid key containing spaces'));
        self::assertSame('', UpdateClient::sanitize(str_repeat('a', 129)));
    }

    public function testUpdateEndpointTargetsOnlyFalussPlatform(): void
    {
        self::assertSame('faluss-platform', UpdateClient::SLUG);
        self::assertSame(
            'https://updates.faluss.com/?action=get_metadata&slug=faluss-platform',
            UpdateClient::METADATA_URL
        );
    }
}
