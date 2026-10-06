<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;

/** Private declarations and decisions. No proof of identity, home address, or eligible payer country. */
final class TerritoryService
{
    private readonly SessionStore $store;
    /** @var array<string,string> */
    private readonly array $tables;
    public function __construct(private readonly \wpdb $db)
    { $this->store = new SessionStore($db); $this->tables = TerritorySchema::tables($db); }

    /** @param array<string,mixed> $input
     * @return array<string,string> */
    public function registerPolicy(string $id, array $input): array
    {
        RankingRegistry::administrator(); ModelValues::uuid($id); $policy = TerritoryPolicy::validate($input); $digest = TerritoryPolicy::digest($input); $this->available();
        return $this->store->transaction(function () use ($id, $policy, $digest): array {
            $old = $this->policyRow($id);
            if ($old !== null) {
                if (!hash_equals($old['policy_sha256'], $digest)) { throw new ModelViolation('hof_territory_policy_immutable'); }
                return $old;
            }
            $this->store->storage->query($this->db->prepare('INSERT INTO %i (policy_id,criteria_url,criteria_text,countries_json,territories_json,policy_sha256,actor_id,created_at) VALUES (%s,%s,%s,%s,%s,%s,%d,UTC_TIMESTAMP(6))',
                $this->tables['policies'], $id, $policy['criteria_url'], $policy['criteria_text'], CanonicalJson::encode($policy['countries']), json_encode($policy['territories'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $digest, get_current_user_id()));
            return $this->policyRow($id) ?? throw new ModelViolation('hof_storage_unavailable');
        });
    }

    /** @return array<string,string> */
    public function own(): array
    {
        $creator = SessionStore::ownerCreator()['creator_id']; $this->available();
        return $this->store->transaction(fn (): array => $this->creatorRow($creator) ?? ['creator_id' => $creator, 'revision' => '0', 'state' => 'absent']);
    }

    /** @return array<string,string> */
    public function submit(string $policy, string $country, string $reference, int $revision): array
    {
        $creator = SessionStore::creator()['creator_id']; ModelValues::uuid($policy); $this->available();
        return $this->store->transaction(function () use ($creator, $policy, $country, $reference, $revision): array {
            SessionStore::activeOwner($creator); $old = $this->creatorRow($creator); $this->revision($old, $revision);
            $registered = $this->policyRow($policy) ?? throw new ModelViolation('hof_territory_policy_missing');
            $values = $this->policyValues($registered);
            if (!in_array($country, $values['countries'], true) || !isset($values['territories'][$reference]) || $values['territories'][$reference]['country'] !== $country) { throw new ModelViolation('hof_unknown_principal_territory'); }
            $count = $this->db->get_var($this->db->prepare('SELECT COUNT(*) FROM %i WHERE creator_id=%s AND action=%s AND occurred_at>UTC_TIMESTAMP(6)-INTERVAL 1 HOUR', $this->tables['decisions'], $creator, 'submit'));
            if ($this->db->last_error !== '' || !is_numeric($count)) { throw new ModelViolation('hof_storage_unavailable'); }
            if ((int) $count >= 20) { throw new ModelViolation('hof_territory_rate_limit'); }
            $this->write($creator, $revision + 1, 'pending', $policy, $country, $reference, 0, 'awaiting_review', '', 'submit');
            return $this->creatorRow($creator) ?? throw new ModelViolation('hof_storage_unavailable');
        });
    }

    /** @return array<string,string> */
    public function decide(string $creator, int $revision, string $action, string $reason): array
    {
        RankingRegistry::administrator(); ModelValues::uuid($creator); $this->available();
        if (!in_array($action, ['approve','reject','revoke'], true)
            || ($action === 'approve' ? $reason !== 'criteria_satisfied' : !in_array($reason, ['criteria_not_met','territory_unsubstantiated','needs_revision'], true))) { throw new ModelViolation('hof_invalid_territory_decision'); }
        return $this->store->transaction(function () use ($creator, $revision, $action, $reason): array {
            $row = $this->creatorRow($creator) ?? throw new ModelViolation('hof_territory_not_found'); $this->revision($row, $revision);
            if ($action === 'revoke' ? $row['state'] !== 'approved' : !in_array($row['state'], ['pending','appealed'], true)) { throw new ModelViolation('hof_territory_revision_conflict'); }
            if ($action === 'approve') { SessionStore::activeOwner($creator); $this->policyRow($row['policy_id']) ?? throw new ModelViolation('hof_territory_policy_missing'); }
            $this->write($creator, $revision + 1, $action === 'approve' ? 'approved' : 'rejected', $row['policy_id'], $row['country'], $row['territory_ref'],
                $action === 'approve' ? $revision + 1 : 0, $reason, $row['appeal_text'], $action);
            return $this->creatorRow($creator) ?? throw new ModelViolation('hof_storage_unavailable');
        });
    }

    /** @return array<string,string> */
    public function appeal(int $revision, string $explanation): array
    {
        $creator = SessionStore::ownerCreator()['creator_id']; $text = TerritoryPolicy::text($explanation, 1000); $this->available();
        return $this->store->transaction(function () use ($creator, $revision, $text): array {
            $row = $this->creatorRow($creator) ?? throw new ModelViolation('hof_territory_not_found'); $this->revision($row, $revision);
            if ($row['state'] !== 'rejected') { throw new ModelViolation('hof_invalid_territory_appeal'); }
            $this->write($creator, $revision + 1, 'appealed', $row['policy_id'], $row['country'], $row['territory_ref'], 0, 'member_appeal', $text, 'appeal');
            return $this->creatorRow($creator) ?? throw new ModelViolation('hof_storage_unavailable');
        });
    }

    /** @return array<string,string> */
    public function withdraw(int $revision): array
    {
        $creator = SessionStore::ownerCreator()['creator_id']; $this->available();
        return $this->store->transaction(function () use ($creator, $revision): array {
            $row = $this->creatorRow($creator) ?? throw new ModelViolation('hof_territory_not_found'); $this->revision($row, $revision);
            if ($row['state'] === 'withdrawn') { throw new ModelViolation('hof_territory_revision_conflict'); }
            $this->write($creator, $revision + 1, 'withdrawn', $row['policy_id'], '', '', 0, 'member_withdrawal', '', 'withdraw');
            return $this->creatorRow($creator) ?? throw new ModelViolation('hof_storage_unavailable');
        });
    }

    /** Moderators only, bounded stable pagination. Never an unapproved public directory.
     * @return list<array<string,string>> */
    public function review(string $after = ''): array
    {
        RankingRegistry::administrator(); $this->available(); if ($after !== '') { ModelValues::uuid($after); }
        return $this->store->transaction(function () use ($after): array {
            $rows = $this->db->get_results($this->db->prepare('SELECT * FROM %i WHERE creator_id>%s ORDER BY creator_id LIMIT 20', $this->tables['creators'], $after), 'ARRAY_A');
            if (!is_array($rows) || $this->db->last_error !== '') { throw new ModelViolation('hof_storage_unavailable'); }
            return $rows;
        });
    }

    /** Called inside the existing session transaction; an immutable binding has no independent COMMIT. */
    public function bind(string $session, string $policy): void
    {
        ModelValues::uuid($session); ModelValues::uuid($policy); $this->available(); $this->insideTransaction();
        $this->policyRow($policy) ?? throw new ModelViolation('hof_territory_policy_missing');
        $old = $this->boundPolicy($session);
        if ($old !== null) {
            if ($old !== $policy) { throw new ModelViolation('hof_territory_policy_immutable'); }
            return;
        }
        $this->store->storage->query($this->db->prepare('INSERT INTO %i (session_id,policy_id,created_at) VALUES (%s,%s,UTC_TIMESTAMP(6))', $this->tables['bindings'], $session, $policy));
    }

    public function boundPolicy(string $session): ?string
    {
        $this->available(); $this->insideTransaction();
        $row = $this->store->storage->row($this->db->prepare('SELECT * FROM %i WHERE session_id=%s FOR UPDATE', $this->tables['bindings'], $session));
        return $row['policy_id'] ?? null;
    }

    /** @param array<string,string> $session
     * @return array<string,string> */
    public function eligible(string $creator, string $policy, array $session): array
    {
        $this->available(); $this->insideTransaction(); $row = $this->creatorRow($creator);
        if ($row === null || $row['state'] !== 'approved' || $row['approved_revision'] === '0' || $row['policy_id'] !== $policy
            || $row['country'] !== $session['country'] || ($session['scope'] === 'local' && $row['territory_ref'] !== $session['territory_ref'])) { throw new ModelViolation('hof_reviewed_territory_required'); }
        $this->policyRow($policy) ?? throw new ModelViolation('hof_territory_policy_missing');
        return $row;
    }

    private function available(): void
    { if (!defined('FALUSS_PLATFORM_ROLE') || constant('FALUSS_PLATFORM_ROLE') !== 'fans' || !TerritorySchema::ready($this->db)) { throw new ModelViolation('hof_territory_schema_unavailable'); } }

    private function insideTransaction(): void
    { if ((string) $this->db->get_var('SELECT @@in_transaction') !== '1' || $this->db->last_error !== '') { throw new ModelViolation('hof_territory_transaction_required'); } }

    /** @return array<string,string>|null */
    private function creatorRow(string $id): ?array
    { return $this->store->storage->row($this->db->prepare('SELECT * FROM %i WHERE creator_id=%s FOR UPDATE', $this->tables['creators'], $id)); }

    /** @return array<string,string>|null */
    private function policyRow(string $id): ?array
    {
        $row = $this->store->storage->row($this->db->prepare('SELECT * FROM %i WHERE policy_id=%s FOR UPDATE', $this->tables['policies'], $id));
        if ($row !== null) { $this->policyValues($row); }
        return $row;
    }

    /** @param array<string,string> $row
     * @return array{criteria_url:string,criteria_text:string,countries:list<string>,territories:array<string,array{country:string,name:string}>} */
    private function policyValues(array $row): array
    {
        try { $input = ['criteria_url' => $row['criteria_url'], 'criteria_text' => $row['criteria_text'], 'countries' => json_decode($row['countries_json'], true, 512, JSON_THROW_ON_ERROR), 'territories' => json_decode($row['territories_json'], true, 512, JSON_THROW_ON_ERROR)]; }
        catch (\JsonException) { throw new ModelViolation('hof_territory_policy_corrupt'); }
        if (!hash_equals($row['policy_sha256'], TerritoryPolicy::digest($input))) { throw new ModelViolation('hof_territory_policy_corrupt'); }
        return TerritoryPolicy::validate($input);
    }

    /** @param array<string,string>|null $row */
    private function revision(?array $row, int $revision): void
    { if ($revision < 0 || $revision >= 2147483646 || (int) ($row['revision'] ?? '0') !== $revision) { throw new ModelViolation('hof_territory_revision_conflict'); } }

    private function write(string $id, int $revision, string $state, string $policy, string $country, string $ref, int $approved, string $reason, string $appeal, string $action): void
    {
        $this->store->storage->query($this->db->prepare('REPLACE INTO %i (creator_id,revision,state,policy_id,country,territory_ref,approved_revision,reason,appeal_text,updated_at) VALUES (%s,%d,%s,%s,%s,%s,%d,%s,%s,UTC_TIMESTAMP(6))',
            $this->tables['creators'], $id, $revision, $state, $policy, $country, $ref, $approved, $reason, $appeal));
        $this->store->storage->query($this->db->prepare('INSERT INTO %i (creator_id,revision,actor_id,action,reason,policy_id,country,territory_ref,occurred_at) VALUES (%s,%d,%d,%s,%s,%s,%s,%s,UTC_TIMESTAMP(6))',
            $this->tables['decisions'], $id, $revision, get_current_user_id(), $action, $reason, $policy, $country, $ref));
    }
}
