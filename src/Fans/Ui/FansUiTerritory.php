<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Ui;

use Faluss\Platform\Fans\Hof\ClosedTerritoryActions;
use Faluss\Platform\Fans\Hof\ClosedTerritoryChanges;
use Faluss\Platform\Fans\Hof\ClosedTerritoryChangeSchema;
use Faluss\Platform\Fans\Hof\TerritoryService;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Native owner adapter; declarations, revisions and recovery remain owned by the existing services. */
final class FansUiTerritory
{
    /** @var array<string,string> */
    public array $declaration = [];
    /** @var list<array<string,mixed>> */
    public array $policies = [];
    /** @var array<string,mixed>|null */
    public ?array $currentPolicy = null;
    /** @var list<array<string,string>> */
    public array $changes = [];
    /** @var array{change:array<string,string>,next:?array<string,string>,total:int,applied:int}|null */
    public ?array $progress = null;
    public string $policyCursor = '';
    public string $changeCursor = '';
    public string $changeId = '';

    public static function load(FansUiSessions $state): self
    {
        global $wpdb; $result = new self(); $service = new TerritoryService($wpdb);
        $result->declaration = $service->own();
        $result->policyCursor = self::field('policy_cursor',$_GET);
        $result->changeCursor = self::field('change_cursor',$_GET); $result->changeId = self::field('change',$_GET);
        $result->policies = $service->policies($result->policyCursor);
        if (isset($result->declaration['policy_id'])) { $result->currentPolicy = $service->criteria($result->declaration['policy_id']); }
        if (ClosedTerritoryChangeSchema::ready($wpdb)) {
            $journal = new ClosedTerritoryChanges($wpdb);
            $result->changes = $journal->listing($state->creatorId,$result->changeCursor);
            if ($result->changeId !== '') { $result->progress = $journal->progress($result->changeId); }
        } elseif ($result->changeId !== '' || $result->changeCursor !== '') { throw new ModelViolation('hof_territory_checkpoint_schema_unavailable'); }
        return $result;
    }

    /** Called only after the existing owner-session nonce guard. */
    public function submit(FansUiSessions $state): void
    {
        global $wpdb; $service = new TerritoryService($wpdb); $action = self::field('hof_session_action',$_POST);
        if ($action === 'territory_resume') {
            if ($this->changeId === '' || self::field('change_id',$_POST) !== $this->changeId) { throw new ModelViolation('hof_invalid_territory_input'); }
            $progress = (new ClosedTerritoryActions($wpdb))->resume($this->changeId);
            $state->lifecyclePending = $progress['change']['phase'] !== 'completed'; return;
        }
        $revision = self::field('territory_revision',$_POST);
        if (preg_match('/^(0|[1-9][0-9]{0,9})$/D',$revision) !== 1 || (int) $revision >= 2147483646) { throw new ModelViolation('hof_invalid_territory_input'); }
        if ($action === 'territory_submit') {
            $policy = $service->criteria(self::field('policy_id',$_POST)); $reference = self::field('territory_ref',$_POST);
            $territory = $policy['territories'][$reference] ?? throw new ModelViolation('hof_unknown_principal_territory');
            $service->submit($policy['policy_id'],$territory['country'],$reference,(int) $revision); return;
        }
        if ($action === 'territory_appeal') { $service->appeal((int) $revision,self::field('explanation',$_POST)); return; }
        if ($action === 'territory_withdraw' && self::field('confirm_withdraw',$_POST) === 'yes') { $service->withdraw((int) $revision); return; }
        throw new ModelViolation('hof_invalid_territory_input');
    }

    /** @param array<string,mixed> $source */
    private static function field(string $name, array $source): string
    { return isset($source[$name]) && is_string($source[$name]) ? trim(wp_unslash($source[$name])) : ''; }
}
