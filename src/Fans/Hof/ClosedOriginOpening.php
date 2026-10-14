<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\Fans\PfContract\ClosedBarrierInbox;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingBarrier;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingValues;

/** Closed B1 primary evidence. No HTTP, public route, automatic opening or economic source. */
final class ClosedOriginOpening
{
    private readonly RankingStorage $storage;
    private readonly string $table;

    public function __construct(private readonly \wpdb $db, private readonly ClosedBarrierInbox $inbox, private readonly string $origin)
    {
        ClosedEnvironment::assertIsolated($db,'fans');ModelValues::uuid($origin);
        $this->storage=new RankingStorage($db);$this->table=ClosedOriginOpeningSchema::tables($db)['records'];
    }

    /** Caller declares the frozen fixture admission interval, never a browser request.
     * @param array<string,mixed> $descriptor
     * @return array<string,string> */
    public function prepare(array $descriptor): array
    {
        $this->available();RankingRegistry::administrator();RankingBarrier::descriptor($descriptor);
        $content=$descriptor['content'];
        if ($content['kind']!=='origin' || $content['origin_id']!==$this->origin || $content['policy_version']!==RankingPolicy::VERSION) { throw new ModelViolation('hof_origin_conflict'); }
        $fields=$this->storage->transaction('registry',function () use ($descriptor): array {
            $prepared=$this->storage->row($this->db->prepare('SELECT * FROM %i WHERE id=1 FOR UPDATE',RankingSchema::tables($this->db)['origin']));
            if ($prepared===null || $prepared['origin_id']!==$this->origin || $prepared['policy_version']!==RankingPolicy::VERSION) { throw new ModelViolation('hof_origin_conflict'); }
            $row=$this->row();$fields=['operation'=>'register','lookup_operation'=>'','action_id'=>$row['action_id']??wp_generate_uuid4(),
                'origin_id'=>$this->origin,'policy_version'=>RankingPolicy::VERSION,'object'=>$descriptor];$bytes=CanonicalJson::encode($fields);
            if ($row!==null) { $this->matching($row,$bytes);return $fields; }
            $this->storage->query($this->db->prepare('INSERT INTO %i (origin_id,policy_version,action_id,fields_json,fields_sha256,state,prepared_by,opened_by,primary_ack_at,admissible_from) VALUES (%s,%s,%s,%s,%s,%s,%d,0,%s,%s)',
                $this->table,$this->origin,RankingPolicy::VERSION,$fields['action_id'],$bytes,hash('sha256',$bytes),'opening',get_current_user_id(),'',''));
            return $fields;
        });
        $progress=$this->inbox->prepare($fields);
        return ['action_id'=>$fields['action_id'],'phase'=>$progress['phase'],'local_state'=>$progress['state']];
    }

    /** @return array<string,string> */
    public function apply(string $action): array
    {
        $this->available();RankingRegistry::administrator();ModelValues::uuid($action);
        return $this->inbox->withAcknowledgement($action,function (array $ack): array {
            $row=$this->row();if ($row===null || $row['action_id']!==$ack['fields']['action_id']) { throw new ModelViolation('hof_origin_conflict'); }
            $this->matching($row,CanonicalJson::encode($ack['fields']));$opening=OriginOpening::fromAcknowledgement($ack,$this->origin);
            if ($row['state']==='open') {
                if ($row['primary_ack_at']!==$opening['primary_ack_at'] || $row['admissible_from']!==$opening['admissible_from']) { throw new ModelViolation('hof_origin_conflict'); }
                return $row;
            }
            if ($row['state']!=='opening' || $row['primary_ack_at']!=='' || $row['admissible_from']!=='') { throw new ModelViolation('hof_origin_conflict'); }
            if ($this->storage->query($this->db->prepare('UPDATE %i SET state=%s,opened_by=%d,primary_ack_at=%s,admissible_from=%s WHERE origin_id=%s AND policy_version=%s AND state=%s',
                $this->table,'open',get_current_user_id(),$opening['primary_ack_at'],$opening['admissible_from'],$this->origin,RankingPolicy::VERSION,'opening'))!==1) { throw new ModelViolation('hof_origin_conflict'); }
            return $this->row()??throw new ModelViolation('hof_storage_unavailable');
        });
    }

    /** Current trust, primary proof and local origin closure gate every delivery, not only the initial write.
     * @return array<string,string> */
    public function read(): array
    {
        $this->available();RankingRegistry::administrator();$row=$this->storage->row($this->db->prepare('SELECT * FROM %i WHERE origin_id=%s AND policy_version=%s',$this->table,$this->origin,RankingPolicy::VERSION));
        if ($row===null || $row['state']!=='open') { throw new ModelViolation('hof_origin_acknowledgement_required'); }
        return $this->inbox->withAcknowledgement($row['action_id'],function (array $ack) use ($row): array {
            $current=$this->row();$this->matching($row,CanonicalJson::encode($ack['fields']));$opening=OriginOpening::fromAcknowledgement($ack,$this->origin);
            if ($current!==$row || $row['primary_ack_at']!==$opening['primary_ack_at'] || $row['admissible_from']!==$opening['admissible_from']) { throw new ModelViolation('hof_origin_conflict'); }
            $now=RankingValues::utc($this->db->get_var('SELECT UTC_TIMESTAMP(6)'));
            if ($now<$row['admissible_from'] || $now>=$ack['fields']['object']['valid_until']) { throw new ModelViolation('hof_origin_unavailable'); }
            return $row;
        });
    }

    private function available(): void
    { ClosedEnvironment::assertIsolated($this->db,'fans');if (!ClosedOriginOpeningSchema::ready($this->db)) { throw new ModelViolation('hof_origin_schema_unavailable'); } }
    /** @return array<string,string>|null */
    private function row(): ?array
    { return $this->storage->row($this->db->prepare('SELECT * FROM %i WHERE origin_id=%s AND policy_version=%s FOR UPDATE',$this->table,$this->origin,RankingPolicy::VERSION)); }
    /** @param array<string,string> $row */
    private function matching(array $row,string $bytes): void
    { if ($row['fields_json']!==$bytes || $row['fields_sha256']!==hash('sha256',$bytes)) { throw new ModelViolation('hof_origin_conflict'); } }
}
