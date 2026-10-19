<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingBarrier;

/** Local invalidation journal only. No HTTP, PF ledger, automatic schema or production admission. */
final class ClosedTerritoryChanges
{
    public function __construct(private readonly \wpdb $db)
    { ClosedEnvironment::assertIsolated($db,'fans'); }

    /** Current owner, or an administrator reviewing that exact declaration. No mutation during GET.
     * @return list<array<string,string>> */
    public function listing(string $creator, string $after = ''): array
    {
        ClosedEnvironment::assertIsolated($this->db,'fans'); ModelValues::uuid($creator);
        if ($after !== '') { ModelValues::uuid($after); }
        if (current_user_can('manage_options')) { RankingRegistry::administrator(); }
        elseif (SessionStore::ownerCreator()['creator_id'] !== $creator) { throw new ModelViolation('hof_session_forbidden'); }
        if (!ClosedTerritoryChangeSchema::ready($this->db)) { throw new ModelViolation('hof_territory_checkpoint_schema_unavailable'); }
        return (new SessionStore($this->db))->transaction(function () use ($creator,$after): array {
            $rows = $this->db->get_results($this->db->prepare('SELECT * FROM %i WHERE creator_id=%s AND change_id>%s ORDER BY change_id LIMIT 20',
                ClosedTerritoryChangeSchema::tables($this->db)['changes'],$creator,$after),'ARRAY_A');
            if (!is_array($rows) || $this->db->last_error !== '') { throw new ModelViolation('hof_storage_unavailable'); }
            return $rows;
        });
    }

    /** One next target and aggregate progress keep recovery bounded even beyond twenty admissions.
     * @return array{change:array<string,string>,next:?array<string,string>,total:int,applied:int} */
    public function progress(string $id): array
    {
        ClosedEnvironment::assertIsolated($this->db,'fans'); ModelValues::uuid($id);
        if (!ClosedTerritoryChangeSchema::ready($this->db)) { throw new ModelViolation('hof_territory_checkpoint_schema_unavailable'); }
        $store = new SessionStore($this->db); $tables = ClosedTerritoryChangeSchema::tables($this->db);
        return $store->transaction(function () use ($id,$store,$tables): array {
            $change = $store->storage->row($this->db->prepare('SELECT * FROM %i WHERE change_id=%s FOR UPDATE',$tables['changes'],$id))
                ?? throw new ModelViolation('hof_territory_checkpoint_not_found');
            self::authorize($change);
            $count = $store->storage->row($this->db->prepare('SELECT COUNT(*) AS total,COALESCE(SUM(state=%s),0) AS applied FROM %i WHERE change_id=%s','applied',$tables['items'],$id));
            $next = $store->storage->row($this->db->prepare('SELECT * FROM %i WHERE change_id=%s AND state<>%s ORDER BY kind,session_id,barrier_version LIMIT 1 FOR UPDATE',
                $tables['items'],$id,'applied'));
            if ($count === null || !is_numeric($count['total']) || !is_numeric($count['applied'])) { throw new ModelViolation('hof_storage_unavailable'); }
            return ['change' => $change,'next' => $next,'total' => (int) $count['total'],'applied' => (int) $count['applied']];
        });
    }

    /** Called only by the territory owner inside its current governance transaction.
     * Historical B2 instances without closed Hub bindings keep their existing behavior.
     * @param array<string,string>|null $previous */
    public static function record(\wpdb $db, ?array $previous, int $revision, string $action): void
    {
        if ($previous === null || $previous['state'] !== 'approved' || $previous['approved_revision'] === '0'
            || !defined('FALUSS_PF_H3_RECIPE_ONLY') || constant('FALUSS_PF_H3_RECIPE_ONLY') !== true) { return; }
        ClosedEnvironment::assertIsolated($db,'fans'); self::inside($db);
        ModelValues::uuid($previous['creator_id']); ModelValues::uuid($previous['policy_id']);
        ModelValues::integer($previous['approved_revision'],true); ModelValues::integer($previous['revision'],true);
        if (!in_array($action,['submit','revoke','withdraw'],true) || $revision !== (int) $previous['revision']+1) {
            throw new ModelViolation('hof_territory_revision_conflict');
        }
        $store = new SessionStore($db); $participant = self::participationMetadata($db);
        $hasParticipant = $participant && $store->storage->row($db->prepare('SELECT session_id FROM %i WHERE creator_id=%s LIMIT 1',
            ClosedParticipationSchema::tables($db)['versions'],$previous['creator_id'])) !== null;
        $sessions = self::organizerTargets($db,$previous);
        if (!$hasParticipant && $sessions === []) { return; }
        if (!ClosedTerritoryChangeSchema::ready($db)) { throw new ModelViolation('hof_territory_checkpoint_schema_unavailable'); }
        $tables = ClosedTerritoryChangeSchema::tables($db);
        if ($store->storage->row($db->prepare('SELECT change_id FROM %i WHERE creator_id=%s AND approval_revision=%s FOR UPDATE',
            $tables['changes'],$previous['creator_id'],$previous['approved_revision'])) !== null) {
            throw new ModelViolation('hof_territory_checkpoint_conflict');
        }
        $id = wp_generate_uuid4();
        $store->storage->query($db->prepare('INSERT INTO %i (creator_id,approval_revision,change_id,territory_revision,policy_id,action,actor_id,phase,cursor_session,cursor_version,created_at,updated_at)'
            . ' VALUES (%s,%s,%s,%d,%s,%s,%d,%s,%s,0,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',
            $tables['changes'],$previous['creator_id'],$previous['approved_revision'],$id,$revision,$previous['policy_id'],$action,
            get_current_user_id(),$hasParticipant ? 'collecting' : 'draining',''));
        foreach ($sessions as $session) {
            // Capture the actual parent version now: later readmission must never be suspended by an old event.
            $descriptor = SessionBarrierDescriptor::compose($session['row'],$session['origin'],$previous['policy_id']);
            $store->storage->query($db->prepare('INSERT INTO %i (change_id,kind,session_id,barrier_version,descriptor_sha256,action_id,state,applied_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s)',
                $tables['items'],$id,'session',$session['row']['session_id'],$session['row']['barrier_version'],
                hash('sha256',CanonicalJson::encode($descriptor)),'','queued',''));
            if (in_array($session['row']['state'],['opening','open'],true)) {
                $store->change($session['row'],['state' => 'closing','closure_action' => 'suspended'],
                    'territory_organizer_invalidated',$previous['creator_id'],'territory_admission_revoked');
            }
        }
    }

    /** Explicit bounded checkpoint, never called by a GET or a background production hook.
     * The old approval cannot acquire new bindings after invalidation under the same domain mutex.
     * @return array<string,string> */
    public function collect(string $id, int $limit = 20): array
    {
        if ($limit < 1 || $limit > 20) { throw new ModelViolation('hof_territory_checkpoint_budget'); }
        ModelValues::uuid($id); ClosedEnvironment::assertIsolated($this->db,'fans');
        if (!ClosedTerritoryChangeSchema::ready($this->db)) { throw new ModelViolation('hof_territory_checkpoint_schema_unavailable'); }
        $store = new SessionStore($this->db); $tables = ClosedTerritoryChangeSchema::tables($this->db);
        return $store->transaction(function () use ($id,$limit,$store,$tables): array {
            $change = $store->storage->row($this->db->prepare('SELECT * FROM %i WHERE change_id=%s FOR UPDATE',$tables['changes'],$id))
                ?? throw new ModelViolation('hof_territory_checkpoint_not_found');
            self::authorize($change);
            if ($change['phase'] !== 'collecting') { return $change; }
            if (!ClosedParticipationSchema::ready($this->db)) { throw new ModelViolation('hof_participation_schema_unavailable'); }
            if ($change['cursor_session'] !== '') { ModelValues::uuid($change['cursor_session']); ModelValues::integer($change['cursor_version'],true); }
            elseif ($change['cursor_version'] !== '0') { throw new ModelViolation('hof_territory_checkpoint_conflict'); }
            $rows = $this->db->get_results($this->db->prepare('SELECT * FROM %i WHERE creator_id=%s'
                . ' AND (session_id>%s OR (session_id=%s AND barrier_version>%s)) ORDER BY session_id,barrier_version LIMIT %d FOR UPDATE',
                ClosedParticipationSchema::tables($this->db)['versions'],$change['creator_id'],$change['cursor_session'],
                $change['cursor_session'],$change['cursor_version'],$limit+1),'ARRAY_A');
            if (!is_array($rows) || $this->db->last_error !== '') { throw new ModelViolation('hof_storage_unavailable'); }
            $cursor = $change['cursor_session']; $version = $change['cursor_version'];
            foreach (array_slice($rows,0,$limit) as $row) {
                $descriptor = RankingBarrier::descriptor(CanonicalJson::object($row['descriptor_json'])); $content = $descriptor['content'];
                if ($row['descriptor_sha256'] !== hash('sha256',$row['descriptor_json']) || $content['kind'] !== 'admission'
                    || $content['object_id'] !== $row['session_id'] || $content['version'] !== $row['barrier_version']
                    || $content['subject_id'] !== $row['subject_id'] || $content['session_barrier_version'] !== $row['session_barrier_version']) {
                    throw new ModelViolation('hof_participation_binding_conflict');
                }
                if ($content['territory_policy_revision'] === $change['policy_id'] && $content['territory_admission_revision'] === $change['approval_revision']) {
                    $store->storage->query($this->db->prepare('INSERT INTO %i (change_id,kind,session_id,barrier_version,descriptor_sha256,action_id,state,applied_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s)',
                        $tables['items'],$id,'admission',$row['session_id'],$row['barrier_version'],$row['descriptor_sha256'],'','queued',''));
                }
                $cursor = $row['session_id']; $version = $row['barrier_version'];
            }
            $phase = count($rows) > $limit ? 'collecting' : 'draining';
            if ($store->storage->query($this->db->prepare('UPDATE %i SET phase=%s,cursor_session=%s,cursor_version=%s,updated_at=UTC_TIMESTAMP(6)'
                . ' WHERE change_id=%s AND phase=%s AND cursor_session=%s AND cursor_version=%s',
                $tables['changes'],$phase,$cursor,$version,$id,$change['phase'],$change['cursor_session'],$change['cursor_version'])) !== 1) {
                throw new ModelViolation('hof_territory_checkpoint_conflict');
            }
            if ($phase === 'draining') { self::finish($this->db,$id); }
            return $store->storage->row($this->db->prepare('SELECT * FROM %i WHERE change_id=%s FOR UPDATE',$tables['changes'],$id))
                ?? throw new ModelViolation('hof_territory_checkpoint_not_found');
        });
    }

    /** Exact immutable target, only inside the existing governance transaction.
     * No caller-supplied creator, policy, territory revision or close reason.
     * @return array{change:array<string,string>,item:array<string,string>} */
    public static function target(\wpdb $db, string $id, string $kind, string $session, string $version): array
    {
        ClosedEnvironment::assertIsolated($db,'fans'); self::inside($db);
        ModelValues::uuid($id); ModelValues::uuid($session); ModelValues::integer($version,true);
        if (!in_array($kind,['admission','session'],true) || !ClosedTerritoryChangeSchema::ready($db)) {
            throw new ModelViolation('hof_territory_checkpoint_schema_unavailable');
        }
        $store = new SessionStore($db); $tables = ClosedTerritoryChangeSchema::tables($db);
        $change = $store->storage->row($db->prepare('SELECT * FROM %i WHERE change_id=%s FOR UPDATE',$tables['changes'],$id))
            ?? throw new ModelViolation('hof_territory_checkpoint_not_found');
        self::authorize($change);
        $item = $store->storage->row($db->prepare('SELECT * FROM %i WHERE change_id=%s AND kind=%s AND session_id=%s AND barrier_version=%s FOR UPDATE',
            $tables['items'],$id,$kind,$session,$version)) ?? throw new ModelViolation('hof_territory_checkpoint_not_found');
        if (!in_array($change['phase'],['collecting','draining','completed'],true)
            || !in_array($item['state'],['queued','closing','applied'],true)) { throw new ModelViolation('hof_territory_checkpoint_conflict'); }
        return ['change' => $change,'item' => $item];
    }

    /** Bind a transport action without replacing a key after a crash. Caller owns the same TX. */
    public static function attach(\wpdb $db, string $id, string $kind, string $session, string $version, string $action): void
    {
        ModelValues::uuid($action); $target = self::target($db,$id,$kind,$session,$version); $item = $target['item'];
        if ($item['action_id'] !== '') {
            if ($item['action_id'] !== $action) { throw new ModelViolation('hof_territory_checkpoint_conflict'); }
            return;
        }
        if ($item['state'] !== 'queued' || (new SessionStore($db))->storage->query($db->prepare(
            'UPDATE %i SET action_id=%s,state=%s WHERE change_id=%s AND kind=%s AND session_id=%s AND barrier_version=%s AND action_id=%s AND state=%s',
            ClosedTerritoryChangeSchema::tables($db)['items'],$action,'closing',$id,$kind,$session,$version,'','queued')) !== 1) {
            throw new ModelViolation('hof_territory_checkpoint_conflict');
        }
    }

    /** Called after the exact signed primary ACK and domain binding have been verified in this TX. */
    public static function settle(\wpdb $db, string $id, string $kind, string $session, string $version, string $action, string $instant): void
    {
        $target = self::target($db,$id,$kind,$session,$version); $item = $target['item'];
        \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingValues::utc($instant);
        if ($item['action_id'] !== $action) { throw new ModelViolation('hof_territory_checkpoint_conflict'); }
        if ($item['state'] === 'applied') {
            if ($item['applied_at'] !== $instant) { throw new ModelViolation('hof_territory_checkpoint_conflict'); }
        } elseif ($item['state'] !== 'closing' || (new SessionStore($db))->storage->query($db->prepare('UPDATE %i SET state=%s,applied_at=%s'
            . ' WHERE change_id=%s AND kind=%s AND session_id=%s AND barrier_version=%s AND state=%s AND action_id=%s',
            ClosedTerritoryChangeSchema::tables($db)['items'],'applied',$instant,$id,$kind,$session,$version,'closing',$action)) !== 1) {
            throw new ModelViolation('hof_territory_checkpoint_conflict');
        }
        self::finish($db,$id);
    }

    /** Caller has verified and applied each exact primary acknowledgement in this same TX. */
    public static function finish(\wpdb $db, string $id): void
    {
        ClosedEnvironment::assertIsolated($db,'fans'); self::inside($db); ModelValues::uuid($id);
        $store = new SessionStore($db); $tables = ClosedTerritoryChangeSchema::tables($db);
        $change = $store->storage->row($db->prepare('SELECT * FROM %i WHERE change_id=%s FOR UPDATE',$tables['changes'],$id))
            ?? throw new ModelViolation('hof_territory_checkpoint_not_found');
        self::authorize($change);
        if ($change['phase'] !== 'draining') { return; }
        if ($store->storage->row($db->prepare('SELECT session_id FROM %i WHERE change_id=%s AND state<>%s LIMIT 1 FOR UPDATE',
            $tables['items'],$id,'applied')) !== null) { return; }
        if ($store->storage->query($db->prepare('UPDATE %i SET phase=%s,updated_at=UTC_TIMESTAMP(6) WHERE change_id=%s AND phase=%s',
            $tables['changes'],'completed',$id,'draining')) !== 1) { throw new ModelViolation('hof_territory_checkpoint_conflict'); }
    }

    /** @param array<string,string> $change */
    private static function authorize(array $change): void
    {
        if (current_user_can('manage_options')) { RankingRegistry::administrator(); return; }
        if (SessionStore::ownerCreator()['creator_id'] !== $change['creator_id']) { throw new ModelViolation('hof_session_forbidden'); }
    }

    private static function participationMetadata(\wpdb $db): bool
    {
        $tables = ClosedParticipationSchema::tables($db); $found = false;
        foreach ($tables as $table) {
            $value = $db->get_var($db->prepare('SHOW TABLES LIKE %s',$db->esc_like($table)));
            if ($db->last_error !== '') { throw new ModelViolation('hof_storage_unavailable'); }
            $found = $found || $value === $table;
        }
        if ($found && !ClosedParticipationSchema::ready($db)) { throw new ModelViolation('hof_participation_schema_unavailable'); }
        return $found;
    }

    /** @param array<string,string> $previous
     * @return list<array{row:array<string,string>,origin:string}> */
    private static function organizerTargets(\wpdb $db, array $previous): array
    {
        $store = new SessionStore($db); $territories = TerritorySchema::tables($db);
        $rows = $db->get_results($db->prepare('SELECT s.* FROM %i s INNER JOIN %i r ON r.session_id=s.session_id'
            . ' INNER JOIN %i t ON t.session_id=s.session_id WHERE r.creator_id=%s AND r.role=%s AND r.state=%s'
            . ' AND t.policy_id=%s AND s.scope IN (%s,%s) AND s.state IN (%s,%s,%s) AND s.ends_at>UTC_TIMESTAMP(6) ORDER BY s.session_id LIMIT 4 FOR UPDATE',
            $store->tables['records'],$store->tables['roles'],$territories['bindings'],$previous['creator_id'],'organizer','accepted',
            $previous['policy_id'],'local','national','opening','open','closing'),'ARRAY_A');
        if (!is_array($rows) || $db->last_error !== '') { throw new ModelViolation('hof_storage_unavailable'); }
        if (count($rows) > 3) { throw new ModelViolation('hof_organizer_open_limit'); }
        if ($rows === []) { return []; }
        if (!ClosedSessionLifecycleSchema::ready($db)) { throw new ModelViolation('hof_session_lifecycle_schema_unavailable'); }
        $result = [];
        foreach ($rows as $row) {
            $binding = $store->storage->row($db->prepare('SELECT * FROM %i WHERE session_id=%s AND barrier_version=%s AND operation=%s FOR UPDATE',
                ClosedSessionLifecycleSchema::tables($db)['bindings'],$row['session_id'],$row['barrier_version'],'register'))
                ?? throw new ModelViolation('hof_session_binding_conflict');
            if ($binding['fields_sha256'] !== hash('sha256',$binding['fields_json'])) { throw new ModelViolation('hof_session_binding_conflict'); }
            $fields = CanonicalJson::object($binding['fields_json']); $descriptor = RankingBarrier::descriptor($fields['object']);
            if (CanonicalJson::encode($descriptor) !== CanonicalJson::encode(SessionBarrierDescriptor::compose($row,$fields['origin_id'],$previous['policy_id']))) {
                throw new ModelViolation('hof_session_binding_conflict');
            }
            $result[] = ['row' => $row,'origin' => $fields['origin_id']];
        }
        return $result;
    }

    private static function inside(\wpdb $db): void
    {
        $lock = 'fans_hof_' . substr(hash('sha256',$db->prefix . '|sessions'),0,48);
        if ((string) $db->get_var('SELECT @@in_transaction') !== '1' || self::failed()
            || (string) $db->get_var($db->prepare('SELECT IS_USED_LOCK(%s)=CONNECTION_ID()',$lock)) !== '1' || self::failed()) {
            throw new ModelViolation('hof_territory_transaction_required');
        }
    }

    /** @phpstan-impure Reads the error produced by the most recent primary operation. */
    private static function failed(): bool { global $wpdb; return $wpdb->last_error !== ''; }
}
