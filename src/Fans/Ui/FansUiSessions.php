<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Ui;

use Faluss\Platform\Fans\Hof\ClosedSessionManagement;
use Faluss\Platform\Fans\Hof\ClosedSessionActions;
use Faluss\Platform\Fans\Hof\SessionModeration;
use Faluss\Platform\Fans\Hof\SessionRules;
use Faluss\Platform\Fans\Hof\SessionService;
use Faluss\Platform\Fans\Hof\SessionRoles;
use Faluss\Platform\Fans\Hof\SessionStore;
use Faluss\Platform\Fans\Moderation\ModerationPanel;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Private native forms. The B2 services remain authoritative for every transition. */
final class FansUiSessions
{
    public bool $available = false;
    public string $mode = 'sessions';
    public string $creatorId = '';
    public string $profileCategory = '';
    public bool $active = false;
    public string $id = '';
    public string $cursor = '';
    public ?string $destination = null;
    public ?\WP_REST_Response $result = null;
    public string $appealText = '';
    public string $postAction = '';
    public string $submittedRevision = '';
    public string $submittedReviewRevision = '';
    public bool $transportAvailable = false;
    public bool $lifecyclePending = false;
    public bool $publicationPending = false;
    public string $serverNow = '';
    /** @var list<array<string,string>> */
    public array $items = [];
    /** @var array{session:array<string,string>,roles:list<array<string,string>>}|null */
    public ?array $detail = null;
    /** @var array<string,string>|null */
    public ?array $review = null;
    /** @var array<string,string>|null */
    public ?array $draft = null;
    public ?FansUiSessionOrganizers $organizers = null;

    public static function supports(string $role, string $view): bool
    { return $role === 'creator' && $view === 'hof' && array_key_exists('manage', $_GET); }

    public static function isPost(): bool
    { return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && array_key_exists('hof_session_action', $_POST); }

    public static function canManage(): bool
    {
        try {
            global $wpdb;
            if (!$wpdb instanceof \wpdb) { return false; }
            ClosedSessionManagement::services($wpdb); SessionStore::ownerCreator(); return true;
        } catch (ModelViolation) { return false; }
    }

    public static function load(string $role, string $view): ?self
    {
        if (!self::supports($role, $view)) { return null; }
        $state = new self();
        try {
            global $wpdb;
            if (!$wpdb instanceof \wpdb) { throw new ModelViolation('hof_session_schema_unavailable'); }
            $services = ClosedSessionManagement::services($wpdb);
            $profile = SessionStore::ownerCreator(); $state->creatorId = $profile['creator_id']; $state->profileCategory = $profile['category'];
            try { SessionStore::creator(); $state->active = true; }
            catch (ModelViolation $error) { if ($error->reason !== 'hof_approved_creator_required') { throw $error; } }
            $state->mode = self::field('manage', $_GET);
            if (!in_array($state->mode, ['sessions', 'new', 'session'], true)) { throw new ModelViolation('hof_invalid_session_input'); }
            $state->id = self::field('session', $_GET); $state->cursor = self::field('sessions_cursor', $_GET);
            $state->read($services['sessions'], $services['reviews']);
            if ($state->detail !== null) {
                $state->transportAvailable = (new ClosedSessionActions($wpdb))->available();
                $state->serverNow = (new SessionStore($wpdb))->now();
            }
            $state->available = true;
            if (self::isPost()) {
                $action = self::field('hof_session_action', $_POST);
                $state->postAction = $action; $state->submittedRevision = self::field('session_revision', $_POST);
                $state->submittedReviewRevision = self::field('review_revision', $_POST);
                if (in_array($action, ['create', 'edit'], true)) { $state->draft = self::draftFields(); }
                if ($action === 'appeal') { $state->appealText = mb_substr(self::field('explanation', $_POST), 0, 1200, 'UTF-8'); }
                try {
                    $state->submit($services['sessions'], $services['reviews']);
                    $state->result = new \WP_REST_Response(['code' => $state->publicationPending ? 'hof_session_publication_pending' : ''], $state->lifecyclePending || $state->publicationPending ? 202 : 200);
                    $state->draft = null; $state->appealText = '';
                }
                catch (ModelViolation $error) { $state->result = self::failure($error->reason); }
                if ($state->destination === null) { $state->read($services['sessions'], $services['reviews']); }
            }
        } catch (ModelViolation $error) {
            $state->available = false; $state->detail = $state->review = null; $state->items = [];
            $state->result = self::failure($error->reason);
        }
        return $state;
    }

    private function read(SessionService $sessions, SessionModeration $reviews): void
    {
        if ($this->mode === 'sessions') { $this->items = $sessions->own($this->cursor); return; }
        if ($this->mode === 'session') {
            $this->detail = $sessions->inspect($this->id);
            try { SessionRules::validate(array_intersect_key($this->detail['session'], array_flip(['title', 'rules_text', 'category', 'scope', 'country', 'territory_ref', 'timezone', 'starts_at', 'ends_at']))); }
            catch (ModelViolation) { throw new ModelViolation('hof_session_unavailable'); }
            $this->review = $this->isOwner() ? $reviews->own($this->id) : null;
            $this->organizers = FansUiSessionOrganizers::load($this);
        }
    }

    private function submit(SessionService $sessions, SessionModeration $reviews): void
    {
        if (wp_verify_nonce(self::field('hof_session_nonce', $_POST), 'fans_hof_sessions') === false) { throw new ModelViolation('hof_session_forbidden'); }
        $action = self::field('hof_session_action', $_POST);
        if ($action === 'create' && $this->mode === 'new') {
            $created = $sessions->create($this->rules());
            $this->destination = wp_make_link_relative(self::url('session', $created['session_id'])); return;
        }
        if ($this->mode !== 'session') { throw new ModelViolation('hof_invalid_session_input'); }
        if ($action === 'edit') { $sessions->edit($this->id, self::revision('session_revision', 1), $this->rules()); return; }
        if ($action === 'submit') {
            $reviews->submit($this->id, self::revision('session_revision', 1), self::revision('review_revision', 0)); return;
        }
        if ($action === 'appeal') { $reviews->appeal($this->id, self::revision('review_revision', 0), self::field('explanation', $_POST)); return; }
        if (in_array($action, ['invite_organizer', 'accept_organizer', 'refuse_organizer'], true)) {
            global $wpdb;
            $roles = new SessionRoles($wpdb); $revision = self::revision('session_revision', 1);
            if ($action === 'invite_organizer') { $roles->invite($this->id, $revision, self::field('organizer_target', $_POST)); }
            else { $roles->answer($this->id, $revision, $action === 'accept_organizer'); }
            return;
        }
        if (in_array($action, ['open', 'readmit', 'cancel', 'complete', 'resume', 'refresh'], true)) {
            global $wpdb;
            $progress = (new ClosedSessionActions($wpdb))->perform($this->id, self::revision('session_revision', 1), $action,
                self::field('hof_session_confirmation', $_POST) === 'yes');
            $this->lifecyclePending = !in_array($progress['session_state'] ?? '', ['open', 'closed', 'cancelled', 'suspended'], true);
            $this->publicationPending = !$this->lifecyclePending && ($progress['publication_phase'] ?? '') !== 'current';
            return;
        }
        throw new ModelViolation('hof_invalid_session_input');
    }

    /** @return array<string,string> */
    private function rules(): array
    {
        $input = $this->draft ?? []; $old = $this->detail['session'] ?? [];
        $rules = array_intersect_key($input, array_flip(['title', 'rules_text', 'category', 'scope', 'country', 'territory_ref', 'timezone']));
        foreach (['starts', 'ends'] as $part) {
            $rules[$part . '_at'] = FansUiSessionDates::utc($input[$part . '_local'] ?? '', $input['timezone'] ?? '', $input[$part . '_offset'] ?? '', $old[$part . '_at'] ?? null, $old['timezone'] ?? null);
        }
        return SessionRules::validate($rules);
    }

    public function isOwner(): bool
    { return $this->detail !== null && $this->detail['session']['owner_creator_id'] === $this->creatorId; }

    public function currentReview(): bool
    { return $this->detail !== null && $this->review !== null && $this->review['rules_sha256'] === SessionRules::digest($this->detail['session']); }

    public static function url(string $mode = 'sessions', string $id = ''): string
    { return add_query_arg(array_filter(['manage' => $mode, 'session' => $id], static fn (string $value): bool => $value !== ''), FansUiRoutes::url('creator', 'hof')); }

    /** @return array<string,string> */
    private static function draftFields(): array
    {
        $fields = [];
        foreach (['title', 'rules_text', 'category', 'scope', 'country', 'territory_ref', 'timezone', 'starts_local', 'ends_local', 'starts_offset', 'ends_offset'] as $key) {
            $fields[$key] = mb_substr(self::field($key, $_POST), 0, $key === 'rules_text' ? 3100 : 160, 'UTF-8');
        }
        return $fields;
    }

    private static function revision(string $key, int $minimum): int
    {
        $value = self::field($key, $_POST);
        if (preg_match('/^(0|[1-9][0-9]{0,9})$/D', $value) !== 1 || (int) $value < $minimum || (int) $value >= 2147483646) { throw new ModelViolation('hof_invalid_session_input'); }
        return (int) $value;
    }

    /** @param array<string,mixed> $data */
    private static function field(string $key, array $data): string { return ModerationPanel::field($key, $data); }
    public function httpStatus(): int { return $this->result?->get_status() ?? 200; }
    private static function failure(string $reason): \WP_REST_Response
    {
        $status = match ($reason) {
            'hof_session_forbidden', 'hof_linked_creator_required' => 403,
            'hof_session_not_found', 'hof_review_not_found' => 404,
            'hof_session_revision_conflict', 'hof_review_revision_conflict', 'hof_review_rules_changed', 'hof_session_rules_frozen', 'hof_invalid_review_transition', 'hof_approved_creator_required',
            'hof_session_deadline_not_reached', 'hof_session_approval_required', 'hof_coorganizer_acceptance_required', 'hof_reviewed_territory_required', 'hof_invalid_session_transition', 'hof_invalid_coorganizer_invitation' => 409,
            'invalid_identity_or_reference' => 400,
            'hof_session_rate_limit' => 429,
            default => str_starts_with($reason, 'hof_invalid_') || in_array($reason, ['hof_ambiguous_local_time', 'hof_nonexistent_local_time'], true) ? 400 : 503,
        };
        return new \WP_REST_Response(['code' => $reason], $status);
    }
}
