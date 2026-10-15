<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\MeStudio;

use PHPUnit\Framework\TestCase;

final class LinkImportActionsTest extends TestCase
{
    public function testPrivatePreviewBoundary(): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/link-import-actions-test.php') . ' 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
        self::assertStringContainsString('Link import AJAX permissions, validation, raw input and preview: OK', implode("\n", $output));
    }
}
