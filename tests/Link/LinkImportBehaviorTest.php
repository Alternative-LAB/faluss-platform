<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Link;

use PHPUnit\Framework\TestCase;

final class LinkImportBehaviorTest extends TestCase
{
    public function testImportsAreAdditiveValidatedAndTransactional(): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/link-import-behavior-test.php') . ' 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
        self::assertStringContainsString('Link import validation, add-only persistence, capacity, conflicts and rollback: OK', implode("\n", $output));
    }
}
