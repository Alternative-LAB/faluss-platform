<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\Fans\Profiles\EditorialService;
use Faluss\Platform\Fans\Sso\FansSsoService;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Editorial alias and opt-in belong to Fans; Identity remains the account authority. */
final class RankingVisibility
{
    private readonly RankingStorage $storage;
    /** @var array<string,string> */
    private readonly array $tables;

    public function __construct(private readonly \wpdb $db)
    { $this->storage = new RankingStorage($db); $this->tables = RankingSchema::tables($db); }

    /** @return array<string,string> */
    public function own(): array
    {
        $owner = self::owner();
        return $this->storage->transaction('member:' . $owner, fn (): array => $this->member($owner));
    }

    /** @return array<string,string> */
    public function submit(string $alias, int $revision): array
    {
        $owner = self::owner(); $alias = trim($alias);
        if (mb_strlen($alias, 'UTF-8') < 2 || mb_strlen($alias, 'UTF-8') > 80 || preg_match('//u', $alias) !== 1
            || preg_match('/[\x00-\x1F\x7F<>@]/u', $alias) === 1) { throw new ModelViolation('hof_invalid_alias'); }
        return $this->storage->transaction('member:' . $owner, function () use ($owner, $alias, $revision): array {
            $row = $this->member($owner); self::revision($row, $revision);
            $count = $this->db->get_var($this->db->prepare('SELECT COUNT(*) FROM %i WHERE wp_user_id=%d AND action=%s AND occurred_at>UTC_TIMESTAMP(6)-INTERVAL 1 HOUR',
                $this->tables['decisions'], $owner, 'alias_submit'));
            if ($this->db->last_error !== '' || !is_numeric($count)) { throw new ModelViolation('hof_storage_unavailable'); }
            if ((int) $count >= 20) { throw new ModelViolation('hof_rate_limit'); }
            $this->write($owner, $row, ['alias_state' => 'pending', 'pending_alias' => $alias], 'alias_submit', 'awaiting_review');
            return $this->member($owner);
        });
    }

    /** @return array<string,string> */
    public function consent(string $family, bool $enabled, int $revision): array
    {
        $owner = self::owner();
        if (!in_array($family, ['fan', 'creator'], true)) { throw new ModelViolation('hof_invalid_family'); }
        $creator = $family === 'creator' && $enabled ? CreatorProfileService::own() : null;
        if ($family === 'creator' && $enabled && ($creator === null || EditorialService::publicById($creator['creator_id']) === null)) {
            throw new ModelViolation('hof_approved_creator_required');
        }
        return $this->storage->transaction('member:' . $owner, function () use ($owner, $family, $enabled, $revision, $creator): array {
            if ($creator !== null && CreatorProfileService::activeOwner($creator['creator_id'], true) !== $owner) { throw new ModelViolation('hof_approved_creator_required'); }
            $row = $this->member($owner); self::revision($row, $revision);
            // Approval and consent are independent: opting in never approves a pseudonym.
            $this->write($owner, $row, [$family . '_public' => $enabled ? '1' : '0'], $family . '_consent', $enabled ? 'explicit_opt_in' : 'member_withdrawal');
            return $this->member($owner);
        });
    }

    /** @return array<string,string> */
    public function withdraw(int $revision): array
    {
        $owner = self::owner();
        return $this->storage->transaction('member:' . $owner, function () use ($owner, $revision): array {
            $row = $this->member($owner); self::revision($row, $revision);
            $this->write($owner, $row, ['alias_state' => 'withdrawn', 'pending_alias' => '', 'approved_alias' => '',
                'approved_revision' => '0', 'fan_public' => '0'], 'alias_withdraw', 'member_withdrawal');
            return $this->member($owner);
        });
    }

    /** @return array<string,string> */
    public function decide(int $member, int $revision, string $decision, string $reason): array
    {
        RankingRegistry::administrator();
        if ($member < 1 || !in_array($decision, ['approve', 'reject', 'revoke'], true)
            || !in_array($reason, $decision === 'approve' ? ['allowed_alias'] : ['needs_revision', 'prohibited_content'], true)) {
            throw new ModelViolation('hof_invalid_decision');
        }
        return $this->storage->transaction('member:' . $member, function () use ($member, $revision, $decision, $reason): array {
            if (!FansSsoService::linkedMember($member)) { throw new ModelViolation('hof_linked_member_required'); }
            $row = $this->member($member); self::revision($row, $revision);
            if ($decision === 'revoke' ? $row['approved_alias'] === '' : $row['alias_state'] !== 'pending') { throw new ModelViolation('hof_revision_conflict'); }
            $changes = match ($decision) {
                'approve' => ['alias_state' => 'approved', 'approved_alias' => $row['pending_alias'], 'pending_alias' => '', 'approved_revision' => (string) ($revision + 1)],
                'reject' => ['alias_state' => 'rejected', 'pending_alias' => ''],
                default => ['alias_state' => 'rejected', 'approved_alias' => '', 'pending_alias' => '', 'approved_revision' => '0', 'fan_public' => '0'],
            };
            $this->write($member, $row, $changes, 'alias_' . $decision, $reason);
            return $this->member($member);
        });
    }

    /** Server delivery contract only: no recipient is accepted from a public request. */
    public function visibleFan(int $member): ?string
    {
        if (!RankingSchema::ready($this->db) || !FansSsoService::linkedMember($member)) { return null; }
        $row = $this->storage->row($this->db->prepare('SELECT approved_alias,fan_public FROM %i WHERE wp_user_id=%d', $this->tables['members'], $member));
        return $row !== null && $row['fan_public'] === '1' && $row['approved_alias'] !== '' ? $row['approved_alias'] : null;
    }

    /** Active/public presentation and ranking consent are checked again at delivery. */
    public function visibleCreator(string $creatorId): ?string
    {
        if (!RankingSchema::ready($this->db)) { return null; }
        $owner = CreatorProfileService::activeOwner($creatorId);
        if ($owner === null) { return null; }
        $row = $this->storage->row($this->db->prepare('SELECT revision,creator_public FROM %i WHERE wp_user_id=%d', $this->tables['members'], $owner));
        if ($row === null || $row['creator_public'] !== '1') { return null; }
        // The editorial module owns its approved fields and transaction; never join its private tables here.
        $editorial = EditorialService::publicById($creatorId);
        $latest = $this->storage->row($this->db->prepare('SELECT revision,creator_public FROM %i WHERE wp_user_id=%d', $this->tables['members'], $owner));
        return $latest === $row && $editorial !== null && CreatorProfileService::activeOwner($creatorId) === $owner ? (string) $editorial['public_name'] : null;
    }

    /** Private review contract, at most twenty rows and a local member cursor.
     * @return list<array<string,string>> */
    public function review(int $after = 0): array
    {
        RankingRegistry::administrator();
        if ($after < 0 || !RankingSchema::ready($this->db)) { throw new ModelViolation('hof_schema_unavailable'); }
        $rows = $this->db->get_results($this->db->prepare('SELECT * FROM %i WHERE alias_state=%s AND wp_user_id>%d ORDER BY wp_user_id LIMIT 20', $this->tables['members'], 'pending', $after), 'ARRAY_A');
        if (!is_array($rows) || $this->db->last_error !== '') { throw new ModelViolation('hof_storage_unavailable'); }
        return $rows;
    }

    /** @return array<string,string> */
    private function member(int $owner): array
    {
        $row = $this->storage->row($this->db->prepare('SELECT * FROM %i WHERE wp_user_id=%d FOR UPDATE', $this->tables['members'], $owner));
        return $row ?? ['wp_user_id' => (string) $owner, 'revision' => '0', 'alias_state' => 'absent', 'pending_alias' => '',
            'approved_alias' => '', 'approved_revision' => '0', 'fan_public' => '0', 'creator_public' => '0', 'updated_at' => ''];
    }

    /** @param array<string,string> $row
     * @param array<string,string> $changes */
    private function write(int $owner, array $row, array $changes, string $action, string $reason): void
    {
        $data = array_replace($row, $changes, ['revision' => (string) ((int) $row['revision'] + 1)]);
        $values = array_values(array_diff_key($data, ['updated_at' => true]));
        $this->storage->query($this->db->prepare('REPLACE INTO %i (wp_user_id,revision,alias_state,pending_alias,approved_alias,approved_revision,fan_public,creator_public,updated_at) VALUES (%d,%d,%s,%s,%s,%d,%d,%d,UTC_TIMESTAMP(6))',
            $this->tables['members'], ...$values));
        $this->storage->query($this->db->prepare('INSERT INTO %i (wp_user_id,revision,actor_id,action,reason,occurred_at) VALUES (%d,%d,%d,%s,%s,UTC_TIMESTAMP(6))',
            $this->tables['decisions'], $owner, (int) $data['revision'], get_current_user_id(), $action, $reason));
    }

    /** @param array<string,string> $row */
    private static function revision(array $row, int $expected): void
    {
        if ($expected < 0 || $expected >= 2147483646 || (int) $row['revision'] !== $expected) { throw new ModelViolation('hof_revision_conflict'); }
    }

    private static function owner(): int
    {
        if (FansSsoService::currentLinkedSubject() === null) { throw new ModelViolation('hof_linked_member_required'); }
        return get_current_user_id();
    }
}
