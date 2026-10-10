"""0.3 gateway over loopback between disposable WordPress nodes, not central SSO."""
import base64
import concurrent.futures
import copy
import hashlib
from http.client import IncompleteRead
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


def run_checks(root,wp,source,cli_path,check,call,sql,command,workers,log,start,finish,await_file,helpers):
    fixture,close_ref=helpers
    fans=root/'fans'
    if fans.exists():raise RuntimeError('Ranked HTTP requires its own fresh Fans instance.')
    shutil.copytree(wp,fans)
    with socket.socket() as listener:
        listener.bind(('127.0.0.1',0));port=listener.getsockname()[1]
    home='http://127.0.0.1:'+str(port); endpoint=home+'/index.php?rest_route=/faluss-b3-recipe/v1/barrier'
    (wp/'wp-config.php').write_text((wp/'wp-config.php').read_text().replace('http://127.0.0.1:9',home))
    values=dict(DB_NAME='fans_pf_recipe',DB_USER='root',DB_PASSWORD='',DB_HOST='localhost:'+str(root/'sql.sock'),
                DB_CHARSET='utf8mb4',DB_COLLATE='',WP_HOME='http://127.0.0.1:9',WP_SITEURL='http://127.0.0.1:9',
                WP_ENVIRONMENT_TYPE='local',WP_HTTP_BLOCK_EXTERNAL=True,DISABLE_WP_CRON=True,WP_DEBUG=True,
                WP_DEBUG_DISPLAY=False,WP_DEBUG_LOG=str(root/'fans-debug.log'),FALUSS_PLATFORM_ROLE='fans',
                FALUSS_PF_H3_RECIPE_ONLY=True,FALUSS_PF_H3_LEASE_SHA256=hashlib.sha256((root/'h3-lease').read_bytes()).hexdigest(),
                FALUSS_FEDERATION_PRIVATE_SEED=base64.urlsafe_b64encode(secrets.token_bytes(32)).decode().rstrip('='))
    for name in ('AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'):
        values[name]=secrets.token_urlsafe(48)
    config='<?php\n'+''.join('define('+json.dumps(k)+','+json.dumps(v)+');\n' for k,v in values.items())
    config+="$table_prefix='wp_';\nif(!defined('ABSPATH')){define('ABSPATH',__DIR__.'/');}\nrequire_once ABSPATH.'wp-settings.php';\n"
    (fans/'wp-config.php').write_text(config);(fans/'wp-config.php').chmod(0o600);sql('CREATE DATABASE fans_pf_recipe')
    def cli(path,*args):return command(['php',cli_path,'--allow-root','--path='+str(path),*args])
    def worker(role,value):
        path=root/(uuid.uuid4().hex+'.json');path.write_text(json.dumps(value));path.chmod(0o600)
        return json.loads(cli(wp if role=='hub' else fans,'eval-file',str(source/'tests/TokenEngine/recipe/b3-barrier-network-worker.php'),str(path),'--use-include'))
    cli(fans,'core','install','--url=http://127.0.0.1:9','--title=Disposable barrier Fans','--admin_user=fixture',
        '--admin_password='+secrets.token_urlsafe(32),'--admin_email=fixture@example.invalid','--skip-email')
    cli(fans,'plugin','activate','faluss-platform')
    hub_public=worker('hub',dict(action='public'))['public_key'];fan_public=worker('fans',dict(action='public'))['public_key']
    check('B3bh distinct WordPress databases use distinct ephemeral signing keys',hub_public!=fan_public)
    now=int(time.time());utc=lambda t:time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime(t))
    def key(public):return dict(public_key=public,state='active',**{'from':utc(now-3600),'until':utc(now+7200)})
    permissions=['pf.ranking.context.register','pf.ranking.context.close','pf.lookup']
    policies=dict(hub=dict(node='fixture.fans',audience='fixture.hub',permissions=permissions,keys={'recipe-fans-k1':key(fan_public)}),
                  fans=dict(node='fixture.hub',audience='fixture.fans',permissions=permissions,keys={'recipe-hub-k1':key(hub_public)}))
    def policy(role,value):
        path=root/(role+'-barrier-trust.json');path.write_text(json.dumps(value));path.chmod(0o600)
    for role in policies:policy(role,policies[role])
    mu=wp/'wp-content/mu-plugins';mu.mkdir(exist_ok=True)
    shutil.copy2(source/'tests/TokenEngine/recipe/b3-barrier-http-adapter.php',mu/'b3-barrier-recipe.php')
    server=subprocess.Popen(['php','-d','opcache.enable=0','-d','opcache.enable_cli=0','-S','127.0.0.1:'+str(port),'-t',str(wp)],
                            env=dict(os.environ,PHP_CLI_SERVER_WORKERS='4'),stdout=log,stderr=log,start_new_session=True)
    workers.append(server)
    for _ in range(80):
        try:urllib.request.urlopen(home+'/index.php',timeout=2).close();break
        except urllib.error.HTTPError:break
        except (urllib.error.URLError,TimeoutError):
            if server.poll() is not None:raise RuntimeError('Isolated barrier server exited.')
            time.sleep(.1)
    else:raise RuntimeError('Isolated barrier server unavailable.')
    def http(body=None,method='POST',headers=None):
        extra={'Content-Type':'application/json','Accept':'application/json'};extra.update(headers or {})
        request=urllib.request.Request(endpoint,data=body.encode() if body is not None else None,headers=extra,method=method)
        try:response=urllib.request.urlopen(request,timeout=25)
        except urllib.error.HTTPError as error:response=error
        with response:
            try:body=response.read().decode()
            except IncompleteRead:body=''
            return response.status,body,dict(response.headers)
    def sign(fields,key,**extra):return worker('fans',dict(action='sign',fields=fields,key=key,**extra))
    def accept(fields,request,wire,**extra):
        values=dict(action='accept',fields=fields,wire=wire,nonce=request['nonce'],digest=hashlib.sha256(request['wire'].encode()).hexdigest())
        values.update(extra);return worker('fans',values)
    def exchange(fields,key):
        request=sign(fields,key);status,wire,_=http(request['wire'])
        if status!=200:raise RuntimeError('Closed barrier HTTP refused: '+str(status))
        return accept(fields,request,wire)
    def nonces():return int(sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3bh_nonces'))
    def events():return int(sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3b_events'))
    def fields(obj,op='register',lookup='',action=None):
        return dict(operation=op,lookup_operation=lookup,action_id=action or str(uuid.uuid4()),
                    origin_id=obj['content']['origin_id'],policy_version=obj['content']['policy_version'],object=obj)
    def bad_signature(wire):
        outer=json.loads(wire);s=outer['signature_base64url'];outer['signature_base64url']=('A' if s[0]!='A' else 'B')+s[1:]
        return json.dumps(outer,sort_keys=True,separators=(',',':'))
    before_ledger=sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')
    payload,refs,objects=fixture();opening=fields(objects[0]);key_value=secrets.token_hex(32)
    count=nonces();before=events()
    check('B3bh GET and wrong content type cannot admit a nonce',http(method='GET')[0]==404
          and http('{}',headers={'Content-Type':'text/plain'})[0]==403 and nonces()==count)
    check('B3bh invalid or oversized JSON cannot admit a nonce',http('x'*66049)[0]==400
          and http(json.dumps({'large':'x'*66049}))[0]==403 and nonces()==count)
    request=sign(opening,key_value);status,wire,headers=http(request['wire']);registered=accept(opening,request,wire)
    check('B3bh signed register commits one private no-store owner event',status==200 and registered['result']['state']=='active'
          and 'no-store' in headers.get('Cache-Control','') and nonces()==count+1 and events()==before+1)
    check('B3bh repeated network nonce is refused before another event',http(request['wire'])[0]==403 and events()==before+1)
    look=dict(opening,operation='lookup',lookup_operation='register')
    check('B3bh primary lookup and fresh replay preserve the same registration',exchange(look,key_value)==registered
          and exchange(opening,key_value)==registered and events()==before+1)
    changes=[('signature',None),('audience',dict(request=dict(audience='fixture.other'))),('context audience',dict(context=dict(audience='fixture.other'))),
             ('unknown outer key',dict(request_key='unknown')),('unknown context key',dict(context_key='unknown')),
             ('historical domain',dict(domain='faluss.hub-purchased-pf.request/1')),('historical context domain',dict(context_domain='faluss.hub-purchased-pf.context/1')),
             ('action binding',dict(context=dict(action_id=str(uuid.uuid4())))),('object binding',dict(context=dict(object_sha256='b'*64)))]
    count=nonces();before=events()
    for name,extra in changes:
        sealed=sign(opening,key_value,**(extra or {}));body=bad_signature(sealed['wire']) if extra is None else sealed['wire']
        check('B3bh refuses '+name+' before admission',http(body)[0]==403 and nonces()==count and events()==before)
    times=dict(issued_at=utc(int(time.time())-120),expires_at=utc(int(time.time())-60))
    check('B3bh expired signed context is refused',http(sign(opening,key_value,request=times,context=times)['wire'])[0]==403 and nonces()==count)
    for name,extra in [('revoked key',dict(keys={'recipe-fans-k1':dict(key(fan_public),state='revoked')})),
                       ('foreign peer',dict(node='fixture.other')),('historical permissions',dict(permissions=['wallet.read','pf.reserve']))]:
        policy('hub',dict(policies['hub'],**extra))
        check('B3bh refuses '+name,http(sign(opening,key_value)['wire'])[0]==403 and nonces()==count and events()==before)
    policy('hub',policies['hub'])
    other,other_refs,other_objects=fixture();new_fields=fields(other_objects[0]);new_key=secrets.token_hex(32);request=sign(new_fields,new_key)
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:answers=list(pool.map(lambda _:http(request['wire']),range(8)))
    good=[answer for answer in answers if answer[0]==200]
    check('B3bh eight simultaneous identical wires create one nonce and event',len(good)==1
          and sum(answer[0]==403 for answer in answers)==7 and nonces()==count+1 and events()==before+1)
    for name,extra in [('nonce',dict(nonce='c'*64)),('digest',dict(digest='d'*64))]:
        check('B3bh refuses response '+name+' mismatch','error' in accept(new_fields,request,good[0][1],**extra))
    changed_wire=worker('hub',dict(action='alter-response',wire=good[0][1],response=dict(action_id=str(uuid.uuid4()))))['wire']
    check('B3bh even a signed response must bind the exact action','error' in accept(new_fields,request,changed_wire))
    policy('fans',dict(policies['fans'],keys={'recipe-hub-k1':dict(key(hub_public),state='revoked')}))
    check('B3bh revoked Hub key cannot authorize a response','error' in accept(new_fields,request,good[0][1]))
    policy('fans',policies['fans'])
    closing=dict(new_fields,operation='close',object=close_ref(other_refs[0]),action_id=str(uuid.uuid4()))
    close_key=secrets.token_hex(32);request=sign(closing,close_key);before=events()
    (root/'barrier-http-fault').write_text('drop-after-commit');status,body,_=http(request['wire'])
    check('B3bh lost close HTTP body follows a committed closure',status==200 and body=='' and events()==before+1)
    recovered=exchange(dict(closing,operation='lookup',lookup_operation='close'),close_key)
    check('B3bh primary lookup after lost closure preserves its action and key',recovered['result']['state']=='closed'
          and exchange(closing,close_key)==recovered and events()==before+1)
    check('B3bh old registration lookup reports closed and cannot reopen',exchange(dict(new_fields,operation='lookup',lookup_operation='register'),new_key)['result']['state']=='closed')
    check('B3bh a foreign origin cannot close or look up a sparse reference',exchange(dict(closing,origin_id=str(uuid.uuid4())),close_key)['outcome']=='refused'
          and exchange(dict(closing,operation='lookup',lookup_operation='close',policy_version='2.0.0'),close_key)['outcome']=='refused')
    _,_,lost_objects=fixture();lost=fields(lost_objects[0]);lost_key=secrets.token_hex(32);request=sign(lost,lost_key);before=events()
    (root/'barrier-http-fault').write_text('drop-after-commit');status,body,_=http(request['wire'])
    check('B3bh lost register HTTP body recovers without another event',status==200 and body==''
          and exchange(dict(lost,operation='lookup',lookup_operation='register'),lost_key)['result']['state']=='active'
          and exchange(lost,lost_key)['result']['state']=='active' and events()==before+1)
    _,_,holder_objects=fixture();marker=root/uuid.uuid4().hex
    held=start(dict(action='b3b-register',payload=holder_objects[0],key=secrets.token_hex(32),fault='b3bc-hold-write',marker=str(marker)))
    await_file(marker,[held]);times=dict(issued_at=utc(int(time.time())),expires_at=utc(int(time.time())+4))
    pending_fields=dict(opening,operation='close',object=close_ref(refs[0]),action_id=str(uuid.uuid4()));request=sign(pending_fields,secrets.token_hex(32),request=times,context=times)
    count=nonces()
    with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
        pending=pool.submit(http,request['wire']);deadline=time.monotonic()+3
        while nonces()==count and time.monotonic()<deadline:time.sleep(.05)
        admitted=nonces()==count+1;time.sleep(4.2);pathlib.Path(str(marker)+'.release').touch();finish(held);answer=pending.result()
    check('B3bh context expired after admission and owner wait cannot close',admitted and answer[0]==200
          and accept(pending_fields,request,answer[1])==dict(outcome='refused',result=dict(reason='pf_context_expired'))
          and exchange(look,key_value)['result']['state']=='active')
    check('B3bh barrier HTTP never modifies historical ledger bytes',sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')==before_ledger)

    from b3_barrier_recovery_checks import run_checks as recovery_checks
    recovery_checks(root,fans,source,cli_path,check,command,sql,worker,policy,policies,fixture,close_ref,fields,sign,accept,http,endpoint,events)
    return dict(worker=worker,policy=policy,policies=policies,fixture=fixture,fields=fields,sign=sign,accept=accept,
                http=http,events=events,nonces=nonces,bad_signature=bad_signature)
