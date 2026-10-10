"""Explicit primary freshness of one immutable private generation; fictitious enclave only."""
import concurrent.futures
import copy
import json
import secrets
import socket
import time
import uuid


def run_checks(root,source,cli_path,check,command,fixture,reader,hub,hub_sql):
    origin=fixture['origin'];sql=fixture['fan_sql'];inbox=fixture['inbox']
    def lit(value):return "'"+value.replace('\\','\\\\').replace("'","''")+"'"
    def invoke(action,**extra):
        fixture['set_clock']();path=root/(uuid.uuid4().hex+'.json')
        path.write_text(json.dumps(dict(action=action,origin=origin,**extra)));path.chmod(0o600)
        return json.loads(command(['php',cli_path,'--allow-root','--path='+str(fixture['fans']),'eval-file',
            str(source/'tests/TokenEngine/recipe/b3-corpus-inbox-worker.php'),str(path),'--use-include']))
    def current():return inbox('current')['current']
    def progress(read):return inbox('prepare',read_id=read)
    def latest():return sql('SELECT read_id FROM wp_fans_pf_b3c_current WHERE origin_id='+lit(origin))
    # MariaDB batch mode escapes backslashes in the nested signed wire; HEX preserves its exact bytes.
    def stored_proof():return json.loads(bytes.fromhex(sql('SELECT HEX(proof_json) FROM wp_fans_pf_b3c_current WHERE origin_id='+lit(origin))).decode())
    def counts():return (sql('SELECT COUNT(*) FROM wp_fans_pf_b3c_reads'),hub_sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3c_corpora'))
    def complete(read):
        result=invoke('advance',read_id=read,steps=4)
        for delay in (1,2):
            if result.get('state')!='pending' or result.get('reason')!='pf_transport_unknown':break
            before=progress(read);time.sleep(delay);result=invoke('advance',read_id=read,steps=4)
            assert result['read_id']==read and progress(read)['read_key']==before['read_key']
        return result
    def renew(proof,**changes):
        now=int(time.time());values=dict(issued_at=time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime(now)),
            expires_at=time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime(now+60)),**changes)
        wire=fixture['worker']('hub',dict(action='alter-response',wire=proof['wire'],response=values))['wire']
        return dict(proof,wire=wire)

    read=latest();key=progress(read)['read_key'];old=stored_proof();historical=hub_sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')
    economic=hub_sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id');initial=counts();old_at=current()['verified_at']
    check('B6pr invalid refresh budgets do not modify the current primary checkpoint',
          invoke('refresh',read_id=read,steps=0)==dict(error='pf_local_corpus_budget')
          and invoke('refresh',read_id=read,steps=17)==dict(error='pf_local_corpus_budget') and counts()==initial
          and current()['verified_at']==old_at)
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        prepared=list(pool.map(lambda _:invoke('prepare-refresh',read_id=read),range(4)))
    check('B6pr concurrent refresh preparation retains one immutable read and key',
          all(x['phase']=='finish' and x['read_id']==read and x['read_key']==key
              and x['fence_request_sha256']=='pending' for x in prepared) and counts()==initial)
    check('B6pr refreshing closes both corpus and derived cache before HTTP',current() is None
          and invoke('cache-read')==dict(error='hof_projection_source_unavailable'))
    check('B6pr an authenticated earlier success cannot satisfy a new refresh challenge',
          inbox('accept',proof=renew(old))==dict(error='pf_local_corpus_checkpoint_moved') and current() is None)
    check('B6pr an authenticated earlier refusal cannot invalidate or settle a new challenge',
          inbox('accept',proof=renew(old,outcome='refused',result=dict(reason='pf_corpus_superseded')))
          ==dict(error='pf_local_corpus_checkpoint_moved') and progress(read)['phase']=='finish')
    fields=old['fields'];sealed=fixture['sign'](fields,key,request=dict(nonce=old['nonce']),context=dict(nonce=old['nonce']))
    sealed['nonce']=old['nonce']
    check('B6pr an already registered nonce cannot become the fresh challenge',
          inbox('request',fields=fields,sealed=sealed)==dict(error='pf_local_corpus_checkpoint_moved')
          and progress(read)['fence_request_sha256']=='pending')
    p=progress(read);fields,sealed,_=fixture['request'](p);status,fresh=fixture['proof_for'](fields,sealed);assert status==200
    check('B6pr even after a new request an older valid finish remains unusable',
          inbox('accept',proof=renew(old))==dict(error='pf_local_corpus_checkpoint_moved') and current() is None)
    accepted=inbox('accept',proof=fresh)
    check('B6pr the new primary acknowledgement promotes exactly the same complete generation',
          accepted['phase']=='current' and accepted['read_id']==read and accepted['read_key']==key
          and current()['manifest']==p['manifest'] and counts()==initial and current()['verified_at']!=old_at)
    check('B6pr a changed attested instant requires rebuilding the derived cache',
          invoke('cache-read')==dict(error='hof_projection_not_reconciled')
          and invoke('cache-rebuild')['state']=='reconciled' and invoke('cache-read')['state']=='reconciled')
    check('B6pr duplicate current acknowledgement is inert and never allocates another key',
          inbox('accept',proof=fresh)==accepted and counts()==initial)

    # The first real COMMIT is the durable refresh preparation, not a simulated HTTP result.
    unknown=invoke('refresh',read_id=read,fault='ack-after-commit')
    check('B6pr uncertain preparation COMMIT persists the same challenge and closes reads',
          unknown.get('state')=='pending' and unknown.get('reason')=='pf_local_corpus_commit_unknown'
          and progress(read)['phase']=='finish' and progress(read)['read_key']==key and current() is None)
    check('B6pr restart after uncertain preparation finishes without another corpus',
          complete(read)['state']=='verified' and counts()==initial and progress(read)['read_key']==key)
    invoke('prepare-refresh',read_id=read);fields,sealed,_=fixture['request'](progress(read))
    status,fresh=fixture['proof_for'](fields,sealed);assert status==200
    check('B6pr uncertain final local COMMIT remains unknown after atomic primary promotion',
          inbox('accept',proof=fresh,fault='ack-after-commit')==dict(error='pf_local_corpus_commit_unknown')
          and progress(read)['phase']=='current' and current() is not None)
    check('B6pr lookup of locally completed refresh performs no replacement materialization',
          complete(read)['state']=='verified' and counts()==initial and progress(read)['read_key']==key)

    (root/'corpus-http-fault').write_text('drop-after-materialize')
    lost=invoke('refresh',read_id=read,steps=1)
    check('B6pr lost genuine finish response keeps unavailable reads and the original key',
          lost.get('state')=='pending' and lost.get('reason')=='pf_transport_unknown'
          and current() is None and progress(read)['read_key']==key and counts()==initial)
    check('B6pr retry of the existing read-only primary finish recovers without another debit',
          complete(read)['state']=='verified' and counts()==initial
          and hub_sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')==economic)
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    endpoint='http://127.0.0.1:'+str(port)+'/index.php?rest_route=/faluss-b3-recipe/v1/corpus'
    offline=invoke('refresh',read_id=read,steps=1,endpoint=endpoint)
    check('B6pr unavailable primary never leaves the earlier cache readable',
          offline.get('state')=='pending' and offline.get('reason')=='pf_transport_unknown'
          and current() is None and invoke('cache-read')==dict(error='hof_projection_source_unavailable'))
    check('B6pr network recovery preserves the immutable identity',complete(read)['state']=='verified'
          and counts()==initial and progress(read)['read_key']==key)
    before=counts();invoke('prepare-refresh',read_id=read)
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        replies=list(pool.map(lambda _:invoke('advance',read_id=read,steps=1),range(4)))
    check('B6pr concurrent replies are verified or retryable under the same durable identity',
          all(x.get('state') in ('verified','pending') and x.get('read_id')==read for x in replies)
          and complete(read)['state']=='verified' and counts()==before and progress(read)['read_key']==key)
    revoked=copy.deepcopy(fixture['policies']['fans']);revoked['keys']['recipe-hub-k1']['state']='revoked'
    fixture['policy']('fans',revoked)
    rejected=invoke('refresh',read_id=read)
    check('B6pr revoked current trust cannot deliver a primary-refreshed generation','error' in rejected)
    fixture['policy']('fans',fixture['policies']['fans'])
    check('B6pr explicit trust restoration permits the same generation to be rechecked',
          invoke('refresh',read_id=read)['state']=='verified' and progress(read)['read_key']==key)

    # A new owner fact makes the immutable generation stale: refusal, never partial ranking.
    pack=dict(fixture['proof'],purchase_id='synthetic.'+uuid.uuid4().hex,evidence_id=str(uuid.uuid4()),
              member_faluss_id=str(uuid.uuid4()),purchased_pf='4')
    lot=hub('h1-evidence',payload=pack,key=secrets.token_hex(32))['lot_id']
    assert hub('h2-admit',lot_id=lot,member=pack['member_faluss_id'],key=secrets.token_hex(32))['state']=='admitted'
    intent=copy.deepcopy(fixture['intent']);intent.update(attribution_id=str(uuid.uuid4()),member_faluss_id=pack['member_faluss_id'],purchased_pf='4')
    fixture['consume'](intent);economic=hub_sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')
    refusal=invoke('refresh',read_id=read);after=counts()
    check('B6pr a new confirmed attribution refuses the entire earlier generation',refusal['state']=='unavailable'
          and progress(read)['phase']=='refused' and current() is None and after==initial)
    check('B6pr terminal refusal never silently replaces the key or restores earlier points',
          invoke('refresh',read_id=read)['state']=='unavailable' and counts()==after
          and invoke('cache-read')==dict(error='hof_projection_source_unavailable'))
    assert reader(str(uuid.uuid4()))['state']=='verified';new_read=latest();invoke('cache-rebuild')
    check('B6pr an earlier read cannot replace the new current generation',
          invoke('refresh',read_id=read)['state']=='unavailable' and latest()==new_read
          and invoke('cache-read')['document']['general']['fans']!=[])
    correction=dict(pack,source_revision='2',evidence_id=str(uuid.uuid4()),state='partially_cancelled',cancelled_purchased_pf_cumulative='2')
    plan=hub('h4-begin',payload=correction,key=secrets.token_hex(32));before=counts()
    incomplete=invoke('refresh',read_id=new_read)
    check('B6pr an incomplete correction closes delivery rather than attesting an old score',
          incomplete['state']=='unavailable' and current() is None and counts()==before)
    for fragment in range(int(plan['next_fragment'])+1,int(plan['fragment_count'])+1):
        hub('h4-resume',member=pack['member_faluss_id'],plan_id=plan['plan_id'],fragment=str(fragment))
    assert reader(str(uuid.uuid4()))['state']=='verified';invoke('cache-rebuild');corrected=invoke('cache-read')['document']
    check('B6pr explicit reconstruction after correction contains only the latest net facts',
          sum(int(x['points']) for x in corrected['general']['fans'])==4
          and sum(int(x['points']) for x in corrected['general']['creators'])==4)
    new_read=latest()
    # This successful historical read predates the refused H4 read; it must not mask the newer current one.
    previous=sql("SELECT read_id FROM wp_fans_pf_b3c_reads WHERE origin_id="+lit(origin)+" AND phase='current' AND read_id<>"+lit(new_read)+" ORDER BY read_id LIMIT 1")
    check('B6pr refreshing a completed historical read cannot resurrect its former generation',
          previous!='' and invoke('refresh',read_id=previous)['state']=='pending'
          and latest()==new_read and current() is not None)
    check('B6pr freshness reads preserve historical claims and introduce no Fans ledger',
          hub_sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')==historical
          and sql("SHOW TABLES LIKE 'wp_fans%ledger%'")=='')
