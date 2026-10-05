<?php

declare(strict_types=1);

namespace Faluss\Platform\Tests\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\ClosedProtocolSchema;
use Faluss\Platform\TokenEngine\PurchasedPf\ClosedProtocolStore;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ClosedProtocolGuardTest extends TestCase
{
    public function testOrdinaryWordPressRejectsEntryPointsBeforeAnyQuery(): void
    {
        eval('namespace { class wpdb { public int $queries=0; public function get_row($q,$a=null) { $this->queries++; return null; } } }');
        $database = new \wpdb();
        foreach ([static fn () => ClosedProtocolSchema::installForRecipe($database),
            static fn () => new ClosedProtocolStore($database, 'fixture.hub', 'recipe-key'),
            static fn () => ClosedEnvironment::assertIsolated($database, 'fans')] as $operation) {
            try {
                $operation();
                self::fail('Normal WordPress must never open this recipe.');
            } catch (ModelViolation $error) {
                self::assertSame('isolated_h3_recipe_required', $error->reason);
            }
        }
        self::assertSame(0, $database->queries);
    }

    public function testCopiedRecipeConstantsCannotBypassPrivateFilesystemAndSocketGate(): void
    {
        eval('namespace { class wpdb { public int $queries=0; public function get_row($q,$a=null) { $this->queries++; return null; } } function wp_get_environment_type(): string { return "local"; } }');
        foreach (['FALUSS_PF_H3_RECIPE_ONLY' => true, 'FALUSS_PLATFORM_ROLE' => 'hub',
            'WP_HTTP_BLOCK_EXTERNAL' => true, 'FALUSS_PF_H3_LEASE_SHA256' => str_repeat('a',64),
            'ABSPATH' => __DIR__ . '/', 'DB_HOST' => 'localhost', 'WP_CLI' => true] as $key => $value) {
            define($key, $value);
        }
        $database = new \wpdb();
        $GLOBALS['wpdb'] = $database;
        try {
            ClosedProtocolSchema::installForRecipe($database);
            self::fail('Copied settings must not migrate a normal site.');
        } catch (ModelViolation $error) {
            self::assertSame('isolated_h3_recipe_required', $error->reason);
        }
        self::assertSame(0, $database->queries);
    }
}
