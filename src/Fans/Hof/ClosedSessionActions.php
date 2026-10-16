<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Native application actions around the approved bridge; no HTTP or transition during GET. */
final class ClosedSessionActions
{
    public function __construct(private readonly \wpdb $db)
    { ClosedSessionManagement::services($db); }

    public function available(): bool
    {
        try { ClosedSessionManagement::lifecycle($this->db); return true; }
        catch (ModelViolation) { return false; }
    }

    /** Return private bridge progress. The UI may announce a terminal state only from session_state.
     * @return array<string,mixed> */
    public function perform(string $id, int $revision, string $operation, bool $confirmed = false): array
    {
        if (!in_array($operation, ['open', 'readmit', 'cancel', 'complete', 'resume', 'refresh'], true)) { throw new ModelViolation('hof_invalid_session_transition'); }
        $services = ClosedSessionManagement::services($this->db); $sessions = $services['sessions'];
        $session = $sessions->inspect($id)['session']; SessionStore::revision($session, $revision);
        if (!in_array($operation, ['resume', 'refresh'], true) && $session['owner_creator_id'] !== SessionStore::ownerCreator()['creator_id']) {
            throw new ModelViolation('hof_session_forbidden');
        }
        if (in_array($operation, ['cancel', 'complete'], true) && !$confirmed) { throw new ModelViolation('hof_invalid_session_confirmation'); }
        if ($operation === 'resume' && !in_array($session['state'], ['opening', 'closing'], true)) { throw new ModelViolation('hof_invalid_session_transition'); }
        if ($operation === 'cancel' && !in_array($session['state'], ['opening', 'open'], true)) { throw new ModelViolation('hof_invalid_session_transition'); }
        if ($operation === 'complete' && ($session['state'] !== 'open' || $session['ends_at'] > (new SessionStore($this->db))->now())) {
            throw new ModelViolation('hof_session_deadline_not_reached');
        }
        // Resolve the trusted transport before requesting a new local transition. No browser endpoint/key.
        $bridge = ClosedSessionManagement::lifecycle($this->db);
        if (in_array($operation, ['open', 'readmit', 'refresh'], true)) { ClosedSessionManagement::freshness($this->db); }
        if ($operation === 'refresh') {
            if (!in_array($session['state'], ['open', 'closed', 'cancelled', 'suspended'], true)) { throw new ModelViolation('hof_invalid_session_transition'); }
            $prepared = $bridge->prepare($id, $revision);
            $current = ClosedSessionManagement::freshness($this->db)->refresh($prepared['action_id']);
            return ['session_state' => $session['state'], 'publication_phase' => $current['phase']];
        }
        if ($operation === 'open') {
            $policy = null;
            if ($session['scope'] !== 'international') {
                $territory = (new TerritoryService($this->db))->own();
                if (($territory['state'] ?? '') !== 'approved') { throw new ModelViolation('hof_reviewed_territory_required'); }
                $policy = $territory['policy_id'];
            }
            $session = $sessions->requestOpen($id, $revision, $policy);
        } elseif ($operation === 'readmit') { $session = $sessions->requestReadmission($id, $revision); }
        elseif ($operation === 'cancel' || $operation === 'complete') {
            $session = $sessions->close($id, $revision, $operation === 'cancel' ? 'cancelled' : 'closed');
        }
        // B2 has committed. prepare/advance owns the durable action and same-key primary lookup.
        $progress = $bridge->advance($id, (int) $session['revision']);
        if (isset($progress['session_state'])) {
            try { $current = ClosedSessionManagement::freshness($this->db)->refresh($progress['action_id']); $progress['publication_phase'] = $current['phase']; }
            catch (ModelViolation) { $progress['publication_phase'] = 'pending'; }
        }
        return $progress;
    }
}
