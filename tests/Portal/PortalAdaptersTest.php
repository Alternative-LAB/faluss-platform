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

    public function testPortalAssetsRemainByteForByteHistorical(): void
    {
        $historical = dirname(__DIR__, 3) . '/FALUSS/plugins/faluss-portal/assets';
        $target = dirname(__DIR__, 2) . '/assets';
        $files = [
            'css/faluss-portal.css',
            'images/apps/faluss-hub.png',
            'images/apps/faluss-me.png',
            'images/apps/faluss-date.png',
            'images/apps/faluss-pro.png',
            'images/pf/faluss-pf-badge.png',
        ];

        foreach ($files as $file) {
            self::assertFileExists($target . '/' . $file);
            self::assertSame(hash_file('sha256', $historical . '/' . $file), hash_file('sha256', $target . '/' . $file), $file);
        }

        $script = file_get_contents($target . '/js/faluss-portal.js');
        self::assertIsString($script);
        self::assertStringContainsString('reward.amount_pf', $script);
        self::assertStringNotContainsString('déjà reçu : 20 Points Faluss', $script);
    }
}
