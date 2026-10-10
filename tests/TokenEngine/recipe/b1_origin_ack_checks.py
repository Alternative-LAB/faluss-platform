"""Closed origin metadata bound to actual current primary ACK, not account/purchase dates."""
import concurrent.futures
import copy
import uuid


def run_checks(root,check,hub_sql,network):
    local=network['recovery'];invoke=local['invoke'];sql=local['sql']
    _,_,objects=network['fixture']();descriptor=next(row for row in objects if row['content']['kind']=='origin');origin=descriptor['content']['origin_id']
    table='wp_fans_hof_origin_records';ledger=hub_sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')
    def run(action,**extra):return invoke('origin-'+action,origin,**extra)
    def stored():return sql('SELECT * FROM '+table+' ORDER BY origin_id,policy_version')
    seed=run('seed')
    check('B1oa prepared metadata does not claim actual admission',seed['state']=='prepared' and sql("SHOW TABLES LIKE 'wp_fans_hof_origin_%'")=='')
    check('B1oa readiness makes no schema or opening evidence',run('ready')==dict(ready=False))
    sql('CREATE TABLE '+table+' (id INT NOT NULL) ENGINE=InnoDB')
    check('B1oa partial private schema is refused without adopting data',run('install')==dict(error='model_schema_divergent'))
    sql('DROP TABLE '+table)
    check('B1oa explicit physical installation verifies two InnoDB tables',run('install')==dict(ready=True) and run('install')==dict(ready=True))
    check('B1oa guest cannot prepare origin admission',run('prepare',actor=0,descriptor=descriptor)==dict(error='hof_forbidden') and stored()=='')
    check('B1oa prepared origin without primary evidence stays unavailable',run('read')==dict(error='hof_origin_acknowledgement_required'))
    prepared=run('prepare',descriptor=descriptor);before=stored()
    check('B1oa preparation persists action but discloses no key or wire',set(prepared)=={'action_id','phase','local_state'} and prepared['local_state']=='opening')
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:answers=list(pool.map(lambda _:run('prepare',descriptor=descriptor),range(4)))
    check('B1oa concurrent callers preserve the same action and frozen interval',all(value==prepared for value in answers) and stored()==before)
    changed=copy.deepcopy(descriptor);changed['valid_until']='2030-01-01 00:00:00.000000'
    check('B1oa second admission interval cannot replace the origin opening',run('prepare',descriptor=changed)==dict(error='hof_origin_conflict') and stored()==before)
    check('B1oa pending action cannot become actual opening',run('apply',action_id=prepared['action_id'])==dict(error='pf_local_barrier_acknowledgement_required'))
    assert invoke('advance',origin,action_id=prepared['action_id'],endpoint=local['endpoint'])['state']=='acknowledged'
    check('B1oa local record failure rolls back without consuming the primary ACK',run('apply',action_id=prepared['action_id'],fault='origin-open-write')==dict(error='hof_storage_unavailable') and stored()==before)
    unknown=run('apply',action_id=prepared['action_id'],fault='commit-unknown');opened=run('read');fields=invoke('ack',origin,action_id=prepared['action_id'])
    check('B1oa unknown local COMMIT is inspected on the primary without a new action',unknown==dict(error='pf_local_barrier_commit_unknown') and opened['state']=='open')
    check('B1oa actual opening is the exact Hub instant rather than account purchase or receipt time',
          opened['primary_ack_at']==fields['result']['effective_at'] and opened['admissible_from']==max(descriptor['valid_from'],fields['result']['effective_at']))
    before=stored();events=network['events']()
    check('B1oa repeated ACK application preserves the original opening and local actor',run('apply',action_id=prepared['action_id'])==opened and stored()==before and network['events']()==events)
    policy=copy.deepcopy(network['policies']['fans']);policy['keys']['recipe-hub-k1']['state']='revoked';network['policy']('fans',policy)
    check('B1oa current revoked Hub trust closes delivery of old opening evidence','error' in run('read') and stored()==before)
    network['policy']('fans',network['policies']['fans'])
    ref=network['fields'](descriptor);reference={name:fields['result'][name] for name in ('barrier_key','version','content_sha256')}
    close=dict(ref,operation='close',action_id=str(uuid.uuid4()),object=reference|dict(reason='origin_closed'))
    local['prepare'](close)
    check('B1oa local closing immediately prevents an old register ACK from reopening delivery','error' in run('read') and stored()==before)
    assert local['advance'](close)['state']=='acknowledged'
    check('B1oa final closed origin retains history without returning active admission','error' in run('read') and stored()==before)
    check('B1oa admission evidence creates no PF debit credit or parallel Fan ledger',hub_sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')==ledger and sql("SHOW TABLES LIKE 'wp_fans%ledger%'")=='')
