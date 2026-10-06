<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\Fans\Profiles\EditorialService;
use Faluss\Platform\Fans\Sso\FansSsoService;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Only Fans-owned governance tables; no reads/writes to the Hub ledger. */
final class SessionStore
{
    public readonly RankingStorage $storage;
    /** @var array<string,string> */
    public readonly array $tables;

    public function __construct(public readonly \wpdb $db)
    { $this->storage = new RankingStorage($db); $this->tables = SessionSchema::tables($db); }

    /** @template T
     * @param callable():T $operation
     * @return T */
    public function transaction(callable $operation): mixed
    {
        if (!SessionSchema::ready($this->db)) { throw new ModelViolation('hof_session_schema_unavailable'); }
        // Small metadata transactions use one domain lock: all organizer quota checks serialize together.
        return $this->storage->transaction('sessions', $operation);
    }

    /** @return array<string,string> */
    public function row(string $id): array
    {
        ModelValues::uuid($id);
        return $this->storage->row($this->db->prepare('SELECT * FROM %i WHERE session_id=%s FOR UPDATE', $this->tables['records'], $id))
            ?? throw new ModelViolation('hof_session_not_found');
    }

    /** @return array<string,string>|null */
    public function role(string $session, string $creator, string $role): ?array
    {
        return $this->storage->row($this->db->prepare('SELECT * FROM %i WHERE session_id=%s AND creator_id=%s AND role=%s FOR UPDATE',
            $this->tables['roles'], $session, $creator, $role));
    }

    /** @return list<array<string,string>> */
    public function roles(string $session): array
    {
        $rows = $this->db->get_results($this->db->prepare('SELECT * FROM %i WHERE session_id=%s ORDER BY creator_id,role FOR UPDATE', $this->tables['roles'], $session), 'ARRAY_A');
        if (!is_array($rows) || $this->db->last_error !== '') { throw new ModelViolation('hof_storage_unavailable'); }
        return $rows;
    }

    /** @param array<string,string> $row */
    public static function revision(array $row, int $revision): void
    { if ($revision < 1 || $revision >= 2147483646 || (int) $row['revision'] !== $revision) { throw new ModelViolation('hof_session_revision_conflict'); } }

    /** @param array<string,string> $row
     * @param array<string,string> $changes
     * @return array<string,string> */
    public function change(array $row, array $changes, string $action, string $target = '', string $reason = ''): array
    {
        $sets = []; $values = [$this->tables['records']];
        foreach ($changes as $key => $value) {
            if (!isset(SessionSchema::definitions()['records']['columns'][$key]) || in_array($key, ['session_id','owner_creator_id','revision','created_at','updated_at'], true)) {
                throw new ModelViolation('hof_invalid_session_change');
            }
            $sets[] = '%i=%s'; $values[] = $key; $values[] = $value;
        }
        $values[] = $row['session_id']; $values[] = $row['revision'];
        if ($this->storage->query($this->db->prepare('UPDATE %i SET ' . ($sets !== [] ? implode(',', $sets) . ',' : '')
            . 'revision=revision+1,updated_at=UTC_TIMESTAMP(6) WHERE session_id=%s AND revision=%d', ...$values)) !== 1) { throw new ModelViolation('hof_session_revision_conflict'); }
        $this->audit($row['session_id'], (int) $row['revision'] + 1, $action, $target, $reason);
        return $this->row($row['session_id']);
    }

    public function audit(string $session, int $revision, string $action, string $target = '', string $reason = ''): void
    {
        $this->storage->query($this->db->prepare('INSERT INTO %i (session_id,revision,actor_id,action,target_creator_id,reason,occurred_at) VALUES (%s,%d,%d,%s,%s,%s,UTC_TIMESTAMP(6))',
            $this->tables['decisions'], $session, $revision, get_current_user_id(), $action, $target, $reason));
    }

    /** @param array<string,string> $row */
    public function manager(array $row, string $creator, bool $ownerOnly = false): void
    {
        self::activeOwner($creator);
        $role = $this->role($row['session_id'], $creator, 'organizer');
        if ($role === null || $role['state'] !== 'accepted' || ($ownerOnly && $row['owner_creator_id'] !== $creator)) { throw new ModelViolation('hof_session_forbidden'); }
    }

    public function quota(string $creator, string $excluding = ''): void
    {
        $count = $this->db->get_var($this->db->prepare('SELECT COUNT(*) FROM %i s INNER JOIN %i r ON r.session_id=s.session_id WHERE r.creator_id=%s AND r.role=%s AND r.state=%s AND s.state IN (%s,%s,%s) AND s.ends_at>UTC_TIMESTAMP(6) AND s.session_id<>%s',
            $this->tables['records'], $this->tables['roles'], $creator, 'organizer', 'accepted', 'opening', 'open', 'closing', $excluding));
        if ($this->db->last_error !== '' || !is_numeric($count)) { throw new ModelViolation('hof_storage_unavailable'); }
        if ((int) $count >= RankingPolicy::MAX_OPEN_SESSIONS) { throw new ModelViolation('hof_session_quota'); }
    }

    public function now(): string
    {
        $now = $this->db->get_var('SELECT UTC_TIMESTAMP(6)'); RankingCalendar::utc($now);
        return (string) $now;
    }

    /** @return array{creator_id:string,category:string,status:string,identity_verified:false,created_at:string,updated_at:string} */
    public static function creator(): array
    {
        $profile = self::ownerCreator();
        if ($profile['status'] !== 'active' || EditorialService::publicById($profile['creator_id']) === null) {
            throw new ModelViolation('hof_approved_creator_required');
        }
        return $profile;
    }

    /** Withdrawal/private reading does not require continued public eligibility.
     * @return array{creator_id:string,category:string,status:string,identity_verified:false,created_at:string,updated_at:string} */
    public static function ownerCreator(): array
    {
        $profile = FansSsoService::currentLinkedSubject() !== null ? CreatorProfileService::own() : null;
        return $profile ?? throw new ModelViolation('hof_linked_creator_required');
    }

    /** Locking approval guard uses a public contract, never the profile module's storage. */
    public static function activeOwner(string $creator): int
    {
        $owner = CreatorProfileService::activeOwner($creator, true);
        if ($owner === null || !EditorialService::approvedInTransaction($creator)) { throw new ModelViolation('hof_approved_creator_required'); }
        return $owner;
    }
}
