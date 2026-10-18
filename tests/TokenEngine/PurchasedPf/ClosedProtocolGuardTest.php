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
        $GLOBALS['wpdb']=$database;
        foreach ([static fn () => ClosedProtocolSchema::installForRecipe($database),
            static fn () => new ClosedProtocolStore($database, 'fixture.hub', 'recipe-key'),
            static fn () => \Faluss\Platform\Fans\PfContract\ClosedProtocolSchema::installForRecipe($database),
            static fn () => new \Faluss\Platform\Fans\PfContract\ClosedProtocolStore($database),
            static fn () => \Faluss\Platform\Fans\PfContract\ClosedRankedProtocolSchema::installForRecipe($database),
            static fn () => new \Faluss\Platform\Fans\PfContract\ClosedRankedProtocolStore($database),
            static fn () => \Faluss\Platform\Fans\PfContract\ClosedAttributionAttemptSchema::installForRecipe($database),
            static fn () => \Faluss\Platform\Fans\PfContract\ClosedAttributionAttemptSchema::ready($database),
            static fn () => \Faluss\Platform\Fans\Ui\FansUiSupport::provider('123e4567-e89b-42d3-a456-426614174000'),
            static fn () => new \Faluss\Platform\Fans\PfContract\ClosedAttributionAttempt($database,
                (new \ReflectionClass(\Faluss\Platform\Fans\Hof\ClosedAttributionSelection::class))->newInstanceWithoutConstructor(),
                (new \ReflectionClass(\Faluss\Platform\Fans\PfContract\ClosedRankedClient::class))->newInstanceWithoutConstructor()),
            static fn () => \Faluss\Platform\Fans\PfContract\ClosedDelegation::resolve($database, []),
            static fn () => new \Faluss\Platform\Fans\Hof\ClosedAttributionPolicy($database,
                (new \ReflectionClass(\Faluss\Platform\Fans\PfContract\ClosedBarrierPrimaryRead::class))->newInstanceWithoutConstructor(),'',''),
            static fn () => new \Faluss\Platform\Fans\Hof\ClosedParticipationContext($database,
                (new \ReflectionClass(\Faluss\Platform\Fans\PfContract\ClosedBarrierPrimaryRead::class))->newInstanceWithoutConstructor(),''),
            static fn () => new \Faluss\Platform\Fans\Hof\ClosedAttributionSelection($database,
                (new \ReflectionClass(\Faluss\Platform\Fans\Hof\ClosedOriginOpening::class))->newInstanceWithoutConstructor(),
                (new \ReflectionClass(\Faluss\Platform\Fans\PfContract\ClosedBarrierPrimaryRead::class))->newInstanceWithoutConstructor(),
                (new \ReflectionClass(\Faluss\Platform\Fans\Hof\ClosedAttributionPolicy::class))->newInstanceWithoutConstructor()),
            static fn () => new \Faluss\Platform\Fans\PfContract\ClosedClient($database,
                new \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy('fixture.hub','fixture.fans',[],[]), 'recipe-key', ''),
            static fn () => new \Faluss\Platform\Fans\PfContract\ClosedRankedClient($database,
                new \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy('fixture.hub','fixture.fans',[],[]), 'recipe-key', ''),
            static fn () => new \Faluss\Platform\TokenEngine\PurchasedPf\ClosedGateway($database,
                new \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy('fixture.fans','fixture.hub',[],[]), 'recipe-key'),
            static fn () => new \Faluss\Platform\TokenEngine\PurchasedPf\ClosedRankedGateway($database,
                new \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy('fixture.fans','fixture.hub',[],[]),'recipe-key'),
            static fn () => new \Faluss\Platform\TokenEngine\PurchasedPf\ClosedCorpusTransaction($database),
            static fn () => new \Faluss\Platform\TokenEngine\PurchasedPf\ClosedRankingCorpusSource($database,[],'recipe-key'),
            static fn () => new \Faluss\Platform\TokenEngine\PurchasedPf\ClosedRankingCorpusStore($database,[],'recipe-key'),
            static fn () => new \Faluss\Platform\TokenEngine\PurchasedPf\ClosedCorpusAdmission($database),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedRankingProjectionSchema::installForRecipe($database),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedRankingProjectionSchema::ready($database),
            static fn () => new \Faluss\Platform\Fans\Hof\ClosedRankingProjectionStore($database,
                new \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy('fixture.hub','fixture.fans',['pf.ranking.corpus'],[]),'','1.0.0'),
            static fn () => new \Faluss\Platform\TokenEngine\PurchasedPf\ClosedBarrierAdmission($database),
            static fn () => \Faluss\Platform\Fans\PfContract\ClosedBarrierInboxSchema::installForRecipe($database),
            static fn () => new \Faluss\Platform\Fans\PfContract\ClosedBarrierInbox($database,
                new \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy('fixture.hub','fixture.fans',[],[]),'',''),
            static fn () => new \Faluss\Platform\TokenEngine\PurchasedPf\ClosedBarrierGateway($database,
                new \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy('fixture.fans','fixture.hub',[],[]),'recipe-key'),
            static fn () => \Faluss\Platform\TokenEngine\PurchasedPf\ClosedBarrierTransportSchema::installForRecipe($database),
            static fn () => \Faluss\Platform\TokenEngine\PurchasedPf\ClosedBarrierTransportSchema::ready($database),
            static fn () => \Faluss\Platform\TokenEngine\PurchasedPf\ClosedCorpusTransportSchema::installForRecipe($database),
            static fn () => \Faluss\Platform\TokenEngine\PurchasedPf\ClosedCorpusTransportSchema::ready($database),
            static fn () => new \Faluss\Platform\TokenEngine\PurchasedPf\ClosedCorpusGateway($database,
                new \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy('fixture.fans','fixture.hub',['pf.ranking.corpus'],[]),'recipe-key'),
            static fn () => new \Faluss\Platform\Fans\PfContract\ClosedCorpusClient($database,
                new \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy('fixture.hub','fixture.fans',['pf.ranking.corpus'],[]),'recipe-key','','',''),
            static fn () => \Faluss\Platform\Fans\PfContract\ClosedCorpusInboxSchema::installForRecipe($database),
            static fn () => \Faluss\Platform\Fans\PfContract\ClosedBarrierReadSchema::installForRecipe($database),
            static fn () => \Faluss\Platform\Fans\PfContract\ClosedCorpusInboxSchema::ready($database),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedOriginOpeningSchema::ready($database),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedOriginOpeningSchema::installForRecipe($database),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedSessionLifecycleSchema::ready($database),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedSessionLifecycleSchema::installForRecipe($database),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedTerritoryChangeSchema::ready($database),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedTerritoryChangeSchema::installForRecipe($database),
            static fn () => new \Faluss\Platform\Fans\Hof\ClosedTerritoryChanges($database),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedTerritoryChanges::target($database,'','admission','',''),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedTerritoryChanges::finish($database,''),
            static fn () => new \Faluss\Platform\Fans\Hof\ClosedTerritorySessionRetirement($database,
                (new \ReflectionClass(\Faluss\Platform\Fans\PfContract\ClosedBarrierInbox::class))->newInstanceWithoutConstructor(),
                (new \ReflectionClass(\Faluss\Platform\Fans\PfContract\ClosedBarrierClient::class))->newInstanceWithoutConstructor(),''),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedParticipationSchema::ready($database),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedParticipationSchema::installForRecipe($database),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedParticipationEntry::preview($database,'123e4567-e89b-42d3-a456-426614174000'),
            static fn () => new \Faluss\Platform\Fans\PfContract\ClosedCorpusInbox($database,
                new \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy('fixture.hub','fixture.fans',['pf.ranking.corpus'],[]),'',''),
            static fn () => \Faluss\Platform\TokenEngine\PurchasedPf\ClosedRankingCorpusSchema::installForRecipe($database),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedRankingPreferences::visibility($database),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedSessionManagement::services($database),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedSessionManagement::lifecycle($database),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedSessionManagement::freshness($database),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedSessionManagement::participation($database),
            static fn () => new \Faluss\Platform\Fans\Hof\ClosedParticipationActions($database),
            static fn () => new \Faluss\Platform\Fans\Hof\ClosedSessionActions($database),
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
            static fn () => new \Faluss\Platform\TokenEngine\PurchasedPf\ClosedRankedGateway($database,
                new \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy('fixture.fans','fixture.hub',[],[]),'recipe-key'),
            static fn () => new \Faluss\Platform\TokenEngine\PurchasedPf\ClosedRankingCorpusSource($database,[],'recipe-key'),
            static fn () => new \Faluss\Platform\TokenEngine\PurchasedPf\ClosedRankingCorpusStore($database,[],'recipe-key'),
            static fn () => new \Faluss\Platform\TokenEngine\PurchasedPf\ClosedCorpusAdmission($database),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedRankingProjectionSchema::installForRecipe($database),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedRankingProjectionSchema::ready($database),
            static fn () => new \Faluss\Platform\Fans\Hof\ClosedRankingProjectionStore($database,
                new \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy('fixture.hub','fixture.fans',['pf.ranking.corpus'],[]),'','1.0.0'),
            static fn () => new \Faluss\Platform\TokenEngine\PurchasedPf\ClosedBarrierAdmission($database),
            static fn () => \Faluss\Platform\Fans\PfContract\ClosedBarrierInboxSchema::installForRecipe($database),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedOriginOpeningSchema::ready($database),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedOriginOpeningSchema::installForRecipe($database),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedSessionLifecycleSchema::ready($database),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedSessionLifecycleSchema::installForRecipe($database),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedTerritoryChangeSchema::ready($database),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedTerritoryChangeSchema::installForRecipe($database),
            static fn () => new \Faluss\Platform\Fans\Hof\ClosedTerritoryChanges($database),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedTerritoryChanges::target($database,'','admission','',''),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedTerritoryChanges::finish($database,''),
            static fn () => new \Faluss\Platform\Fans\Hof\ClosedTerritorySessionRetirement($database,
                (new \ReflectionClass(\Faluss\Platform\Fans\PfContract\ClosedBarrierInbox::class))->newInstanceWithoutConstructor(),
                (new \ReflectionClass(\Faluss\Platform\Fans\PfContract\ClosedBarrierClient::class))->newInstanceWithoutConstructor(),''),
            static fn () => new \Faluss\Platform\Fans\PfContract\ClosedBarrierInbox($database,
                new \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy('fixture.hub','fixture.fans',[],[]),'',''),
            static fn () => new \Faluss\Platform\TokenEngine\PurchasedPf\ClosedBarrierGateway($database,
                new \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy('fixture.fans','fixture.hub',[],[]),'recipe-key'),
            static fn () => \Faluss\Platform\TokenEngine\PurchasedPf\ClosedBarrierTransportSchema::installForRecipe($database),
            static fn () => \Faluss\Platform\TokenEngine\PurchasedPf\ClosedBarrierTransportSchema::ready($database),
            static fn () => \Faluss\Platform\TokenEngine\PurchasedPf\ClosedCorpusTransportSchema::installForRecipe($database),
            static fn () => new \Faluss\Platform\TokenEngine\PurchasedPf\ClosedCorpusGateway($database,
                new \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy('fixture.fans','fixture.hub',['pf.ranking.corpus'],[]),'recipe-key'),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedRankingPreferences::visibility($database),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedSessionManagement::services($database),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedSessionManagement::lifecycle($database),
            static fn () => \Faluss\Platform\Fans\Hof\ClosedSessionManagement::freshness($database),
            static fn () => new \Faluss\Platform\Fans\Hof\ClosedSessionActions($database),
            static fn () => \Faluss\Platform\TokenEngine\PurchasedPf\ClosedRankingCorpusSchema::installForRecipe($database)] as $operation) {
            try { $operation(); self::fail('Copied constants cannot expose the corpus.'); }
            catch (ModelViolation $error) { self::assertSame('isolated_h3_recipe_required',$error->reason); }
        }
        self::assertSame(0, $database->queries);
    }

    public function testCopiedCorpusMuAdapterRegistersNoRouteOnOrdinaryWordPress(): void
    {
        eval('namespace { class wpdb { public int $queries=0; public function get_row($q,$a=null) { $this->queries++; return null; } } function add_action($name,$callback) { $callback(); } function register_rest_route(...$args) { $GLOBALS["recipe_routes"]++; } }');
        $database = new \wpdb(); $GLOBALS['wpdb'] = $database; $GLOBALS['recipe_routes'] = 0;
        require dirname(__DIR__) . '/recipe/b3-corpus-http-adapter.php';
        require dirname(__DIR__) . '/recipe/b3-ranked-http-adapter.php';
        self::assertSame(0,$database->queries); self::assertSame(0,$GLOBALS['recipe_routes']);
    }

    public function testCopiedFansSettingsCannotOpenTheDurableInbox(): void
    {
        eval('namespace { class wpdb { public int $queries=0; public function get_row($q,$a=null) { $this->queries++; return null; } } function wp_get_environment_type(): string { return "local"; } }');
        foreach (['FALUSS_PF_H3_RECIPE_ONLY' => true,'FALUSS_PLATFORM_ROLE' => 'fans','WP_HTTP_BLOCK_EXTERNAL' => true,
            'FALUSS_PF_H3_LEASE_SHA256' => str_repeat('a',64),'ABSPATH' => __DIR__ . '/','DB_HOST' => 'localhost','WP_CLI' => true] as $key => $value) { define($key,$value); }
        $database = new \wpdb(); $GLOBALS['wpdb'] = $database;
        foreach ([static fn () => \Faluss\Platform\Fans\PfContract\ClosedCorpusInboxSchema::installForRecipe($database),
            static fn () => \Faluss\Platform\Fans\PfContract\ClosedBarrierInboxSchema::installForRecipe($database),
            static fn () => new \Faluss\Platform\Fans\PfContract\ClosedBarrierInbox($database,
                new \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy('fixture.hub','fixture.fans',[],[]),'',''),
            static fn () => \Faluss\Platform\Fans\PfContract\ClosedRankedProtocolSchema::installForRecipe($database),
            static fn () => new \Faluss\Platform\Fans\PfContract\ClosedRankedProtocolStore($database),
            static fn () => new \Faluss\Platform\Fans\PfContract\ClosedCorpusInbox($database,
                new \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy('fixture.hub','fixture.fans',['pf.ranking.corpus'],[]),'',''),
            static fn () => new \Faluss\Platform\Fans\Hof\ClosedRankingDelivery($database,
                (new \ReflectionClass(\Faluss\Platform\Fans\Hof\ClosedOriginOpening::class))->newInstanceWithoutConstructor(),
                (new \ReflectionClass(\Faluss\Platform\Fans\PfContract\ClosedBarrierPrimaryRead::class))->newInstanceWithoutConstructor(),
                (new \ReflectionClass(\Faluss\Platform\Fans\Hof\ClosedRankingProjectionStore::class))->newInstanceWithoutConstructor())] as $operation) {
            try { $operation(); self::fail('Copied settings cannot stage private global facts.'); }
            catch (ModelViolation $error) { self::assertSame('isolated_h3_recipe_required',$error->reason); }
        }
        self::assertSame(0,$database->queries);
    }

    public function testBarrierClientCannotBypassThePhysicalGate(): void
    {
        eval('namespace { class wpdb { public int $queries=0; } }');
        $database = new \wpdb();
        $inbox = (new \ReflectionClass(\Faluss\Platform\Fans\PfContract\ClosedBarrierInbox::class))->newInstanceWithoutConstructor();
        try {
            new \Faluss\Platform\Fans\PfContract\ClosedBarrierClient($database,$inbox,'recipe-key','');
            self::fail('The closed client must refuse an ordinary WordPress before HTTP.');
        } catch (ModelViolation $error) { self::assertSame('isolated_h3_recipe_required',$error->reason); }
        self::assertSame(0,$database->queries);
        $primaryType = new \ReflectionClass(\Faluss\Platform\Fans\PfContract\ClosedBarrierPrimaryRead::class);
        $primary = $primaryType->newInstanceWithoutConstructor();
        try {
            (new \Faluss\Platform\Fans\Hof\ClosedSessionRead($database,$primary,'11111111-1111-4111-8111-111111111111'))
                ->readInTransaction('22222222-2222-4222-8222-222222222222');
            self::fail('Session metadata must not read ordinary WordPress storage or trust.');
        } catch (ModelViolation $error) { self::assertSame('isolated_h3_recipe_required',$error->reason); }
        self::assertSame(0,$database->queries);
        foreach ([static fn () => new \Faluss\Platform\Fans\PfContract\ClosedBarrierPrimaryRead($database,$inbox,
            new \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy('fixture.hub','fixture.fans',[],[])),
            static fn () => new \Faluss\Platform\Fans\PfContract\ClosedBarrierPrimaryClient($database,$inbox,$primary,'recipe-key','')] as $operation) {
            try { $operation(); self::fail('Ordinary WordPress cannot refresh a private barrier.'); }
            catch (ModelViolation $error) { self::assertSame('isolated_h3_recipe_required',$error->reason); }
        }
        self::assertSame(0,$database->queries);
        $type = new \ReflectionClass(\Faluss\Platform\Fans\PfContract\ClosedBarrierInbox::class);
        $type->getProperty('db')->setValue($inbox,$database);
        try { $inbox->withAcknowledgementInTransaction('11111111-1111-4111-8111-111111111111',static fn (): bool => true);
            self::fail('Caller transactions cannot bypass the physical gate.'); }
        catch (ModelViolation $error) { self::assertSame('isolated_h3_recipe_required',$error->reason); }
        self::assertSame(0,$database->queries);
        $GLOBALS['wpdb'] = $database;
        foreach (['hof', 'classements', 'classement-fans', 'espace', 'progression'] as $view) {
            $screen = \Faluss\Platform\Fans\Ui\FansUiHof::load('fan', $view);
            self::assertNotNull($screen);
            self::assertFalse($screen->available);
            self::assertNull($screen->ranking);
            self::assertNull($screen->statement);
        }
        self::assertSame(0,$database->queries, 'An ordinary site cannot resolve a ranking provider with copied settings.');
    }

    public function testReaderCannotBypassThePhysicalGateOrPersistAnUnboundedJob(): void
    {
        eval('namespace { class wpdb { public int $queries=0; } }');
        $database = new \wpdb();
        $type = new \ReflectionClass(\Faluss\Platform\Fans\PfContract\ClosedCorpusInbox::class);
        $inbox = $type->newInstanceWithoutConstructor(); $type->getProperty('db')->setValue($inbox,$database);
        $client = (new \ReflectionClass(\Faluss\Platform\Fans\PfContract\ClosedCorpusClient::class))->newInstanceWithoutConstructor();
        $reader = new \Faluss\Platform\Fans\PfContract\ClosedCorpusReader($inbox,$client);
        foreach ([0,17] as $budget) {
            try { $reader->advance('11111111-1111-4111-8111-111111111111',$budget); self::fail('The work budget must be bounded.'); }
            catch (ModelViolation $error) { self::assertSame('pf_local_corpus_budget',$error->reason); }
        }
        try { $reader->advance('11111111-1111-4111-8111-111111111111'); self::fail('Ordinary WordPress cannot run the private reader.'); }
        catch (ModelViolation $error) { self::assertSame('isolated_h3_recipe_required',$error->reason); }
        self::assertSame(0,$database->queries);
    }
    public function testCopiedBarrierAdapterCannotRegisterAnOrdinaryWordPressRoute(): void
    {
        eval('namespace { class wpdb { public int $queries=0; public function get_row($q,$a=null) { $this->queries++; return null; } } function add_action($name,$callback) { $callback(); } function register_rest_route(...$args) { $GLOBALS["recipe_routes"]++; } }');
        $database = new \wpdb(); $GLOBALS['wpdb'] = $database; $GLOBALS['recipe_routes'] = 0;
        require dirname(__DIR__) . '/recipe/b3-barrier-http-adapter.php';
        self::assertSame(0,$database->queries); self::assertSame(0,$GLOBALS['recipe_routes']);
    }

}
