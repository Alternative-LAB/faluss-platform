<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Private orchestration. Opening/closing require a later attested B3 acknowledgement. */
final class SessionService
{
    private readonly SessionStore $store;
    public function __construct(\wpdb $db) { $this->store = new SessionStore($db); }

    /** @param array<string,mixed> $input
     * @return array<string,string> */
    public function create(array $input): array
    {
        $creator = SessionStore::creator()['creator_id']; $rules = SessionRules::validate($input);
        return $this->store->transaction(function () use ($creator, $rules): array {
            SessionStore::activeOwner($creator); $db = $this->store->db; $tables = $this->store->tables;
            $count = $db->get_var($db->prepare('SELECT COUNT(*) FROM %i WHERE owner_creator_id=%s AND created_at>UTC_TIMESTAMP(6)-INTERVAL 1 HOUR', $tables['records'], $creator));
            if ($db->last_error !== '' || !is_numeric($count)) { throw new ModelViolation('hof_storage_unavailable'); }
            if ((int) $count >= 20) { throw new ModelViolation('hof_session_rate_limit'); }
            $id = wp_generate_uuid4();
            $values = array_merge([$tables['records'], $id, $creator, 'draft', ''], array_values($rules), ['', RankingPolicy::VERSION]);
            $this->store->storage->query($db->prepare('INSERT INTO %i (session_id,owner_creator_id,revision,rules_version,barrier_version,state,closure_action,title,rules_text,category,scope,country,territory_ref,timezone,starts_at,ends_at,frozen_sha256,policy_version,created_at,updated_at) VALUES (%s,%s,1,1,0,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',
                ...$values));
            $this->store->storage->query($db->prepare('INSERT INTO %i (session_id,creator_id,role,state,revision,rules_sha256,admitted_at,reason,updated_at) VALUES (%s,%s,%s,%s,1,%s,%s,%s,UTC_TIMESTAMP(6))',
                $tables['roles'], $id, $creator, 'organizer', 'accepted', '', '', 'initiator'));
            $this->store->audit($id, 1, 'create', $creator);
            return $this->store->row($id);
        });
    }

    /** @param array<string,mixed> $input
     * @return array<string,string> */
    public function edit(string $id, int $revision, array $input): array
    {
        $creator = SessionStore::creator()['creator_id']; $rules = SessionRules::validate($input);
        return $this->store->transaction(function () use ($creator, $id, $revision, $rules): array {
            $row = $this->store->row($id); $this->store->manager($row, $creator, true); SessionStore::revision($row, $revision);
            if ($row['state'] !== 'draft' || $row['frozen_sha256'] !== '') { throw new ModelViolation('hof_session_rules_frozen'); }
            return $this->store->change($row, $rules + ['rules_version' => (string) ((int) $row['rules_version'] + 1)], 'edit_rules');
        });
    }

    /** Admission metadata can be prepared; economic opening waits for the primary Hub.
     * @return array<string,string> */
    public function requestOpen(string $id, int $revision, ?string $territoryPolicy = null): array
    {
        $creator = SessionStore::creator()['creator_id'];
        return $this->store->transaction(function () use ($creator, $id, $revision, $territoryPolicy): array {
            $row = $this->store->row($id); $this->store->manager($row, $creator, true); SessionStore::revision($row, $revision);
            if ($row['state'] !== 'draft' || $row['frozen_sha256'] !== '' || $row['ends_at'] <= $this->store->now()) { throw new ModelViolation('hof_invalid_session_transition'); }
            $this->organizers($row,$territoryPolicy);
            (new SessionModeration($this->store->db))->requireApproval($row);
            return $this->store->change($row, ['state' => 'opening', 'frozen_sha256' => SessionRules::digest($row), 'barrier_version' => '1'], 'request_open');
        });
    }

    /** Effective suspension must already have been acknowledged by B3; this never fabricates that state.
     * @return array<string,string> */
    public function requestReadmission(string $id, int $revision): array
    {
        $creator = SessionStore::creator()['creator_id'];
        return $this->store->transaction(function () use ($creator,$id,$revision): array {
            $row = $this->store->row($id); $this->store->manager($row,$creator,true); SessionStore::revision($row,$revision);
            if ($row['state'] !== 'suspended' || $row['ends_at'] <= $this->store->now() || $row['frozen_sha256'] !== SessionRules::digest($row)
                || (int) $row['barrier_version'] < 1 || (int) $row['barrier_version'] >= 2147483646) { throw new ModelViolation('hof_readmission_not_available'); }
            $policy = $row['scope'] !== 'international' ? (new TerritoryService($this->store->db))->boundPolicy($id) : null;
            $this->organizers($row,$policy); (new SessionModeration($this->store->db))->requireApproval($row);
            return $this->store->change($row,['state' => 'opening','closure_action' => '', 'barrier_version' => (string) ((int) $row['barrier_version'] + 1)],'request_readmission');
        });
    }

    /** @param array<string,string> $row */
    private function organizers(array $row, ?string $policy): void
    {
        $territories = null;
        if ($row['scope'] !== 'international') {
            if ($policy === null) { throw new ModelViolation('hof_reviewed_territory_required'); }
            $territories = new TerritoryService($this->store->db); $territories->bind($row['session_id'],$policy);
        } elseif ($policy !== null) { throw new ModelViolation('hof_invalid_territory_binding'); }
        foreach ($this->store->roles($row['session_id']) as $role) {
            if ($role['role'] !== 'organizer') { continue; }
            if ($role['state'] === 'invited') { throw new ModelViolation('hof_coorganizer_acceptance_required'); }
            if ($role['state'] !== 'accepted') { continue; }
            SessionStore::activeOwner($role['creator_id']); $this->store->quota($role['creator_id'],$row['session_id']);
            if ($territories !== null) { $territories->eligible($role['creator_id'],$policy,$row); }
        }
    }

    /** @return array<string,string> */
    public function close(string $id, int $revision, string $action): array
    {
        $creator = $action === 'suspended' && current_user_can('manage_options') ? null : SessionStore::creator()['creator_id'];
        if (!in_array($action, ['closed', 'cancelled', 'suspended'], true) || ($action === 'suspended' && $creator !== null)) { throw new ModelViolation('hof_session_forbidden'); }
        if ($creator === null) { RankingRegistry::administrator(); }
        return $this->store->transaction(function () use ($creator, $id, $revision, $action): array {
            $row = $this->store->row($id); if ($creator !== null) { $this->store->manager($row, $creator, true); }
            SessionStore::revision($row, $revision);
            if (!in_array($row['state'], ['draft', 'opening', 'open'], true)) { throw new ModelViolation('hof_invalid_session_transition'); }
            return $this->store->change($row, ['state' => $row['state'] === 'draft' ? $action : 'closing', 'closure_action' => $action], 'request_close', '', $action);
        });
    }

    /** @return array{session:array<string,string>,roles:list<array<string,string>>} */
    public function inspect(string $id): array
    {
        $creator = current_user_can('manage_options') ? null : SessionStore::ownerCreator()['creator_id'];
        if ($creator === null) { RankingRegistry::administrator(); }
        return $this->store->transaction(function () use ($id, $creator): array {
            $row = $this->store->row($id);
            if ($creator !== null && $this->store->role($id, $creator, 'organizer') === null && $this->store->role($id, $creator, 'participant') === null) { throw new ModelViolation('hof_session_forbidden'); }
            $roles = $this->store->roles($id);
            $manager = $creator === null || ($this->store->role($id, $creator, 'organizer')['state'] ?? '') === 'accepted';
            return ['session' => $row, 'roles' => $manager ? $roles : array_values(array_filter($roles, static fn (array $role): bool => $role['creator_id'] === $creator))];
        });
    }
}
