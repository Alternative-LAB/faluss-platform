<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Fans\Ui;

use Faluss\Platform\Fans\Ui\FansUiHof;
use Faluss\Platform\Fans\Ui\FansUiHofView;
use PHPUnit\Framework\TestCase;

final class FansHofPresentationTest extends TestCase
{
    public function testPointPresentationNeverRoundsAnExactScoreThroughFloatingPoint(): void
    {
        self::assertSame('0', FansUiHofView::points('0'));
        self::assertSame('1', FansUiHofView::points('1'));
        self::assertSame("12\u{202F}450", FansUiHofView::points('12450'));
        self::assertSame("9\u{202F}007\u{202F}199\u{202F}254\u{202F}740\u{202F}993", FansUiHofView::points('9007199254740993'));
    }

    public function testOtherScreensNeverResolveAClosedRankingProvider(): void
    {
        foreach (['explorer', 'public-profile', 'notifications', 'messages', 'mon-profil', 'boutique', 'creer', 'images'] as $view) {
            self::assertNull(FansUiHof::load('creator', $view));
        }
    }

    public function testAnUnavailableWordPressConnectionNeverBecomesAScoreOrAFatalError(): void
    {
        $existed = array_key_exists('wpdb', $GLOBALS);
        $previous = $GLOBALS['wpdb'] ?? null;
        unset($GLOBALS['wpdb']);
        try {
            $screen = FansUiHof::load('fan', 'espace');
            self::assertNotNull($screen);
            self::assertFalse($screen->available);
            self::assertNull($screen->ranking);
            self::assertNull($screen->statement);
        } finally {
            if ($existed) { $GLOBALS['wpdb'] = $previous; }
            else { unset($GLOBALS['wpdb']); }
        }
    }
}
