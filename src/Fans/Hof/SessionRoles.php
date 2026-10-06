<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\Fans\Profiles\EditorialService;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Coorganization is explicit. Participation is independent, individual and voluntary. */
final class SessionRoles
{
    private readonly SessionStore $store;
    public function __construct(\wpdb $db) { $this->store = new SessionStore($db); }

    /** @return array<string,string> */
    public function invite(string $id, int $revision, string $target): array
    {
        $creator = SessionStore::creator()['creator_id']; ModelValues::uuid($target);
        if (EditorialService::publicById($target) === null) { throw new ModelViolation('hof_approved_creator_required'); }
        return $this->store->transaction(function () use ($id, $revision, $creator, $target): array {
            $row = $this->store->row($id); $this->store->manager($row, $creator); SessionStore::revision($row, $revision);
            SessionStore::activeOwner($target);
            if ($row['state'] !== 'draft' || $this->store->role($id, $target, 'organizer') !== null) { throw new ModelViolation('hof_invalid_coorganizer_invitation'); }
            $this->writeRole($id, $target, 'organizer', 'invited', 1, '', '', 'awaiting_acceptance');
            return $this->store->change($row, [], 'invite_organizer', $target);
        });
    }

    /** @return array<string,string> */
    public function answer(string $id, int $revision, bool $accept): array
    {
        $creator = ($accept ? SessionStore::creator() : SessionStore::ownerCreator())['creator_id'];
        return $this->store->transaction(function () use ($id, $revision, $creator, $accept): array {
            $row = $this->store->row($id); SessionStore::revision($row, $revision);
            $role = $this->store->role($id, $creator, 'organizer');
            if ($row['state'] !== 'draft' || $role === null || $role['state'] !== 'invited') { throw new ModelViolation('hof_invalid_coorganizer_invitation'); }
            if ($accept) { SessionStore::activeOwner($creator); }
            $this->writeRole($id, $creator, 'organizer', $accept ? 'accepted' : 'refused', (int) $role['revision'] + 1, '', '', $accept ? 'explicit_acceptance' : 'member_declined');
            return $this->store->change($row, [], 'answer_organizer', $creator, $accept ? 'accepted' : 'refused');
        });
    }

    /** Rules digest must be accepted explicitly; membership is not implicit for organizers.
     * @return array<string,string> */
    public function apply(string $id, int $revision, string $acceptedRules): array
    {
        $creator = SessionStore::creator()['creator_id'];
        return $this->store->transaction(function () use ($id, $revision, $creator, $acceptedRules): array {
            $row = $this->store->row($id); SessionStore::revision($row, $revision); SessionStore::activeOwner($creator);
            if (!in_array($row['state'], ['opening', 'open'], true) || $row['ends_at'] <= $this->store->now()
                || $row['frozen_sha256'] === '' || !hash_equals($row['frozen_sha256'], $acceptedRules)) { throw new ModelViolation('hof_session_rules_acceptance_required'); }
            $old = $this->store->role($id, $creator, 'participant');
            if ($old !== null && !in_array($old['state'], ['refused', 'withdrawn'], true)) { throw new ModelViolation('hof_participation_conflict'); }
            $this->writeRole($id, $creator, 'participant', 'requested', (int) ($old['revision'] ?? '0') + 1, $acceptedRules, '', 'awaiting_admission');
            return $this->store->change($row, [], 'request_participation', $creator);
        });
    }

    /** @return array<string,string> */
    public function admit(string $id, int $revision, string $target, bool $allow): array
    {
        $creator = SessionStore::creator()['creator_id']; ModelValues::uuid($target);
        if ($allow && EditorialService::publicById($target) === null) { throw new ModelViolation('hof_approved_creator_required'); }
        return $this->store->transaction(function () use ($id, $revision, $creator, $target, $allow): array {
            $row = $this->store->row($id); $this->store->manager($row, $creator); SessionStore::revision($row, $revision);
            if (!in_array($row['state'], ['opening', 'open'], true) || $row['ends_at'] <= $this->store->now()) { throw new ModelViolation('hof_invalid_session_transition'); }
            $role = $this->store->role($id, $target, 'participant');
            if ($role === null || $role['state'] !== 'requested' || $role['rules_sha256'] !== $row['frozen_sha256']) { throw new ModelViolation('hof_participation_conflict'); }
            if ($allow) {
                SessionStore::activeOwner($target);
                if ($row['scope'] !== 'international') {
                    $territories = new TerritoryService($this->store->db);
                    $policy = $territories->boundPolicy($id) ?? throw new ModelViolation('hof_reviewed_territory_required');
                    $territories->eligible($target, $policy, $row);
                }
            }
            $this->writeRole($id, $target, 'participant', $allow ? 'admitted' : 'refused', (int) $role['revision'] + 1,
                $role['rules_sha256'], $allow ? $this->store->now() : '', $allow ? 'allowed_participation' : 'criteria_not_met');
            return $this->store->change($row, [], 'decide_participation', $target, $allow ? 'allowed' : 'refused');
        });
    }

    /** Stop future choices immediately; an admitted context needs a B3 close acknowledgement.
     * @return array<string,string> */
    public function withdraw(string $id, int $revision): array
    {
        $creator = SessionStore::ownerCreator()['creator_id'];
        return $this->store->transaction(function () use ($id, $revision, $creator): array {
            $row = $this->store->row($id); SessionStore::revision($row, $revision);
            $role = $this->store->role($id, $creator, 'participant');
            if ($role === null || !in_array($role['state'], ['requested', 'admitted', 'refused'], true)) { throw new ModelViolation('hof_participation_conflict'); }
            $this->writeRole($id, $creator, 'participant', $role['state'] === 'admitted' ? 'closing' : 'withdrawn', (int) $role['revision'] + 1,
                $role['rules_sha256'], $role['admitted_at'], 'member_withdrawal');
            return $this->store->change($row, [], 'withdraw_participation', $creator);
        });
    }

    private function writeRole(string $session, string $creator, string $role, string $state, int $revision, string $rules, string $admitted, string $reason): void
    {
        $this->store->storage->query($this->store->db->prepare('REPLACE INTO %i (session_id,creator_id,role,state,revision,rules_sha256,admitted_at,reason,updated_at) VALUES (%s,%s,%s,%s,%d,%s,%s,%s,UTC_TIMESTAMP(6))',
            $this->store->tables['roles'], $session, $creator, $role, $state, $revision, $rules, $admitted, $reason));
    }
}
