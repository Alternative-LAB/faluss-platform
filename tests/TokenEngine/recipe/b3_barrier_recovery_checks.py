"""Durable metadata and actual WordPress HTTP, not governance/SSO or production proof."""
import concurrent.futures
import copy
import hashlib
import json
import os
import signal
import subprocess
import time
import uuid


def run_checks(root,fans,source,cli_path,check,command,hub_sql,network,policy,policies,fixture,close_ref,fields,sign,accept,http,endpoint,events):
    table='wp_fans_pf_b3b_'
    def sql(query):
        return command(['mariadb','--no-defaults','--socket='+str(root/'sql.sock'),'-uroot','--batch','--skip-column-names','fans_pf_recipe','-e',query]).strip()
    def invoke(action,origin=None,**extra):
        path=root/(uuid.uuid4().hex+'.json');path.write_text(json.dumps(dict(action=action,origin=origin,**extra)));path.chmod(0o600)
        return json.loads(command(['php',cli_path,'--allow-root','--path='+str(fans),'eval-file',
                                  str(source/'tests/TokenEngine/recipe/b3-barrier-inbox-worker.php'),str(path),'--use-include']))
    def literal(value):return "'"+value.replace('\\','\\\\').replace("'","''")+"'"
    def count():return [int(sql('SELECT COUNT(*) FROM '+table+kind)) for kind in ('versions','actions','requests')]
    def fresh():
        _,refs,objects=fixture();opening=fields(objects[0]);return opening,refs[0]
    def prepare(opening,**extra):return invoke('prepare',opening['origin_id'],fields=opening,**extra)
    def recover(opening):return invoke('recover',opening['origin_id'],action_id=opening['action_id'])
    def advance(opening,steps=4,**extra):return invoke('advance',opening['origin_id'],action_id=opening['action_id'],endpoint=endpoint,steps=steps,**extra)
    def proof(opening,request,wire):return dict(wire=wire,fields=opening,nonce=request['nonce'],request_sha256=hashlib.sha256(request['wire'].encode()).hexdigest())
    ledger=hub_sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')
    check('B3br ordinary activation installed no barrier inbox',sql("SHOW TABLES LIKE '"+table+"%'")=='')
    check('B3br readiness creates no metadata',invoke('ready')==dict(ready=False))
    sql('CREATE TABLE '+table+'actions (id INT NOT NULL) ENGINE=InnoDB')
    check('B3br partial schema is never adopted',invoke('install')==dict(error='model_schema_divergent'))
    sql('DROP TABLE '+table+'actions')
    check('B3br explicit isolated installation verifies four InnoDB tables',invoke('install')==dict(ready=True)
          and invoke('install')==dict(ready=True) and len(sql("SHOW TABLES LIKE '"+table+"%'").splitlines())==4)
    opening,ref=fresh();first=prepare(opening)
    check('B3br opening is durable without claiming primary admission',first['state']=='opening' and first['phase']=='lookup'
          and recover(opening)==first and count()==[1,1,0])
    sql('UPDATE '+table+'versions SET state=\'active\' WHERE barrier_key='+literal(ref['barrier_key']))
    check('B3br local active label without a signed acknowledgement grants no admission',recover(opening)==dict(error='pf_local_barrier_conflict'))
    sql('UPDATE '+table+'versions SET state=\'opening\' WHERE barrier_key='+literal(ref['barrier_key']))
    with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:answers=list(pool.map(lambda _:prepare(opening),range(6)))
    check('B3br concurrent preparations retain exactly one action and key',all(x==first for x in answers) and count()==[1,1,0])
    changed=dict(opening,action_id=str(uuid.uuid4()));before=count()
    check('B3br another action cannot replace a pending version key',prepare(changed)==dict(error='pf_local_barrier_conflict') and count()==before)
    check('B3br another origin cannot recover or mutate this action','error' in invoke('recover',str(uuid.uuid4()),action_id=opening['action_id']) and count()==before)
    closing=dict(opening,operation='close',action_id=str(uuid.uuid4()),object=close_ref(ref));closed=prepare(closing)
    check('B3br close immediately blocks choices while register remains uncertain',closed['state']=='closing'
          and closed['blocking_action_id']==opening['action_id'] and recover(opening)['state']=='closing')
    done=advance(closing,monitor=True)
    check('B3br bounded WordPress client reconciles opening before queued close',done['state']=='acknowledged' and done['local_state']=='closed'
          and len(done['http_trace'])==4 and all(x==dict(transaction='0',mutex_free='1',registered='1') for x in done['http_trace']))
    check('B3br closed action recovery keeps both original keys',recover(opening)['operation_key']==first['operation_key']
          and recover(closing)['operation_key']==closed['operation_key'] and advance(closing)['completed_steps']=='0')
    check('B3br old opening preparation cannot reopen a closed version',prepare(opening)['state']=='closed')
    # A delayed authenticated opening result cannot override closing, even before its close reaches Hub.
    pending,pending_ref=fresh();p=prepare(pending);assert advance(pending,1)['state']=='pending'
    request=sign(pending,p['operation_key']);assert invoke('request',pending['origin_id'],fields=pending,sealed=request)==dict(accepted=True)
    status,wire,_=http(request['wire']);assert status==200
    queued=dict(pending,operation='close',action_id=str(uuid.uuid4()),object=close_ref(pending_ref));prepare(queued)
    accepted=invoke('accept',pending['origin_id'],proof=proof(pending,request,wire))
    check('B3br late signed register acknowledgement cannot reopen closing',accepted['phase']=='acknowledged' and accepted['state']=='closing')
    check('B3br primary close acknowledgement finishes the same queued action',advance(queued)['local_state']=='closed'
          and invoke('accept',pending['origin_id'],proof=proof(pending,request,wire))['state']=='closed')
    # Durable key and action recover a dropped body only by primary lookup.
    lost,lost_ref=fresh();original=prepare(lost);assert advance(lost,1)['state']=='pending';before=events()
    (root/'barrier-http-fault').write_text('drop-after-commit');uncertain=advance(lost,1)
    check('B3br lost register body leaves opening unresolved with the original key',uncertain['state']=='pending'
          and uncertain['reason']=='pf_transport_unknown' and events()==before+1 and recover(lost)['operation_key']==original['operation_key'])
    recovered=advance(lost,1)
    check('B3br lookup after lost register commits no second event',recovered['state']=='acknowledged'
          and recovered['local_state']=='active' and events()==before+1)
    close=dict(lost,operation='close',action_id=str(uuid.uuid4()),object=close_ref(lost_ref));c=prepare(close)
    assert advance(close,1)['state']=='pending';before=events();(root/'barrier-http-fault').write_text('drop-after-commit')
    uncertain=advance(close,1)
    check('B3br lost close body never restores choices',uncertain['state']=='pending' and recover(close)['state']=='closing'
          and recover(close)['operation_key']==c['operation_key'] and events()==before+1)
    check('B3br primary close lookup establishes closed without a second event',advance(close,1)['local_state']=='closed' and events()==before+1)
    # Transactional faults cannot publish partial local closing or lose the immutable key.
    fault,fault_ref=fresh();before=count()
    check('B3br action insert failure rolls back its version too',prepare(fault,fault='action-insert')==dict(error='pf_local_barrier_storage') and count()==before)
    prepared=prepare(fault);failed_close=dict(fault,operation='close',action_id=str(uuid.uuid4()),object=close_ref(fault_ref));before=count()
    check('B3br failure after setting closing rolls back both state and action',prepare(failed_close,fault='action-insert')==dict(error='pf_local_barrier_storage')
          and recover(fault)['state']=='opening' and count()==before)
    ambiguous=prepare(failed_close,fault='commit-unknown');stored=recover(failed_close)
    check('B3br lost local COMMIT acknowledgement recovers durable closing and key',ambiguous==dict(error='pf_local_barrier_commit_unknown')
          and stored['state']=='closing' and prepare(failed_close)['operation_key']==stored['operation_key'])
    another,another_ref=fresh();prepare(another);before=events()
    check('B3br request insert failure prevents all HTTP',advance(another,1,fault='request-insert')==dict(error='pf_local_barrier_storage')
          and recover(another)['phase']=='lookup' and events()==before)
    check('B3br unchanged action resumes after request persistence recovery',advance(another)['state']=='acknowledged')
    # The controller terminates real CLI processes on both sides of COMMIT, then reads the primary.
    for point in ('before-commit','after-commit'):
        killed,killed_ref=fresh();base=prepare(killed);queued=dict(killed,operation='close',action_id=str(uuid.uuid4()),object=close_ref(killed_ref))
        marker=root/uuid.uuid4().hex;path=root/(uuid.uuid4().hex+'.json')
        path.write_text(json.dumps(dict(action='prepare',origin=killed['origin_id'],fields=queued,fault=point,marker=str(marker))));path.chmod(0o600)
        job=subprocess.Popen(['php',cli_path,'--allow-root','--path='+str(fans),'eval-file',str(source/'tests/TokenEngine/recipe/b3-barrier-inbox-worker.php'),str(path),'--use-include'],
                             stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
        try:
            deadline=time.monotonic()+15
            while not marker.exists() and time.monotonic()<deadline:
                if job.poll() is not None:raise RuntimeError('Barrier fixture stopped before checkpoint.')
                time.sleep(.05)
            if not marker.exists():raise RuntimeError('Barrier fixture checkpoint not reached.')
            os.killpg(job.pid,signal.SIGKILL);job.wait(timeout=5)
        finally:
            if job.poll() is None:os.killpg(job.pid,signal.SIGKILL);job.wait(timeout=5)
        recovered=recover(killed)
        check('B3br real process death '+point+' preserves atomic local close outcome',recovered['state']==('opening' if point=='before-commit' else 'closing')
              and recovered['operation_key']==base['operation_key'])
        check('B3br close replay '+point+' restores one stable action',prepare(queued)['state']=='closing' and advance(queued)['local_state']=='closed')
    sql('ALTER TABLE '+table+'requests ENGINE=MyISAM')
    check('B3br unsafe metadata prevents private recovery',recover(lost)==dict(error='pf_local_barrier_schema'))
    sql('ALTER TABLE '+table+'requests ENGINE=InnoDB')
    revoked=copy.deepcopy(policies['fans']);revoked['keys']['recipe-hub-k1']['state']='revoked';policy('fans',revoked)
    check('B3br revoked Hub key cannot authorize a persisted acknowledgement','error' in recover(lost))
    policy('fans',policies['fans'])
    sql('UPDATE '+table+'versions SET state=\'active\' WHERE barrier_key='+literal(lost_ref['barrier_key']))
    check('B3br restoring only an old active row cannot undo a durable close',recover(lost)==dict(error='pf_local_barrier_conflict'))
    sql('UPDATE '+table+'versions SET state=\'closed\' WHERE barrier_key='+literal(lost_ref['barrier_key']))
    check('B3br invalid endpoint and budgets send no HTTP',invoke('advance',lost['origin_id'],action_id=lost['action_id'],endpoint='https://example.invalid')['error']=='pf_fixture_peer_required'
          and invoke('advance',lost['origin_id'],action_id=lost['action_id'],endpoint=endpoint,steps=0)['error']=='pf_local_barrier_budget')
    check('B3br recovery never mutates PF or creates a parallel ledger',hub_sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')==ledger
          and sql("SHOW TABLES LIKE 'wp_fans%ledger%'")=='')
