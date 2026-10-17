<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Ui;

use Faluss\Platform\Fans\Hof\ClosedParticipationActions;
use Faluss\Platform\Fans\Hof\ClosedSessionManagement;
use Faluss\Platform\Fans\Hof\SessionAppeals;
use Faluss\Platform\Fans\Hof\SessionStore;
use Faluss\Platform\Fans\Profiles\EditorialService;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Authorized private participation presentation; never infers approval from a button or role alone. */
final class FansUiSessionParticipants
{
    public bool $manager = false;
    public bool $transport = false;
    public bool $paged = false;
    public string $cursor = '';
    public ?string $next = null;
    /** @var array<string,string>|null */
    public ?array $own = null;
    /** @var array<string,string>|null */
    public ?array $appeal = null;
    /** @var array{bound:bool,opening_applied:bool,closing_applied:bool} */
    public array $checkpoint = ['bound' => false,'opening_applied' => false,'closing_applied' => false];
    /** @var list<array{id:string,name:?string,state:string}> */
    public array $items = [];

    public static function entryAvailable(): bool
    {
        try {
            global $wpdb;
            if (!$wpdb instanceof \wpdb) { return false; }
            ClosedSessionManagement::participation($wpdb); SessionStore::creator(); return true;
        } catch (ModelViolation) { return false; }
    }

    public static function load(FansUiSessions $state): self
    {
        global $wpdb;
        $view = new self(); $view->manager = $state->organizers->manager ?? false;
        $view->transport = (new ClosedParticipationActions($wpdb))->available();
        $roles = [];
        foreach ($state->detail['roles'] ?? [] as $role) {
            if ($role['role'] !== 'participant') { continue; }
            if ($role['creator_id'] === $state->creatorId) { $view->own = $role; }
            $roles[] = $role;
        }
        if ($view->own !== null) {
            if ($view->own['state'] === 'refused') { $view->appeal = (new SessionAppeals($wpdb))->currentForParticipation($state->id); }
            if ($view->transport) { $view->checkpoint = ClosedSessionManagement::participation($wpdb)->checkpoint($state->id,$state->creatorId); }
        }
        $cursor = $_GET['participant_cursor'] ?? '';
        if (!is_string($cursor) || (!$view->manager && $cursor !== '')) { throw new ModelViolation('hof_invalid_participant_cursor'); }
        if ($cursor !== '') { ModelValues::uuid($cursor); }
        if (!$view->manager) { return $view; }
        $view->paged = $cursor !== ''; $view->cursor = $cursor;
        $roles = array_values(array_filter($roles,static fn (array $role): bool => strcmp($role['creator_id'],$cursor) > 0));
        if (count($roles) > 20) { $view->next = $roles[19]['creator_id']; }
        foreach (array_slice($roles,0,20) as $role) {
            $presentation = EditorialService::publicById($role['creator_id']);
            $view->items[] = ['id' => $role['creator_id'],'name' => $presentation['public_name'] ?? null,'state' => $role['state']];
        }
        return $view;
    }
}
