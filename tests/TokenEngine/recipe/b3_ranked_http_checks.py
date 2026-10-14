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
    proof,fixture,fresh=helpers
    fans=root/'fans'
    if fans.exists():raise RuntimeError('Ranked HTTP requires its own fresh Fans instance.')
    shutil.copytree(wp,fans)
    with socket.socket() as listener:
        listener.bind(('127.0.0.1',0));port=listener.getsockname()[1]
    home='http://127.0.0.1:'+str(port); endpoint=home+'/index.php?rest_route=/faluss-b3-recipe/v1/ranked'
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
        return json.loads(cli(wp if role=='hub' else fans,'eval-file',str(source/'tests/TokenEngine/recipe/b3-ranked-network-worker.php'),str(path),'--use-include'))
    cli(fans,'core','install','--url=http://127.0.0.1:9','--title=Disposable ranked Fans','--admin_user=fixture',
        '--admin_password='+secrets.token_urlsafe(32),'--admin_email=fixture@example.invalid','--skip-email')
    cli(fans,'plugin','activate','faluss-platform')
    hub_public=worker('hub',dict(action='public'))['public_key'];fan_public=worker('fans',dict(action='public'))['public_key']
    check('B3rh distinct WordPress databases use distinct ephemeral signing keys',hub_public!=fan_public)
    now=int(time.time());utc=lambda t:time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime(t))
    def key(public):return dict(public_key=public,state='active',**{'from':utc(now-3600),'until':utc(now+7200)})
    permissions=['pf.context.delegate','pf.reserve','pf.confirm','pf.release','pf.lookup']
    policies=dict(hub=dict(node='fixture.fans',audience='fixture.hub',permissions=permissions,keys={'recipe-fans-k1':key(fan_public)}),
                  fans=dict(node='fixture.hub',audience='fixture.fans',permissions=permissions,keys={'recipe-hub-k1':key(hub_public)}))
    def policy(role,value):
        path=root/(role+'-ranked-trust.json');path.write_text(json.dumps(value));path.chmod(0o600)
    for role in policies:policy(role,policies[role])
    mu=wp/'wp-content/mu-plugins';mu.mkdir(exist_ok=True)
    shutil.copy2(source/'tests/TokenEngine/recipe/b3-ranked-http-adapter.php',mu/'b3-ranked-recipe.php')
    server=subprocess.Popen(['php','-d','opcache.enable=0','-d','opcache.enable_cli=0','-S','127.0.0.1:'+str(port),'-t',str(wp)],
                            env=dict(os.environ,PHP_CLI_SERVER_WORKERS='4'),stdout=log,stderr=log,start_new_session=True)
    workers.append(server)
    for _ in range(80):
        try:urllib.request.urlopen(home+'/index.php',timeout=2).close();break
        except urllib.error.HTTPError:break
        except (urllib.error.URLError,TimeoutError):
            if server.poll() is not None:raise RuntimeError('Isolated ranked server exited.')
            time.sleep(.1)
    else:raise RuntimeError('Isolated ranked server unavailable.')
    def http(body=None,method='POST',headers=None):
        extra={'Content-Type':'application/json','Accept':'application/json'};extra.update(headers or {})
        request=urllib.request.Request(endpoint,data=body.encode() if body is not None else None,headers=extra,method=method)
        try:response=urllib.request.urlopen(request,timeout=25)
        except urllib.error.HTTPError as error:response=error
        with response:
            try:body=response.read().decode()
            except IncompleteRead:body=''
            return response.status,body,dict(response.headers)
    def sign(intent,op,key,lookup='',**extra):return worker('fans',dict(action='sign',intent=intent,operation=op,key=key,lookup=lookup,**extra))
    def accept(intent,op,request,wire,**extra):
        values=dict(action='accept',intent=intent,operation=op,wire=wire,nonce=request['nonce'],digest=hashlib.sha256(request['wire'].encode()).hexdigest())
        values.update(extra);return worker('fans',values)
    def exchange(intent,op,key,lookup=''):
        request=sign(intent,op,key,lookup);status,wire,_=http(request['wire'])
        if status!=200:raise RuntimeError('Closed ranked HTTP refused: '+str(status))
        return accept(intent,op,request,wire)
    def nonces():return int(sql('SELECT COUNT(*) FROM wp_token_engine_pf_h3_nonces'))
    def debits():return int(sql("SELECT COUNT(*) FROM wp_token_engine_pf_ledger WHERE direction='debit'"))
    def bad_signature(wire):
        outer=json.loads(wire);s=outer['signature_base64url'];outer['signature_base64url']=('A' if s[0]!='A' else 'B')+s[1:]
        return json.dumps(outer,sort_keys=True,separators=(',',':'))
    evidence,_=proof();intent,refs=fixture(evidence['member_faluss_id']);reserve_key=secrets.token_hex(32);confirm_key=secrets.token_hex(32)
    count=nonces();before=debits()
    check('B3rh GET cannot admit a nonce',http(method='GET')[0]==404 and nonces()==count)
    check('B3rh unsupported content type cannot admit a nonce',http('{}',headers={'Content-Type':'text/plain'})[0]==403 and nonces()==count)
    check('B3rh WordPress rejects malformed JSON before dispatch',http('x'*66049)[0]==400 and nonces()==count)
    check('B3rh gateway rejects oversized valid JSON before nonce or debit',
          http(json.dumps({'oversized':'x'*66049}))[0]==403 and nonces()==count and debits()==before)
    request=sign(intent,'reserve',reserve_key);status,wire,headers=http(request['wire']);reserved=accept(intent,'reserve',request,wire)
    check('B3rh signed reserve is private no-store and has no debit',status==200 and reserved==dict(outcome='ok',result=dict(state='reserved'))
          and 'no-store' in headers.get('Cache-Control','') and debits()==before)
    check('B3rh identical network nonce is not an economic replay',http(request['wire'])[0]==403 and nonces()==count+1 and debits()==before)
    count=nonces()
    changes=[('signature',None),('outer audience',dict(request=dict(audience='fixture.other'))),('context audience',dict(context=dict(audience='fixture.other'))),
             ('outer key',dict(request_key='unknown-key')),('context key',dict(context_key='unknown-key')),
             ('old request domain',dict(domain='faluss.hub-purchased-pf.request/1')),('old context domain',dict(context_domain='faluss.hub-purchased-pf.context/1')),
             ('operation binding',dict(context=dict(operation='release'))),('key binding',dict(context=dict(key_sha256=secrets.token_hex(32))))]
    for name,extra in changes:
        sealed=sign(intent,'confirm',confirm_key,**(extra or {}));body=bad_signature(sealed['wire']) if extra is None else sealed['wire']
        check('B3rh rejects '+name+' before nonce or debit',http(body)[0]==403 and nonces()==count and debits()==before)
    for name,times in [('expired',dict(issued_at=utc(now-120),expires_at=utc(now-60))),('future',dict(issued_at=utc(now+3600),expires_at=utc(now+3660)))]:
        check('B3rh rejects '+name+' signed context',http(sign(intent,'confirm',confirm_key,request=times,context=times)['wire'])[0]==403 and nonces()==count)
    for name,extra in [('revoked key',dict(keys={'recipe-fans-k1':dict(key(fan_public),state='revoked')})),('other pair',dict(node='fixture.other')),
                       ('historical permission',dict(permissions=['wallet.read','pf.snapshot'])),('missing delegation',dict(permissions=['pf.confirm']))]:
        policy('hub',dict(policies['hub'],**extra))
        check('B3rh rejects '+name+' without mutation',http(sign(intent,'confirm',confirm_key)['wire'])[0]==403 and nonces()==count and debits()==before)
    policy('hub',policies['hub'])
    request=sign(intent,'confirm',confirm_key)
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:answers=list(pool.map(lambda _:http(request['wire']),range(8)))
    good=[value for value in answers if value[0]==200]
    check('B3rh eight concurrent duplicate nonces yield one confirmed official debit',len(good)==1
          and all(value[0] in (200,403) for value in answers) and nonces()==count+1 and debits()==before+1)
    confirmed=accept(intent,'confirm',request,good[0][1]);receipt=confirmed['result']['receipt']
    check('B3rh current response and private receipt authenticate the full ranked intention',confirmed['outcome']=='ok' and confirmed['result']['state']=='confirmed')
    check('B3rh fresh primary lookup and same-key confirmation preserve the original signed receipt',
          exchange(intent,'lookup',confirm_key,'confirm')['result']['receipt']==receipt
          and exchange(intent,'confirm',confirm_key)['result']['receipt']==receipt and debits()==before+1)
    check('B3rh a replacement key cannot double-consume an attribution',exchange(intent,'confirm',secrets.token_hex(32))['outcome']=='refused' and debits()==before+1)
    for name,extra in [('wrong nonce',dict(nonce=secrets.token_hex(32))),('wrong request digest',dict(digest=secrets.token_hex(32)))]:
        check('B3rh response rejects '+name,'error' in accept(intent,'confirm',request,good[0][1],**extra))
    check('B3rh response signature alteration never yields a receipt','error' in accept(intent,'confirm',request,bad_signature(good[0][1])))
    altered=worker('hub',dict(action='alter-response',wire=good[0][1],response=dict(audience='fixture.other')))
    check('B3rh response for another audience is refused','error' in accept(intent,'confirm',request,altered['wire']))
    policy('fans',dict(policies['fans'],keys={'recipe-hub-k1':dict(key(hub_public),state='revoked')}))
    check('B3rh revoked Hub signing key cannot authorize the receipt','error' in accept(intent,'confirm',request,good[0][1]))
    policy('fans',policies['fans'])
    changed=copy.deepcopy(intent);changed['creator_faluss_id']=str(uuid.uuid4())
    check('B3rh authenticated different identity cannot replace a stored attribution',exchange(changed,'confirm',confirm_key)['outcome']=='refused' and debits()==before+1)
    # Proof retrieval can fail after a confirmed owner commit; this cannot assert rollback.
    table='wp_token_engine_pf_b3r_receipts';where=" WHERE attribution_id='"+intent['attribution_id']+"'"
    digest=sql('SELECT payload_sha256 FROM '+table+where);sql("UPDATE "+table+" SET payload_sha256='"+'0'*64+"'"+where)
    check('B3rh unavailable historical proof after commit remains unknown',exchange(intent,'lookup',confirm_key,'confirm')==dict(outcome='unknown',result=dict(reason='pf_ranked_receipt_unknown')) and debits()==before+1)
    sql("UPDATE "+table+" SET payload_sha256='"+digest+"'"+where)
    check('B3rh same lookup recovers when the private fixture proof is restored',exchange(intent,'lookup',confirm_key,'confirm')['result']['receipt']==receipt)
    data,_,_,lost_key,_,_=fresh();before=debits();request=sign(data,'confirm',lost_key)
    (root/'ranked-http-fault').write_text('drop-after-commit');status,body,_=http(request['wire'])
    check('B3rh lost HTTP body follows an actual committed debit',status==200 and body=='' and debits()==before+1)
    recovered=exchange(data,'lookup',lost_key,'confirm')
    check('B3rh primary lookup after lost body and retry never debit twice',recovered['result']['state']=='confirmed'
          and exchange(data,'confirm',lost_key)==recovered and debits()==before+1)
    release_data,_,_,_,_,_=fresh();release_key=secrets.token_hex(32);before=debits()
    check('B3rh release and primary lookup preserve one terminal reservation without debit',exchange(release_data,'release',release_key)==dict(outcome='ok',result=dict(state='released'))
          and exchange(release_data,'lookup',release_key,'release')==dict(outcome='ok',result=dict(state='released')) and debits()==before)
    blocked,_,_,blocked_key,_,_=fresh();holder,_,_,holder_key,_,_=fresh();marker=root/uuid.uuid4().hex
    held=start(dict(action='b3r-lookup',payload=holder,key=holder_key,operation='confirm',fault='b3o-hold-global',marker=str(marker)))
    await_file(marker,[held]);now=int(time.time());times=dict(issued_at=utc(now),expires_at=utc(now+4));request=sign(blocked,'confirm',blocked_key,request=times,context=times)
    count=nonces();before=debits()
    with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
        pending=pool.submit(http,request['wire']);deadline=time.monotonic()+3
        while nonces()==count and time.monotonic()<deadline:time.sleep(.05)
        admitted=nonces()==count+1;time.sleep(4.2);pathlib.Path(str(marker)+'.release').touch();finish(held);answer=pending.result()
    check('B3rh signed request expiring behind the owner mutex cannot debit',admitted and answer[0]==200
          and accept(blocked,'confirm',request,answer[1])==dict(outcome='refused',result=dict(reason='pf_context_expired')) and debits()==before)
    return dict(fans=fans,proof=proof,fixture=fixture,exchange=exchange,http=http,sign=sign,accept=accept,policies=policies,policy=policy,debits=debits)
