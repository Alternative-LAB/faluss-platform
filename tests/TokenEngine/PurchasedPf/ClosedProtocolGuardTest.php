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
            static fn () => \Faluss\Platform\Fans\PfContract\ClosedProtocolSchema::installForRecipe($database),
            static fn () => new \Faluss\Platform\Fans\PfContract\ClosedProtocolStore($database),
            static fn () => \Faluss\Platform\Fans\PfContract\ClosedDelegation::resolve($database, []),
            static fn () => new \Faluss\Platform\Fans\PfContract\ClosedClient($database,
                new \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy('fixture.hub','fixture.fans',[],[]), 'recipe-key', ''),
            static fn () => new \Faluss\Platform\TokenEngine\PurchasedPf\ClosedGateway($database,
                new \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy('fixture.fans','fixture.hub',[],[]), 'recipe-key'),
            static fn () => new \Faluss\Platform\TokenEngine\PurchasedPf\ClosedCorpusTransaction($database),
            static fn () => new \Faluss\Platform\TokenEngine\PurchasedPf\ClosedRankingCorpusSource($database,[],'recipe-key'),
            static fn () => new \Faluss\Platform\TokenEngine\PurchasedPf\ClosedRankingCorpusStore($database,[],'recipe-key'),
            static fn () => \Faluss\Platform\TokenEngine\PurchasedPf\ClosedRankingCorpusSchema::installForRecipe($database),
            static fn () => \Faluss\Platform\TokenEngine\PurchasedPf\ClosedCorpusTransaction::assertActive($database),
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
        foreach ([static fn () => new \Faluss\Platform\TokenEngine\PurchasedPf\ClosedCorpusTransaction($database),
            static fn () => new \Faluss\Platform\TokenEngine\PurchasedPf\ClosedRankingCorpusSource($database,[],'recipe-key'),
            static fn () => new \Faluss\Platform\TokenEngine\PurchasedPf\ClosedRankingCorpusStore($database,[],'recipe-key'),
            static fn () => \Faluss\Platform\TokenEngine\PurchasedPf\ClosedRankingCorpusSchema::installForRecipe($database)] as $operation) {
            try { $operation(); self::fail('Copied constants cannot expose the corpus.'); }
            catch (ModelViolation $error) { self::assertSame('isolated_h3_recipe_required',$error->reason); }
        }
        self::assertSame(0, $database->queries);
    }
}
