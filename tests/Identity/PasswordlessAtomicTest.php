<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Identity;

use PHPUnit\Framework\TestCase;

final class PasswordlessAtomicTest extends TestCase
{
    public function testInternalFailureDoesNotConsumeAValidProof(): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/contracts/passwordless-atomic-test.php') . ' 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
        self::assertStringContainsString('Passwordless atomic identity and OTP: OK', implode("\n", $output));
    }
}
