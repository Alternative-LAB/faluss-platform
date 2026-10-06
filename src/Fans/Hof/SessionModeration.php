<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Approval belongs to an exact editorial fingerprint, separate from profile admission and business rights. */
final class SessionModeration
{
    private readonly SessionStore $store;
    /** @var array<string,string> */
    private readonly array $tables;
    public function __construct(private readonly \wpdb $db)
    { $this->store = new SessionStore($db); $this->tables = SessionReviewSchema::tables($db); }

    /** @return array<string,string> */
    public function submit(string $id, int $sessionRevision, int $reviewRevision): array
    {
        $creator = SessionStore::creator()['creator_id']; $this->available();
        return $this->store->transaction(function () use ($creator,$id,$sessionRevision,$reviewRevision): array {
            $session = $this->store->row($id); $this->store->manager($session,$creator,true); SessionStore::revision($session,$sessionRevision);
            $old = $this->row($id); $this->revision($old,$reviewRevision);
            if (!in_array($session['state'],['draft','suspended'],true)
                || (($old['state'] ?? '') === 'pending' && $old['rules_sha256'] === SessionRules::digest($session))) { throw new ModelViolation('hof_invalid_review_transition'); }
            $this->write($id,$reviewRevision + 1,'pending',SessionRules::digest($session),'awaiting_review','','submit');
            return $this->row($id) ?? throw new ModelViolation('hof_storage_unavailable');
        });
    }

    /** @return array<string,string> */
    public function decide(string $id, int $revision, string $action, string $reason): array
    {
        RankingRegistry::administrator(); $this->available();
        if (!in_array($action,['approve','reject','revoke'],true)
            || ($action === 'approve' ? $reason !== 'allowed_session' : !in_array($reason,['needs_revision','prohibited_content','criteria_not_met'],true))) { throw new ModelViolation('hof_invalid_review_decision'); }
        return $this->store->transaction(function () use ($id,$revision,$action,$reason): array {
            $session = $this->store->row($id); $row = $this->row($id) ?? throw new ModelViolation('hof_review_not_found'); $this->revision($row,$revision);
            if ($action === 'revoke' ? $row['state'] !== 'approved' : !in_array($row['state'],['pending','appealed'],true)) { throw new ModelViolation('hof_review_revision_conflict'); }
            if ($row['rules_sha256'] !== SessionRules::digest($session)) { throw new ModelViolation('hof_review_rules_changed'); }
            if ($action === 'approve') { SessionStore::activeOwner($session['owner_creator_id']); }
            $this->write($id,$revision + 1,$action === 'approve' ? 'approved' : 'rejected',$row['rules_sha256'],$reason,$row['appeal_text'],$action);
            if ($action !== 'approve' && in_array($session['state'],['opening','open'],true)) {
                $this->store->change($session,['state' => 'closing','closure_action' => 'suspended'],'moderation_close','',$reason);
            }
            return $this->row($id) ?? throw new ModelViolation('hof_storage_unavailable');
        });
    }

    /** @return array<string,string> */
    public function appeal(string $id, int $revision, string $explanation): array
    {
        $creator = SessionStore::ownerCreator()['creator_id']; $text = TerritoryPolicy::text($explanation,1000); $this->available();
        return $this->store->transaction(function () use ($id,$creator,$revision,$text): array {
            $session = $this->store->row($id);
            if ($session['owner_creator_id'] !== $creator) { throw new ModelViolation('hof_session_forbidden'); }
            $row = $this->row($id) ?? throw new ModelViolation('hof_review_not_found'); $this->revision($row,$revision);
            if ($row['state'] !== 'rejected') { throw new ModelViolation('hof_invalid_review_transition'); }
            if ($row['rules_sha256'] !== SessionRules::digest($session)) { throw new ModelViolation('hof_review_rules_changed'); }
            $this->write($id,$revision + 1,'appealed',$row['rules_sha256'],'member_appeal',$text,'appeal');
            return $this->row($id) ?? throw new ModelViolation('hof_storage_unavailable');
        });
    }

    /** @return array<string,string>|null */
    public function own(string $id): ?array
    {
        $creator = current_user_can('manage_options') ? null : SessionStore::ownerCreator()['creator_id']; $this->available();
        return $this->store->transaction(function () use ($id,$creator): ?array {
            $session = $this->store->row($id);
            if ($creator !== null && $session['owner_creator_id'] !== $creator) { throw new ModelViolation('hof_session_forbidden'); }
            return $this->row($id);
        });
    }

    /** @return list<array<string,string>> */
    public function review(string $after = ''): array
    {
        RankingRegistry::administrator(); $this->available(); if ($after !== '') { ModelValues::uuid($after); }
        return $this->store->transaction(function () use ($after): array {
            $rows = $this->db->get_results($this->db->prepare('SELECT * FROM %i WHERE session_id>%s ORDER BY session_id LIMIT 20',$this->tables['sessions'],$after),'ARRAY_A');
            if (!is_array($rows) || $this->db->last_error !== '') { throw new ModelViolation('hof_storage_unavailable'); }
            return $rows;
        });
    }

    /** Exact private historical content; ordinary owners do not receive moderator technical identifiers.
     * @return list<array<string,string>> */
    public function history(string $id, int $after = 0): array
    {
        $creator = current_user_can('manage_options') ? null : SessionStore::ownerCreator()['creator_id']; $this->available();
        if ($after < 0 || $after >= 2147483646) { throw new ModelViolation('hof_invalid_review_cursor'); }
        return $this->store->transaction(function () use ($id,$creator,$after): array {
            $session = $this->store->row($id);
            if ($creator !== null && $session['owner_creator_id'] !== $creator) { throw new ModelViolation('hof_session_forbidden'); }
            $rows = $this->db->get_results($this->db->prepare('SELECT * FROM %i WHERE session_id=%s AND revision>%d ORDER BY revision LIMIT 20',$this->tables['events'],$id,$after),'ARRAY_A');
            if (!is_array($rows) || $this->db->last_error !== '') { throw new ModelViolation('hof_storage_unavailable'); }
            if ($creator !== null) { foreach ($rows as &$row) { unset($row['actor_id']); } unset($row); }
            return $rows;
        });
    }

    /** Called under the existing session transaction, immediately before freeze/readmission.
     * @param array<string,string> $session */
    public function requireApproval(array $session): void
    {
        $this->available();
        if ((string) $this->db->get_var('SELECT @@in_transaction') !== '1' || $this->db->last_error !== '') { throw new ModelViolation('hof_review_transaction_required'); }
        $row = $this->row($session['session_id']);
        if ($row === null || $row['state'] !== 'approved' || $row['rules_sha256'] !== SessionRules::digest($session)) { throw new ModelViolation('hof_session_approval_required'); }
    }

    private function available(): void
    { if (!defined('FALUSS_PLATFORM_ROLE') || constant('FALUSS_PLATFORM_ROLE') !== 'fans' || !SessionReviewSchema::ready($this->db)) { throw new ModelViolation('hof_review_schema_unavailable'); } }

    /** @return array<string,string>|null */
    private function row(string $id): ?array
    { return $this->store->storage->row($this->db->prepare('SELECT * FROM %i WHERE session_id=%s FOR UPDATE',$this->tables['sessions'],$id)); }

    /** @param array<string,string>|null $row */
    private function revision(?array $row, int $revision): void
    { if ($revision < 0 || $revision >= 2147483646 || (int) ($row['revision'] ?? '0') !== $revision) { throw new ModelViolation('hof_review_revision_conflict'); } }

    private function write(string $id, int $revision, string $state, string $digest, string $reason, string $appeal, string $action): void
    {
        $session = $this->store->row($id);
        $rules = array_intersect_key($session,array_flip(['title','rules_text','category','scope','country','territory_ref','timezone','starts_at','ends_at']));
        $snapshot = json_encode(SessionRules::validate($rules),JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->store->storage->query($this->db->prepare('REPLACE INTO %i (session_id,revision,state,rules_sha256,reason,appeal_text,updated_at) VALUES (%s,%d,%s,%s,%s,%s,UTC_TIMESTAMP(6))',$this->tables['sessions'],$id,$revision,$state,$digest,$reason,$appeal));
        $this->store->storage->query($this->db->prepare('INSERT INTO %i (session_id,revision,actor_id,action,reason,rules_sha256,rules_json,appeal_text,occurred_at) VALUES (%s,%d,%d,%s,%s,%s,%s,%s,UTC_TIMESTAMP(6))',$this->tables['events'],$id,$revision,get_current_user_id(),$action,$reason,$digest,$snapshot,$appeal));
    }
}
