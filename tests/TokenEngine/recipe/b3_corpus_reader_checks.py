"""Bounded real WordPress HTTP orchestration of the durable private corpus inbox."""
import concurrent.futures
import copy
import json
import secrets
import socket
import uuid


def run_checks(root, source, cli_path, check, call, sql, command, fixture):
    if fixture is None: raise RuntimeError('Closed inbox fixture required.')
    fans=fixture['fans'];origin=fixture['origin'];fan_sql=fixture['fan_sql'];inbox=fixture['inbox']
    recipe=source/'tests/TokenEngine/recipe/b3-corpus-inbox-worker.php'
    def reader(read, steps=4, selected=origin, **extra):
        fixture['set_clock']()
        value=dict(action='advance',origin=selected,read_id=read,steps=steps,**extra)
        path=root/(uuid.uuid4().hex+'.json');path.write_text(json.dumps(value));path.chmod(0o600)
        return json.loads(command(['php',cli_path,'--allow-root','--path='+str(fans),'eval-file',str(recipe),str(path),'--use-include']))
    def lit(value):return "'"+value.replace('\\','\\\\').replace("'","''")+"'"
    def rows(read, table='requests'):return int(fan_sql('SELECT COUNT(*) FROM wp_fans_pf_b3c_'+table+' WHERE read_id='+lit(read)))
    def progress(read, selected=origin):return inbox('prepare',selected,read_id=read)
    def current(selected=origin):return inbox('current',selected)['current']
    def total():return int(fan_sql('SELECT COUNT(*) FROM wp_fans_pf_b3c_reads'))

    historical=sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')
    economic=sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')
    read=str(uuid.uuid4());count=total()
    check('B3cr invalid step budgets do not persist a job',reader(read,0)==dict(error='pf_local_corpus_budget')
          and reader(read,17)==dict(error='pf_local_corpus_budget') and total()==count)
    one=reader(read,1);p=progress(read)
    check('B3cr one bounded step looks up the primary before materialization',one['state']=='pending' and one['completed_steps']=='1'
          and p['phase']=='start' and rows(read)==1 and current() is None)
    stable=p['read_key'];other=reader(str(uuid.uuid4()),1);p=progress(read)
    check('B3cr another caller advances the pending job without replacing its key',other['state']=='pending' and other['read_id']==read
          and p['read_key']==stable and p['phase']=='page' and rows(read,'pages')==1)
    three=reader(read,1);p=progress(read)
    check('B3cr one further step stages the remaining immutable page only',three['state']=='pending' and p['phase']=='finish'
          and rows(read,'pages')==2 and current() is None)
    four=reader(read,1);value=current()
    check('B3cr final bounded step promotes a complete signed generation at its attested instant',four['state']=='verified'
          and four['completed_steps']=='1' and four['verified_at']==value['verified_at'] and value['manifest']['fact_count']=='103'
          and value['manifest']['net_pf']=='1')
    count=rows(read);again=reader(read)
    check('B3cr resuming a completed job performs no further HTTP',again['state']=='verified' and again['completed_steps']=='0' and rows(read)==count)
    monitored=reader(str(uuid.uuid4()),monitor=True)
    if monitored.get('state')!='verified' or len(monitored.get('http_trace',[]))!=4 or any(x!=dict(transaction='0',mutex_free='1',registered='1') for x in monitored.get('http_trace',[])):
        raise RuntimeError('Expurgated HTTP checkpoint diagnostics: '+json.dumps(dict(state=monitored.get('state'),trace=monitored.get('http_trace'))))
    check('B3cr every real HTTP request follows durable registration outside the local transaction and mutex',monitored['state']=='verified'
          and len(monitored['http_trace'])==4 and all(x==dict(transaction='0',mutex_free='1',registered='1') for x in monitored['http_trace']))

    # Four real processes may hold old checkpoints; none may create a second stable job.
    read=str(uuid.uuid4());p=progress(read);count=int(sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3c_corpora'))
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        outcomes=list(pool.map(lambda _:reader(read,4),range(4)))
    check('B3cr concurrent bounded readers return verified or retryable pending without a conflict error',
          all(x.get('state') in ('pending','verified') and x.get('read_id')==read for x in outcomes))
    done=reader(read,4)
    check('B3cr concurrent collection commits one corpus and one exact local page chain',done['state']=='verified'
          and progress(read)['read_key']==p['read_key'] and int(sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3c_corpora'))==count+1
          and rows(read,'pages')==2 and current()['manifest']['net_pf']=='1')

    # Deterministically delay an authenticated absence response until another worker stages page zero.
    read=str(uuid.uuid4());p=progress(read);fields,sealed,_=fixture['request'](p);status,late=fixture['proof_for'](fields,sealed);assert status==200
    staged=reader(read,2)
    check('B3cr late primary absence never rewinds a newer materialized checkpoint',staged['state']=='pending'
          and inbox('accept',proof=late)==dict(error='pf_local_corpus_checkpoint_moved') and progress(read)['phase']=='page')
    check('B3cr late absence recovery resumes the existing immutable generation',reader(read)['state']=='verified' and rows(read,'pages')==2)

    # Lose a genuine materialization response after Hub COMMIT; no second creation may follow.
    read=str(uuid.uuid4());reader(read,1);p=progress(read);count=int(sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3c_corpora'))
    (root/'corpus-http-fault').write_text('drop-after-materialize')
    lost=reader(read,1)
    check('B3cr lost Hub body after COMMIT returns pending under the original key',lost['state']=='pending' and lost['reason']=='pf_transport_unknown'
          and progress(read)['phase']=='lookup' and progress(read)['read_key']==p['read_key'] and current() is None)
    recovered=reader(read)
    check('B3cr primary lookup recovers the committed corpus without another materialization',recovered['state']=='verified'
          and int(sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3c_corpora'))==count+1 and progress(read)['read_key']==p['read_key'])

    selected=str(uuid.uuid4());fixture['admit_origin'](selected);read=str(uuid.uuid4())
    prepared=reader(read,1,selected,fault='ack-after-commit')
    check('B3cr unknown initial local COMMIT remains retryable under the caller durable ID',prepared['state']=='pending'
          and prepared['reason']=='pf_local_corpus_commit_unknown' and prepared['read_id']==read and rows(read,'reads')==1)
    key=progress(read,selected)['read_key'];empty=reader(read,4,selected)
    check('B3cr restart after unknown prepare COMMIT reuses its key and verifies an empty origin',empty['state']=='verified'
          and progress(read,selected)['read_key']==key and current(selected)['facts']==[])

    read=str(uuid.uuid4());reader(read,1);p=progress(read)
    # Select an unused exact-loopback endpoint; connection failure cannot create a new key.
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    endpoint='http://127.0.0.1:'+str(port)+'/index.php?rest_route=/faluss-b3-recipe/v1/corpus'
    offline=reader(read,1,endpoint=endpoint)
    check('B3cr offline loopback returns pending without clearing the durable checkpoint',offline['state']=='pending'
          and offline['reason']=='pf_transport_unknown' and progress(read)['read_key']==p['read_key'] and current() is None)
    check('B3cr network recovery looks up the same key before another start',reader(read)['state']=='verified' and progress(read)['read_key']==p['read_key'])
    read=str(uuid.uuid4());p=progress(read);(root/'corpus-http-fault').write_text('redirect')
    redirected=reader(read,1)
    check('B3cr redirects stay pending without following the location',redirected['state']=='pending'
          and redirected['reason']=='pf_transport_unknown' and not (root/'corpus-redirect-followed').exists()
          and progress(read)['read_key']==p['read_key'])
    check('B3cr a fresh bound request recovers after the refused redirect',reader(read)['state']=='verified')

    read=str(uuid.uuid4());p=progress(read);(root/'corpus-http-fault').write_text('tamper-reply')
    altered=reader(read,1)
    check('B3cr invalid Hub signature is never an accepted or refused generation',altered['state']=='pending'
          and altered['reason']=='pf_transport_unknown' and progress(read)['phase']=='lookup' and current() is None)
    check('B3cr signature recovery retains the original job and exact net',reader(read)['state']=='verified'
          and progress(read)['read_key']==p['read_key'] and current()['manifest']['net_pf']=='1')
    read=str(uuid.uuid4());progress(read);(root/'corpus-http-fault').write_text('server-error')
    failed=reader(read,1)
    check('B3cr HTTP error never promotes a generation',failed['state']=='pending' and failed['reason']=='pf_transport_unknown' and current() is None)
    recovered=reader(read)
    if recovered.get('state')!='verified':
        raise RuntimeError('Expurgated HTTP recovery diagnostics: '+json.dumps({**{k:recovered.get(k) for k in ('state','reason','completed_steps')},
            'phase':progress(read)['phase'],'hub_reason':(root/'corpus-http-diagnostic').read_text() if (root/'corpus-http-diagnostic').exists() else 'none'}))
    check('B3cr HTTP error recovery preserves the pending primary read',recovered['state']=='verified')

    read=str(uuid.uuid4());p=progress(read);policies=fixture['policies'];revoked=copy.deepcopy(policies['fans'])
    revoked['keys']['recipe-hub-k1']['state']='revoked';fixture['policy']('fans',revoked)
    refused=reader(read,1)
    check('B3cr revoked Hub trust never promotes a page and remains unresolved',refused['state']=='pending'
          and refused['reason']=='pf_transport_unknown' and current() is None)
    fixture['policy']('fans',policies['fans'])
    check('B3cr explicit trust restoration resumes the same durable read',reader(read)['state']=='verified' and progress(read)['read_key']==p['read_key'])

    read=str(uuid.uuid4());reader(read,2);p=progress(read)
    fixture['consume'](dict(fixture['intent'],attribution_id=str(uuid.uuid4())))
    superseded=reader(read,4)
    check('B3cr changed owner facts refuse the entire generation instead of a partial result',superseded['state']=='unavailable'
          and progress(read)['phase']=='refused' and current() is None)
    count=rows(read);terminal=reader(read)
    check('B3cr signed terminal refusal never automatically creates another read key',terminal['state']=='unavailable'
          and terminal['completed_steps']=='0' and rows(read)==count)
    fresh=reader(str(uuid.uuid4()));new=current()
    check('B3cr explicit next job reconstructs the latest corrected complete owner corpus',fresh['state']=='verified'
          and new['manifest']['fact_count']=='104' and new['manifest']['net_pf']=='2')
    count=rows(read);older=reader(read)
    check('B3cr resuming an old refused job cannot mask a new complete generation',older['state']=='unavailable'
          and rows(read)==count and current()['manifest']['net_pf']=='2')
    check('B3cr reader keeps historical claims and adds no Fans economic ledger',sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')==historical
          and set(economic.splitlines()).issubset(set(sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id').splitlines()))
          and fan_sql("SHOW TABLES LIKE 'wp_fans%ledger%'")=='')
