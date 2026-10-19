<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\PfContract;

use Faluss\Platform\Fans\Hof\ClosedAttributionSelection;
use Faluss\Platform\Fans\Sso\FansLocalSession;
use Faluss\Platform\Fans\Sso\FansSsoService;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedIntent;

/** One unresolved intention per linked member, durable before HTTP; no network inside a SQL transaction. */
final class ClosedAttributionAttempt
{
    private readonly string $table;

    public function __construct(private readonly \wpdb $db,private readonly ClosedAttributionSelection $selection,
        private readonly ClosedRankedClient $client)
    {
        ClosedEnvironment::assertIsolated($db,'fans');
        if ($db!==($GLOBALS['wpdb']??null) || !$client->usesConnection($db) || !$selection->usesConnection($db)) {
            throw new ModelViolation('hof_primary_connection_required');
        }
        $this->table=ClosedAttributionAttemptSchema::tables($db)['current'];
    }

    public function usesConnection(\wpdb $db): bool { return $this->db===$db; }

    /** @return array{items:list<array<string,string>>,next_cursor:?string} */
    public function choices(string $profile,string $after=''): array { return $this->selection->choices($profile,$after); }

    /** @param array<array-key,mixed> $ids
     * @return list<array<string,string>> */
    public function selected(string $profile,array $ids): array { return $this->selection->selected($profile,$ids); }

    public function currentIntent(): ?RankedIntent
    {
        return $this->withMember(function(string $member):?RankedIntent {
            $row=$this->row($member);
            return $row===null?null:(new ClosedRankedProtocolStore($this->db))->recover($row['attribution_id'],$member);
        });
    }

    /** Private read only. Visiting a page never retries an economic operation.
     * @return array<string,string>|null */
    public function current(): ?array { return $this->withMember(fn(string $member):?array=>$this->row($member)); }

    /** @param array<array-key,mixed> $sessions
     * @return array<string,string> */
    public function start(string $id,string $profile,string $quantity,array $sessions): array
    {
        ModelValues::uuid($id);ModelValues::uuid($profile);
        return $this->withMember(function(string $member) use($id,$profile,$quantity,$sessions):array {
            $current=$this->row($member);
            if ($current!==null && $current['attribution_id']===$id) {
                // An old browser form cannot mutate the stored intention, even after a status/category change.
                $intent=(new ClosedRankedProtocolStore($this->db))->recover($id,$member);
                if ($current['creator_profile_id']!==$profile || $intent->values['purchased_pf']!==$quantity
                    || !array_is_list($sessions)) { throw new ModelViolation('pf_local_intent_conflict'); }
                $ids=array_map(static fn(mixed $value):string=>ModelValues::uuid($value),$sessions);sort($ids,SORT_STRING);
                if ($ids!==array_column($intent->values['ranking_context']['sessions'],'session_id')) { throw new ModelViolation('pf_local_intent_conflict'); }
                return $current;
            }
            if ($current!==null && !self::terminal($current['phase'])) { throw new ModelViolation('pf_local_attempt_pending'); }
            // Previously replaced intentions remain immutable in the protocol inbox; never restart an old form.
            $store=new ClosedRankedProtocolStore($this->db);
            try { $store->recover($id,$member);throw new ModelViolation('pf_local_attempt_replaced'); }
            catch(ModelViolation $error) { if ($error->reason!=='pf_local_intent_absent') { throw $error; } }
            $intent=$this->selection->resolve($id,$profile,$quantity,$sessions);
            $store->prepare($intent,'reserve');
            // Autocommit publishes the checkpoint before any HTTP. Failure leaves only an unsent protocol key.
            if ($this->db->query($this->db->prepare('INSERT INTO %i (member_faluss_id,attribution_id,creator_profile_id,operation,phase,updated_at) VALUES (%s,%s,%s,%s,%s,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE attribution_id=VALUES(attribution_id),creator_profile_id=VALUES(creator_profile_id),operation=VALUES(operation),phase=VALUES(phase),updated_at=VALUES(updated_at)',
                $this->table,$member,$id,$profile,'reserve','unknown'))===false || $this->db->last_error!=='') { throw new ModelViolation('pf_local_attempt_storage'); }
            return $this->send($member,$intent,'reserve');
        });
    }

    /** Only an explicit protected POST calls this method. A fresh page read never sends or confirms.
     * @return array<string,string> */
    public function advance(string $id,string $action): array
    {
        ModelValues::uuid($id);
        if (!in_array($action,['lookup','retry','confirm','release'],true)) { throw new ModelViolation('pf_invalid_operation'); }
        return $this->withMember(function(string $member) use($id,$action):array {
            $row=$this->row($member);
            if ($row===null || $row['attribution_id']!==$id) { throw new ModelViolation('pf_local_intent_absent'); }
            if (self::terminal($row['phase'])) { return $row; }
            $intent=(new ClosedRankedProtocolStore($this->db))->recover($id,$member);
            if ($action==='lookup') {
                $reply=$this->client->lookup($id,$row['operation']);
                return $this->accept($member,$reply,true);
            }
            if ($action==='retry') {
                if ($row['phase']!=='retry') { throw new ModelViolation('pf_local_attempt_lookup_required'); }
                $operation=$row['operation'];
            } else {
                if ($row['phase']!=='reserved') { throw new ModelViolation('pf_local_attempt_lookup_required'); }
                $operation=$action;
            }
            (new ClosedRankedProtocolStore($this->db))->prepare($intent,$operation);
            $this->change($member,$operation,'unknown');
            return $this->send($member,$intent,$operation);
        });
    }

    /** @return array<string,string> */
    private function send(string $member,RankedIntent $intent,string $operation): array
    {
        $reply=match($operation) {
            'reserve'=>$this->client->reserve($intent),
            'confirm'=>$this->client->confirm($intent->values['attribution_id']),
            'release'=>$this->client->release($intent->values['attribution_id']),
            default=>throw new ModelViolation('pf_invalid_operation'),
        };
        return $this->accept($member,$reply,false);
    }

    /** An authenticated refusal is not proof that an earlier operation never committed.
     * @param array<string,mixed> $reply
     * @return array<string,string> */
    private function accept(string $member,array $reply,bool $lookup): array
    {
        $row=$this->row($member)??throw new ModelViolation('pf_local_attempt_storage');
        $phase='unknown';
        if (($reply['outcome']??'')==='ok') {
            $state=$reply['result']['state'];
            $phase=match($state) {
                'confirmed','released','expired'=>$state,
                'reserved'=>$row['operation']==='reserve'?'reserved':'retry',
                'not_found'=>$lookup?'retry':'unknown',
                default=>'unknown',
            };
        }
        $this->change($member,$row['operation'],$phase);
        return $this->row($member)??throw new ModelViolation('pf_local_attempt_storage');
    }

    public static function terminal(string $phase): bool { return in_array($phase,['confirmed','released','expired'],true); }

    /** @return array<string,string>|null */
    private function row(string $member): ?array
    {
        $row=$this->db->get_row($this->db->prepare('SELECT * FROM %i WHERE member_faluss_id=%s',$this->table,$member),'ARRAY_A');
        if ($this->db->last_error!=='') { throw new ModelViolation('pf_local_attempt_storage'); }
        if ($row!==null) {
            ModelValues::uuid($row['attribution_id']);ModelValues::uuid($row['creator_profile_id']);
            if ($row['member_faluss_id']!==$member || !in_array($row['operation'],['reserve','confirm','release'],true)
                || !in_array($row['phase'],['unknown','retry','reserved','confirmed','released','expired'],true)) { throw new ModelViolation('pf_local_attempt_storage'); }
            (new ClosedRankedProtocolStore($this->db))->recover($row['attribution_id'],$member);
        }
        return $row;
    }

    private function change(string $member,string $operation,string $phase): void
    {
        if ($this->db->query($this->db->prepare('UPDATE %i SET operation=%s,phase=%s,updated_at=UTC_TIMESTAMP(6) WHERE member_faluss_id=%s',
            $this->table,$operation,$phase,$member))===false || $this->db->last_error!=='') { throw new ModelViolation('pf_local_attempt_storage'); }
    }

    /** Advisory lock spans HTTP, but no SQL transaction does. All operations use the member's current local session.
     * @template T
     * @param callable(string):T $operation
     * @return T */
    private function withMember(callable $operation): mixed
    {
        ClosedEnvironment::assertIsolated($this->db,'fans');
        $subject=FansSsoService::currentLinkedSubject();$expires=FansLocalSession::expires();
        if ($subject===null || $expires===null || $expires<=time()) { throw new ModelViolation('pf_linked_session_required'); }
        if (!ClosedAttributionAttemptSchema::ready($this->db) || !ClosedRankedProtocolSchema::ready($this->db)) { throw new ModelViolation('pf_local_attempt_schema'); }
        $lock='fans_pf_attempt_'.substr(hash('sha256',$subject['faluss_id']),0,32);
        if ((string)$this->db->get_var('SELECT @@in_transaction')!=='0' || $this->db->last_error!==''
            || (string)$this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1') { throw new ModelViolation('pf_local_attempt_busy'); }
        try { return $operation($subject['faluss_id']); }
        finally {
            if ((string)$this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)',$lock))!=='1') { throw new ModelViolation('pf_local_attempt_unknown'); }
        }
    }
}
