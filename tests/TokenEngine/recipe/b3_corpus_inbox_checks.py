"""Durable Fans SQL recovery against real signed Hub HTTP; fictitious private enclave only."""
import concurrent.futures
import copy
import hashlib
import http.client
import json
import secrets
import subprocess
import time
import uuid


def run_checks(root, source, cli_path, check, call, sql, command, workers, log, await_file, fixture):
    if fixture is None: raise RuntimeError('Closed corpus HTTP fixture required.')
    fans=fixture['fans']; fan_sql=fixture['fan_sql']; network=fixture['worker']; origin=fixture['origin']
    recipe=source/'tests/TokenEngine/recipe/b3-corpus-inbox-worker.php'
    def input_file(value):
        path=root/(uuid.uuid4().hex+'.json');path.write_text(json.dumps(value));path.chmod(0o600);return path
    def args(value):return ['php',cli_path,'--allow-root','--path='+str(fans),'eval-file',str(recipe),str(input_file(value)),'--use-include']
    def inbox(action, selected=origin, **extra):return json.loads(command(args(dict(action=action,origin=selected,**extra))))
    def lit(value):return "'"+value.replace('\\','\\\\').replace("'","''")+"'"
    def row_count(table, read):return int(fan_sql('SELECT COUNT(*) FROM wp_fans_pf_b3c_'+table+' WHERE read_id='+lit(read)))
    def prepare(selected=origin):return inbox('prepare',selected,read_id=str(uuid.uuid4()))
    def proof_for(fields, sealed):
        fixture['set_clock']()
        try:status,wire,_=fixture['http'](sealed['wire'])
        except http.client.IncompleteRead as error:status,wire=200,error.partial.decode()
        return status,dict(wire=wire,fields=fields,nonce=sealed['nonce'],request_sha256=hashlib.sha256(sealed['wire'].encode()).hexdigest())
    def request(progress):
        fields=inbox('fields',progress['origin_id'],read_id=progress['read_id'])
        sealed=fixture['sign'](fields,progress['read_key'])
        saved=inbox('request',progress['origin_id'],fields=fields,sealed=sealed)
        assert 'error' not in saved and row_count('requests',progress['read_id'])>0
        return fields,sealed,saved
    def step(progress, fault=None):
        fields,sealed,saved=request(progress);status,proof=proof_for(fields,sealed)
        assert status==200
        answer=inbox('accept',progress['origin_id'],proof=proof,**(dict(fault=fault) if fault else {}))
        return answer,proof
    def refresh(selected=origin):
        progress=prepare(selected);proofs=[]
        for _ in range(10):
            progress,proof=step(progress);proofs.append(proof)
            if 'error' in progress or progress['phase'] in ('current','refused'):return progress,proofs
        raise RuntimeError('Fixture reader did not terminate.')
    def renew(proof):
        t=int(time.time());times=dict(issued_at=time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime(t)),expires_at=time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime(t+60)))
        wire=network('hub',dict(action='alter-response',wire=proof['wire'],response=times))['wire']
        return dict(proof,wire=wire)
    def current(selected=origin):return inbox('current',selected)['current']
    def snapshot():return fan_sql('SELECT origin_id,policy_version,read_id,revision,state,full_sha256 FROM wp_fans_pf_b3c_current ORDER BY origin_id')
    def rejected(value):return isinstance(value,dict) and 'error' in value

    historical=sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')
    check('B3ci ordinary bootstrap installs no durable Fans inbox',inbox('ready')==dict(ready=False))
    fan_sql('CREATE TABLE wp_fans_pf_b3c_pages (id INT NOT NULL) ENGINE=InnoDB')
    check('B3ci partial schema is refused without repair or adoption',inbox('install')==dict(error='model_schema_divergent'))
    fan_sql('DROP TABLE wp_fans_pf_b3c_pages')
    check('B3ci explicit enclave installation verifies five InnoDB metadata tables',inbox('install')==dict(ready=True)
          and inbox('install')==dict(ready=True) and len(fan_sql("SHOW TABLES LIKE 'wp_fans_pf_b3c_%'").splitlines())==5)
    fan_sql('ALTER TABLE wp_fans_pf_b3c_requests ENGINE=MyISAM')
    check('B3ci nontransactional inbox refuses prepare without automatic migration',prepare()==dict(error='pf_local_corpus_schema')
          and inbox('install')==dict(error='model_schema_divergent'))
    fan_sql('ALTER TABLE wp_fans_pf_b3c_requests ENGINE=InnoDB')
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        prepared=list(pool.map(lambda _:prepare(),range(4)))
    p=prepared[0]
    check('B3ci concurrent callers share one durable read and one stable key',len({x['read_id'] for x in prepared})==1
          and len({x['read_key'] for x in prepared})==1 and row_count('reads',p['read_id'])==1)
    check('B3ci a retry with another caller ID keeps the pending primary key',prepare()['read_key']==p['read_key'])
    check('B3ci another origin cannot resume this opaque local read',rejected(inbox('prepare',str(uuid.uuid4()),read_id=p['read_id'])))
    check('B3ci no generation is current before any signed primary response',current() is None)
    p,absent=step(p)
    check('B3ci primary absence advances the same stored key to start',p['phase']=='start')
    fields,sealed,saved=request(p)
    check('B3ci the exact signed request is durable before HTTP and start checkpoints lookup',saved['phase']=='lookup'
          and fan_sql('SELECT request_sha256 FROM wp_fans_pf_b3c_requests WHERE read_id='+lit(p['read_id'])+' AND nonce_sha256='+lit(hashlib.sha256(sealed['nonce'].encode()).hexdigest()))==hashlib.sha256(sealed['wire'].encode()).hexdigest())
    check('B3ci a concurrent stale start checkpoint cannot authorize another outbound operation',
          inbox('request',fields=fields,sealed=fixture['sign'](fields,p['read_key']))==dict(error='pf_local_corpus_checkpoint_moved'))
    before=int(sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3c_corpora'))
    (root/'corpus-http-fault').write_text('drop-after-materialize');status,lost=proof_for(fields,sealed)
    check('B3ci lost Hub body after actual COMMIT keeps Fans unavailable and key durable',status==200 and lost['wire']==''
          and current() is None and int(sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3c_corpora'))==before+1)
    p=inbox('prepare',read_id=p['read_id']);check('B3ci restart resumes lookup rather than another start key',p['phase']=='lookup' and p['read_key']==saved['read_key'])
    p,first=step(p)
    check('B3ci primary lookup recovers the exact committed first page',p['phase']=='page' and p['manifest']['fact_count']=='102'
          and int(sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3c_corpora'))==before+1)
    check('B3ci a valid page proof not registered before HTTP is refused',rejected(inbox('accept',proof=dict(first,request_sha256=secrets.token_hex(32)))))
    check('B3ci duplicate first page is inert with no duplicate staging',inbox('accept',proof=first)==p and row_count('pages',p['read_id'])==1)
    unknown=network('hub',dict(action='alter-response',wire=first['wire'],response=dict(outcome='unknown',result=dict(reason='pf_corpus_commit_unknown'))))
    check('B3ci signed unknown result preserves the pending checkpoint and key',inbox('accept',proof=dict(first,wire=unknown['wire']))==dict(error='pf_transport_unknown')
          and inbox('prepare',read_id=p['read_id'])==p)
    nonce_hash=hashlib.sha256(first['nonce'].encode()).hexdigest();where=' WHERE read_id='+lit(p['read_id'])+' AND nonce_sha256='+lit(nonce_hash)
    registered=fan_sql('SELECT wire_json FROM wp_fans_pf_b3c_requests'+where)
    fan_sql('UPDATE wp_fans_pf_b3c_requests SET wire_json='+lit(registered+' ')+where)
    check('B3ci tampered durable request fails binding even for a valid Hub reply',inbox('accept',proof=first)==dict(error='pf_local_corpus_request')
          and inbox('prepare',read_id=p['read_id'])==p)
    fan_sql('UPDATE wp_fans_pf_b3c_requests SET wire_json='+lit(registered)+where)
    altered=copy.deepcopy(first);outer=json.loads(altered['wire']);signature=outer['signature_base64url'];outer['signature_base64url']=('A' if signature[0]!='A' else 'B')+signature[1:]
    altered['wire']=json.dumps(outer,sort_keys=True,separators=(',',':'))
    check('B3ci altered Hub signature never advances the durable cursor',rejected(inbox('accept',proof=altered)) and inbox('prepare',read_id=p['read_id'])==p)
    p2,second=step(p,'ack-after-commit')
    check('B3ci lost local page COMMIT acknowledgement is unknown after durable staging',p2==dict(error='pf_local_corpus_commit_unknown')
          and row_count('pages',p['read_id'])==2)
    p=inbox('prepare',read_id=p['read_id'])
    check('B3ci restart after local page COMMIT resumes finish without another page',p['phase']=='finish'
          and inbox('accept',proof=second)==p and current() is None)
    fields,sealed,_=request(p);status,fence=proof_for(fields,sealed);assert status==200
    stored=fan_sql('SELECT payload_json FROM wp_fans_pf_b3c_pages WHERE read_id='+lit(p['read_id'])+' AND page_index=0')
    fan_sql('DELETE FROM wp_fans_pf_b3c_pages WHERE read_id='+lit(p['read_id'])+' AND page_index=0')
    check('B3ci even a signed current fence cannot promote a missing page',inbox('accept',proof=fence)==dict(error='pf_corpus_incomplete') and current() is None)
    accepted=json.dumps(first,sort_keys=True,separators=(',',':'))
    fan_sql('INSERT INTO wp_fans_pf_b3c_pages VALUES ('+','.join([lit(p['read_id']),'0',lit(stored),lit(hashlib.sha256(stored.encode()).hexdigest()),lit(accepted),lit(hashlib.sha256(accepted.encode()).hexdigest())])+')')
    changed=json.loads(stored);changed['facts']=changed['facts'][1:];changed=json.dumps(changed,sort_keys=True,separators=(',',':'))
    fan_sql('UPDATE wp_fans_pf_b3c_pages SET payload_json='+lit(changed)+',payload_sha256='+lit(hashlib.sha256(changed.encode()).hexdigest())+' WHERE read_id='+lit(p['read_id'])+' AND page_index=0')
    check('B3ci altered staged facts with recomputed local hash still fail signed completeness',rejected(inbox('accept',proof=fence)) and current() is None)
    fan_sql('UPDATE wp_fans_pf_b3c_pages SET payload_json='+lit(stored)+',payload_sha256='+lit(hashlib.sha256(stored.encode()).hexdigest())+' WHERE read_id='+lit(p['read_id'])+' AND page_index=0')
    check('B3ci final current write failure rolls back all promotion state',inbox('accept',proof=fence,fault='current-write')==dict(error='pf_local_corpus_storage')
          and current() is None and inbox('prepare',read_id=p['read_id'])['phase']=='finish')
    check('B3ci final COMMIT acknowledgement loss is unknown but promotion is atomic',inbox('accept',proof=fence,fault='ack-after-commit')==dict(error='pf_local_corpus_commit_unknown')
          and inbox('prepare',read_id=p['read_id'])['phase']=='current')
    old=current();old_finish=renew(fence)
    check('B3ci complete 102-fact private generation matches corrected owner totals',old is not None and old['manifest']['net_pf']=='20'
          and len(old['facts'])==102 and len({x['attribution_id'] for x in old['facts']})==102)
    before_state=snapshot();check('B3ci duplicate completion is inert',inbox('accept',proof=renew(fence))['phase']=='current' and snapshot()==before_state)

    # Actual worker death before and after COMMIT, with primary state examined independently.
    for mode in ('before-commit','after-commit'):
        selected=str(uuid.uuid4());read=str(uuid.uuid4());marker=root/uuid.uuid4().hex
        process=subprocess.Popen(args(dict(action='prepare',origin=selected,read_id=read,fault=mode,marker=str(marker))),stdout=log,stderr=log,start_new_session=True)
        workers.append(process);await_file(marker,[process]);process.kill();process.wait(timeout=10)
        exists=row_count('reads',read)
        persisted=fan_sql('SELECT read_key FROM wp_fans_pf_b3c_reads WHERE read_id='+lit(read)) if exists else None
        resumed=inbox('prepare',selected,read_id=read)
        check('B3ci process death '+mode+' recovers actual primary checkpoint',exists==(0 if mode=='before-commit' else 1)
              and resumed['phase']=='lookup' and (persisted is None or resumed['read_key']==persisted))
    selected=str(uuid.uuid4());read=str(uuid.uuid4())
    check('B3ci lost prepare acknowledgement cannot allocate a replacement key',inbox('prepare',selected,read_id=read,fault='ack-after-commit')==dict(error='pf_local_corpus_commit_unknown')
          and inbox('prepare',selected,read_id=str(uuid.uuid4()))['read_id']==read and row_count('reads',read)==1)

    p=prepare();check('B3ci beginning a new primary reconciliation hides the old generation',current() is None)
    check('B3ci asking for an earlier completed read resumes the active pending read',inbox('prepare',read_id=old_finish['fields']['read_id'])['read_id']==p['read_id'])
    p,_=step(p);p,page=step(p);assert p['phase']=='page'
    # A further confirmed fact between immutable pages and the final fence supersedes the entire read.
    pack=dict(fixture['proof'],purchase_id='synthetic.'+uuid.uuid4().hex,evidence_id=str(uuid.uuid4()),purchased_pf='5')
    lot=call('h1-evidence',payload=pack,key=secrets.token_hex(32))['lot_id']
    assert call('h2-admit',lot_id=lot,member=pack['member_faluss_id'],key=secrets.token_hex(32))['state']=='admitted'
    unchanged=network('fans',dict(action='exchange',fields=dict(operation='finish',read_id=p['read_id'],origin_id=origin,policy_version='1.0.0',corpus_id=p['manifest']['corpus_id'],cursor=''),key=p['read_key']))
    check('B3ci an admitted pack alone adds no fact or score to the current owner corpus',unchanged['outcome']=='ok'
          and unchanged['result']['manifest']['fact_count']=='102' and unchanged['result']['manifest']['net_pf']=='20')
    fixture['consume'](dict(fixture['intent'],attribution_id=str(uuid.uuid4())))
    p,_=step(p);p,refusal=step(p)
    check('B3ci consumption during collection rejects final promotion instead of publishing a partial corpus',p['phase']=='refused' and current() is None)
    check('B3ci a signed old completion cannot reopen an unavailable previous generation',inbox('accept',proof=renew(old_finish))['phase']=='current' and current() is None)
    fresh,proofs=refresh();assert fresh['phase']=='current'
    check('B3ci explicit reconciliation after supersession includes the unknown new fact',current()['manifest']['fact_count']=='103' and current()['manifest']['net_pf']=='21')

    # Corrections are owner H4 operations; Fans cannot derive or edit the economic net.
    base=fixture['proof']
    def correction(revision,state,cancelled):
        return dict(base,source_revision=str(revision),evidence_id=str(uuid.uuid4()),state=state,cancelled_purchased_pf_cumulative=str(cancelled))
    def complete(value):
        plan=call('h4-begin',payload=value,key=secrets.token_hex(32))
        for fragment in range(int(plan['next_fragment'])+1,int(plan['fragment_count'])+1):
            call('h4-resume',member=base['member_faluss_id'],plan_id=plan['plan_id'],fragment=str(fragment))
        return plan
    value=correction(3,'partially_cancelled',107);plan=call('h4-begin',payload=value,key=secrets.token_hex(32))
    progress,_=refresh()
    check('B3ci incomplete H4 rapprochement leaves no current exact generation',progress['phase']=='refused' and current() is None)
    for fragment in range(int(plan['next_fragment'])+1,int(plan['fragment_count'])+1):call('h4-resume',member=base['member_faluss_id'],plan_id=plan['plan_id'],fragment=str(fragment))
    refreshed,_=refresh();after=current()
    check('B3ci completed partial correction replaces all facts atomically',refreshed['phase']=='current' and after['manifest']['net_pf']=='14'
          and after['manifest']['cancelled_pf']=='89' and len(after['facts'])==103)
    before_state=snapshot()
    check('B3ci delayed signed old success cannot restore cancelled points',inbox('accept',proof=renew(old_finish))['phase']=='current'
          and snapshot()==before_state and current()['manifest']['net_pf']=='14')
    check('B3ci old signed refusal cannot mask a newer completed revision',inbox('accept',proof=renew(refusal))['phase']=='refused'
          and snapshot()==before_state and current()['manifest']['net_pf']=='14')
    complete(correction(4,'disputed',107));refresh()
    check('B3ci dispute makes the affected lot net zero and preserves an unrelated lot',current()['manifest']['net_pf']=='1' and len(current()['facts'])==103)
    complete(correction(5,'partially_cancelled',107));refresh()
    check('B3ci resolution reconstructs only the latest admissible corrected net',current()['manifest']['net_pf']=='14')
    complete(correction(6,'cancelled',120));refresh()
    check('B3ci total cancellation retains zero facts and cannot be undone by old receipts',current()['manifest']['net_pf']=='1'
          and current()['manifest']['cancelled_pf']=='102' and inbox('accept',proof=renew(old_finish))['phase']=='current' and current()['manifest']['net_pf']=='1')

    # At-rest signed witnesses remain auditable after transport expiry, but revoked trust is never ignored.
    policies=fixture['policies'];revoked=copy.deepcopy(policies['fans']);revoked['keys']['recipe-hub-k1']['state']='revoked'
    fixture['policy']('fans',revoked)
    check('B3ci revoked Hub key refuses historical stored generation too',rejected(inbox('current')))
    fixture['policy']('fans',policies['fans'])
    fixture['policy']('fans',dict(policies['fans'],permissions=['pf.snapshot','wallet.read']))
    check('B3ci historical permissions cannot read or resume the global inbox',inbox('current')==dict(error='pf_permission_denied') and prepare()==dict(error='pf_permission_denied'))
    fixture['policy']('fans',policies['fans'])
    empty_origin=str(uuid.uuid4());fixture['admit_origin'](empty_origin);empty,_=refresh(empty_origin)
    check('B3ci admitted empty origin promotes one signed empty page without inferred members',empty['phase']=='current'
          and current(empty_origin)['facts']==[] and current(empty_origin)['manifest']['fact_count']=='0')
    closed=prepare(str(uuid.uuid4()));serialized=fan_sql('SELECT progress_json FROM wp_fans_pf_b3c_reads WHERE read_id='+lit(closed['read_id']))
    malformed=json.loads(serialized);malformed.update(phase='page',manifest='not-a-manifest');malformed=json.dumps(malformed,sort_keys=True,separators=(',',':'))
    fan_sql('UPDATE wp_fans_pf_b3c_reads SET phase=\'page\',progress_json='+lit(malformed)+',progress_sha256='+lit(hashlib.sha256(malformed.encode()).hexdigest())+' WHERE read_id='+lit(closed['read_id']))
    check('B3ci malformed local checkpoint fails closed without a type error',inbox('prepare',closed['origin_id'],read_id=closed['read_id'])==dict(error='pf_local_corpus_conflict'))
    check('B3ci inbox adds no Fan ledger and preserves all historical claims',len(fan_sql("SHOW TABLES LIKE 'wp_fans_pf_b3c_%'").splitlines())==5
          and fan_sql("SHOW TABLES LIKE 'wp_fans%ledger%'")=='' and sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')==historical)
