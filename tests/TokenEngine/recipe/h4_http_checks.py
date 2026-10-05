"""Private signed complete snapshots between two real disposable WP/DB instances.

Only fictitious Identity links, keys, purchases and peers. No F1 projection is installed.
"""
import base64
import concurrent.futures
import copy
import hashlib
import json
import os
import secrets
import shutil
import signal
import subprocess
import time
import urllib.error
import urllib.request
import uuid


def run_checks(root, wp, source, cli_path, check, call, sql, command, workers, log):
    fans = root / 'fans'
    route = '/index.php?rest_route=/faluss-h4-recipe/v1/snapshot'
    endpoint = (root / 'hub-endpoint').read_text().replace('/faluss-h3-recipe/v1/pf', '/faluss-h4-recipe/v1/snapshot')
    hub_origin = endpoint.split('/index.php', 1)[0]
    config = (fans / 'wp-config.php').read_text()
    fan_origin = next(json.loads(line.split(',', 1)[1][:-2]) for line in config.splitlines() if line.startswith('define("WP_HOME",'))
    origins = dict(hub=hub_origin, fans=fan_origin)
    (root / 'snapshot-endpoint').write_text(endpoint)
    epoch = sql('SELECT epoch FROM wp_token_engine_pf_h4s_counter WHERE id=1')
    (root / 'snapshot-epoch').write_text(epoch)
    (root / 'snapshot-epoch').chmod(0o600)

    def fan_sql(query):
        return command(['mariadb', '--no-defaults', '--socket=' + str(root / 'sql.sock'), '-uroot',
                        '--batch', '--skip-column-names', 'fans_pf_recipe', '-e', query]).strip()

    def start(role, data):
        file = root / (uuid.uuid4().hex + '.json')
        file.write_text(json.dumps(data)); file.chmod(0o600)
        process = subprocess.Popen(['php', cli_path,
                                    '--allow-root', '--path=' + str(wp if role == 'hub' else fans),
                                    'eval-file', str(source / 'tests/TokenEngine/recipe/h4-network-worker.php'),
                                    str(file), '--use-include'], stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                   text=True, start_new_session=True)
        workers.append(process)
        return process

    def worker(role, data):
        process = start(role, data)
        output, error = process.communicate(timeout=40)
        if process.returncode: raise RuntimeError('H4 private fixture worker failed.')
        return json.loads(output)

    for path in (wp, fans):
        shutil.copy2(source / 'tests/TokenEngine/recipe/h4-http-adapter.php', path / 'wp-content/mu-plugins/h4-recipe.php')
    check('H4 HTTP ordinary Fans bootstrap has no snapshot staging schema', fan_sql("SHOW TABLES LIKE 'wp_fans_pf_h4_%'") == '')
    # Before the marker the guard throws outside the test worker's catch. Test the actual unregistered route instead.
    def http(role, body=None, who=None, headers=None, method='POST'):
        extra = {'Content-Type': 'application/json', 'Accept': 'application/json'}
        if who is not None:
            extra.update(Cookie=sessions[who]['cookie'], **{'X-WP-Nonce': sessions[who]['nonce']})
        extra.update(headers or {})
        raw = body.encode() if isinstance(body, str) else json.dumps(body).encode() if body is not None else None
        try: response = urllib.request.urlopen(urllib.request.Request(origins[role] + route, data=raw, headers=extra, method=method), timeout=25)
        except urllib.error.HTTPError as error: response = error
        with response: return response.status, response.read().decode(), dict(response.headers)

    sessions = json.loads((root / 'h3-sessions.json').read_text())['sessions']
    check('H4 HTTP copied test MU creates no Fans route without H4 marker', http('fans', dict(read_id=str(uuid.uuid4())), 'creator')[0] == 404)
    (fans / 'wp-config.php').write_text(config.replace('$table_prefix=', "define('FALUSS_PF_H4_RECIPE',true);\n$table_prefix="))
    check('H4 HTTP explicit Fans staging install verifies four additive InnoDB tables',
          worker('fans', dict(action='install')) == dict(ready=True) and worker('fans', dict(action='ready')) == dict(ready=True))
    assert worker('fans', dict(action='sessions')) == dict(ready=True)
    data = json.loads((root / 'h4-sessions.json').read_text())
    sessions, identities = data['sessions'], data['identities']
    member, creator = identities['fan'], identities['creator']
    policies = {role: json.loads((root / (role + '-trust.json')).read_text()) for role in ('hub', 'fans')}
    policies['hub']['permissions'].append('pf.snapshot')

    def policy(role, value):
        file = root / (role + '-trust.json'); file.write_text(json.dumps(value)); file.chmod(0o600)

    policy('hub', policies['hub'])
    original_h3 = fan_sql('SELECT * FROM wp_fans_pf_h3_receipts ORDER BY receipt_id')
    before_ledger = sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')
    before_alb = sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')
    max_original = sql('SELECT COALESCE(MAX(id),0) FROM wp_token_engine_pf_ledger')
    proof = dict(contract='hub.purchased-pf.h1-model/1.0.0', authority_id='fixture.purchase', purchase_id='synthetic.' + uuid.uuid4().hex,
                 source_revision='1', evidence_id=str(uuid.uuid4()), member_faluss_id=member, purchased_pf='101', bonus_pf='0',
                 cancelled_purchased_pf_cumulative='0', state='confirmed', confirmed_at='2026-10-01T10:00:00Z',
                 observed_at='2026-10-05T10:00:00Z', policy_version='1.0.0')
    lot = call('h1-evidence', payload=proof, key=secrets.token_hex(32))['lot_id']
    call('h2-admit', lot_id=lot, member=member, key=secrets.token_hex(32))
    assert len(call('h4-seed-many', member=member, creator=creator, count=101)['attributions']) == 101

    def fan(read_id, who='fan', **headers):
        status, body, response_headers = http('fans', dict(read_id=read_id), who, headers)
        return status, json.loads(body), response_headers

    def current(): return worker('fans', dict(action='current', member=member))
    def complete(read_id):
        for _ in range(6):
            status, answer, _ = fan(read_id)
            assert status == 200, answer.get('error')
            if answer['state'] in ('current', 'refused'): return answer
        raise RuntimeError('H4 checkpoint did not finish within bounded pages.')

    for who in (None, 'unlinked', 'admin', 'expired'):
        check('H4 HTTP absent unlinked privileged or expired session denied ' + str(who), fan(str(uuid.uuid4()), who)[0] in (401, 403))
    check('H4 HTTP forged nonce denied without creating a read', fan(str(uuid.uuid4()), **{'X-WP-Nonce': 'forged'})[0] == 403)
    check('H4 HTTP browser cannot provide a recipient identity', http('fans', dict(read_id=str(uuid.uuid4()), member_faluss_id=creator), 'fan')[0] == 403)
    read_id = str(uuid.uuid4())
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        answers = list(pool.map(lambda _: fan(read_id), range(4)))
    check('H4 HTTP concurrent advances keep one read key and no duplicate page',
          all(status == 200 for status, _, _ in answers) and fan_sql("SELECT COUNT(*) FROM wp_fans_pf_h4_reads WHERE read_id='" + read_id + "'") == '1')
    check('H4 HTTP linked other member cannot resume a foreign read', fan(read_id, 'creator')[0] == 403)
    check('H4 HTTP complete two-page primary-attested snapshot enters only private proof inbox',
          complete(read_id)['state'] == 'current' and current()['manifest']['net_pf'] == '101'
          and len(current()['rows']) == 102 and fan_sql("SELECT COUNT(*) FROM wp_fans_pf_h4_pages WHERE read_id='" + read_id + "'") == '2')
    check('H4 HTTP replies are private no-store and current retry is inert',
          'private' in fan(read_id)[2].get('Cache-Control', '') and 'no-store' in fan(read_id)[2].get('Cache-Control', '')
          and fan(read_id)[1]['state'] == 'current')

    # Actual lost response after snapshot COMMIT: same stable read key recovers materialization.
    lost_id = str(uuid.uuid4())
    (root / 'h4-http-fault').write_text('drop-after-snapshot')
    status, answer, _ = fan(lost_id)
    before = sql("SELECT COUNT(*) FROM wp_token_engine_pf_h4s_snapshots WHERE member_faluss_id='" + member + "'")
    stable = fan_sql("SELECT read_key FROM wp_fans_pf_h4_reads WHERE read_id='" + lost_id + "'")
    check('H4 HTTP lost committed start response leaves original read key and no partial exact state',
          status == 403 and answer == dict(error='pf_transport_unknown') and current() is None)
    check('H4 HTTP retry after lost response recovers same snapshot without a new key or debit',
          complete(lost_id)['state'] == 'current'
          and before == sql("SELECT COUNT(*) FROM wp_token_engine_pf_h4s_snapshots WHERE member_faluss_id='" + member + "'")
          and stable == fan_sql("SELECT read_key FROM wp_fans_pf_h4_reads WHERE read_id='" + lost_id + "'"))

    def exchange(read, progress):
        fields = dict(operation=progress['phase'], read_id=read, member_faluss_id=member,
                      snapshot_id=(progress['manifest'] or {}).get('snapshot_id', ''), cursor=progress['next_cursor'] or '')
        signing = dict(action='sign', fields=fields, key=progress['read_key'])
        request = worker('fans', signing)
        status, wire, _ = http('hub', request['wire'])
        assert status == 200
        accept = dict(action='accept', wire=wire, fields=fields, nonce=request['nonce'], digest=hashlib.sha256(request['wire'].encode()).hexdigest())
        return signing, request, accept

    fault_id = str(uuid.uuid4())
    progress = worker('fans', dict(action='prepare', member=member, read_id=fault_id))
    signing, request, accept = exchange(fault_id, progress)
    check('H4 HTTP exact signed request replay is denied before any economic effect', http('hub', request['wire'])[0] == 403)
    nonce_bad = worker('fans', dict(accept, nonce=secrets.token_hex(32)))
    check('H4 HTTP response binding rejects mismatched nonce and request digest',
          'error' in nonce_bad and 'error' in worker('fans', dict(accept, digest=secrets.token_hex(32))))
    outer = json.loads(accept['wire']); sig = bytearray(base64.urlsafe_b64decode(outer['signature_base64url'] + '===')); sig[0] ^= 1
    outer['signature_base64url'] = base64.urlsafe_b64encode(sig).decode().rstrip('=')
    check('H4 HTTP altered response signature never stages a page', 'error' in worker('fans', dict(accept, wire=json.dumps(outer, sort_keys=True, separators=(',', ':')))))
    now = time.time(); utc = lambda t: time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime(t))
    for name, alteration in [('audience', dict(response=dict(audience='fixture.other'))),
                             ('expired response', dict(response=dict(issued_at=utc(now-120), expires_at=utc(now-60)))),
                             ('unknown Hub key', dict(response_key='unknown-hub-key')),
                             ('unapproved epoch', dict(manifest=dict(epoch=str(uuid.uuid4())))),
                             ('wrong member', dict(manifest=dict(member_faluss_id=creator))),
                             ('missing page rows', dict(page=dict(rows=[])))]:
        bad = worker('hub', dict(action='alter-response', wire=accept['wire'], **alteration))['wire']
        check('H4 HTTP rejects ' + name, 'error' in worker('fans', dict(accept, wire=bad)))
    for name, alteration in [('expired context', dict(context=dict(issued_at=utc(now-120), expires_at=utc(now-60)))),
                             ('unknown request key', dict(request_key='unknown-fans-key')),
                             ('unknown context key', dict(context_key='unknown-fans-key')),
                             ('delegated identity mismatch', dict(context=dict(member_faluss_id=creator)))]:
        bad = worker('fans', dict(signing, **alteration))['wire']
        check('H4 HTTP rejects ' + name, http('hub', bad)[0] == 403)
    for permission in ('pf.snapshot', 'pf.context.delegate'):
        bad = copy.deepcopy(policies['hub']); bad['permissions'].remove(permission); policy('hub', bad)
        check('H4 HTTP explicit dedicated permission required ' + permission, http('hub', worker('fans', signing)['wire'])[0] == 403)
    policy('hub', policies['hub'])
    revoked = copy.deepcopy(policies['hub']); revoked['keys']['recipe-fans-k1']['state'] = 'revoked'; policy('hub', revoked)
    check('H4 HTTP revoked Fan key denied', http('hub', worker('fans', signing)['wire'])[0] == 403)
    policy('hub', policies['hub'])
    revoked = copy.deepcopy(policies['fans']); revoked['keys'][(root / 'hub-key-id').read_text()]['state'] = 'revoked'; policy('fans', revoked)
    check('H4 HTTP revoked Hub proof key denied without checkpoint', 'error' in worker('fans', accept))
    policy('fans', policies['fans'])
    check('H4 HTTP page INSERT failure rolls back page and cursor together',
          worker('fans', dict(accept, fault='page-insert', marker=str(root / 'unused'))) == dict(error='pf_local_storage_unavailable')
          and fan_sql("SELECT COUNT(*) FROM wp_fans_pf_h4_pages WHERE read_id='" + fault_id + "'") == '0')
    check('H4 HTTP lost local checkpoint COMMIT acknowledgement replays same immutable page',
          worker('fans', dict(accept, fault='lost-commit-ack', marker=str(root / 'unused'))) == dict(error='pf_local_commit_unknown')
          and worker('fans', accept)['state'] == 'page'
          and fan_sql("SELECT COUNT(*) FROM wp_fans_pf_h4_pages WHERE read_id='" + fault_id + "'") == '1')
    for fault in ('before-commit', 'after-commit'):
        progress = worker('fans', dict(action='prepare', member=member, read_id=fault_id))
        _, _, packet = exchange(fault_id, progress)
        marker = root / uuid.uuid4().hex
        process = start('fans', dict(packet, fault=fault, marker=str(marker)))
        deadline = time.monotonic()+25
        while not marker.exists():
            if process.poll() is not None or time.monotonic() > deadline: raise RuntimeError('H4 checkpoint interleaving unavailable.')
            time.sleep(.02)
        os.killpg(process.pid, signal.SIGKILL); process.wait(timeout=10)
        check('H4 HTTP process death ' + fault + ' recovers cursor checkpoint and immutable page',
              'error' not in worker('fans', packet))
    assert complete(fault_id)['state'] == 'current'

    missing_id = str(uuid.uuid4())
    assert fan(missing_id)[1]['state'] == 'page' and fan(missing_id)[1]['state'] == 'finish'
    backup = fan_sql("SELECT HEX(payload_json),payload_sha256,HEX(response_json),response_sha256 FROM wp_fans_pf_h4_pages WHERE read_id='" + missing_id + "' AND page_index=1").split('\t')
    fan_sql("DELETE FROM wp_fans_pf_h4_pages WHERE read_id='" + missing_id + "' AND page_index=1")
    check('H4 HTTP missing private staged page prevents atomic complete activation',
          fan(missing_id)[1] == dict(error='pf_snapshot_incomplete') and current() is None)
    fan_sql("INSERT INTO wp_fans_pf_h4_pages VALUES ('" + missing_id + "',1,UNHEX('" + backup[0] + "'),'" + backup[1] + "',UNHEX('" + backup[2] + "'),'" + backup[3] + "')")
    fan_sql("UPDATE wp_fans_pf_h4_pages SET payload_sha256=REPEAT('0',64) WHERE read_id='" + missing_id + "' AND page_index=1")
    check('H4 HTTP corrupted staged digest is refused despite valid Hub primary fence',
          fan(missing_id)[1] == dict(error='pf_snapshot_incomplete') and current() is None)
    fan_sql("UPDATE wp_fans_pf_h4_pages SET payload_sha256='" + backup[1] + "' WHERE read_id='" + missing_id + "' AND page_index=1")
    check('H4 HTTP restored exact private page resumes original read without replacement key', complete(missing_id)['state'] == 'current')

    stale_id = str(uuid.uuid4()); assert fan(stale_id)[1]['state'] == 'page'
    revised = dict(proof, source_revision='2', evidence_id=str(uuid.uuid4()), state='partially_cancelled', cancelled_purchased_pf_cumulative='20')
    plan = call('h4-begin', payload=revised, key=secrets.token_hex(32))
    assert fan(stale_id)[1]['state'] == 'finish'
    check('H4 HTTP incomplete correction prevents final attestation and leaves no exact partial inbox',
          fan(stale_id)[1]['state'] == 'refused' and current() is None)
    for fragment in ('1', '2'):
        assert 'error' not in call('h4-resume', member=member, plan_id=plan['plan_id'], fragment=fragment)
    newer_id = str(uuid.uuid4()); assert complete(newer_id)['state'] == 'current'
    state = current(); allocations = [row for row in state['rows'] if row['kind'] == 'allocation']
    # These are sums of private fixture evidence, NOT a deployed HoF/Fan projection.
    fan_sum = sum(int(row['net_pf']) for row in allocations if row['member_faluss_id'] == member)
    creator_sum = sum(int(row['net_pf']) for row in allocations if row['creator_faluss_id'] == creator)
    check('H4 HTTP complete corrected evidence reconciles every affected Fan and Creator leg cumulatively',
          state['manifest']['cancelled_pf'] == '20' and state['manifest']['net_pf'] == '81' and fan_sum == creator_sum == 81)
    delayed = worker('hub', dict(action='alter-response', wire=packet['wire'],
                                response=dict(issued_at=utc(time.time()), expires_at=utc(time.time()+60))))['wire']
    check('H4 HTTP delayed older complete signed proof cannot regress newer cumulative inbox',
          worker('fans', dict(packet, wire=delayed)) == dict(error='pf_snapshot_regression')
          and current()['manifest']['full_sha256'] == state['manifest']['full_sha256'])
    old_progress = worker('fans', dict(action='prepare', member=member, read_id=lost_id))
    old_fields = dict(operation='finish', read_id=lost_id, member_faluss_id=member,
                      snapshot_id=old_progress['manifest']['snapshot_id'], cursor='')
    # Replay of an old fresh-at-the-time final reply cannot replace the newer inbox revision.
    old_request = worker('fans', dict(action='sign', fields=old_fields, key=old_progress['read_key']))
    _, old_wire, _ = http('hub', old_request['wire'])
    check('H4 HTTP primary refuses old document after completed correction without re-crediting',
          worker('fans', dict(action='accept', wire=old_wire, fields=old_fields, nonce=old_request['nonce'],
                             digest=hashlib.sha256(old_request['wire'].encode()).hexdigest()))['state'] == 'refused'
          and current()['manifest']['full_sha256'] == state['manifest']['full_sha256'])
    for number, source_state in ((3, 'disputed'), (4, 'partially_cancelled')):
        source_revision = dict(revised, source_revision=str(number), evidence_id=str(uuid.uuid4()), state=source_state)
        correction = call('h4-begin', payload=source_revision, key=secrets.token_hex(32))
        for fragment in ('1', '2'):
            assert 'error' not in call('h4-resume', member=member, plan_id=correction['plan_id'], fragment=fragment)
        assert complete(str(uuid.uuid4()))['state'] == 'current'
        facts = current()['manifest']
        check('H4 HTTP complete ' + source_state + ' snapshot changes every private net leg without restoring cancelled units',
              facts['cancelled_pf'] == '20' and facts['suspended_pf'] == ('81' if source_state == 'disputed' else '0')
              and facts['net_pf'] == ('0' if source_state == 'disputed' else '81'))
    check('H4 HTTP staging creates no PF ledger ranking schema or rewritten original H3 receipts',
          fan_sql("SHOW TABLES LIKE 'wp_token_engine%'") == '' and fan_sql("SHOW TABLES LIKE '%hof%'") == ''
          and fan_sql('SELECT * FROM wp_fans_pf_h3_receipts ORDER BY receipt_id') == original_h3)
    check('H4 HTTP historical ledger bytes and generic ALB are untouched',
          sql('SELECT * FROM wp_token_engine_pf_ledger WHERE id<=' + max_original + ' ORDER BY id') == before_ledger
          and sql('SELECT * FROM wp_token_engine_ledger ORDER BY id') == before_alb)
    check('H4 HTTP GET and forged Host do not admit fixture protocol', http('hub', method='GET')[0] in (404, 405)
          and http('hub', headers={'Host': 'example.invalid'})[0] == 404)
    lease = root / 'h3-lease'; lease.chmod(0o644)
    check('H4 HTTP insecure lease closes Hub and Fans even with both markers', http('hub')[0] == 404 and fan(str(uuid.uuid4()))[0] == 404)
    lease.chmod(0o600)
    command(['php', cli_path, '--allow-root', '--path=' + str(fans), 'eval', 'WP_Session_Tokens::get_instance(' + str(sessions['fan']['id']) + ')->destroy_all();'])
    check('H4 HTTP revoked local session cannot begin or resume a read', fan(newer_id)[0] in (401, 403))
    log.flush()
    logs = ''.join(path.read_text(errors='replace') for path in (root / 'runtime.log', root / 'debug.log', root / 'fans-debug.log') if path.exists())
    check('H4 HTTP logs contain no private identity read key cookie signed page or evidence',
          all(value not in logs for value in [member, creator, stable] + [s['cookie'].split('=', 1)[1] for s in sessions.values()])
          and 'payload_base64url' not in logs and 'signature_base64url' not in logs)
