<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\Fans\PfContract\ClosedBarrierClient;
use Faluss\Platform\Fans\PfContract\ClosedBarrierInbox;
use Faluss\Platform\Fans\Sso\FansLinkedAccounts;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\BarrierTransport;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\BarrierTerritoryRevocation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingBarrier;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingValues;

/** Disposable-only B2 admission/withdrawal recovery. No route, ledger, cron or production factory. */
final class ClosedParticipationLifecycle
{
    private readonly SessionStore $sessions;
    /** @var array<string,string> */
    private readonly array $tables;

    public function __construct(private readonly \wpdb $db, private readonly ClosedBarrierInbox $inbox,
        private readonly ClosedBarrierClient $client, private readonly string $origin)
    {
        ClosedEnvironment::assertIsolated($db,'fans'); ModelValues::uuid($origin);
        if (!$inbox->usesConnection($db)) { throw new ModelViolation('hof_primary_connection_required'); }
        $this->sessions = new SessionStore($db); $this->tables = ClosedParticipationSchema::tables($db);
    }

    public function usesConnection(\wpdb $db): bool
    { ClosedEnvironment::assertIsolated($db,'fans'); return $this->db === $db; }

    /** Private local checkpoint only, never a fresh-primary proof or a mutation during GET.
     * @return array{bound:bool,opening_applied:bool,closing_applied:bool} */
    public function checkpoint(string $sessionId, string $creator): array
    {
        $this->available(); ModelValues::uuid($creator);
        return $this->sessions->transaction(function () use ($sessionId,$creator): array {
            $session = $this->sessions->row($sessionId); $this->authorize($session,$creator);
            $role = $this->requiredRole($sessionId,$creator); $version = $this->latest($sessionId,$creator);
            $offset = match ($role['state']) { 'admitted' => 0,'closing' => 1,'withdrawn' => 2,default => -1 };
            $current = $version !== null && $offset >= 0 && (int) $role['revision'] === (int) $version['role_revision']+$offset;
            if ($role['state'] === 'admitted' && ($version['session_barrier_version'] ?? '') !== $session['barrier_version']) { $current = false; }
            if ($current && $role['state'] === 'admitted') {
                try {
                    $facts = $this->eligible($session,$creator);
                    $expected = ParticipationBarrierDescriptor::compose($session,$role,$this->origin,$facts['subject'],
                        $version['barrier_version'],$facts['policy'],$facts['territory_revision']);
                    $current = CanonicalJson::encode($expected) === $version['descriptor_json']
                        && $this->binding($version,'close') === null;
                } catch (ModelViolation $error) {
                    if (!in_array($error->reason,['hof_reviewed_territory_required','hof_approved_creator_required','hof_session_approval_required'],true)) { throw $error; }
                    $current = false;
                }
            }
            $opening = $current ? $this->binding($version,'register') : null;
            $closing = $current ? $this->binding($version,'close') : null;
            return ['bound' => $current,'opening_applied' => ($opening['applied_at'] ?? '') !== '',
                'closing_applied' => ($closing['applied_at'] ?? '') !== ''];
        });
    }

    /** Admission and its immutable Identity/version checkpoint commit together, before any HTTP.
     * Refusals keep the existing B2 decision/appeal path and create no Hub admission.
     * @return array<string,string> */
    public function admit(string $sessionId, int $revision, string $creator): array
    {
        return $this->withSessionProof($sessionId,function (array $session,array $proof) use ($revision,$creator): array {
            SessionStore::revision($session,$revision); $this->openSession($session,$proof);
            $session = SessionAdmission::decide($this->sessions,$session,$creator,true);
            $role = $this->requiredRole($session['session_id'],$creator);
            $this->createVersion($session,$role,$this->latest($session['session_id'],$creator));
            return $session;
        });
    }

    /** A favourable appeal includes its actual case decision/log in the admission checkpoint.
     * No bare moderator boolean can bypass the pending case and its exact revision.
     * @return array<string,string> */
    public function admitAppeal(string $case, int $revision): array
    {
        RankingRegistry::administrator(); $this->available();
        $appeals = new SessionAppeals($this->db); $pending = $appeals->own($case);
        return $this->withSessionProof($pending['session_id'],function (array $session,array $proof) use ($appeals,$case,$revision,$pending): array {
            $this->openSession($session,$proof);
            $decision = $appeals->decideInTransaction($case,$revision,'admit','criteria_satisfied');
            $session = $this->sessions->row($session['session_id']);
            $role = $this->requiredRole($session['session_id'],$pending['creator_id']);
            $this->createVersion($session,$role,$this->latest($session['session_id'],$pending['creator_id']));
            return $decision;
        });
    }

    /** Same durable action survives a crash before inbox preparation. No identity is inferred for an
     * unbound role from an older caller: admission must use the atomic admit() checkpoint.
     * @return array<string,string> */
    public function prepare(string $sessionId, int $revision, string $creator): array
    {
        $this->available(); ModelValues::uuid($creator);
        $prepared = $this->sessions->transaction(function () use ($sessionId,$revision,$creator): array {
            $session = $this->sessions->row($sessionId); SessionStore::revision($session,$revision); $this->authorize($session,$creator);
            $role = $this->requiredRole($sessionId,$creator); $version = $this->latest($sessionId,$creator);
            if ($version === null || !in_array($role['state'],['admitted','closing','withdrawn'],true)
                || (int) $role['revision'] < (int) $version['role_revision']) { throw new ModelViolation('hof_participation_binding_required'); }
            if ($role['state'] === 'admitted' && ($role['revision'] !== $version['role_revision']
                || $session['barrier_version'] !== $version['session_barrier_version'])) { throw new ModelViolation('hof_participation_binding_conflict'); }
            if ($role['state'] === 'admitted') {
                $facts = $this->eligible($session,$creator);
                $descriptor = ParticipationBarrierDescriptor::compose($session,$role,$this->origin,$facts['subject'],
                    $version['barrier_version'],$facts['policy'],$facts['territory_revision']);
                if ($session['state'] !== 'open' || $session['ends_at'] <= $this->sessions->now()
                    || CanonicalJson::encode($descriptor) !== $version['descriptor_json']) { throw new ModelViolation('hof_participation_binding_conflict'); }
            }
            $opening = $this->bind($version,'register');
            $closing = in_array($role['state'],['closing','withdrawn'],true);
            if ($closing && (int) $role['revision'] !== (int) $version['role_revision'] + ($role['state'] === 'closing' ? 1 : 2)) {
                throw new ModelViolation('hof_participation_binding_conflict');
            }
            return ['opening' => $opening,'target' => $closing ? $this->bind($version,'close') : $opening];
        });
        $opening = $prepared['opening']; $binding = $prepared['target'];
        $this->inbox->prepare(CanonicalJson::object($opening['fields_json']),$opening['contract']);
        $progress = $this->inbox->prepare(CanonicalJson::object($binding['fields_json']),$binding['contract']);
        return ['action_id' => $binding['action_id'],'phase' => $progress['phase'],'local_state' => $progress['state']];
    }

    /** @return array<string,string> */
    public function advance(string $sessionId, int $revision, string $creator, int $steps = 4): array
    {
        if ($steps < 1 || $steps > 16) { throw new ModelViolation('pf_local_barrier_budget'); }
        $prepared = $this->prepare($sessionId,$revision,$creator); $result = $this->client->advance($prepared['action_id'],$steps);
        if ($result['state'] !== 'acknowledged') { return $result; }
        return $result + ['participation_state' => $this->apply($sessionId,$creator,$prepared['action_id'])['state']];
    }

    /** Retire the exact old territorial admission without rewriting voluntary participation.
     * Historical withdrawals keep their original contract/action/key. No HTTP under the TX.
     * @return array<string,string> */
    public function retireTerritory(string $change, string $sessionId, string $barrierVersion, int $steps = 4): array
    {
        $this->available();
        if ($steps < 1 || $steps > 16) { throw new ModelViolation('pf_local_barrier_budget'); }
        $plan = $this->sessions->transaction(function () use ($change,$sessionId,$barrierVersion): array {
            $target = ClosedTerritoryChanges::target($this->db,$change,'admission',$sessionId,$barrierVersion);
            $creator = $target['change']['creator_id']; $version = $this->version($sessionId,$creator,$barrierVersion);
            $descriptor = $this->descriptor($version); BarrierTerritoryRevocation::requireTerritorialAdmission($descriptor);
            $content = $descriptor['content'];
            if ($version['descriptor_sha256'] !== $target['item']['descriptor_sha256']
                || $content['origin_id'] !== $this->origin || $content['territory_policy_revision'] !== $target['change']['policy_id']
                || $content['territory_admission_revision'] !== $target['change']['approval_revision']) {
                throw new ModelViolation('hof_territory_checkpoint_conflict');
            }
            $opening = $this->bind($version,'register'); $closing = $this->bind($version,'close',BarrierTerritoryRevocation::REASON);
            ClosedTerritoryChanges::attach($this->db,$change,'admission',$sessionId,$barrierVersion,$closing['action_id']);
            return ['creator' => $creator,'opening' => $opening,'closing' => $closing];
        });
        foreach (['opening','closing'] as $operation) {
            $binding = $plan[$operation]; $this->inbox->prepare(CanonicalJson::object($binding['fields_json']),$binding['contract']);
        }
        $result = $this->client->advance($plan['closing']['action_id'],$steps);
        if ($result['state'] !== 'acknowledged') { return $result; }
        $this->apply($sessionId,$plan['creator'],$plan['closing']['action_id']);
        return $this->sessions->withGovernanceMutex(fn (): array => $this->inbox->withAcknowledgement($plan['closing']['action_id'],
            function (array $ack) use ($change,$sessionId,$barrierVersion,$plan,$result): array {
                $target = ClosedTerritoryChanges::target($this->db,$change,'admission',$sessionId,$barrierVersion);
                $binding = $this->binding($this->version($sessionId,$plan['creator'],$barrierVersion),'close');
                $bytes = CanonicalJson::encode($ack['fields']); $instant = RankingValues::utc($ack['result']['effective_at']);
                if ($binding === null || $target['item']['action_id'] !== $binding['action_id'] || $binding['action_id'] !== $plan['closing']['action_id']
                    || $binding['fields_json'] !== $bytes || $binding['contract'] !== $ack['contract'] || $binding['applied_at'] !== $instant
                    || $ack['local_state'] !== 'closed' || $ack['result']['state'] !== 'closed') { throw new ModelViolation('hof_territory_checkpoint_conflict'); }
                ClosedTerritoryChanges::settle($this->db,$change,'admission',$sessionId,$barrierVersion,$binding['action_id'],$instant);
                return $result + ['territory_state' => 'applied'];
            }));
    }

    /** Reuse unchanged voluntary rules after an attested session suspension/readmission.
     * Retire the old admission first, then bind only future contributions to the current parent.
     * Every network stage is outside the owner transaction and resumes its existing action/key.
     * @return array<string,string> */
    public function requalify(string $sessionId, int $revision, string $creator, int $steps = 4): array
    {
        if ($steps < 1 || $steps > 16) { throw new ModelViolation('pf_local_barrier_budget'); }
        $plan = $this->withSessionProof($sessionId,function (array $session,array $proof) use ($revision,$creator): array {
            SessionStore::revision($session,$revision); $this->authorize($session,$creator); $this->openSession($session,$proof);
            $role = $this->requiredRole($session['session_id'],$creator); $version = $this->latest($session['session_id'],$creator);
            if ($version === null || $role['state'] !== 'admitted' || $role['revision'] !== $version['role_revision']
                || $role['rules_sha256'] !== $session['frozen_sha256']) { throw new ModelViolation('hof_participation_binding_conflict'); }
            $retirement = 'parent';
            if ($version['session_barrier_version'] === $session['barrier_version']) {
                $facts = $this->eligible($session,$creator);
                $expected = ParticipationBarrierDescriptor::compose($session,$role,$this->origin,$facts['subject'],
                    $version['barrier_version'],$facts['policy'],$facts['territory_revision']);
                if (CanonicalJson::encode($expected) === $version['descriptor_json']) { return ['current' => true]; }
                $this->retiredTerritory($version); $retirement = 'territory';
            } else { $this->retiredParent($session,$version); }
            return ['current' => false,'parent' => $session['barrier_version'],'version' => $version,
                'retirement' => $retirement,
                'opening' => $this->bind($version,'register'),'closing' => $this->bind($version,'close','session_suspended')];
        });
        if ($plan['current']) { return $this->advance($sessionId,$revision,$creator,$steps); }
        foreach (['opening','closing'] as $operation) {
            $binding = $plan[$operation]; $this->inbox->prepare(CanonicalJson::object($binding['fields_json']),$binding['contract']);
        }
        $result = $this->client->advance($plan['closing']['action_id'],$steps);
        if ($result['state'] !== 'acknowledged') { return $result; }
        $this->apply($sessionId,$creator,$plan['closing']['action_id']);
        $current = $this->withSessionProof($sessionId,function (array $session,array $proof) use ($creator,$plan): array {
            $this->authorize($session,$creator); $this->openSession($session,$proof);
            $role = $this->requiredRole($session['session_id'],$creator); $previous = $this->latest($session['session_id'],$creator);
            if ($session['barrier_version'] !== $plan['parent'] || $previous === null
                || $previous['barrier_version'] !== $plan['version']['barrier_version'] || $role['state'] !== 'admitted'
                || $role['revision'] !== $previous['role_revision'] || $role['rules_sha256'] !== $session['frozen_sha256']) {
                throw new ModelViolation('hof_participation_binding_conflict');
            }
            if ($plan['retirement'] === 'territory') { $this->retiredTerritory($previous); }
            else { $this->retiredParent($session,$previous); }
            if ($this->sessions->storage->query($this->db->prepare('UPDATE %i SET revision=revision+1,admitted_at=%s,updated_at=UTC_TIMESTAMP(6)'
                . ' WHERE session_id=%s AND creator_id=%s AND role=%s AND state=%s AND revision=%s',
                $this->sessions->tables['roles'],$this->sessions->now(),$session['session_id'],$creator,'participant','admitted',$role['revision'])) !== 1) {
                throw new ModelViolation('hof_participation_binding_conflict');
            }
            $session = $this->sessions->change($session,[],'requalify_participation',$creator,'session_readmitted');
            $this->createVersion($session,$this->requiredRole($session['session_id'],$creator),$previous);
            return $session;
        });
        return $this->advance($sessionId,(int) $current['revision'],$creator,$steps);
    }

    /** Session mutex precedes inbox origin mutex/TX, as for signed corpus/session delivery.
     * An old opening acknowledgement never overrides withdrawal or a later admission.
     * @return array<string,string> */
    public function apply(string $sessionId, string $creator, string $action): array
    {
        $this->available(); ModelValues::uuid($creator); ModelValues::uuid($action);
        return $this->sessions->withGovernanceMutex(fn (): array => $this->inbox->withAcknowledgement($action,
            function (array $ack) use ($sessionId,$creator,$action): array {
                $session = $this->sessions->row($sessionId); $this->authorize($session,$creator);
                $role = $this->requiredRole($sessionId,$creator);
                $binding = $this->sessions->storage->row($this->db->prepare('SELECT * FROM %i WHERE action_id=%s FOR UPDATE',$this->tables['bindings'],$action));
                $bytes = CanonicalJson::encode($ack['fields']);
                if ($binding === null || $binding['session_id'] !== $sessionId || $binding['creator_id'] !== $creator
                    || $binding['contract'] !== $ack['contract'] || $binding['fields_json'] !== $bytes
                    || $binding['fields_sha256'] !== hash('sha256',$bytes)) { throw new ModelViolation('hof_participation_binding_conflict'); }
                $version = $this->version($sessionId,$creator,$binding['barrier_version']);
                $descriptor = $this->descriptor($version); $ref = RankingBarrier::reference($descriptor['content']);
                foreach (['barrier_key','version','content_sha256'] as $field) {
                    if ($ack['result'][$field] !== $ref[$field]) { throw new ModelViolation('hof_participation_binding_conflict'); }
                }
                $instant = RankingValues::utc($ack['result']['effective_at']);
                if ($binding['applied_at'] !== '') {
                    if ($binding['applied_at'] !== $instant) { throw new ModelViolation('hof_participation_binding_conflict'); }
                    if ($binding['operation'] === 'close' && in_array($ack['result']['reason'],['session_suspended',BarrierTerritoryRevocation::REASON],true)
                        && $role['state'] === 'closing' && (int) $role['revision'] === (int) $version['role_revision']+1) {
                        $this->settleClose($session,$version,$role,$ack);
                        return $this->requiredRole($sessionId,$creator);
                    }
                    return $role;
                }
                if ($binding['operation'] === 'close') {
                    $this->settleClose($session,$version,$role,$ack);
                } elseif ($role['state'] === 'admitted' && $role['revision'] === $version['role_revision']) {
                    if ($session['state'] !== 'open' || $session['barrier_version'] !== $version['session_barrier_version']
                        || $ack['local_state'] !== 'active' || $ack['result']['state'] !== 'active') { throw new ModelViolation('hof_participation_binding_conflict'); }
                    $facts = $this->eligible($session,$creator);
                    $expected = ParticipationBarrierDescriptor::compose($session,$role,$this->origin,$facts['subject'],
                        $version['barrier_version'],$facts['policy'],$facts['territory_revision']);
                    if (CanonicalJson::encode($expected) !== $version['descriptor_json'] || $session['ends_at'] <= $this->sessions->now()) {
                        throw new ModelViolation('hof_participation_binding_conflict');
                    }
                    $this->sessions->change($session,[],'hub_participation_open_ack',$creator,'primary_acknowledgement');
                }
                // Otherwise retain the historical ACK without changing a closing/withdrawn/newer role.
                if ($this->sessions->storage->query($this->db->prepare('UPDATE %i SET applied_at=%s WHERE action_id=%s AND applied_at=%s',
                    $this->tables['bindings'],$instant,$action,'')) !== 1) { throw new ModelViolation('hof_participation_binding_conflict'); }
                return $this->requiredRole($sessionId,$creator);
            }));
    }

    private function available(): void
    {
        ClosedEnvironment::assertIsolated($this->db,'fans');
        if (!ClosedParticipationSchema::ready($this->db) || !ClosedSessionLifecycleSchema::ready($this->db)) { throw new ModelViolation('hof_participation_schema_unavailable'); }
    }

    /** @param array<string,string> $session
     * @param array<string,string> $version
     * @return array<string,string> */
    private function retiredParent(array $session, array $version): array
    {
        $closed = $this->sessions->storage->row($this->db->prepare('SELECT * FROM %i WHERE session_id=%s AND barrier_version=%s AND operation=%s FOR UPDATE',
            ClosedSessionLifecycleSchema::tables($this->db)['bindings'],$session['session_id'],$version['session_barrier_version'],'close'));
        return ParticipationSessionRetirement::reference($session,$version,$this->origin,$closed);
    }

    /** An applied old territorial checkpoint, never a local label or newer approval alone.
     * The signed close is reverified by apply() before a future version can commit.
     * @param array<string,string> $version */
    private function retiredTerritory(array $version): void
    {
        $descriptor = $this->descriptor($version); BarrierTerritoryRevocation::requireTerritorialAdmission($descriptor);
        if (!ClosedTerritoryChangeSchema::ready($this->db)) { throw new ModelViolation('hof_territory_checkpoint_schema_unavailable'); }
        $content = $descriptor['content']; $tables = ClosedTerritoryChangeSchema::tables($this->db);
        $change = $this->sessions->storage->row($this->db->prepare('SELECT * FROM %i WHERE creator_id=%s AND approval_revision=%s FOR UPDATE',
            $tables['changes'],$version['creator_id'],$content['territory_admission_revision']));
        $item = $change === null ? null : $this->sessions->storage->row($this->db->prepare('SELECT * FROM %i WHERE change_id=%s AND kind=%s AND session_id=%s AND barrier_version=%s FOR UPDATE',
            $tables['items'],$change['change_id'],'admission',$version['session_id'],$version['barrier_version']));
        $closed = $this->binding($version,'close');
        if ($change === null || $change['policy_id'] !== $content['territory_policy_revision'] || $item === null
            || $item['descriptor_sha256'] !== $version['descriptor_sha256'] || $item['state'] !== 'applied'
            || $item['applied_at'] === '' || $closed === null || $closed['action_id'] !== $item['action_id']
            || $closed['applied_at'] !== $item['applied_at'] || $closed['contract'] !== BarrierTransport::TERRITORY_CONTRACT
            || $closed['fields_sha256'] !== hash('sha256',$closed['fields_json'])) {
            throw new ModelViolation('hof_territory_retirement_acknowledgement_required');
        }
        $fields = CanonicalJson::object($closed['fields_json']); BarrierTransport::fields($fields,$closed['contract']);
        $reference = array_intersect_key(RankingBarrier::reference($content),array_flip(['barrier_key','version','content_sha256']))
            + ['reason' => BarrierTerritoryRevocation::REASON];
        if ($content['origin_id'] !== $this->origin || $fields['operation'] !== 'close' || $fields['action_id'] !== $closed['action_id']
            || $fields['origin_id'] !== $this->origin || CanonicalJson::encode($fields['object']) !== CanonicalJson::encode($reference)) {
            throw new ModelViolation('hof_territory_retirement_acknowledgement_required');
        }
    }

    /** @param array<string,string> $session
     * @param array<string,string> $version
     * @param array<string,string> $role
     * @param array<string,mixed> $ack */
    private function settleClose(array $session, array $version, array $role, array $ack): void
    {
        $reason = $ack['result']['reason'];
        if ($ack['local_state'] !== 'closed' || $ack['result']['state'] !== 'closed'
            || !in_array($reason,['participant_withdrawn','session_suspended',BarrierTerritoryRevocation::REASON],true)
            || $ack['fields']['object']['reason'] !== $reason) { throw new ModelViolation('hof_participation_binding_conflict'); }
        if ($reason === 'session_suspended') {
            $expected = $this->retiredParent($session,$version);
            if (CanonicalJson::encode($expected) !== CanonicalJson::encode($ack['fields']['object'])) { throw new ModelViolation('hof_participation_binding_conflict'); }
            if ($role['state'] === 'admitted' && $role['revision'] === $version['role_revision']) {
                $this->sessions->change($session,[],'retire_participation_parent',$role['creator_id'],$reason); return;
            }
        }
        if ($reason === BarrierTerritoryRevocation::REASON) {
            BarrierTerritoryRevocation::requireTerritorialAdmission($this->descriptor($version));
            if ($ack['contract'] !== BarrierTransport::TERRITORY_CONTRACT) { throw new ModelViolation('hof_participation_binding_conflict'); }
            if ($role['state'] === 'admitted' && $role['revision'] === $version['role_revision']) {
                $this->sessions->change($session,[],'retire_territory_participation',$role['creator_id'],$reason); return;
            }
        }
        if ($role['state'] !== 'closing' || (int) $role['revision'] !== (int) $version['role_revision']+1) { throw new ModelViolation('hof_participation_binding_conflict'); }
        if ($this->sessions->storage->query($this->db->prepare('UPDATE %i SET state=%s,revision=revision+1,updated_at=UTC_TIMESTAMP(6)'
            . ' WHERE session_id=%s AND creator_id=%s AND role=%s AND state=%s AND revision=%s',
            $this->sessions->tables['roles'],'withdrawn',$session['session_id'],$role['creator_id'],'participant','closing',$role['revision'])) !== 1) {
            throw new ModelViolation('hof_participation_binding_conflict');
        }
        $this->sessions->change($session,[],'hub_participation_close_ack',$role['creator_id'],$reason);
    }

    /** @param array<string,string> $session */
    private function authorize(array $session, string $creator): void
    {
        if (current_user_can('manage_options')) { return; }
        $own = SessionStore::ownerCreator()['creator_id'];
        if ($own !== $creator) { $this->sessions->manager($session,$own); }
    }

    /** @template T
     * @param callable(array<string,string>,array<string,mixed>):T $operation
     * @return T */
    private function withSessionProof(string $id, callable $operation): mixed
    {
        $this->available(); ModelValues::uuid($id);
        return $this->sessions->withGovernanceMutex(function () use ($id,$operation): mixed {
            $session = $this->sessions->row($id);
            $binding = $this->sessions->storage->row($this->db->prepare('SELECT action_id FROM %i WHERE session_id=%s AND barrier_version=%s AND operation=%s',
                ClosedSessionLifecycleSchema::tables($this->db)['bindings'],$id,$session['barrier_version'],'register'));
            if ($binding === null) { throw new ModelViolation('hof_session_acknowledgement_required'); }
            return $this->inbox->withAcknowledgement($binding['action_id'],function (array $proof) use ($id,$operation): mixed {
                $session = $this->sessions->row($id);
                $policy = $session['scope'] === 'international' ? null : (new TerritoryService($this->db))->boundPolicy($id);
                $expected = SessionBarrierDescriptor::compose($session,$this->origin,$policy);
                if ($proof['fields']['operation'] !== 'register' || $proof['contract'] !== BarrierTransport::CONTRACT
                    || CanonicalJson::encode($proof['fields']['object']) !== CanonicalJson::encode($expected)) {
                    throw new ModelViolation('hof_session_acknowledgement_required');
                }
                return $operation($session,$proof);
            });
        });
    }

    /** @param array<string,string> $session
     * @param array<string,mixed> $proof */
    private function openSession(array $session, array $proof): void
    {
        if ($session['state'] !== 'open' || $proof['local_state'] !== 'active' || $proof['result']['state'] !== 'active'
            || $session['ends_at'] <= $this->sessions->now()) { throw new ModelViolation('hof_session_acknowledgement_required'); }
    }

    /** @param array<string,string> $session
     * @return array{subject:string,policy:?string,territory_revision:string} */
    private function eligible(array $session, string $creator): array
    {
        $owner = SessionStore::activeOwner($creator);
        $subject = FansLinkedAccounts::identityInTransaction($owner) ?? throw new ModelViolation('hof_linked_creator_required');
        (new SessionModeration($this->db))->requireApproval($session);
        $policy = null; $territoryRevision = '0';
        if ($session['scope'] !== 'international') {
            $territories = new TerritoryService($this->db);
            $policy = $territories->boundPolicy($session['session_id']) ?? throw new ModelViolation('hof_reviewed_territory_required');
            $territoryRevision = $territories->eligible($creator,$policy,$session)['approved_revision'];
        }
        return ['subject' => $subject,'policy' => $policy,'territory_revision' => $territoryRevision];
    }

    /** @return array<string,string> */
    private function requiredRole(string $session, string $creator): array
    { return $this->sessions->role($session,$creator,'participant') ?? throw new ModelViolation('hof_participation_binding_required'); }

    /** @return array<string,string>|null */
    private function latest(string $session, string $creator): ?array
    {
        return $this->sessions->storage->row($this->db->prepare('SELECT * FROM %i WHERE session_id=%s AND creator_id=%s ORDER BY barrier_version DESC LIMIT 1 FOR UPDATE',
            $this->tables['versions'],$session,$creator));
    }

    /** @return array<string,string> */
    private function version(string $session, string $creator, string $version): array
    {
        return $this->sessions->storage->row($this->db->prepare('SELECT * FROM %i WHERE session_id=%s AND creator_id=%s AND barrier_version=%s FOR UPDATE',
            $this->tables['versions'],$session,$creator,$version)) ?? throw new ModelViolation('hof_participation_binding_required');
    }

    /** @param array<string,string> $session
     * @param array<string,string> $role
     * @param array<string,string>|null $previous */
    private function createVersion(array $session, array $role, ?array $previous): void
    {
        $creator = $role['creator_id']; $facts = $this->eligible($session,$creator);
        if ($previous !== null) {
            $closed = $this->binding($previous,'close');
            if ($closed === null || $closed['applied_at'] === '' || $previous['subject_id'] !== $facts['subject']) {
                throw new ModelViolation('hof_participation_binding_conflict');
            }
        }
        $version = (string) ((int) ($previous['barrier_version'] ?? '0')+1);
        $descriptor = ParticipationBarrierDescriptor::compose($session,$role,$this->origin,$facts['subject'],$version,$facts['policy'],$facts['territory_revision']);
        $bytes = CanonicalJson::encode($descriptor);
        if ($this->db->insert($this->tables['versions'],['session_id' => $session['session_id'],'creator_id' => $creator,'barrier_version' => $version,
            'subject_id' => $facts['subject'],'session_barrier_version' => $session['barrier_version'],'role_revision' => $role['revision'],
            'descriptor_json' => $bytes,'descriptor_sha256' => hash('sha256',$bytes)]) !== 1) { throw new ModelViolation('hof_storage_unavailable'); }
        $this->bind($this->version($session['session_id'],$creator,$version),'register');
    }

    /** @param array<string,string> $version
     * @return array<string,mixed> */
    private function descriptor(array $version): array
    {
        if ($version['descriptor_sha256'] !== hash('sha256',$version['descriptor_json'])) { throw new ModelViolation('hof_participation_binding_conflict'); }
        return RankingBarrier::descriptor(CanonicalJson::object($version['descriptor_json']));
    }

    /** @param array<string,string> $version
     * @return array<string,string>|null */
    private function binding(array $version, string $operation): ?array
    {
        return $this->sessions->storage->row($this->db->prepare('SELECT * FROM %i WHERE session_id=%s AND creator_id=%s AND barrier_version=%s AND operation=%s FOR UPDATE',
            $this->tables['bindings'],$version['session_id'],$version['creator_id'],$version['barrier_version'],$operation));
    }

    /** @param array<string,string> $version
     * @return array<string,string> */
    private function bind(array $version, string $operation, string $reason = 'participant_withdrawn'): array
    {
        $old = $this->binding($version,$operation); $descriptor = $this->descriptor($version); $object = $descriptor;
        $contract = $old['contract'] ?? ($operation === 'close' && $reason === BarrierTerritoryRevocation::REASON
            ? BarrierTransport::TERRITORY_CONTRACT : BarrierTransport::CONTRACT);
        if ($operation === 'close') {
            if ($old !== null) { $reason = CanonicalJson::object($old['fields_json'])['object']['reason']; }
            if (!in_array($reason,['participant_withdrawn','session_suspended',BarrierTerritoryRevocation::REASON],true)) { throw new ModelViolation('hof_participation_binding_conflict'); }
            if ($reason === BarrierTerritoryRevocation::REASON) { BarrierTerritoryRevocation::requireTerritorialAdmission($descriptor); }
            $object = array_intersect_key(RankingBarrier::reference($descriptor['content']),array_flip(['barrier_key','version','content_sha256']))
                + ['reason' => $reason];
        }
        $fields = ['operation' => $operation,'lookup_operation' => '','action_id' => $old['action_id'] ?? wp_generate_uuid4(),
            'origin_id' => $this->origin,'policy_version' => RankingPolicy::VERSION,'object' => $object];
        BarrierTransport::fields($fields,$contract); $bytes = CanonicalJson::encode($fields);
        if ($old !== null) {
            if ($old['contract'] !== $contract || $old['fields_json'] !== $bytes || $old['fields_sha256'] !== hash('sha256',$bytes)) {
                throw new ModelViolation('hof_participation_binding_conflict');
            }
            return $old;
        }
        if ($this->db->insert($this->tables['bindings'],['session_id' => $version['session_id'],'creator_id' => $version['creator_id'],
            'barrier_version' => $version['barrier_version'],'operation' => $operation,'action_id' => $fields['action_id'],
            'contract' => $contract,'fields_json' => $bytes,'fields_sha256' => hash('sha256',$bytes),'applied_at' => '']) !== 1) {
            throw new ModelViolation('hof_storage_unavailable');
        }
        return $this->binding($version,$operation) ?? throw new ModelViolation('hof_storage_unavailable');
    }
}
