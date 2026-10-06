<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Private participant recourse. A favourable live decision admits only future contributions. */
final class SessionAppeals
{
    private readonly SessionStore $store;
    /** @var array<string,string> */
    private readonly array $tables;
    public function __construct(private readonly \wpdb $db)
    { $this->store = new SessionStore($db); $this->tables = SessionReviewSchema::tables($db); }

    /** @return array<string,string> */
    public function submit(string $sessionId, int $roleRevision, string $explanation): array
    {
        $creator = SessionStore::ownerCreator()['creator_id']; $text = TerritoryPolicy::text($explanation,1000); $this->available();
        return $this->store->transaction(function () use ($sessionId,$roleRevision,$creator,$text): array {
            $this->store->row($sessionId); $role = $this->store->role($sessionId,$creator,'participant');
            if ($roleRevision < 1 || $roleRevision >= 2147483646 || $role === null || $role['state'] !== 'refused' || (int) $role['revision'] !== $roleRevision) { throw new ModelViolation('hof_appeal_refusal_required'); }
            $old = $this->store->storage->row($this->db->prepare('SELECT case_id FROM %i WHERE session_id=%s AND creator_id=%s AND role_revision=%d FOR UPDATE',$this->tables['appeals'],$sessionId,$creator,$roleRevision));
            if ($old !== null) { throw new ModelViolation('hof_appeal_already_submitted'); }
            $id = wp_generate_uuid4();
            $this->store->storage->query($this->db->prepare('INSERT INTO %i (case_id,session_id,creator_id,role_revision,revision,state,explanation,result,reason,updated_at) VALUES (%s,%s,%s,%d,1,%s,%s,%s,%s,UTC_TIMESTAMP(6))',
                $this->tables['appeals'],$id,$sessionId,$creator,$roleRevision,'pending',$text,'','awaiting_review'));
            $this->audit($id,1,'submit','awaiting_review'); return $this->row($id);
        });
    }

    /** @return array<string,string> */
    public function decide(string $id, int $revision, string $action, string $reason): array
    {
        RankingRegistry::administrator(); $this->available();
        $reasons = ['admit' => ['criteria_satisfied'], 'uphold' => ['criteria_not_met','territory_unsubstantiated','needs_revision'],
            'close_without_admission' => ['context_closed','application_replaced','member_withdrew']];
        if (!isset($reasons[$action]) || !in_array($reason,$reasons[$action],true)) { throw new ModelViolation('hof_invalid_appeal_decision'); }
        return $this->store->transaction(function () use ($id,$revision,$action,$reason): array {
            $row = $this->row($id);
            if ($revision < 1 || $revision >= 2147483646 || (int) $row['revision'] !== $revision || $row['state'] !== 'pending') { throw new ModelViolation('hof_appeal_revision_conflict'); }
            $session = $this->store->row($row['session_id']); $role = $this->store->role($row['session_id'],$row['creator_id'],'participant');
            if ($role === null) { throw new ModelViolation('hof_participation_conflict'); }
            if ($action === 'admit') {
                if ($role['revision'] !== $row['role_revision']) { throw new ModelViolation('hof_appeal_application_changed'); }
                SessionAdmission::decide($this->store,$session,$row['creator_id'],true,true);
            } elseif ($action === 'close_without_admission') {
                $valid = match ($reason) {
                    'context_closed' => !in_array($session['state'],['opening','open'],true) || $session['ends_at'] <= $this->store->now(),
                    'application_replaced' => $role['revision'] !== $row['role_revision'],
                    'member_withdrew' => in_array($role['state'],['withdrawn','closing'],true),
                    default => false,
                };
                if (!$valid) { throw new ModelViolation('hof_invalid_appeal_decision'); }
            }
            if ($this->store->storage->query($this->db->prepare('UPDATE %i SET revision=revision+1,state=%s,result=%s,reason=%s,updated_at=UTC_TIMESTAMP(6) WHERE case_id=%s AND revision=%d',
                $this->tables['appeals'],'decided',$action,$reason,$id,$revision)) !== 1) { throw new ModelViolation('hof_appeal_revision_conflict'); }
            $this->audit($id,$revision + 1,$action,$reason); return $this->row($id);
        });
    }

    /** @return array<string,string> */
    public function own(string $id): array
    {
        $creator = current_user_can('manage_options') ? null : SessionStore::ownerCreator()['creator_id']; $this->available();
        return $this->store->transaction(function () use ($id,$creator): array {
            $row = $this->row($id);
            if ($creator !== null && $row['creator_id'] !== $creator) { throw new ModelViolation('hof_appeal_forbidden'); }
            return $row;
        });
    }

    /** Owner and moderator queues are distinct; no recipient override parameter.
     * @return list<array<string,string>> */
    public function list(string $after = ''): array
    {
        $creator = current_user_can('manage_options') ? null : SessionStore::ownerCreator()['creator_id']; $this->available();
        if ($after !== '') { ModelValues::uuid($after); }
        return $this->store->transaction(function () use ($creator,$after): array {
            $sql = $creator === null ? $this->db->prepare('SELECT * FROM %i WHERE case_id>%s ORDER BY case_id LIMIT 20',$this->tables['appeals'],$after)
                : $this->db->prepare('SELECT * FROM %i WHERE creator_id=%s AND case_id>%s ORDER BY case_id LIMIT 20',$this->tables['appeals'],$creator,$after);
            $rows = $this->db->get_results($sql,'ARRAY_A');
            if (!is_array($rows) || $this->db->last_error !== '') { throw new ModelViolation('hof_storage_unavailable'); }
            return $rows;
        });
    }

    private function available(): void
    { if (!defined('FALUSS_PLATFORM_ROLE') || constant('FALUSS_PLATFORM_ROLE') !== 'fans' || !SessionReviewSchema::ready($this->db)) { throw new ModelViolation('hof_review_schema_unavailable'); } }

    /** @return array<string,string> */
    private function row(string $id): array
    { ModelValues::uuid($id); return $this->store->storage->row($this->db->prepare('SELECT * FROM %i WHERE case_id=%s FOR UPDATE',$this->tables['appeals'],$id)) ?? throw new ModelViolation('hof_appeal_not_found'); }

    private function audit(string $id, int $revision, string $action, string $reason): void
    { $this->store->storage->query($this->db->prepare('INSERT INTO %i (case_id,revision,actor_id,action,reason,occurred_at) VALUES (%s,%d,%d,%s,%s,UTC_TIMESTAMP(6))',$this->tables['appeal_log'],$id,$revision,get_current_user_id(),$action,$reason)); }
}
