"""Private persisted session dimensions, no public opening or winner."""
import copy
import hashlib
import json
import os
import secrets
import signal
import subprocess
import time
import uuid


def run_checks(root,source,cli_path,check,command,fixture,reader,hub,hub_sql):
    origin=fixture['origin'];fans=fixture['fans'];sql=fixture['fan_sql'];table='wp_fans_hof_b4_generation'
    def invoke(action,**extra):
        fixture['set_clock']();path=root/(uuid.uuid4().hex+'.json')
        path.write_text(json.dumps(dict(action='cache-'+action,origin=origin,**extra)));path.chmod(0o600)
        return json.loads(command(['php',cli_path,'--allow-root','--path='+str(fans),'eval-file',
            str(source/'tests/TokenEngine/recipe/b3-corpus-inbox-worker.php'),str(path),'--use-include']))
    def stored():return sql('SELECT document_json,document_sha256 FROM '+table+' ORDER BY origin_id,policy_version')
    def lit(value):return "'"+value.replace('\\','\\\\').replace("'","''")+"'"
    document=invoke('read')['document'];session_id=fixture['intent']['ranking_context']['sessions'][0]['session_id']
    selected=next(row for row in document['sessions'] if row['session_id']==session_id)
    check('B5b current authenticated generation persists session and persistent dimensions atomically',
          document['cache_format']=='2' and sum(int(r['points']) for r in selected['fans'])==2
          and sum(int(r['points']) for r in document['general']['fans'])==2)
    check('B5b derived session rows contain no public status winner or reward',
          all('winner' not in row and 'state' not in row and 'price' not in row for row in document['sessions']))
    before=stored()
    old=dict(document);old.pop('sessions');old.pop('cache_format')
    old['months']=[dict(month=month,**statement) for month,statement in sorted(old['months'].items())]
    body=json.dumps(old,sort_keys=True,separators=(',',':'))
    sql('UPDATE '+table+' SET document_json='+lit(body)+',document_sha256='+lit(hashlib.sha256(body.encode()).hexdigest())+' WHERE origin_id='+lit(origin))
    check('B5b earlier derived cache format cannot pretend session completeness',invoke('read')==dict(error='hof_projection_not_reconciled'))
    invoke('rebuild');check('B5b explicit rebuild restores all derived dimensions from current proof',stored()==before)
    check('B5b failed insert rolls back session and persistent results together',
          invoke('rebuild',fault='cache-insert')==dict(error='hof_projection_storage_unavailable') and stored()==before)
    check('B5b unknown COMMIT recovers the same entire session generation',
          invoke('rebuild',fault='commit-unknown')==dict(error='pf_local_corpus_commit_unknown') and stored()==before
          and invoke('read')['document']['sessions']==document['sessions'])
    for fault in ('before-commit','after-commit'):
        path=root/(uuid.uuid4().hex+'.json');marker=root/uuid.uuid4().hex
        path.write_text(json.dumps(dict(action='cache-rebuild',origin=origin,fault=fault,marker=str(marker))));path.chmod(0o600)
        process=subprocess.Popen(['php',cli_path,'--allow-root','--path='+str(fans),'eval-file',
            str(source/'tests/TokenEngine/recipe/b3-corpus-inbox-worker.php'),str(path),'--use-include'],
            stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,start_new_session=True)
        try:
            deadline=time.monotonic()+25
            while not marker.exists():
                if process.poll() is not None or time.monotonic()>deadline:raise RuntimeError('Private B5 process checkpoint unavailable.')
                time.sleep(.05)
        finally:
            if process.poll() is None:os.killpg(process.pid,signal.SIGKILL)
            process.wait(timeout=10)
        check('B5b process death '+fault+' preserves a coherent full primary generation',
              stored()==before and invoke('read')['document']['sessions']==document['sessions'])

    historical=hub_sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')
    pack=dict(fixture['proof'],purchase_id='synthetic.'+uuid.uuid4().hex,evidence_id=str(uuid.uuid4()),
              member_faluss_id=str(uuid.uuid4()),purchased_pf='4')
    lot=hub('h1-evidence',payload=pack,key=secrets.token_hex(32))['lot_id']
    assert hub('h2-admit',lot_id=lot,member=pack['member_faluss_id'],key=secrets.token_hex(32))['state']=='admitted'
    intent=copy.deepcopy(fixture['intent']);extra=copy.deepcopy(intent['ranking_context']['sessions'][0])
    extra['session_id']=str(uuid.uuid4());intent['ranking_context']['sessions'].append(extra)
    intent['ranking_context']['sessions'].sort(key=lambda row:row['session_id'])
    intent.update(attribution_id=str(uuid.uuid4()),member_faluss_id=pack['member_faluss_id'],purchased_pf='4')
    intent['context_sha256']=hashlib.sha256(json.dumps(intent['ranking_context'],sort_keys=True,separators=(',',':')).encode()).hexdigest()
    for ref in hub('b3b-refs',payload=intent)['references']:
        if ref['content']['object_id']!=extra['session_id']:continue
        descriptor=dict(content=ref['content'],valid_from=ref['content']['starts_at'],valid_until=ref['content']['ends_at'])
        if ref['content']['kind']=='admission':descriptor['valid_from']=max(descriptor['valid_from'],ref['content']['admitted_at'])
        assert hub('b3b-register',payload=descriptor,key=secrets.token_hex(32))['state']=='active'
    fixture['consume'](intent)
    old_generation=invoke('read')['generation']
    def refresh():
        assert reader(str(uuid.uuid4()))['state']=='verified'
        assert invoke('read')==dict(error='hof_projection_not_reconciled')
        invoke('rebuild');return invoke('read')['document']
    def points(doc,id):
        row=next(row for row in doc['sessions'] if row['session_id']==id)
        return sum(int(v['points']) for v in row['fans']),sum(int(v['points']) for v in row['creators'])
    fresh=refresh()
    check('B5b one actual owner consumption feeds two session projections without a second debit',
          points(fresh,session_id)==(6,6) and points(fresh,extra['session_id'])==(4,4)
          and sum(int(row['points']) for row in fresh['general']['fans'])==6
          and hub_sql("SELECT COUNT(*) FROM wp_token_engine_pf_h2c_consumptions WHERE attribution_id='"+intent['attribution_id']+"'")=='1')
    for revision,state,cancelled,net in [(2,'partially_cancelled',2,2),(3,'disputed',2,0),(4,'partially_cancelled',2,2),(5,'cancelled',4,0)]:
        correction=dict(pack,source_revision=str(revision),evidence_id=str(uuid.uuid4()),state=state,cancelled_purchased_pf_cumulative=str(cancelled))
        plan=hub('h4-begin',payload=correction,key=secrets.token_hex(32))
        if revision==2:
            check('B5b incomplete correction closes stale session cache access',reader(str(uuid.uuid4()))['state']=='unavailable'
                  and invoke('read')==dict(error='hof_projection_source_unavailable'))
        for fragment in range(int(plan['next_fragment'])+1,int(plan['fragment_count'])+1):
            hub('h4-resume',member=pack['member_faluss_id'],plan_id=plan['plan_id'],fragment=str(fragment))
        fresh=refresh()
        check('B5b correction revision '+str(revision)+' updates every selected session and persistent projection',
              points(fresh,session_id)==(2+net,2+net) and points(fresh,extra['session_id'])==(net,net)
              and sum(int(row['points']) for row in fresh['general']['fans'])==2+net)
    check('B5b corrections replace the generation without restoring cancelled points',
          invoke('read')['generation']!=old_generation and points(fresh,extra['session_id'])==(0,0))
    check('B5b historical ALB claims stay byte identical and no Fans PF ledger exists',
          hub_sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')==historical and sql("SHOW TABLES LIKE 'wp_fans%ledger%'")=='')
