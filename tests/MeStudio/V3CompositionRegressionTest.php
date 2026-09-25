<?php

declare(strict_types=1);

namespace Faluss\Platform\MeStudio;

use PHPUnit\Framework\TestCase;

final class V3CompositionRegressionTest extends TestCase
{
    public function testCanonicalPreviewAndPublishedStudioTransactions(): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/v3-composition-regression.php') . ' 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
        self::assertStringContainsString('V3 composition, preview, persistence, media and concurrency: OK', implode("\n", $output));
    }
}
