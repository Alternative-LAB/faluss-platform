"""Signed global reads over private loopback: real WP/MariaDB, fictitious peers only."""
import base64
import concurrent.futures
import copy
import datetime
import hashlib
import json
import os
import pathlib
import secrets
import shutil
import socket
import subprocess
import time
import urllib.error
import urllib.request
import uuid


def run_checks(root, wp, source, cli_path, check, call, sql, command, workers, log, start, finish, await_file):
    fans=root/'fans'
    if fans.exists(): raise RuntimeError('Corpus HTTP requires its own fresh Fans instance.')
    shutil.copytree(wp,fans)
    with socket.socket() as listener:
        listener.bind(('127.0.0.1',0)); hub_port=listener.getsockname()[1]
    origin_http='http://127.0.0.1:'+str(hub_port)
    route='/index.php?rest_route=/faluss-b3-recipe/v1/corpus'
    endpoint=origin_http+route
    (root/'corpus-endpoint').write_text(endpoint)
    (wp/'wp-config.php').write_text((wp/'wp-config.php').read_text().replace('http://127.0.0.1:9',origin_http))
    values=dict(DB_NAME='fans_pf_recipe',DB_USER='root',DB_PASSWORD='',DB_HOST='localhost:'+str(root/'sql.sock'),
                DB_CHARSET='utf8mb4',DB_COLLATE='',WP_HOME='http://127.0.0.1:9',WP_SITEURL='http://127.0.0.1:9',
                WP_ENVIRONMENT_TYPE='local',WP_HTTP_BLOCK_EXTERNAL=True,DISABLE_WP_CRON=True,WP_DEBUG=True,
                WP_DEBUG_DISPLAY=False,WP_DEBUG_LOG=str(root/'fans-debug.log'),FALUSS_PLATFORM_ROLE='fans',
                FALUSS_PF_H3_RECIPE_ONLY=True,FALUSS_PF_H3_LEASE_SHA256=hashlib.sha256((root/'h3-lease').read_bytes()).hexdigest(),
                FALUSS_FEDERATION_PRIVATE_SEED=base64.urlsafe_b64encode(secrets.token_bytes(32)).decode().rstrip('='))
    for key in ('AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'):
        values[key]=secrets.token_urlsafe(48)
    config='<?php\n'+''.join('define('+json.dumps(k)+','+json.dumps(v)+');\n' for k,v in values.items())
    config+="$table_prefix='wp_';\nif(!defined('ABSPATH')){define('ABSPATH',__DIR__.'/');}\nrequire_once ABSPATH.'wp-settings.php';\n"
    (fans/'wp-config.php').write_text(config); (fans/'wp-config.php').chmod(0o600)
    sql('CREATE DATABASE fans_pf_recipe')

    def cli(path,*args):return command(['php',cli_path,'--allow-root','--path='+str(path),*args])
    def fan_sql(query):
        # Large private pages must not pass through argv (Linux has a per-argument size limit).
        result=subprocess.run(['mariadb','--no-defaults','--socket='+str(root/'sql.sock'),'-uroot','--batch','--skip-column-names','fans_pf_recipe'],
                              input=query,text=True,capture_output=True,timeout=90)
        if result.returncode:raise RuntimeError('Disposable Fans SQL failed; no private query printed.')
        return result.stdout.strip()
    def worker(role,value):
        path=root/(uuid.uuid4().hex+'.json'); path.write_text(json.dumps(value)); path.chmod(0o600)
        result=json.loads(cli(wp if role=='hub' else fans,'eval-file',str(source/'tests/TokenEngine/recipe/b3-corpus-network-worker.php'),str(path),'--use-include'))
        if value['action']=='exchange' and 'error' in result:
            metrics=path.with_name(path.name+'.http-metrics')
            (root/'corpus-client-diagnostic').write_text(json.dumps(dict(error=result['error'],
                transport=json.loads(metrics.read_text()) if metrics.exists() else [])))
        return result
    cli(fans,'core','install','--url=http://127.0.0.1:9','--title=Disposable corpus Fans','--admin_user=fixture',
        '--admin_password='+secrets.token_urlsafe(32),'--admin_email=fixture@example.invalid','--skip-email')
    cli(fans,'plugin','activate','faluss-platform')
    check('B3ch normal Fans bootstrap installs no corpus inbox or owner metadata',fan_sql("SHOW TABLES LIKE 'wp_fans_pf_b3%' ")=='')
    hub_public=worker('hub',dict(action='public'))['public_key']; fan_public=worker('fans',dict(action='public'))['public_key']
    check('B3ch distinct WordPress databases and distinct node signing keys',hub_public!=fan_public and fan_sql('SELECT DATABASE()')=='fans_pf_recipe')
    now=int(time.time()); utc=lambda t:time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime(t))
    def key(public):return dict(public_key=public,state='active',**{'from':utc(now-3600),'until':utc(now+7200)})
    policies=dict(hub=dict(node='fixture.fans',audience='fixture.hub',permissions=['pf.ranking.corpus'],keys={'recipe-fans-k1':key(fan_public)}),
                  fans=dict(node='fixture.hub',audience='fixture.fans',permissions=['pf.ranking.corpus'],keys={'recipe-hub-k1':key(hub_public)}))
    def policy(role,value):
        path=root/(role+'-corpus-trust.json'); path.write_text(json.dumps(value)); path.chmod(0o600)
    for role in policies:policy(role,policies[role])
    check('B3ch normal bootstrap never installs nonce metadata',worker('hub',dict(action='ready'))==dict(ready=False))
    sql('CREATE TABLE wp_token_engine_pf_b3ch_nonces (id INT NOT NULL) ENGINE=InnoDB')
    check('B3ch partial nonce schema is refused without adopting it',worker('hub',dict(action='install'))==dict(error='model_schema_divergent'))
    sql('DROP TABLE wp_token_engine_pf_b3ch_nonces')
    check('B3ch explicit physical installation publishes verified InnoDB metadata',worker('hub',dict(action='install'))==dict(ready=True)
          and worker('hub',dict(action='install'))==dict(ready=True))
    mu=wp/'wp-content/mu-plugins';mu.mkdir(exist_ok=True)
    shutil.copy2(source/'tests/TokenEngine/recipe/b3-corpus-http-adapter.php',mu/'b3-corpus-recipe.php')
    server=subprocess.Popen(['php','-d','opcache.enable=0','-d','opcache.enable_cli=0','-S','127.0.0.1:'+str(hub_port),'-t',str(wp)],
                            env=dict(os.environ,PHP_CLI_SERVER_WORKERS='4'),stdout=log,stderr=log,start_new_session=True)
    workers.append(server)
    for _ in range(80):
        try:urllib.request.urlopen(origin_http+'/index.php',timeout=2).close();break
        except urllib.error.HTTPError:break
        except (urllib.error.URLError,TimeoutError):
            if server.poll() is not None:raise RuntimeError('Closed corpus HTTP server failed.')
            time.sleep(.1)
    else:raise RuntimeError('Closed corpus HTTP server unavailable.')

    def clock():
        last=sql('SELECT last_confirmed_at FROM wp_token_engine_pf_b3r_counter')
        latest=datetime.datetime.fromisoformat(last).replace(tzinfo=datetime.timezone.utc) if last else datetime.datetime.now(datetime.timezone.utc)
        return str(max(int(sql('SELECT UNIX_TIMESTAMP()')),int(latest.timestamp()))+7)+'.000000'
    def set_clock():
        path=root/'corpus-sql-clock';path.write_text(clock());path.chmod(0o600)
    def http(body=None,method='POST',headers=None):
        extra={'Content-Type':'application/json','Accept':'application/json'};extra.update(headers or {})
        request=urllib.request.Request(endpoint,data=body.encode() if body is not None else None,headers=extra,method=method)
        try:response=urllib.request.urlopen(request,timeout=25)
        except urllib.error.HTTPError as error:response=error
        with response:return response.status,response.read().decode(),dict(response.headers)
    def fields(origin,op='start',page=None):return dict(operation=op,read_id=str(uuid.uuid4()),origin_id=origin,policy_version='1.0.0',
        corpus_id=page['manifest']['corpus_id'] if page else '',cursor=page['next_cursor'] if op=='page' and page else '')
    def sign(f,key,**extra):return worker('fans',dict(action='sign',fields=f,key=key,**extra))
    def exchange(f,key,**extra):
        set_clock()
        for name in ('corpus-client-diagnostic','corpus-http-diagnostic'):(root/name).unlink(missing_ok=True)
        return worker('fans',dict(action='exchange',fields=f,key=key,**extra))
    def accept(f,request,wire,**extra):
        value=dict(action='accept',fields=f,wire=wire,nonce=request['nonce'],digest=hashlib.sha256(request['wire'].encode()).hexdigest())
        value.update(extra);return worker('fans',value)
    def nonce_count():return int(sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3ch_nonces'))
    def generation_count():return int(sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3c_corpora'))
    def admit_origin(origin):
        now_value=datetime.datetime.now(datetime.timezone.utc)
        descriptor=dict(content=dict(kind='origin',origin_id=origin,object_id=origin,subject_id='',version='1',policy_version='1.0.0'),
                        valid_from=(now_value-datetime.timedelta(days=1)).strftime('%Y-%m-%d %H:%M:%S.%f'),
                        valid_until=(now_value+datetime.timedelta(days=2)).strftime('%Y-%m-%d %H:%M:%S.%f'))
        assert call('b3b-register',payload=descriptor,key=secrets.token_hex(32))['state']=='active'
    empty_origin=str(uuid.uuid4());f=fields(empty_origin);read_key=secrets.token_hex(32)
    check('B3ch unadmitted origin fails closed even with an authenticated owner request',
          exchange(f,read_key)==dict(outcome='refused',result=dict(reason='pf_corpus_origin_not_admitted')))
    admit_origin(empty_origin);set_clock()
    request=sign(f,read_key);status,wire,headers=http(request['wire']);empty=accept(f,request,wire)
    if status!=200 or empty.get('outcome')!='ok':
        path=root/'corpus-http-diagnostic'
        reason=path.read_text() if path.exists() else empty.get('error',empty.get('result',{}).get('reason','unknown'))
        raise RuntimeError('Closed corpus first read failed: status '+str(status)+'; reason '+reason)
    check('B3ch signed empty owner origin has an exact empty immutable page',status==200 and empty['outcome']=='ok' and empty['result']['page']['facts']==[])
    check('B3ch private response forbids browser and CDN cache','private' in headers.get('Cache-Control','') and 'no-store' in headers.get('Cache-Control','') and headers.get('CDN-Cache-Control')=='no-store')
    check('B3ch GET cannot mutate or read a corpus',http(method='GET')[0]==404)
    check('B3ch altered Host cannot open the fixture route',http(request['wire'],headers={'Host':'example.invalid'})[0]==404)
    check('B3ch byte identical request replay is refused before dispatch',http(request['wire'])[0]==403)
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        once=sign(fields(empty_origin,'lookup'),read_key);statuses=list(pool.map(lambda _:http(once['wire'])[0],range(4)))
    check('B3ch four concurrent uses of one nonce admit exactly one',statuses.count(200)==1 and statuses.count(403)==3)
    count=nonce_count();corpora=generation_count()
    def bad_signature(value):
        outer=json.loads(value);sig=bytearray(base64.urlsafe_b64decode(outer['signature_base64url']+'==='));sig[0]^=1
        outer['signature_base64url']=base64.urlsafe_b64encode(sig).decode().rstrip('=');return json.dumps(outer,sort_keys=True,separators=(',',':'))
    check('B3ch altered outer signature never admits a nonce',http(bad_signature(sign(f,read_key)['wire']))[0]==403 and nonce_count()==count)
    changes=[('request audience',dict(request=dict(audience='fixture.other'))),('context audience',dict(context=dict(audience='fixture.other'))),
             ('unknown request key',dict(request_key='unknown-fans-key')),('unknown context key',dict(context_key='unknown-fans-key')),
             ('context signature',dict(bad_context_signature=True)),('economic operation',dict(request=dict(operation='confirm'))),
             ('member supplied',dict(request=dict(member_faluss_id=str(uuid.uuid4())))),('origin context',dict(context=dict(origin_id=str(uuid.uuid4())))),
             ('bound key',dict(context=dict(key_sha256=secrets.token_hex(32)))),('wrong domain',dict(domain='faluss.hub-purchased-pf.request/1'))]
    for name,extra in changes:
        check('B3ch rejects '+name,http(sign(f,read_key,**extra)['wire'])[0]==403 and nonce_count()==count)
    for name,times in [('expired',dict(issued_at=utc(now-120),expires_at=utc(now-60))),('future',dict(issued_at=utc(now+3600),expires_at=utc(now+3660)))]:
        check('B3ch rejects '+name+' context',http(sign(f,read_key,request=times,context=times)['wire'])[0]==403 and nonce_count()==count)
    for name,alter in [('revoked key',dict(keys={'recipe-fans-k1':dict(key(fan_public),state='revoked')})),
                       ('wrong trusted key',dict(keys={'recipe-fans-k1':key(hub_public)})),('other pair',dict(node='fixture.other')),
                       ('historical permissions',dict(permissions=['wallet.read','pf.snapshot','pf.context.delegate']))]:
        policy('hub',dict(policies['hub'],**alter))
        check('B3ch rejects '+name,http(sign(f,read_key)['wire'])[0]==403 and nonce_count()==count)
    policy('hub',policies['hub'])
    check('B3ch refusal tests never create a generation or economic fact',generation_count()==corpora)
    check('B3ch response nonce and digest are mandatory',accept(f,request,wire,nonce=secrets.token_hex(32))==dict(error='pf_transport_unknown')
          and accept(f,request,wire,digest=secrets.token_hex(32))==dict(error='pf_transport_unknown'))
    check('B3ch altered response signature fails closed',accept(f,request,bad_signature(wire))==dict(error='pf_transport_unknown'))
    for name,extra in [('other audience',dict(response=dict(audience='fixture.other'))),('expired',dict(response=dict(issued_at=utc(now-120),expires_at=utc(now-60)))),
                       ('other origin',dict(response=dict(origin_id=str(uuid.uuid4())))),('unknown Hub key',dict(response_key='unknown-hub-key'))]:
        changed=worker('hub',dict(action='alter-response',wire=wire,**extra))
        check('B3ch refuses '+name+' response',accept(f,request,changed['wire'])==dict(error='pf_transport_unknown'))
    policy('fans',dict(policies['fans'],keys={'recipe-hub-k1':dict(key(hub_public),state='revoked')}))
    check('B3ch revoked Hub key never admits a private page',accept(f,request,wire)==dict(error='pf_transport_unknown'))
    policy('fans',dict(policies['fans'],keys={'recipe-hub-k1':key(fan_public)}))
    check('B3ch wrong trusted Hub public key never admits a private page',accept(f,request,wire)==dict(error='pf_transport_unknown'))
    policy('fans',dict(policies['fans'],permissions=['pf.snapshot','pf.context.delegate']))
    count=nonce_count()
    check('B3ch Fans global reader also requires its dedicated permission before HTTP',
          exchange(f,read_key)==dict(error='pf_permission_denied') and nonce_count()==count)
    policy('fans',policies['fans'])
    for name,bad in [('external','https://example.invalid/index.php?rest_route=/faluss-b3-recipe/v1/corpus'),('route',origin_http+'/wp-json/other'),
                     ('hostname','http://localhost:'+str(hub_port)+route),('port','http://127.0.0.1:99999'+route),('recipient',endpoint+'&recipient=forged')]:
        check('B3ch nonexact '+name+' endpoint is refused without HTTP',exchange(f,read_key,endpoint=bad)==dict(error='pf_fixture_peer_required'))
    (root/'corpus-http-fault').write_text('redirect')
    count=nonce_count()
    check('B3ch client refuses redirects without following even another fixture path',
          exchange(dict(f,operation='lookup'),read_key)==dict(error='pf_transport_unknown')
          and not (root/'corpus-redirect-followed').exists() and nonce_count()==count+1)
    check('B3ch missing primary key lookup returns absent without generation',exchange(fields(empty_origin,'lookup'),secrets.token_hex(32))==dict(outcome='ok',result=dict(state='absent')))
    lost_origin=str(uuid.uuid4());admit_origin(lost_origin);lost_fields=fields(lost_origin);lost_key=secrets.token_hex(32);count=generation_count()
    (root/'corpus-http-fault').write_text('drop-after-materialize')
    lost=exchange(lost_fields,lost_key)
    check('B3ch response lost after actual COMMIT is unknown while one corpus persists',lost==dict(error='pf_transport_unknown') and generation_count()==count+1)
    recovered=exchange(dict(lost_fields,operation='lookup'),lost_key)
    replay=exchange(lost_fields,lost_key)
    check('B3ch fresh primary lookup and same stable key recover the committed exact page',recovered==replay and recovered['outcome']=='ok' and generation_count()==count+1)
    sql('ALTER TABLE wp_token_engine_pf_b3ch_nonces ENGINE=MyISAM')
    check('B3ch nontransactional admission refuses HTTP without repairing schema',http(sign(f,read_key)['wire'])[0]==403
          and worker('hub',dict(action='install'))==dict(error='model_schema_divergent'))
    sql('ALTER TABLE wp_token_engine_pf_b3ch_nonces ENGINE=InnoDB')
    # Exercise freshness after the actual owner mutex wait, not only at parsing time.
    marker=root/uuid.uuid4().hex
    held=start(dict(action='b3o-read',origin=empty_origin,clock_value=clock(),fault='b3o-hold-global',marker=str(marker)))
    await_file(marker,[held]);set_clock();t=int(time.time());times=dict(issued_at=utc(t),expires_at=utc(t+2));short=sign(f,read_key,request=times,context=times);count=nonce_count()
    with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
        pending=pool.submit(http,short['wire']);time.sleep(3);pathlib.Path(str(marker)+'.release').touch();answer=pending.result()
    finish(held)
    check('B3ch context expiring behind the owner mutex never admits its nonce',answer[0]==403 and nonce_count()==count)
    # New origin with genuine ranked consumptions; primary time is accelerated only inside the fixture.
    proof=dict(contract='hub.purchased-pf.h1-model/1.0.0',authority_id='fixture.purchase',purchase_id='synthetic.'+uuid.uuid4().hex,source_revision='1',
               evidence_id=str(uuid.uuid4()),member_faluss_id=str(uuid.uuid4()),purchased_pf='120',bonus_pf='0',cancelled_purchased_pf_cumulative='0',
               state='confirmed',confirmed_at='2026-10-01T10:00:00Z',observed_at='2026-10-05T10:00:00Z',policy_version='1.0.0')
    lot=call('h1-evidence',payload=proof,key=secrets.token_hex(32))['lot_id'];assert call('h2-admit',lot_id=lot,member=proof['member_faluss_id'],key=secrets.token_hex(32))['state']=='admitted'
    now_dt=datetime.datetime.now(datetime.timezone.utc);instant=lambda t:t.strftime('%Y-%m-%d %H:%M:%S.%f');origin=str(uuid.uuid4());creator=str(uuid.uuid4())
    selected=dict(origin_id=origin,policy_version='1.0.0',creator_category='arts',category_revision='1',country_policy_revision=str(uuid.uuid4()),
                  sessions=[dict(session_id=str(uuid.uuid4()),rules_revision='1',rules_sha256='a'*64,admission_revision='1',barrier_version='1',
                    starts_at=instant(now_dt-datetime.timedelta(days=1)),ends_at=instant(now_dt+datetime.timedelta(days=2)),admitted_at=instant(now_dt-datetime.timedelta(seconds=5)),
                    scope='international',territory_policy_revision='',territory_admission_revision='0',country='',territory_ref='')])
    intent=dict(attribution_id=str(uuid.uuid4()),member_faluss_id=proof['member_faluss_id'],creator_faluss_id=creator,client_authority='fixture.fans',purchased_pf='1',
                policy_version='1.0.0',ranking_context=selected,context_sha256=hashlib.sha256(json.dumps(selected,sort_keys=True,separators=(',',':')).encode()).hexdigest())
    for ref in call('b3b-refs',payload=intent)['references']:
        barrier=dict(content=ref['content'],valid_from=selected['sessions'][0]['starts_at'],valid_until=selected['sessions'][0]['ends_at'])
        if ref['content']['kind']=='admission':barrier['valid_from']=max(ref['content']['starts_at'],ref['content']['admitted_at'])
        assert call('b3b-register',payload=barrier,key=secrets.token_hex(32))['state']=='active'
    def consume(value):
        now_value=clock();assert call('b3r-reserve',payload=value,key=secrets.token_hex(32),clock_value=now_value)['state']=='reserved'
        assert call('b3r-confirm',payload=value,key=secrets.token_hex(32),clock_value=now_value)['state']=='confirmed'
    for _ in range(101):consume(dict(intent,attribution_id=str(uuid.uuid4())))
    ledger=sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id');old_claims=sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')
    def positive_page(f,key):
        value=exchange(f,key)
        if value.get('outcome')!='ok':
            raise RuntimeError('Expurgated positive corpus diagnostics: '+json.dumps(dict(
                operation=f['operation'],error=value.get('error','none'),outcome=value.get('outcome','none'),
                hub_reason=(root/'corpus-http-diagnostic').read_text() if (root/'corpus-http-diagnostic').exists() else 'none',
                client=json.loads((root/'corpus-client-diagnostic').read_text()) if (root/'corpus-client-diagnostic').exists() else {},
                phase=json.loads((root/'corpus-http-phase').read_text()) if (root/'corpus-http-phase').exists() else {})))
        return value['result']['page']
    f=fields(origin);key_value=secrets.token_hex(32);first=positive_page(f,key_value);second=positive_page(fields(origin,'page',first),key_value)
    check('B3ch signed immutable pagination delivers 101 genuine facts without duplication',len(first['facts'])==100 and len(second['facts'])==1
          and first['manifest']==second['manifest'] and second['next_cursor'] is None and len({x['attribution_id'] for x in first['facts']+second['facts']})==101)
    check('B3ch final signed primary fence attests the complete current manifest',exchange(fields(origin,'finish',first),key_value)['result']['state']=='current')
    wrong=dict(fields(origin,'page',first),cursor=str(uuid.uuid4()));refusal=exchange(wrong,key_value)
    check('B3ch wrong generation cursor returns signed refusal not fabricated facts',refusal==dict(outcome='refused',result=dict(reason='pf_corpus_page_unavailable')))
    foreign=dict(fields(str(uuid.uuid4()),'page',first));refusal=exchange(foreign,key_value)
    check('B3ch another origin cannot read this private corpus',refusal==dict(outcome='refused',result=dict(reason='pf_corpus_unavailable')))
    consume(dict(intent,attribution_id=str(uuid.uuid4())))
    obsolete=exchange(fields(origin,'finish',first),key_value)
    if obsolete!=dict(outcome='refused',result=dict(reason='pf_corpus_superseded')):
        client=root/'corpus-client-diagnostic';diagnostic=root/'corpus-http-diagnostic'
        raise RuntimeError('Expurgated obsolete fence diagnostics: '+json.dumps(dict(
            client=json.loads(client.read_text()) if client.exists() else {},hub_reason=diagnostic.read_text() if diagnostic.exists() else 'none')))
    check('B3ch new confirmed consumption makes the older final fence unavailable',True)
    next_generation=exchange(fields(origin),secrets.token_hex(32))
    if next_generation.get('outcome')!='ok':
        diagnostic=root/'corpus-http-diagnostic'
        raise RuntimeError('Expurgated corpus refresh diagnostics: '+json.dumps(dict(
            error=next_generation.get('error','none'),outcome=next_generation.get('outcome','none'),
            hub_reason=diagnostic.read_text() if diagnostic.exists() else 'none',
            client=json.loads((root/'corpus-client-diagnostic').read_text()) if (root/'corpus-client-diagnostic').exists() else {})))
    fresh=next_generation['result']['page'];assert fresh['manifest']['fact_count']=='102'
    updated=dict(proof,source_revision='2',evidence_id=str(uuid.uuid4()),state='partially_cancelled',cancelled_purchased_pf_cumulative='100')
    plan=call('h4-begin',payload=updated,key=secrets.token_hex(32))
    check('B3ch unfinished H4 reconciliation refuses all fresh exact corpus reads',exchange(fields(origin),secrets.token_hex(32))==dict(outcome='refused',result=dict(reason='h4_reconciliation_incomplete')))
    for fragment in range(int(plan['next_fragment'])+1,int(plan['fragment_count'])+1):call('h4-resume',member=proof['member_faluss_id'],plan_id=plan['plan_id'],fragment=str(fragment))
    check('B3ch completed partial correction invalidates an unchanged old generation',exchange(fields(origin,'finish',fresh),key_value)==dict(outcome='refused',result=dict(reason='pf_corpus_superseded')))
    corrected=exchange(fields(origin),secrets.token_hex(32))['result']['page']
    check('B3ch signed corrected corpus preserves cancelled facts and original Hub order',corrected['manifest']['fact_count']=='102' and corrected['manifest']['net_pf']=='20'
          and corrected['manifest']['cancelled_pf']=='82' and corrected['facts'][0]['consumption_order']==first['facts'][0]['consumption_order'])
    check('B3ch old immutable page stays historical and never claims current after correction',exchange(fields(origin,'page',first),key_value)['result']['page']==second)
    check('B3ch global HTTP reads never rewrite official historical claims or ledger rows',set(ledger.splitlines()).issubset(set(sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id').splitlines()))
          and sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')==old_claims)
    check('B3ch recipe adds no browser global read and no Fans PF ledger',fan_sql("SHOW TABLES LIKE 'wp_fans_pf_b3%'")=='')
    # Private closures only for the next disposable SQL recipe, never an artifact or runtime API.
    return dict(fans=fans,fan_sql=fan_sql,cli=cli,worker=worker,fields=fields,sign=sign,http=http,
                policy=policy,policies=policies,set_clock=set_clock,origin=origin,proof=proof,
                consume=consume,intent=intent,admit_origin=admit_origin)
