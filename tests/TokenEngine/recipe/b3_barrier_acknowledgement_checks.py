"""Current signed acknowledgement facade, real SQL/HTTP; no B2 lifecycle or SSO claim."""
import copy
import datetime
import hashlib
import uuid


def run_checks(root,check,hub_sql,network):
    local=network['recovery'];invoke=local['invoke'];sql=local['sql'];advance=local['advance'];literal=local['literal']
    table=local['table'];events=network['events'];clock=root/'barrier-primary-clock'
    ledger=hub_sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')
    sql('CREATE TABLE wp_barrier_ack_probe (action_id CHAR(36) NOT NULL PRIMARY KEY) ENGINE=InnoDB')
    def fresh(opened=True):
        _,refs,objects=network['fixture']()
        ref=next(value for value in refs if value['content']['kind']=='session')
        descriptor=next(value for value in objects if value['content']==ref['content'])
        fields=network['fields'](descriptor);local['prepare'](fields)
        if opened:assert advance(fields)['state']=='acknowledged'
        return fields,ref,descriptor
    def ack(fields,**extra):return invoke('ack',fields['origin_id'],action_id=fields['action_id'],**extra)
    def count():return int(sql('SELECT COUNT(*) FROM wp_barrier_ack_probe'))
    try:
        pending,_,_=fresh(False)
        check('B3ba pending preparation cannot run a local acknowledgement callback',
            ack(pending,probe=True)==dict(error='pf_local_barrier_acknowledgement_required') and count()==0)
        assert advance(pending)['state']=='acknowledged'
        value=ack(pending)
        check('B3ba facade returns exact authenticated fields and primary result in one transaction',
            value['fields']==pending and value['contract']=='hub.purchased-pf.ranking-barriers/1.0.0'
            and value['result']['operation']=='register' and value['local_state']=='active' and value['transaction']=='1')
        check('B3ba facade discloses neither operation key nor signed request or response wire',
            set(value)=={'fields','contract','result','local_state','transaction'})
        check('B3ba another origin cannot apply this acknowledgement',
            'error' in invoke('ack',str(uuid.uuid4()),action_id=pending['action_id'],probe=True) and count()==0)
        check('B3ba rejected governance callback rolls back its local metadata',
            ack(pending,probe=True,reject_apply=True)==dict(error='pf_fixture_apply_refused') and count()==0)
        check('B3ba nested transaction is refused and callback metadata rolls back',
            ack(pending,probe=True,nested=True)==dict(error='nested_transaction_refused') and count()==0)
        check('B3ba unknown local COMMIT can be inspected without a new Hub operation',
            ack(pending,probe=True,fault='commit-unknown')==dict(error='pf_local_barrier_commit_unknown') and count()==1
            and ack(pending)['result']==value['result'])
        policy=copy.deepcopy(network['policies']['fans']);policy['keys']['recipe-hub-k1']['state']='revoked'
        network['policy']('fans',policy)
        check('B3ba revoked current Hub trust closes historical acknowledgement application','error' in ack(pending))
        network['policy']('fans',network['policies']['fans'])
        saved=sql('SELECT proof_json FROM '+table+'actions WHERE action_id='+literal(pending['action_id']))
        sql('UPDATE '+table+'actions SET proof_sha256='+literal('a'*64)+' WHERE action_id='+literal(pending['action_id']))
        check('B3ba altered durable proof cannot reach a callback',ack(pending,probe=True)==dict(error='pf_local_barrier_conflict') and count()==1)
        sql('UPDATE '+table+'actions SET proof_sha256='+literal(hashlib.sha256(saved.encode()).hexdigest())+' WHERE action_id='+literal(pending['action_id']))
        opening,ref,descriptor=fresh()
        closing=dict(opening,operation='close',action_id=str(uuid.uuid4()),
                     object={name:ref[name] for name in ('barrier_key','version','content_sha256')}|dict(reason='session_cancelled'))
        local['prepare'](closing)
        check('B3ba old register proof exposes closing rather than reopening local choices',
            ack(opening)['local_state']=='closing' and ack(opening)['result']['state']=='active')
        assert advance(closing)['state']=='acknowledged'
        check('B3ba confirmed cancellation and old registration both retain current closed state',
            ack(closing)['result']['reason']=='session_cancelled' and ack(closing)['local_state']=='closed'
            and ack(opening)['local_state']=='closed')
        opening,ref,descriptor=fresh();completion=dict(opening,operation='close',action_id=str(uuid.uuid4()),
            object={name:ref[name] for name in ('barrier_key','version','content_sha256')}|dict(reason='session_completed'))
        invoke('prepare',completion['origin_id'],fields=completion,contract='hub.purchased-pf.ranking-barriers/1.1.0')
        check('B3ba unacknowledged completion never promotes a completed local lifecycle',
            ack(completion)==dict(error='pf_local_barrier_acknowledgement_required'))
        at=datetime.datetime.fromisoformat(descriptor['valid_until']).replace(tzinfo=datetime.timezone.utc)
        clock.write_text(format(at.timestamp()+1,'.6f'));clock.chmod(0o600)
        assert advance(completion)['state']=='acknowledged';before=events();value=ack(completion)
        check('B3ba explicit 1.1 completion supplies its primary instant and reason without a second operation',
            value['contract']=='hub.purchased-pf.ranking-barriers/1.1.0' and value['result']['reason']=='session_completed'
            and value['result']['effective_at']>=descriptor['valid_until'] and value['local_state']=='closed' and events()==before)
        check('B3ba acknowledgement facade leaves the official ledger unchanged and adds no Fan ledger',
            hub_sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')==ledger and sql("SHOW TABLES LIKE 'wp_fans%ledger%'")=='')
    finally:
        clock.unlink(missing_ok=True);network['policy']('fans',network['policies']['fans'])
        sql('DROP TABLE IF EXISTS wp_barrier_ack_probe')
