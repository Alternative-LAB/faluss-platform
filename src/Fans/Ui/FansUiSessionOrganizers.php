<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Ui;

use Faluss\Platform\Fans\Profiles\EditorialService;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Presentation of roles already authorized by SessionService; no implicit acceptance. */
final class FansUiSessionOrganizers
{
    public bool $manager = false;
    public string $ownState = '';
    /** @var list<array{id:string,name:?string,state:string}> */
    public array $items = [];
    /** @var list<array{id:string,name:string}> */
    public array $candidates = [];
    public ?string $next = null;
    public bool $paged = false;

    public static function load(FansUiSessions $state): self
    {
        $view = new self(); $known = [];
        foreach ($state->detail['roles'] ?? [] as $role) {
            if ($role['role'] !== 'organizer') { continue; }
            $known[$role['creator_id']] = true;
            if ($role['creator_id'] === $state->creatorId) { $view->ownState = $role['state']; }
            $presentation = EditorialService::publicById($role['creator_id']);
            $view->items[] = ['id' => $role['creator_id'], 'name' => is_string($presentation['public_name'] ?? null) ? $presentation['public_name'] : null, 'state' => $role['state']];
        }
        $view->manager = $state->active && $view->ownState === 'accepted';
        if (!$view->manager || ($state->detail['session']['state'] ?? '') !== 'draft') { return $view; }
        $cursor = $_GET['coorganizer_cursor'] ?? null;
        $page = EditorialService::discovery(null, 20, $cursor);
        if (is_wp_error($page)) {
            throw new ModelViolation($page->get_error_code() === 'invalid_discovery_cursor' ? 'hof_invalid_coorganizer_cursor' : 'hof_coorganizers_unavailable');
        }
        $view->paged = $cursor !== null && $cursor !== ''; $view->next = $page['next_cursor'];
        foreach ($page['items'] as $candidate) {
            if (isset($known[$candidate['creator_id']])) { continue; }
            $view->candidates[] = ['id' => $candidate['creator_id'], 'name' => $candidate['editorial']['public_name']];
        }
        return $view;
    }
}
