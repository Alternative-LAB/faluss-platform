"""Durable 1.1 completion, real isolated WordPress HTTP, no governance/central SSO."""
import concurrent.futures
import copy
import datetime
import hashlib
import json
import os
import signal
import subprocess
import time
import uuid


def run_checks(root,source,cli_path,check,hub_sql,network):
    local=network['recovery'];invoke=local['invoke'];sql=local['sql'];recover=local['recover'];advance=local['advance']
    table=local['table'];literal=local['literal'];events=network['events'];contract='hub.purchased-pf.ranking-barriers/1.1.0'
    clock=root/'barrier-primary-clock'
    old_ids=sql('SELECT action_id FROM '+table+'actions ORDER BY action_id').splitlines()
    where=' WHERE action_id IN ('+','.join(literal(value) for value in old_ids)+') ORDER BY action_id'
    old=sql('SELECT * FROM '+table+'actions'+where);ledger=hub_sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')
    def fresh(kind='session',opened=True):
        clock.unlink(missing_ok=True);_,refs,objects=network['fixture']()
        ref=next(value for value in refs if value['content']['kind']==kind)
        descriptor=next(value for value in objects if value['content']==ref['content'])
        opening=network['fields'](descriptor);prepared=local['prepare'](opening)
        assert prepared['state']=='opening'
        if opened:assert advance(opening)['state']=='acknowledged'
        closing=dict(opening,operation='close',action_id=str(uuid.uuid4()),
            object={name:ref[name] for name in ('barrier_key','version','content_sha256')}|dict(reason='session_completed'))
        return opening,closing,descriptor
    def prepare(fields,**extra):return invoke('prepare',fields['origin_id'],fields=fields,contract=contract,**extra)
    def primary(descriptor,offset=1):
        at=datetime.datetime.fromisoformat(descriptor['valid_until']).replace(tzinfo=datetime.timezone.utc)
        clock.write_text(format(at.timestamp()+offset,'.6f'));clock.chmod(0o600)
    def saved(fields):
        return json.loads(sql('SELECT fields_json FROM '+table+'actions WHERE action_id='+literal(fields['action_id'])))
    try:
        opening,closing,descriptor=fresh();before=events()
        check('B3ber default 1.0 rejects completion without altering local active or primary state',
            invoke('prepare',closing['origin_id'],fields=closing)==dict(error='pf_barrier_invalid_reason')
            and recover(opening)['state']=='active' and events()==before)
        check('B3ber unknown explicit version cannot persist an action',
            invoke('prepare',closing['origin_id'],fields=closing,contract='hub.purchased-pf.ranking-barriers/9.0.0')==dict(error='pf_barrier_contract_mismatch'))
        with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:rows=list(pool.map(lambda _:prepare(closing),range(6)))
        stored=rows[0]
        check('B3ber concurrent preparation persists one action key and explicit version before HTTP',
            all(row==stored for row in rows) and stored['state']=='closing' and stored['phase']=='lookup'
            and stored['contract']==contract and saved(closing)==dict(contract=contract,fields=closing) and events()==before)
        check('B3ber a default-version replay cannot reinterpret the saved completion',
            invoke('prepare',closing['origin_id'],fields=closing)==dict(error='pf_barrier_invalid_reason')
            and recover(closing)['operation_key']==stored['operation_key'])
        wrong=network['sign'](dict(closing,operation='lookup',lookup_operation='close'),stored['operation_key'],contract=contract,
            request=dict(contract='hub.purchased-pf.ranking-barriers/1.0.0'))
        check('B3ber saved explicit version refuses a signed checkpoint of another version before HTTP',
            invoke('request',closing['origin_id'],fields=dict(closing,operation='lookup',lookup_operation='close'),sealed=wrong)
            ==dict(error='pf_local_barrier_request') and events()==before)
        primary(descriptor,-1);early=advance(closing,1)
        check('B3ber signed primary not-due refusal stays pending closing with the same key',
            early['state']=='pending' and recover(closing)['phase']=='lookup' and recover(closing)['state']=='closing'
            and recover(closing)['operation_key']==stored['operation_key'] and events()==before)
        primary(descriptor);look=advance(closing,1)
        check('B3ber primary absence advances only the same close action to delivery',
            look['state']=='pending' and recover(closing)['phase']=='close' and events()==before)
        (root/'barrier-http-fault').write_text('drop-after-commit');lost=advance(closing,1)
        check('B3ber lost 1.1 close body preserves durable closing and version after one owner event',
            lost['state']=='pending' and lost['reason']=='pf_transport_unknown' and events()==before+1
            and recover(closing)['contract']==contract and recover(closing)['operation_key']==stored['operation_key'])
        done=advance(closing,1,monitor=True)
        check('B3ber same-key primary lookup acknowledges completion without another close',
            done['state']=='acknowledged' and done['local_state']=='closed' and events()==before+1
            and done['http_trace']==[dict(transaction='0',mutex_free='1',registered='1')])
        check('B3ber acknowledged completion makes no further HTTP and keeps original action bytes',
            advance(closing)['completed_steps']=='0' and saved(closing)==dict(contract=contract,fields=closing))
        check('B3ber late legacy opening preparation cannot reopen the completed version',local['prepare'](opening)['state']=='closed')
        changed=copy.deepcopy(closing);changed['action_id']=str(uuid.uuid4())
        check('B3ber new action cannot replace the completed version or original key',prepare(changed)==dict(error='pf_local_barrier_conflict'))
        policies=network['policies'];revoked=copy.deepcopy(policies['fans']);revoked['keys']['recipe-hub-k1']['state']='revoked'
        network['policy']('fans',revoked)
        check('B3ber revoked Hub key cannot authorize saved completion proof','error' in recover(closing))
        network['policy']('fans',policies['fans'])
        raw=saved(closing);raw['contract']='hub.purchased-pf.ranking-barriers/9.0.0'
        body=json.dumps(raw,sort_keys=True,separators=(',',':'))
        sql('UPDATE '+table+'actions SET fields_json='+literal(body)+',fields_sha256='+literal(hashlib.sha256(body.encode()).hexdigest())
            +' WHERE action_id='+literal(closing['action_id']))
        check('B3ber matching local digest cannot authorize a substituted contract','error' in recover(closing))
        raw['contract']=contract;body=json.dumps(raw,sort_keys=True,separators=(',',':'))
        sql('UPDATE '+table+'actions SET fields_json='+literal(body)+',fields_sha256='+literal(hashlib.sha256(body.encode()).hexdigest())
            +' WHERE action_id='+literal(closing['action_id']))
        check('B3ber restored original bytes recover the authenticated closed action',recover(closing)['phase']=='acknowledged')

        opening,queued,descriptor=fresh(opened=False);q=prepare(queued)
        check('B3ber pending legacy opening blocks queued 1.1 delivery while choices stay closed',
            q['blocking_action_id']==opening['action_id'] and q['state']=='closing')
        assert advance(queued,2)['state']=='pending'
        primary(descriptor);done=advance(queued,2,monitor=True)
        check('B3ber legacy opening resolves before completion without reopening local closing',
            done['state']=='acknowledged' and done['local_state']=='closed' and len(done['http_trace'])==2
            and all(row==dict(transaction='0',mutex_free='1',registered='1') for row in done['http_trace']))
        for kind in ('origin','admission'):
            opening,wrong,_=fresh(kind);before=events()
            check('B3ber '+kind+' cannot persist a normal session completion',
                prepare(wrong)==dict(error='pf_barrier_completion_requires_session') and recover(opening)['state']=='active' and events()==before)
        opening,failed,descriptor=fresh();before=events()
        check('B3ber failed action insert rolls back local 1.1 closing',
            prepare(failed,fault='action-insert')==dict(error='pf_local_barrier_storage') and recover(opening)['state']=='active' and events()==before)
        check('B3ber uncertain local COMMIT preserves explicit 1.1 bytes and same stable key',
            prepare(failed,fault='commit-unknown')==dict(error='pf_local_barrier_commit_unknown')
            and recover(failed)['contract']==contract and prepare(failed)['operation_key']==recover(failed)['operation_key'])
        primary(descriptor)
        check('B3ber request insert failure prevents 1.1 HTTP',advance(failed,1,fault='request-insert')==dict(error='pf_local_barrier_storage')
            and events()==before and recover(failed)['phase']=='lookup')
        assert advance(failed)['state']=='acknowledged'
        for point in ('before-commit','after-commit'):
            opening,killed,descriptor=fresh();marker=root/uuid.uuid4().hex;path=root/(uuid.uuid4().hex+'.json')
            path.write_text(json.dumps(dict(action='prepare',origin=killed['origin_id'],fields=killed,contract=contract,fault=point,marker=str(marker))));path.chmod(0o600)
            process=subprocess.Popen(['php',cli_path,'--allow-root','--path='+str(local['fans']),'eval-file',
                str(source/'tests/TokenEngine/recipe/b3-barrier-inbox-worker.php'),str(path),'--use-include'],
                stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
            try:
                deadline=time.monotonic()+25
                while not marker.exists():
                    if process.poll() is not None or time.monotonic()>deadline:raise RuntimeError('Private completion checkpoint unavailable.')
                    time.sleep(.05)
            finally:
                if process.poll() is None:os.killpg(process.pid,signal.SIGKILL)
                process.wait(timeout=10)
            check('B3ber real process death '+point+' preserves atomic local outcome',recover(opening)['state']==('active' if point=='before-commit' else 'closing'))
            key=prepare(killed)['operation_key'];primary(descriptor)
            check('B3ber replay '+point+' completes with the recovered explicit version and key',
                advance(killed)['state']=='acknowledged' and recover(killed)['contract']==contract and recover(killed)['operation_key']==key)
        check('B3ber legacy 1.0 action bytes remain unchanged',sql('SELECT * FROM '+table+'actions'+where)==old)
        check('B3ber durable completion changes no economic ledger and creates no parallel one',
            hub_sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')==ledger and sql("SHOW TABLES LIKE 'wp_fans%ledger%'")=='')
    finally:clock.unlink(missing_ok=True)
