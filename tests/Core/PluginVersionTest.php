<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Core;

use PHPUnit\Framework\TestCase;

final class PluginVersionTest extends TestCase
{
    private const EXPECTED_VERSION = '0.2.0';

    public function testPluginHeaderAndChangelogUseExpectedVersion(): void
    {
        $root = dirname(__DIR__, 2);
        $bootstrap = file_get_contents($root . '/faluss-platform.php');
        $changelog = file_get_contents($root . '/CHANGELOG.md');

        self::assertIsString($bootstrap);
        self::assertIsString($changelog);
        self::assertMatchesRegularExpression(
            '/^ \* Version: ' . preg_quote(self::EXPECTED_VERSION, '/') . '\r?$/m',
            $bootstrap
        );
        self::assertStringContainsString(
            'Version du Master Plugin portée de `0.1.0` à `' . self::EXPECTED_VERSION . '`',
            $changelog
        );
    }
}
