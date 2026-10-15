<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Ui;

use Faluss\Platform\Fans\Hof\ClosedRankingDelivery;
use Faluss\Platform\Fans\Hof\RankingPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;

/** Closed B6 reader adapter. No activation, identity override or browser-supplied recipient. */
final class FansUiHof
{
    public const VIEWS = ['hof', 'hof/session', 'classements', 'classement-fans', 'espace', 'progression'];
    public bool $available = false;
    public string $category = '';
    public string $month = '';
    public string $family = 'creator';
    public int $page = 1;
    /** @var array{items:list<array{name:string,points:string,position:string}>,source_at:string}|null */
    public ?array $ranking = null;
    /** @var array{month:string,start:string,end:string,points:string,source_at:string}|null */
    public ?array $statement = null;
    /** @var array{items:list<array<string,mixed>>,next_cursor:?string,source_at:string}|null */
    public ?array $sessions = null;
    /** @var array<string,mixed>|null */
    public ?array $session = null;

    private function __construct(public readonly string $role, public readonly string $view) {}

    public static function load(string $role, string $view): ?self
    {
        if (!in_array($view, self::VIEWS, true)) { return null; }
        $state = new self($role, $view);
        $state->family = $view === 'classement-fans' || $view === 'espace' ? 'fan' : 'creator';
        $state->month = (new \DateTimeImmutable('now', new \DateTimeZone(RankingPolicy::MONTH_TIMEZONE)))->format('Y-m');
        try {
            global $wpdb;
            if (!$wpdb instanceof \wpdb) { return $state; }
            // A filter or copied constant cannot admit this transport on an ordinary site.
            ClosedEnvironment::assertIsolated($wpdb, 'fans');
            $provider = apply_filters('faluss_fans_hof_closed_delivery', null);
            if (!$provider instanceof ClosedRankingDelivery) { return $state; }
            if (isset($_GET['category'])) {
                if (!is_string($_GET['category'])) { throw new ModelViolation('hof_invalid_category'); }
                $category = wp_unslash($_GET['category']);
                $state->category = $category === '' ? '' : RankingPolicy::category($category);
            }
            if (isset($_GET['rank_page'])) {
                if (!is_string($_GET['rank_page']) || preg_match('/^[1-9][0-9]{0,4}$/D', $_GET['rank_page']) !== 1) {
                    throw new ModelViolation('hof_invalid_page');
                }
                $state->page = (int) $_GET['rank_page'];
            }
            if (in_array($view, ['espace', 'progression'], true)) {
                if (!in_array($role, ['fan', 'creator'], true)) { throw new ModelViolation('hof_linked_member_required'); }
                if (isset($_GET['month'])) {
                    if (!is_string($_GET['month'])) { throw new ModelViolation('hof_invalid_month'); }
                    $state->month = wp_unslash($_GET['month']);
                }
                $state->statement = $provider->statement($state->family, $state->month);
            } elseif ($view === 'hof/session') {
                if (!isset($_GET['session']) || !is_string($_GET['session'])) { throw new ModelViolation('hof_session_not_found'); }
                $state->session = $provider->session(wp_unslash($_GET['session']));
            } else {
                if ($view === 'hof') {
                    if (isset($_GET['session_cursor']) && !is_string($_GET['session_cursor'])) { throw new ModelViolation('hof_invalid_cursor'); }
                    $overview = $provider->overview($state->category === '' ? null : $state->category,
                        isset($_GET['session_cursor']) ? wp_unslash($_GET['session_cursor']) : '');
                    $state->ranking = $overview['ranking']; $state->sessions = $overview['sessions'];
                } else { $state->ranking = $provider->ranking($state->family, $state->category === '' ? null : $state->category); }
            }
            $state->available = $state->ranking !== null || $state->statement !== null || $state->session !== null || $state->sessions !== null;
        } catch (ModelViolation) {
            // No diagnostic, partial score or last known rank is sent to the member.
            $state->ranking = $state->statement = null;
            $state->session = $state->sessions = null;
        }
        return $state;
    }
}
