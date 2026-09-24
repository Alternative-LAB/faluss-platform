<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\Core;

use PHPUnit\Framework\TestCase;

final class PluginVersionTest extends TestCase
{
    public function testPluginHeaderConstantAndChangelogUseTheSameSemanticVersion(): void
    {
        $root = dirname(__DIR__, 2);
        $bootstrap = file_get_contents($root . '/faluss-platform.php');
        $changelog = file_get_contents($root . '/CHANGELOG.md');

        self::assertIsString($bootstrap);
        self::assertIsString($changelog);
        self::assertSame(1, preg_match('/^ \* Version: (\d+\.\d+\.\d+)\r?$/m', $bootstrap, $header));
        self::assertSame(1, preg_match("/define\('FALUSS_PLATFORM_VERSION', '(\d+\.\d+\.\d+)'\);/", $bootstrap, $constant));
        self::assertSame($header[1], $constant[1]);
        self::assertStringContainsString('## [' . $header[1] . ']', $changelog);
    }
}
