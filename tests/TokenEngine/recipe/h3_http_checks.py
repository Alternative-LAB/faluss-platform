"""Real isolated WP-to-WP HTTP; fictitious local links, never true Identity SSO."""
import base64
import concurrent.futures
import copy
import hashlib
import json
import os
import secrets
import shutil
import socket
import subprocess
import time
import urllib.error
import urllib.request
import uuid


def run_checks(root, wp, source, core, cli_path, check, call, sql, command, workers, log):
    fans = root / 'fans'
    shutil.copytree(wp, fans)

    def port():
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            return listener.getsockname()[1]

    hub_port, fan_port = port(), port()
    while hub_port == fan_port:
        fan_port = port()
    origins = dict(hub='http://127.0.0.1:' + str(hub_port), fans='http://127.0.0.1:' + str(fan_port))
    route = '/index.php?rest_route=/faluss-h3-recipe/v1/pf'
    endpoint = origins['hub'] + route
    (root / 'hub-endpoint').write_text(endpoint)
    (root / 'hub-key-id').write_text('recipe-hub-k1')
    hub_config = (wp / 'wp-config.php').read_text().replace('http://127.0.0.1:9', origins['hub'])
    (wp / 'wp-config.php').write_text(hub_config)
    seed = base64.urlsafe_b64encode(secrets.token_bytes(32)).decode().rstrip('=')
    values = dict(DB_NAME='fans_pf_recipe', DB_USER='root', DB_PASSWORD='', DB_HOST='localhost:' + str(root / 'sql.sock'),
                  DB_CHARSET='utf8mb4', DB_COLLATE='', WP_HOME=origins['fans'], WP_SITEURL=origins['fans'],
                  WP_ENVIRONMENT_TYPE='local', WP_HTTP_BLOCK_EXTERNAL=True, DISABLE_WP_CRON=True,
                  WP_DEBUG=True, WP_DEBUG_DISPLAY=False, WP_DEBUG_LOG=str(root / 'fans-debug.log'),
                  FALUSS_PLATFORM_ROLE='fans', FALUSS_PF_H3_RECIPE_ONLY=True,
                  FALUSS_PF_H3_LEASE_SHA256=hashlib.sha256((root / 'h3-lease').read_bytes()).hexdigest(),
                  FALUSS_FEDERATION_PRIVATE_SEED=seed)
    for key in ('AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'):
        values[key] = secrets.token_urlsafe(48)
    config = '<?php\n' + ''.join('define(' + json.dumps(k) + ',' + json.dumps(v) + ');\n' for k, v in values.items())
    config += "$table_prefix='wp_';\nif(!defined('ABSPATH')){define('ABSPATH',__DIR__.'/');}\nrequire_once ABSPATH.'wp-settings.php';\n"
    (fans / 'wp-config.php').write_text(config)
    (fans / 'wp-config.php').chmod(0o600)
    sql('CREATE DATABASE fans_pf_recipe')

    def fan_sql(query):
        return command(['mariadb','--no-defaults','--socket=' + str(root / 'sql.sock'),'-uroot','--batch','--skip-column-names',
                        'fans_pf_recipe','-e',query]).strip()

    def cli(path, *arguments):
        return command(['php',cli_path,'--allow-root','--path=' + str(path),*arguments])

    cli(fans, 'core','install','--url=' + origins['fans'],'--title=Disposable Fans H3',
        '--admin_user=fixture','--admin_password=' + secrets.token_urlsafe(32),'--admin_email=fixture@example.invalid','--skip-email')
    cli(fans, 'plugin','activate','faluss-platform')
    check('H3 HTTP normal Fans bootstrap never installs protocol tables',fan_sql("SHOW TABLES LIKE 'wp_fans_pf_h3_%'") == '')
    session_file = root / 'h3-sessions.json'
    cli(fans,'eval-file',str(source / 'tests/TokenEngine/recipe/h3-fans-seed.php'),str(session_file),'--use-include')
    data = json.loads(session_file.read_text())
    sessions = data['sessions']
    identities = data['identities']
    hub_public = call('h3-public')['public_key']
    permissions = ['pf.reserve','pf.confirm','pf.release','pf.lookup','pf.context.delegate']

    def key(public, state='active'):
        return dict(public_key=public,state=state,**{'from':'2026-01-01T00:00:00Z','until':'2027-01-01T00:00:00Z'})

    policies = dict(hub=dict(node='fixture.fans',audience='fixture.hub',permissions=permissions,
                            keys={'recipe-fans-k1':key(data['public_key'])}),
                    fans=dict(node='fixture.hub',audience='fixture.fans',permissions=[],keys={'recipe-hub-k1':key(hub_public)}))

    def policy(role, value):
        file = root / (role + '-trust.json')
        file.write_text(json.dumps(value))
        file.chmod(0o600)

    for role, path in [('hub',wp),('fans',fans)]:
        policy(role, policies[role])
        mu = path / 'wp-content/mu-plugins'
        mu.mkdir(exist_ok=True)
        shutil.copy2(source / 'tests/TokenEngine/recipe/h3-http-adapter.php', mu / 'h3-recipe.php')
        server = subprocess.Popen(['php','-d','opcache.enable=0','-d','opcache.enable_cli=0','-S',
                                   '127.0.0.1:' + str(hub_port if role == 'hub' else fan_port),'-t',str(path)],
                                  env=dict(os.environ,PHP_CLI_SERVER_WORKERS='4'),stdout=log,stderr=log,start_new_session=True)
        workers.append(server)
        for _ in range(80):
            try:
                urllib.request.urlopen(origins[role] + '/index.php', timeout=2).close()
                break
            except urllib.error.HTTPError:
                break
            except (urllib.error.URLError, TimeoutError):
                if server.poll() is not None: raise RuntimeError('Closed fixture HTTP unavailable.')
                time.sleep(.1)
        else: raise RuntimeError('Closed fixture HTTP did not start.')

    def http(role, body=None, session=None, headers=None, method='POST'):
        extra = {'Content-Type':'application/json','Accept':'application/json'}
        if session:
            extra.update(Cookie=sessions[session]['cookie'], **{'X-WP-Nonce':sessions[session]['nonce']})
        extra.update(headers or {})
        raw = body.encode() if isinstance(body,str) else json.dumps(body).encode() if body is not None else None
        req = urllib.request.Request(origins[role] + route,data=raw,headers=extra,method=method)
        try: response = urllib.request.urlopen(req,timeout=25)
        except urllib.error.HTTPError as error: response = error
        with response:
            return response.status, response.read().decode(), dict(response.headers)

    def fan(input, op, lookup='', session='fan'):
        status, body, headers = http('fans',dict(input=input,operation=op,lookup_operation=lookup),session)
        return status, json.loads(body), headers

    def worker(role, input):
        file = root / (uuid.uuid4().hex + '.json')
        file.write_text(json.dumps(input))
        file.chmod(0o600)
        return json.loads(cli(wp if role == 'hub' else fans,'eval-file',str(source / 'tests/TokenEngine/recipe/h3-network-worker.php'),str(file),'--use-include'))

    def debit_count(): return int(sql("SELECT COUNT(*) FROM wp_token_engine_pf_ledger WHERE direction='debit'"))
    def receipt_count(): return int(fan_sql('SELECT COUNT(*) FROM wp_fans_pf_h3_receipts'))
    def credited_intent(quantity='20'):
        proof = dict(contract='hub.purchased-pf.h1-model/1.0.0',authority_id='fixture.purchase',purchase_id='synthetic.' + uuid.uuid4().hex,
                     source_revision='1',evidence_id=str(uuid.uuid4()),member_faluss_id=identities['fan'],purchased_pf='100',bonus_pf='0',
                     cancelled_purchased_pf_cumulative='0',state='confirmed',confirmed_at='2026-10-01T10:00:00Z',
                     observed_at='2026-10-05T10:00:00Z',policy_version='1.0.0')
        lot = call('h1-evidence',payload=proof,key=secrets.token_hex(32))['lot_id']
        call('h2-admit',lot_id=lot,member=identities['fan'],key=secrets.token_hex(32))
        return dict(attribution_id=str(uuid.uuid4()),creator_profile_id=data['creator_profile_id'],purchased_pf=quantity,policy_version='1.0.0')

    def intent(input):
        return dict(attribution_id=input['attribution_id'],client_authority='fixture.fans',member_faluss_id=identities['fan'],
                    creator_faluss_id=identities['creator'],purchased_pf=input['purchased_pf'],policy_version=input['policy_version'])

    historic_max = sql('SELECT COALESCE(MAX(id),0) FROM wp_token_engine_pf_ledger')
    historic = sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')
    alb = sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')
    input = credited_intent()
    before = debit_count()
    for who in (None,'unlinked','admin'):
        status, _, _ = http('fans',dict(input=input,operation='reserve',lookup_operation=''),who)
        check('H3 HTTP denies unlinked privileged or absent session ' + str(who), status in (401,403) and debit_count() == before)
    status, _, _ = http('fans',dict(input=input,operation='reserve',lookup_operation=''),'fan',{'X-WP-Nonce':'forged'})
    check('H3 HTTP refuses forged REST nonce before delegation',status == 403)
    check('H3 HTTP absolute local expiry rejects otherwise valid WP POST grace cookie',fan(input,'reserve',session='expired')[0] == 403)
    forged = dict(input,member_faluss_id=str(uuid.uuid4()))
    check('H3 HTTP browser cannot supply a forged member identity',fan(forged,'reserve')[0] == 403)
    check('H3 HTTP linked creator cannot self attribute',fan(input,'reserve',session='creator')[0] == 403)
    fan_sql("UPDATE wp_faluss_fans_creator_profiles SET status='suspended' WHERE creator_id='" + data['creator_profile_id'] + "'")
    check('H3 HTTP suspended creator cannot be delegated',fan(input,'reserve')[0] == 403)
    fan_sql("UPDATE wp_faluss_fans_creator_profiles SET status='active' WHERE creator_id='" + data['creator_profile_id'] + "'")
    status, result, headers = fan(input,'reserve')
    check('H3 HTTP real Fans cookie delegates exact identities to Hub reserve',status == 200 and result == dict(outcome='ok',result=dict(state='reserved')))
    check('H3 HTTP proof responses are private no-store and reserve never debits',
          'private' in headers.get('Cache-Control','') and 'no-store' in headers.get('Cache-Control','') and debit_count() == before)
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        answers = list(pool.map(lambda _:fan(input,'confirm'),range(4)))
    first = answers[0][1]
    check('H3 HTTP four concurrent Fans confirmations consume once and deduplicate inbox',
          all(status == 200 and answer == first for status,answer,_ in answers) and first['result']['state'] == 'confirmed'
          and debit_count() == before+1 and receipt_count() == 1)
    original_keys = fan_sql('SELECT * FROM wp_fans_pf_h3_keys ORDER BY attribution_id,operation')
    check('H3 HTTP same operation retry keeps stable keys receipt and debit',fan(input,'confirm')[1] == first and debit_count() == before+1
          and receipt_count() == 1 and fan_sql('SELECT * FROM wp_fans_pf_h3_keys ORDER BY attribution_id,operation') == original_keys)
    changed = dict(input,purchased_pf='21')
    check('H3 HTTP changed intention cannot replace stable key or consumed content',fan(changed,'confirm')[0] == 403 and debit_count() == before+1)

    confirm_key = fan_sql("SELECT operation_key FROM wp_fans_pf_h3_keys WHERE attribution_id='" + input['attribution_id'] + "' AND operation='confirm'")
    signing = dict(action='sign',intent=intent(input),operation='lookup',key=confirm_key,lookup='confirm')
    request = worker('fans',signing)
    status, wire, headers = http('hub',request['wire'])
    check('H3 HTTP bound signed primary lookup returns private receipt without second debit',status == 200 and debit_count() == before+1)
    accept = dict(action='accept',wire=wire,intent=intent(input),operation='lookup',nonce=request['nonce'],digest=hashlib.sha256(request['wire'].encode()).hexdigest())
    check('H3 HTTP Fans verifies fresh envelope and receipt then inbox replay is inert',worker('fans',accept) == first and receipt_count() == 1)
    status, _, _ = http('hub',request['wire'])
    check('H3 HTTP exact network replay is refused before economic dispatch',status == 403 and debit_count() == before+1)
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        once = worker('fans',signing)
        statuses = list(pool.map(lambda _:http('hub',once['wire'])[0],range(4)))
    check('H3 HTTP concurrent nonce admits one request and rejects three replays',statuses.count(200) == 1 and statuses.count(403) == 3)

    outer = json.loads(worker('fans',signing)['wire'])
    signature = bytearray(base64.urlsafe_b64decode(outer['signature_base64url']+'===')); signature[0] ^= 1
    outer['signature_base64url'] = base64.urlsafe_b64encode(signature).decode().rstrip('=')
    bad_wire = json.dumps(outer,separators=(',',':'),sort_keys=True)
    check('H3 HTTP altered request signature is rejected',http('hub',bad_wire)[0] == 403)
    now = int(time.time())
    utc = lambda timestamp:time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime(timestamp))
    changes = [('audience',dict(request=dict(audience='fixture.other'))),
               ('context audience',dict(context=dict(audience='fixture.other'))),
               ('expired context',dict(context=dict(issued_at=utc(now-120),expires_at=utc(now-60)))),
               ('future context',dict(context=dict(issued_at=utc(now+20),expires_at=utc(now+60)))),
               ('unknown request key',dict(request_key='unknown-fans-key')),
               ('unknown context key',dict(context_key='unknown-fans-key')),
               ('context signature',dict(bad_context_signature=True)),
               ('wrong context operation',dict(context=dict(operation='confirm'))),
               ('wrong context nonce',dict(context=dict(nonce=secrets.token_hex(32)))),
               ('wrong bound key',dict(context=dict(key_sha256=secrets.token_hex(32)))),
               ('invalid delegated identity',dict(context=dict(intent=dict(intent(input),member_faluss_id='forged'))))]
    for name, alteration in changes:
        bad = worker('fans',dict(signing,**alteration))
        check('H3 HTTP rejects ' + name,http('hub',bad['wire'])[0] == 403 and debit_count() == before+1)
    revoked = copy.deepcopy(policies['hub']); revoked['keys']['recipe-fans-k1']['state'] = 'revoked'
    policy('hub',revoked)
    check('H3 HTTP revoked Fan signing key is rejected',http('hub',worker('fans',signing)['wire'])[0] == 403)
    wallet = copy.deepcopy(policies['hub']); wallet['permissions'] = ['wallet.read','reward.claim','event.publish']
    policy('hub',wallet)
    check('H3 HTTP historical Federation permissions confer no PF delegation',http('hub',worker('fans',signing)['wire'])[0] == 403)
    for removed in ['pf.lookup','pf.context.delegate']:
        limited = copy.deepcopy(policies['hub']); limited['permissions'] = [p for p in permissions if p != removed]
        policy('hub',limited)
        check('H3 HTTP requires dedicated ' + removed,http('hub',worker('fans',signing)['wire'])[0] == 403)
    policy('hub',policies['hub'])

    check('H3 HTTP response is bound to request digest and nonce',
          'error' in worker('fans',dict(accept,digest=secrets.token_hex(32))) and 'error' in worker('fans',dict(accept,nonce=secrets.token_hex(32))))
    altered_outer = json.loads(wire); signature = bytearray(base64.urlsafe_b64decode(altered_outer['signature_base64url']+'===')); signature[0] ^= 1
    altered_outer['signature_base64url'] = base64.urlsafe_b64encode(signature).decode().rstrip('=')
    check('H3 HTTP altered response signature never enters Fans inbox',
          'error' in worker('fans',dict(accept,wire=json.dumps(altered_outer,separators=(',',':'),sort_keys=True))))
    for name, alteration in [('response audience',dict(response=dict(audience='fixture.other'))),
                             ('expired response',dict(response=dict(issued_at=utc(now-120),expires_at=utc(now-60)))),
                             ('unknown Hub key',dict(response_key='unknown-hub-key')),
                             ('receipt signature',dict(bad_receipt_signature=True)),
                             ('receipt audience',dict(receipt=dict(audience='fixture.other'))),
                             ('receipt identity',dict(receipt=dict(creator_faluss_id=str(uuid.uuid4())))),
                             ('unknown receipt key',dict(receipt={},receipt_key='unknown-receipt-key'))]:
        altered = worker('hub',dict(action='alter-response',wire=wire,**alteration))['wire']
        check('H3 HTTP refuses ' + name,'error' in worker('fans',dict(accept,wire=altered)) and receipt_count() == 1)

    original_digest = fan_sql("SELECT payload_sha256 FROM wp_fans_pf_h3_receipts WHERE attribution_id='" + input['attribution_id'] + "'")
    fan_sql("UPDATE wp_fans_pf_h3_receipts SET payload_sha256='" + '0'*64 + "' WHERE attribution_id='" + input['attribution_id'] + "'")
    check('H3 HTTP conflicting immutable Fans receipt is refused without replacement or debit',
          'error' in worker('fans',accept) and receipt_count() == 1 and debit_count() == before+1
          and fan_sql("SELECT payload_sha256 FROM wp_fans_pf_h3_receipts WHERE attribution_id='" + input['attribution_id'] + "'") == '0'*64)
    fan_sql("UPDATE wp_fans_pf_h3_receipts SET payload_sha256='" + original_digest + "' WHERE attribution_id='" + input['attribution_id'] + "'")

    lost = credited_intent()
    fan(lost,'reserve')
    previous = debit_count()
    (root / 'http-fault').write_text('drop-after-consume')
    status, result, _ = fan(lost,'confirm')
    check('H3 HTTP response actually lost after consumption is unknown with no inbox proof',
          status == 403 and result == dict(error='pf_transport_unknown') and debit_count() == previous+1 and receipt_count() == 1)
    stable_keys = fan_sql('SELECT * FROM wp_fans_pf_h3_keys ORDER BY attribution_id,operation')
    status, recovered, _ = fan(lost,'lookup','confirm')
    check('H3 HTTP primary lookup after lost response recovers receipt without second debit',
          status == 200 and recovered['result']['state'] == 'confirmed' and debit_count() == previous+1 and receipt_count() == 2
          and fan_sql('SELECT * FROM wp_fans_pf_h3_keys ORDER BY attribution_id,operation') == stable_keys)
    release = credited_intent()
    fan(release,'reserve')
    check('H3 HTTP release and stable lookup are non-economic',fan(release,'release')[1] == dict(outcome='ok',result=dict(state='released'))
          and fan(release,'lookup','release')[1] == dict(outcome='ok',result=dict(state='released')) and debit_count() == previous+1)

    before_rotation = sql('SELECT payload_json,payload_sha256,original_envelope_json FROM wp_token_engine_pf_h3_receipts ORDER BY receipt_id')
    second_seed = base64.urlsafe_b64encode(secrets.token_bytes(32)).decode().rstrip('=')
    import re
    hub_config = re.sub(r"define\('FALUSS_FEDERATION_PRIVATE_SEED','[^']+'\);", "define('FALUSS_FEDERATION_PRIVATE_SEED','" + second_seed + "');",hub_config)
    (wp / 'wp-config.php').write_text(hub_config)
    second_public = call('h3-public')['public_key']
    (root / 'hub-key-id').write_text('recipe-hub-k2')
    rotated = copy.deepcopy(policies['fans']); rotated['keys']['recipe-hub-k1']['state'] = 'revoked'
    rotated['keys']['recipe-hub-k2'] = key(second_public)
    policy('fans',rotated)
    check('H3 HTTP revoked old Hub response and receipt are refused', 'error' in worker('fans',accept))
    status, restored, _ = fan(lost,'lookup','confirm')
    check('H3 HTTP key rotation reattests identical historical bytes without another debit',status == 200 and restored == recovered
          and sql('SELECT payload_json,payload_sha256,original_envelope_json FROM wp_token_engine_pf_h3_receipts ORDER BY receipt_id') == before_rotation
          and debit_count() == previous+1 and receipt_count() == 2)

    check('H3 HTTP GET never mutates economics',http('hub',method='GET')[0] in (404,405) and debit_count() == previous+1)
    check('H3 HTTP wrong Host cannot open fixture route',http('hub',headers={'Host':'example.invalid'})[0] == 404)
    lease = root / 'h3-lease'; lease.chmod(0o644)
    check('H3 HTTP insecure copied lease disables both routes',http('hub')[0] == 404 and http('fans',session='fan')[0] == 404)
    lease.chmod(0o600)
    cli(fans,'eval','WP_Session_Tokens::get_instance(' + str(sessions['fan']['id']) + ')->destroy_all();')
    check('H3 HTTP expired or revoked local session cannot delegate',fan(input,'reserve')[0] in (401,403))
    check('H3 HTTP Fans inbox creates no parallel PF ledger score or Token Engine schema',
          fan_sql("SHOW TABLES LIKE 'wp_token_engine%'") == '' and fan_sql("SHOW TABLES LIKE '%hof%'") == '')
    check('H3 HTTP all prior PF bytes and ALB ledger remain unchanged',
          sql('SELECT * FROM wp_token_engine_pf_ledger WHERE id<=' + historic_max + ' ORDER BY id') == historic
          and sql('SELECT * FROM wp_token_engine_ledger ORDER BY id') == alb)
    log.flush()
    logs = ''.join(path.read_text(errors='replace') for path in (root / 'runtime.log',root / 'debug.log',root / 'fans-debug.log') if path.exists())
    private_values = list(identities.values()) + [seed,second_seed,confirm_key] + [s['cookie'].split('=',1)[1] for s in sessions.values()]
    check('H3 HTTP logs contain no identity cookie signing seed operation key or receipt',
          all(value not in logs for value in private_values) and 'signature_base64url' not in logs and 'payload_base64url' not in logs)
