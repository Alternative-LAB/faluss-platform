<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Explicit application actions; member withdrawal closes choices before transport recovery. */
final class ClosedParticipationActions
{
    public function __construct(private readonly \wpdb $db) { ClosedSessionManagement::services($db); }

    public function available(): bool
    {
        try { ClosedSessionManagement::participation($this->db); return true; }
        catch (ModelViolation) { return false; }
    }

    /** @return array<string,string> */
    public function perform(string $id, int $revision, string $action, string $target = ''): array
    {
        $services = ClosedSessionManagement::services($this->db); $sessions = $services['sessions'];
        $current = $sessions->inspect($id)['session']; SessionStore::revision($current,$revision);
        $roles = new SessionRoles($this->db);
        if ($action === 'refuse_participant') {
            $roles->admit($id,$revision,$target,false); return ['participation_state' => 'refused'];
        }
        if ($action === 'withdraw_participation') {
            $target = SessionStore::ownerCreator()['creator_id']; $current = $roles->withdraw($id,$revision);
            $role = array_values(array_filter($sessions->inspect($id)['roles'],
                static fn (array $value): bool => $value['role'] === 'participant' && $value['creator_id'] === $target))[0] ?? null;
            if (($role['state'] ?? '') === 'withdrawn') { return ['participation_state' => 'withdrawn']; }
            // B2 is committed even if the provider or network becomes unavailable afterwards.
            try { return ClosedSessionManagement::participation($this->db)->advance($id,(int) $current['revision'],$target); }
            catch (ModelViolation) { return ['participation_state' => 'closing','state' => 'pending']; }
        }
        if (!in_array($action,['admit_participant','resume_participation'],true)) { throw new ModelViolation('hof_invalid_participation_action'); }
        $bridge = ClosedSessionManagement::participation($this->db);
        if ($action === 'admit_participant') {
            $current = $bridge->admit($id,$revision,$target);
            try { return $bridge->advance($id,(int) $current['revision'],$target); }
            catch (ModelViolation) { return ['participation_state' => 'admitted','state' => 'pending']; }
        }
        return $bridge->advance($id,$revision,$target);
    }
}
