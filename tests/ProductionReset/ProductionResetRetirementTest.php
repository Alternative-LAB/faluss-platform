<?php

declare(strict_types=1);

namespace Faluss\Platform\ProductionReset;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ProductionResetRetirementTest extends TestCase
{
    public function testPlatformExposesNoProductionResetRuntime(): void
    {
        $root = dirname(__DIR__, 2);
        $runtime = [$root . '/faluss-platform.php'];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $runtime[] = $file->getPathname();
            }
        }

        $forbidden = [
            'Faluss_Production_Reset',
            'FALUSS_PLATFORM_PRODUCTION_RESET',
            'FALUSS_PRODUCTION_RESET_SHARED_SECRET',
            'faluss_production_reset_arm',
            'faluss_production_reset_preflight',
            'faluss_production_reset_execute',
            'faluss-production-reset/v1',
            'hub_member_reset_v1',
        ];

        foreach ($runtime as $path) {
            $contents = file_get_contents($path);
            self::assertIsString($contents);
            foreach ($forbidden as $marker) {
                self::assertStringNotContainsString($marker, $contents, $path . ' must not expose the retired reset runtime.');
            }
        }

        self::assertDirectoryDoesNotExist($root . '/src/ProductionReset');
    }

    public function testRetirementDecisionRecordsTheAuditedArtifactAndProductionGate(): void
    {
        $root = dirname(__DIR__, 2);
        $documentation = file_get_contents($root . '/docs/modules/PRODUCTION-RESET.md');

        self::assertIsString($documentation);
        foreach ([
            '4c84e4bbfc859f9d6c17b1d44a76c79bdbcdadb4',
            'Faluss Production Reset `0.1.3`',
            '`Faluss_Production_Reset`',
            '`manage_options`',
            '`faluss-production-reset/v1/hub-member-reset`',
            '`wp_delete_user()`',
            '`wp_delete_attachment()`',
            'Aucun consommateur',
            'aucun module runtime',
            'autorisation explicite de production',
            'journal d’audit durable',
            'sauvegarde restaurable',
        ] as $marker) {
            self::assertStringContainsString($marker, $documentation);
        }

        self::assertStringContainsString('PRODUCTION-RESET.md', file_get_contents($root . '/README.md'));
        self::assertStringContainsString('PRODUCTION-RESET.md', file_get_contents($root . '/docs/ARCHITECTURE.md'));
        self::assertStringContainsString('Production Reset', file_get_contents($root . '/CHANGELOG.md'));
    }
}
