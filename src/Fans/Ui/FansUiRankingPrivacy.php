<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Ui;

use Faluss\Platform\Fans\Hof\ClosedRankingPreferences;
use Faluss\Platform\Fans\Hof\RankingVisibility;
use Faluss\Platform\Fans\Moderation\ModerationPanel;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Current-member POST forms; public consent is independent of economic admission. */
final class FansUiRankingPrivacy
{
    public bool $available = false;
    /** @var array<string,string>|null */
    public ?array $row = null;
    public ?\WP_REST_Response $result = null;
    public ?string $draftAlias = null;

    private function __construct(public readonly string $role, public readonly string $view) {}

    public static function isPost(): bool
    { return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && array_key_exists('hof_privacy_action', $_POST); }

    public static function load(string $role, string $view): ?self
    {
        if (!in_array($role . ':' . $view, ['fan:espace', 'creator:progression'], true)) { return null; }
        $state = new self($role, $view);
        try {
            global $wpdb;
            if (!$wpdb instanceof \wpdb) { throw new ModelViolation('hof_schema_unavailable'); }
            $service = ClosedRankingPreferences::visibility($wpdb);
            $state->row = $service->own();
            $state->available = true;
            if (self::isPost()) {
                if (ModerationPanel::field('hof_privacy_action', $_POST) === 'alias-submit') {
                    $state->draftAlias = mb_substr(ModerationPanel::field('public_alias', $_POST), 0, 160, 'UTF-8');
                }
                try { $state->row = self::submit($service, $role); $state->result = new \WP_REST_Response([], 200); $state->draftAlias = null; }
                catch (ModelViolation $error) { $state->result = self::failure($error->reason); $state->row = $service->own(); }
            }
        } catch (ModelViolation $error) {
            $state->available = false; $state->row = null;
            if (self::isPost()) { $state->result = self::failure($error->reason); }
        }
        return $state;
    }

    /** @return array<string,string> */
    private static function submit(RankingVisibility $service, string $role): array
    {
        $field = static fn (string $key): string => ModerationPanel::field($key, $_POST);
        if (wp_verify_nonce($field('hof_privacy_nonce'), 'fans_hof_preferences') === false) { throw new ModelViolation('hof_forbidden'); }
        $revision = $field('revision');
        if (preg_match('/^(0|[1-9][0-9]{0,9})$/D', $revision) !== 1 || (int) $revision >= 2147483646) { throw new ModelViolation('hof_invalid_input'); }
        $action = $field('hof_privacy_action');
        if ($action === 'consent') {
            if (!in_array($field('ranking_public'), ['', 'yes'], true)) { throw new ModelViolation('hof_invalid_input'); }
            return $service->consent($role, $field('ranking_public') === 'yes', (int) $revision);
        }
        if ($role !== 'fan') { throw new ModelViolation('hof_invalid_input'); }
        if ($action === 'alias-submit') { return $service->submit($field('public_alias'), (int) $revision); }
        if ($action === 'alias-withdraw' && $field('confirm_withdraw') === 'yes') { return $service->withdraw((int) $revision); }
        throw new ModelViolation('hof_invalid_input');
    }

    public function httpStatus(): int { return $this->result?->get_status() ?? 200; }

    public static function failure(string $reason): \WP_REST_Response
    {
        $status = match ($reason) {
            'hof_forbidden', 'hof_linked_member_required' => 403,
            'hof_invalid_input', 'hof_invalid_alias', 'hof_invalid_decision' => 400,
            'hof_revision_conflict', 'hof_approved_creator_required' => 409,
            'hof_rate_limit' => 429,
            default => 503,
        };
        return new \WP_REST_Response(['code' => $reason], $status);
    }
}
