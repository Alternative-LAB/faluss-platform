"""Fresh read of existing barrier contracts on actual isolated Hub/Fans primaries."""
import concurrent.futures
import copy
import hashlib
import json
import time
import uuid


def run_checks(root, check, hub_sql, network):
    local = network['recovery']; invoke = local['invoke']; sql = local['sql']; literal = local['literal']
    sign = network['sign']; http = network['http']; events = network['events']
    ledger = hub_sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')
    check('B6br ordinary activation created no primary barrier read schema', invoke('primary-ready') == dict(ready=False))
    check('B6br explicit isolated primary read installation is idempotent',
          invoke('primary-install') == dict(ready=True) and invoke('primary-install') == dict(ready=True))
    sql('CREATE TABLE wp_barrier_primary_probe (action_id CHAR(36) PRIMARY KEY) ENGINE=InnoDB')

    def fresh():
        _, refs, objects = network['fixture']()
        ref = next(value for value in refs if value['content']['kind'] == 'session')
        descriptor = next(value for value in objects if value['content'] == ref['content'])
        fields = network['fields'](descriptor); local['prepare'](fields)
        assert local['advance'](fields)['state'] == 'acknowledged'
        return fields, ref

    fields, ref = fresh(); action = fields['action_id']; origin = fields['origin_id']

    def call(operation, **extra):
        return invoke('primary-'+operation, origin, action_id=action, **extra)

    def sealed():
        value = call('recover')
        return sign(value['fields'], local['recover'](fields)['operation_key'], contract=value['contract'])

    def request():
        value = sealed(); assert call('request', sealed=value) == dict(accepted=True)
        status, wire, _ = http(value['wire']); assert status == 200
        return value, dict(wire=wire, nonce=value['nonce'], request_sha256=hashlib.sha256(value['wire'].encode()).hexdigest())

    try:
        check('B6br old lifecycle ACK alone cannot authorize current delivery', call('read') == dict(error='pf_local_barrier_primary_read_required'))
        prepared = call('prepare')
        with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
            values = list(pool.map(lambda _: call('prepare'), range(4)))
        check('B6br concurrent preparations retain one pending read revision', all(value == prepared for value in values) and prepared['revision'] == '1')
        check('B6br preparation immediately closes delivery before HTTP', call('read') == dict(error='pf_local_barrier_primary_read_required'))
        before = events(); first, proof = request(); progress = call('accept', proof=proof); current = call('read')
        check('B6br primary lookup binds the original action and operation with no new event',
              progress['phase'] == 'current' and current['result']['state'] == 'active'
              and current['result']['effective_at'] == invoke('ack', origin, action_id=action)['result']['effective_at'] and events() == before)
        check('B6br current proof callback shares the existing transaction and reveals no secret',
              current['transaction'] == '1' and set(current) == {'fields','contract','result','local_state','read_revision','transaction'})
        check('B6bt caller-owned current read preserves the same primary transaction', call('caller-read') == current)
        check('B6bt missing caller transaction grants no read', call('caller-read',no_transaction=True) == dict(error='pf_local_barrier_owner_transaction_required'))
        check('B6bt a transaction alone cannot substitute for the origin mutex', call('caller-read',manual_transaction=True) == dict(error='pf_local_barrier_owner_transaction_required'))
        check('B6bt another origin mutex cannot authorize this read', call('caller-read',other_origin=str(uuid.uuid4())) == dict(error='pf_local_barrier_owner_transaction_required'))
        check('B6bt caller rejection rolls back its proof-related metadata', call('caller-read',probe=True,reject_apply=True) == dict(error='pf_fixture_apply_refused')
              and sql('SELECT COUNT(*) FROM wp_barrier_primary_probe') == '0')
        check('B6bt caller commit publishes the callback exactly once without independent commit',
              call('caller-read',probe=True)['transaction'] == '1' and sql('SELECT COUNT(*) FROM wp_barrier_primary_probe') == '1')
        sql('DELETE FROM wp_barrier_primary_probe')
        check('B6br failed local callback rolls back its metadata', call('read', probe=True, reject_apply=True) == dict(error='pf_fixture_apply_refused')
              and sql('SELECT COUNT(*) FROM wp_barrier_primary_probe') == '0')
        check('B6br nested callback refuses a second transaction', call('read', probe=True, nested=True) == dict(error='nested_transaction_refused')
              and sql('SELECT COUNT(*) FROM wp_barrier_primary_probe') == '0')
        check('B6br a new explicit read closes the former proof', call('prepare')['revision'] == '2'
              and call('read') == dict(error='pf_local_barrier_primary_read_required'))
        check('B6br nonce from a preceding read cannot become the new challenge', call('request', sealed=first) == dict(error='pf_local_barrier_checkpoint_moved'))
        a, old_answer = request(); b, new_answer = request()
        check('B6br only the latest durable challenge can settle an uncertain read',
              call('accept', proof=old_answer) == dict(error='pf_local_barrier_checkpoint_moved') and call('recover')['phase'] == 'checking')
        bad = dict(new_answer, wire=network['bad_signature'](new_answer['wire']))
        check('B6br altered signature cannot reopen delivery', 'error' in call('accept', proof=bad) and call('read') == dict(error='pf_local_barrier_primary_read_required'))
        changed = network['worker']('hub', dict(action='alter-response', wire=new_answer['wire'], response=dict(audience='fixture.other')))
        check('B6br signed wrong audience is refused', 'error' in call('accept', proof=dict(new_answer, wire=changed['wire'])))
        expired = network['worker']('hub', dict(action='alter-response', wire=new_answer['wire'], response=dict(
            issued_at=time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime(time.time()-120)),
            expires_at=time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime(time.time()-60)))))
        check('B6br expired response stays unresolved', 'error' in call('accept', proof=dict(new_answer, wire=expired['wire'])) and call('recover')['phase'] == 'checking')
        ambiguous = call('accept', proof=new_answer, fault='commit-unknown')
        check('B6br lost local COMMIT acknowledgement recovers the same current revision',
              ambiguous == dict(error='pf_local_barrier_commit_unknown') and call('recover')['revision'] == '2'
              and call('recover')['phase'] == 'current' and call('read')['result']['state'] == 'active')
        call('prepare'); original = local['recover'](fields)['operation_key']; before = events()
        (root/'barrier-http-fault').write_text('drop-after-commit')
        uncertain = call('advance', endpoint=local['endpoint'])
        check('B6br lost lookup response keeps delivery closed and the original mutation key',
              uncertain['phase'] == 'pending' and call('recover')['phase'] == 'checking'
              and call('read') == dict(error='pf_local_barrier_primary_read_required') and local['recover'](fields)['operation_key'] == original)
        recovered = call('advance', endpoint=local['endpoint'], monitor=True)
        check('B6br same action recovery uses lookup only, outside transaction and origin mutex',
              recovered['phase'] == 'current' and events() == before
              and all(value['transaction'] == '0' and value['mutex_free'] == '1' and value['registered'] == '1' for value in recovered['http_trace']))
        saved = sql('SELECT proof_sha256 FROM wp_fans_pf_b6br_reads WHERE action_id='+literal(action))
        sql('UPDATE wp_fans_pf_b6br_reads SET proof_sha256='+literal('a'*64)+' WHERE action_id='+literal(action))
        check('B6br corrupted proof cannot reach delivery callback', call('read') == dict(error='pf_local_barrier_conflict'))
        sql('UPDATE wp_fans_pf_b6br_reads SET proof_sha256='+literal(saved)+' WHERE action_id='+literal(action))
        check('B6br restoration preserves the actual canonical proof bytes', call('read')['result']['state'] == 'active')
        revoked = copy.deepcopy(network['policies']['fans']); revoked['keys']['recipe-hub-k1']['state'] = 'revoked'
        network['policy']('fans', revoked)
        check('B6br current trust revocation closes stored proof delivery', 'error' in call('read'))
        network['policy']('fans',network['policies']['fans'])
        sql('ALTER TABLE wp_fans_pf_b6br_requests ENGINE=MyISAM')
        check('B6br nontransactional checkpoint metadata is refused', call('recover') == dict(error='pf_local_barrier_primary_schema'))
        sql('ALTER TABLE wp_fans_pf_b6br_requests ENGINE=InnoDB')
        closing = dict(fields, operation='close',action_id=str(uuid.uuid4()),object={name:ref[name] for name in ('barrier_key','version','content_sha256')}|dict(reason='session_cancelled'))
        queued = local['prepare'](closing)
        if queued.get('state') != 'closing':
            raise RuntimeError('Local close preparation failed: '+queued.get('error','missing state'))
        value = call('read')
        if 'error' in value:
            raise RuntimeError('Primary read after local close failed: '+value['error'])
        check('B6br local closing always prevails over a still active primary read', value['local_state'] == 'closing' and value['result']['state'] == 'active')
        assert local['advance'](closing)['state'] == 'acknowledged'; call('prepare')
        assert call('advance',endpoint=local['endpoint'])['phase'] == 'current'
        check('B6br fresh lookup exposes primary closure, never restores the registration',
              call('read')['result']['state'] == 'closed' and call('read')['local_state'] == 'closed')
        check('B6br arbitrary nonloopback endpoint is denied before HTTP',
              call('advance',endpoint='https://example.invalid').get('error') == 'pf_fixture_peer_required')
        fields, ref = fresh(); action = fields['action_id']; origin = fields['origin_id']; call('prepare')
        digest = hashlib.sha256(local['recover'](fields)['operation_key'].encode()).hexdigest()
        hub_sql('DELETE FROM wp_token_engine_pf_b3b_operations WHERE operation='+literal('register')+' AND key_sha256='+literal(digest))
        before = events(); absent = call('advance',endpoint=local['endpoint'])
        check('B6br primary not found closes delivery without fallback registration or key',
              absent['phase'] == 'refused' and call('read') == dict(error='pf_local_barrier_primary_read_required')
              and call('advance',endpoint=local['endpoint'])['phase'] == 'refused' and events() == before)
        check('B6br primary reads preserve the official ledger and add no PF ledger',
              hub_sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id') == ledger and sql("SHOW TABLES LIKE 'wp_fans%ledger%'") == '')
    finally:
        network['policy']('fans',network['policies']['fans']); (root/'barrier-http-fault').unlink(missing_ok=True)
        sql('DROP TABLE IF EXISTS wp_barrier_primary_probe')
