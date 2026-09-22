<?php

declare(strict_types=1);

namespace Faluss\Platform\Federation;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class FederationRetirementGateTest extends TestCase
{
    public function testExactActiveConsumerInventoryBlocksRetirement(): void
    {
        $root = dirname(__DIR__, 2);
        $source = $root . '/src';
        $actual = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source));

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            if (str_contains($path, '/src/Federation/')) {
                continue;
            }

            $contents = file_get_contents($path);
            if (is_string($contents) && preg_match('/Faluss_Federation_(?:Client|Crypto|Policy|Providers|Schema|Server)/', $contents) === 1) {
                $actual[] = substr($path, strlen(str_replace('\\', '/', $root)) + 1);
            }
        }

        sort($actual, SORT_STRING);
        self::assertSame([
            'src/Analytics/Legacy/includes/class-faluss-analytics.php',
            'src/AppsRegistry/AppsRegistryService.php',
            'src/Events/Legacy/includes/class-faluss-events-workers.php',
            'src/Events/Legacy/includes/class-faluss-events.php',
            'src/Link/LegacyLinkEventsCatalog.php',
            'src/Link/LegacyLinkEventsRuntime.php',
            'src/Link/LegacyLinkManifest.php',
            'src/Portal/LegacyPortalEventsCatalog.php',
            'src/Portal/LegacyPortalEventsRuntime.php',
            'src/Portal/LegacyPortalManifest.php',
        ], $actual, 'Federation cannot be retired while this exact consumer inventory remains.');
    }
}
