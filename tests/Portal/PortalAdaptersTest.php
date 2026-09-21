<?php

declare(strict_types=1);

namespace Faluss\Platform\Portal;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class PortalAdaptersTest extends TestCase
{
    public function testUnavailableDependenciesFailClosed(): void
    {
        portal_test_reset();
        $falussId = '11111111-1111-4111-8111-111111111111';

        self::assertNull(PortalIdentityAdapter::currentLinkedSubject());
        self::assertNull(PortalAppsRegistryAdapter::readForMember($falussId));
        self::assertSame('unavailable', PortalSubscriptionsAdapter::snapshot($falussId)['state']);
        self::assertSame(['status' => 'unavailable'], PortalTokenEngineAdapter::dailyStatus($falussId));
        self::assertFalse(PortalAnalyticsAdapter::registerRuntime());
    }

    public function testPortalAssetsRemainPinnedToTheHistoricalHashes(): void
    {
        $target = dirname(__DIR__, 2) . '/assets';
        $files = [
            'css/faluss-portal.css' => 'e229f44faad1d390b0af5a9aea606bcc878cf013c9650f0733288bf9dcbcab02',
            'images/apps/faluss-hub.png' => '5a534c15e5254c6982df0739152c3784dbb2edd7c7972976e32e306fc55b017b',
            'images/apps/faluss-me.png' => '1541ef775c32d229c11ec79a579ef9d371cf8f23a77c0c4b1920fda5bdb6d64a',
            'images/apps/faluss-date.png' => '21ee86bbe26342a2ca4a6f979a0052b3cf24a9ff0fd15c1c54c3638e8c113ed8',
            'images/apps/faluss-pro.png' => '33c828517cb2b9de0599ea3df6d0c735266bcbbc63212c743d1ef4e81f2467ff',
            'images/pf/faluss-pf-badge.png' => 'a25533ca502e4e6286cb58c858de8d7a4de5d25b18a5d91894b31c46ff1f1955',
        ];

        foreach ($files as $file => $expectedHash) {
            self::assertFileExists($target . '/' . $file);
            self::assertSame($expectedHash, hash_file('sha256', $target . '/' . $file), $file);
        }

        $script = file_get_contents($target . '/js/faluss-portal.js');
        self::assertIsString($script);
        self::assertStringContainsString('reward.amount_pf', $script);
        self::assertStringNotContainsString('déjà reçu : 20 Points Faluss', $script);
    }
}
