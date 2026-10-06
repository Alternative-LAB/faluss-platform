"""Private persistent projections from real reconciled H4 transport in two disposable WP/DBs.

Fictitious proofs/identities/keys only, no public API or score UI. No receipt is a projection command.
"""
import concurrent.futures
import hashlib
import json
import os
import re
import secrets
import signal
import subprocess
import time
import urllib.error
import urllib.request
import uuid


def run_checks(root, wp, source, cli_path, check, call, sql, command, workers, log):
    fans = root / 'fans'
    data = json.loads((root / 'h4-sessions.json').read_text())
    member_a, creator_a = data['identities']['fan'], data['identities']['creator']
    member_b, creator_b = str(uuid.uuid4()), str(uuid.uuid4())
    endpoint = (root / 'snapshot-endpoint').read_text()

    def fan_sql(query):
        return command(['mariadb', '--no-defaults', '--socket=' + str(root / 'sql.sock'), '-uroot',
                        '--batch', '--skip-column-names', 'fans_pf_recipe', '-e', query]).strip()

    def start(data, projection=True, role='fans'):
        file = root / (uuid.uuid4().hex + '.json')
        file.write_text(json.dumps(data)); file.chmod(0o600)
        worker_path = 'tests/Fans/PfProjection/recipe/worker.php' if projection else 'tests/TokenEngine/recipe/h4-network-worker.php'
        process = subprocess.Popen(['php', cli_path, '--allow-root', '--path=' + str(fans if role == 'fans' else wp),
                                    'eval-file', str(source / worker_path), str(file), '--use-include'],
                                   stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, start_new_session=True)
        workers.append(process)
        return process

    def finish(process):
        output, error = process.communicate(timeout=40)
        if process.returncode: raise RuntimeError('F1a private fixture worker failed; no private data printed.')
        return json.loads(output)

    def worker(data, projection=True, role='fans'): return finish(start(data, projection, role))

    def await_file(path, processes):
        deadline = time.monotonic() + 25
        while not path.exists():
            if any(process.poll() is not None for process in processes) or time.monotonic() > deadline:
                raise RuntimeError('F1a controlled interleaving unavailable.')
            time.sleep(.02)

    def exchange(member, read_id, progress):
        fields = dict(operation=progress['phase'], read_id=read_id, member_faluss_id=member,
                      snapshot_id=(progress['manifest'] or {}).get('snapshot_id', ''), cursor=progress['next_cursor'] or '')
        request = worker(dict(action='sign', fields=fields, key=progress['read_key']), False)
        try:
            response = urllib.request.urlopen(urllib.request.Request(endpoint, data=request['wire'].encode(),
                headers={'Content-Type': 'application/json'}, method='POST'), timeout=25)
        except urllib.error.HTTPError as error: response = error
        with response:
            if response.status != 200: raise RuntimeError('Private H4 fixture transport refused.')
            wire = response.read().decode()
        return dict(action='accept', wire=wire, fields=fields, nonce=request['nonce'],
                    digest=hashlib.sha256(request['wire'].encode()).hexdigest())

    def pull(member, read_id=None):
        read_id = read_id or str(uuid.uuid4())
        for _ in range(8):
            progress = worker(dict(action='prepare', member=member, read_id=read_id), False)
            packet = exchange(member, read_id, progress)
            answer = worker(packet, False)
            if answer.get('state') == 'current': return packet
            if 'error' in answer or answer.get('state') == 'refused': raise RuntimeError('Private H4 checkpoint incomplete.')
        raise RuntimeError('Private H4 bounded pages exhausted.')

    def credit(member, quantity):
        proof = dict(contract='hub.purchased-pf.h1-model/1.0.0', authority_id='fixture.purchase',
                     purchase_id='synthetic.' + uuid.uuid4().hex, source_revision='1', evidence_id=str(uuid.uuid4()),
                     member_faluss_id=member, purchased_pf=str(quantity), bonus_pf='50',
                     cancelled_purchased_pf_cumulative='0', state='confirmed', confirmed_at='2026-10-01T10:00:00Z',
                     observed_at='2026-10-06T10:00:00Z', policy_version='1.0.0')
        lot = call('h1-evidence', payload=proof, key=secrets.token_hex(32))['lot_id']
        call('h2-admit', lot_id=lot, member=member, key=secrets.token_hex(32))
        return proof, lot

    def attribute(member, creator, quantity):
        intent = dict(attribution_id=str(uuid.uuid4()), client_authority='fixture.fans', member_faluss_id=member,
                      creator_faluss_id=creator, purchased_pf=str(quantity), policy_version='1.0.0')
        assert call('h2-reserve', payload=intent, key=secrets.token_hex(32))['state'] == 'reserved'
        confirmed = call('h2c-confirm', payload=intent, key=secrets.token_hex(32))
        assert confirmed['state'] == 'confirmed'
        return intent, confirmed

    def correct(proof, revision, cancelled, state):
        changed = dict(proof, source_revision=str(revision), evidence_id=str(uuid.uuid4()),
                       cancelled_purchased_pf_cumulative=str(cancelled), state=state)
        plan = call('h4-begin', payload=changed, key=secrets.token_hex(32))
        assert 'error' not in plan
        assert 'error' not in call('h4-resume', member=proof['member_faluss_id'], plan_id=plan['plan_id'], fragment='1')
        return changed

    def totals():
        state = worker(dict(action='read'))
        return state, {row['faluss_id']: row['points'] for row in state.get('fans', [])}, {
            row['faluss_id']: row['points'] for row in state.get('creators', [])}

    def cache_bytes():
        return [fan_sql('SELECT * FROM wp_fans_pf_f1a_' + kind + ' ORDER BY ' + order)
                for kind, order in [('generation', 'id'), ('attributions', 'attribution_id'), ('fans', 'faluss_id'), ('creators', 'faluss_id')]]

    def ledger_bytes():
        return sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id'), sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')

    check('F1a ordinary Fans bootstrap installs no projection and marker alone is not automatic DDL',
          fan_sql("SHOW TABLES LIKE 'wp_fans_pf_f1a_%'") == ''
          and worker(dict(action='install')) == dict(error='isolated_f1a_recipe_required'))
    config = fans / 'wp-config.php'
    config.write_text(config.read_text().replace('$table_prefix=', "define('FALUSS_FANS_F1A_RECIPE',true);\n$table_prefix="))
    check('F1a explicit marker still leaves schema absent until explicit recipe installation',
          worker(dict(action='ready')) == dict(ready=False) and fan_sql("SHOW TABLES LIKE 'wp_fans_pf_f1a_%'") == '')
    fan_sql('CREATE TABLE wp_fans_pf_f1a_generation (id INT NOT NULL) ENGINE=InnoDB')
    check('F1a partial schema fails closed without implicit repair', worker(dict(action='install')) == dict(error='model_schema_divergent'))
    fan_sql('DROP TABLE wp_fans_pf_f1a_generation')
    check('F1a five additive tables are explicitly installed verified and reinstall is inert',
          worker(dict(action='install')) == worker(dict(action='install')) == dict(ready=True)
          and len(fan_sql("SHOW TABLES LIKE 'wp_fans_pf_f1a_%'").splitlines()) == 5)
    check('F1a absent generation is unavailable rather than fabricated zero score',
          worker(dict(action='read')) == dict(error='pf_projection_not_reconciled'))
    preserved = ledger_bytes()
    first = worker(dict(action='rebuild'))
    state, fans_points, creators_points = totals()
    check('F1a first persistent Fan and Creator projections use latest corrected H4 net independently',
          first['state'] == 'attested' and fans_points == {member_a: '81'} and creators_points == {creator_a: '81'}
          and fan_sql('SELECT COUNT(*) FROM wp_fans_pf_f1a_attributions') == '101')
    before = cache_bytes()
    check('F1a repeated rebuild and read are deterministic with no debit or duplicate attribution',
          worker(dict(action='rebuild')) == first and cache_bytes() == before and ledger_bytes() == preserved)
    check('F1a unexpected epoch cannot read or rebuild an apparently valid generation',
          worker(dict(action='rebuild', epoch=str(uuid.uuid4()))) == dict(error='pf_snapshot_incomplete'))

    proof_one, lot_one = credit(member_b, 12)
    # A historic claim and the non-admitted pack bonus must not enter the purchased-PF source.
    call('hub', member_b)
    pull(member_b)
    check('F1a a newly reconciled member invalidates old generation before any next projection read',
          worker(dict(action='read')) == dict(error='pf_projection_not_reconciled'))
    worker(dict(action='rebuild'))
    state, fans_points, creators_points = totals()
    check('F1a pack alone bonus and historical earned PF yield zero Fan score and no invented Creator',
          fans_points == {member_a: '81', member_b: '0'} and creators_points == {creator_a: '81'}
          and call('balances', member_b)['earned'] > 0)
    proof_two, lot_two = credit(member_b, 8)
    multi_intent, multi = attribute(member_b, creator_b, 15)
    check('F1a fixture consumption really spans two owner lots with exactly one official debit',
          len(multi['consumption']['allocations']) == 2
          and sql("SELECT COUNT(*) FROM wp_token_engine_pf_ledger WHERE entry_uuid='" + multi['consumption']['ledger_entry_uuid'] + "'") == '1')
    attribute(member_b, creator_a, 5)
    old_packet = pull(member_b)
    preserved = ledger_bytes()
    worker(dict(action='rebuild'))
    state, fans_points, creators_points = totals()
    check('F1a multiple Fans Creators and multi-lot legs produce one attribution and separate exact aggregates',
          fans_points == {member_a: '81', member_b: '20'} and creators_points == {creator_a: '86', creator_b: '15'}
          and fan_sql('SELECT COUNT(*) FROM wp_fans_pf_f1a_attributions') == '103' and ledger_bytes() == preserved)

    # Incomplete refresh never keeps claiming the previous generation is exact.
    read_id = str(uuid.uuid4())
    progress = worker(dict(action='prepare', member=member_b, read_id=read_id), False)
    check('F1a pending H4 refresh makes both read and rebuild unavailable before all pages and primary fence',
          worker(dict(action='read')) == worker(dict(action='rebuild')) == dict(error='pf_reconciled_set_unavailable'))
    packet = exchange(member_b, read_id, progress)
    assert worker(packet, False)['state'] in ('page', 'finish')
    check('F1a staged page without final Hub attestation is never a partial score',
          worker(dict(action='rebuild')) == dict(error='pf_reconciled_set_unavailable'))
    pull(member_b, read_id)
    worker(dict(action='rebuild'))

    corrected = correct(proof_one, 2, 5, 'partially_cancelled')
    pull(member_b)
    check('F1a correction source advances before projection and stale points are refused',
          worker(dict(action='read')) == dict(error='pf_projection_not_reconciled'))
    before = cache_bytes()
    check('F1a INSERT failure after both dimensions rolls back entire replacement generation',
          worker(dict(action='rebuild', fault='generation-insert', marker=str(root / 'unused'))) == dict(error='pf_projection_storage_unavailable')
          and cache_bytes() == before and worker(dict(action='read')) == dict(error='pf_projection_not_reconciled'))
    worker(dict(action='rebuild'))
    state, fans_points, creators_points = totals()
    check('F1a partial cancellation reduces all concerned projections and only its attested multi-lot leg',
          fans_points[member_b] == '15' and creators_points == {creator_a: '86', creator_b: '10'})

    for revision, source_state, expected in [(3, 'disputed', '8'), (4, 'partially_cancelled', '15')]:
        correct(corrected, revision, 5, source_state)
        pull(member_b)
        worker(dict(action='rebuild'))
        state, fans_points, creators_points = totals()
        check('F1a ' + source_state + ' rebuild uses latest attested net without restoring cancelled points',
              fans_points[member_b] == expected and creators_points[creator_b] == ('3' if source_state == 'disputed' else '10'))
    correct(corrected, 5, 12, 'cancelled')
    pull(member_b)
    worker(dict(action='rebuild'))
    state, fans_points, creators_points = totals()
    check('F1a total first-lot cancellation preserves only other eligible non-cancelled legs',
          fans_points[member_b] == '8' and creators_points == {creator_a: '86', creator_b: '3'})
    correct(proof_two, 2, 8, 'cancelled')
    pull(member_b)
    worker(dict(action='rebuild'))
    state, fans_points, creators_points = totals()
    check('F1a complete cumulative cancellation zeros the Fan and Creator affected without negative points',
          fans_points == {member_a: '81', member_b: '0'} and creators_points == {creator_a: '81', creator_b: '0'})
    now = time.time()
    utc = lambda t: time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime(t))
    delayed = worker(dict(action='alter-response', wire=old_packet['wire'],
                          response=dict(issued_at=utc(now), expires_at=utc(now+60))), False, 'hub')['wire']
    check('F1a older signed completion cannot restore points after total correction',
          worker(dict(old_packet, wire=delayed), False) == dict(error='pf_snapshot_regression')
          and totals()[1][member_b] == '0')
    before = cache_bytes()
    generation = worker(dict(action='rebuild'))
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        results = list(pool.map(lambda _: worker(dict(action='rebuild')), range(4)))
    check('F1a concurrent duplicated or reordered wake-ups reread current facts with one canonical generation',
          all(result == generation for result in results) and cache_bytes() == before)

    for corruption in ['DELETE FROM wp_fans_pf_f1a_generation',
                       "UPDATE wp_fans_pf_f1a_fans SET points=999999 WHERE faluss_id='" + member_b + "'",
                       "UPDATE wp_fans_pf_f1a_creators SET points=999999 WHERE faluss_id='" + creator_b + "'",
                       'DELETE FROM wp_fans_pf_f1a_attributions']:
        fan_sql(corruption)
        check('F1a missing or corrupt derived dimension is refused and rebuilt from H4 alone ' + corruption.split()[0],
              worker(dict(action='read')) == dict(error='pf_projection_not_reconciled')
              and worker(dict(action='rebuild')) == generation and cache_bytes() == before)
    check('F1a lost replacement COMMIT acknowledgement is recovered by current read without additional consumption',
          worker(dict(action='rebuild', fault='lost-commit-ack', marker=str(root / 'unused'))) == dict(error='pf_local_commit_unknown')
          and worker(dict(action='read'))['generation'] == generation['generation'])
    for fault in ('before-commit', 'after-commit'):
        prior = cache_bytes()
        prior_generation = worker(dict(action='read'))['generation']
        # A new complete attestation changes the source vector, not the net quantities.
        pull(member_b)
        marker = root / uuid.uuid4().hex
        process = start(dict(action='rebuild', fault=fault, marker=str(marker)))
        await_file(marker, [process])
        os.killpg(process.pid, signal.SIGKILL); process.wait(timeout=10)
        outcome = worker(dict(action='read'))
        check('F1a real process death ' + fault + ' preserves atomic old-or-new source generation',
              (outcome == dict(error='pf_projection_not_reconciled') and cache_bytes() == prior) if fault == 'before-commit'
              else outcome['generation'] != prior_generation and cache_bytes() != prior)
        recovered = worker(dict(action='rebuild'))
        check('F1a primary recovery ' + fault + ' rereads latest attestation without restoring cancelled points',
              recovered['generation'] != prior_generation and worker(dict(action='rebuild')) == recovered
              and worker(dict(action='read'))['generation'] == recovered['generation'] and totals()[1][member_b] == '0')

    # Actual native-engine source-row AND insertion-gap waits, never timing inferred from a fake DB.
    for new_member in (False, True):
        target = str(uuid.uuid4()) if new_member else member_b
        insertion = None
        if new_member:
            credit(target, 3)
            # Prepare alone does not insert a current member. Stage every page first, then race the real finish.
            read_id = str(uuid.uuid4())
            for _ in range(8):
                progress = worker(dict(action='prepare', member=target, read_id=read_id), False)
                packet = exchange(target, read_id, progress)
                if progress['phase'] == 'finish':
                    insertion = packet
                    break
                assert 'error' not in worker(packet, False)
            assert insertion is not None
        marker = root / uuid.uuid4().hex
        locked = start(dict(action='rebuild', fault='fence-pause', marker=str(marker)))
        await_file(marker, [locked])
        refresher = start(insertion or dict(action='prepare', member=target, read_id=str(uuid.uuid4())), False)
        def source_wait():
            # Use active native-engine evidence independently of information_schema refresh timing.
            # Raw status can contain private SQL: it stays in memory and is never logged or exported.
            status = fan_sql('SHOW ENGINE INNODB STATUS').replace('\\n', '\n')
            if 'TRANSACTIONS' not in status: return False
            active = status.split('TRANSACTIONS', 1)[-1].split('FILE I/O', 1)[0]
            return re.search(r'RECORD LOCKS[^\n]*table `fans_pf_recipe`\.`wp_fans_pf_h4_current`[^\n]*waiting', active) is not None
        deadline = time.monotonic()+15
        while not source_wait():
            if refresher.poll() is not None:
                outcome = finish(refresher)
                raise RuntimeError('F1a source wait absent: checkpoint ' + ('refused' if 'error' in outcome else 'completed') + '.')
            if time.monotonic() > deadline:
                raise RuntimeError('F1a actual native-engine source lock wait absent.')
            time.sleep(.02)
        (root / (marker.name + '.go')).touch()
        assert finish(locked)['state'] == 'attested'
        answer = finish(refresher)
        assert answer.get('state' if new_member else 'phase') == ('current' if new_member else 'start')
        check('F1a H4 ' + ('new-member insertion' if new_member else 'existing-member replacement') + ' waits for full-set projection transaction then invalidates stale read',
              worker(dict(action='read')) == dict(error='pf_projection_not_reconciled' if new_member else 'pf_reconciled_set_unavailable'))
        pull(target)
        worker(dict(action='rebuild'))

    backup = fan_sql("SELECT HEX(rows_json),full_sha256 FROM wp_fans_pf_h4_current WHERE member_faluss_id='" + member_b + "'").split('\t')
    fan_sql("UPDATE wp_fans_pf_h4_current SET full_sha256=REPEAT('0',64) WHERE member_faluss_id='" + member_b + "'")
    check('F1a corrupt supposedly complete source digest cannot be read or projected',
          worker(dict(action='read')) == worker(dict(action='rebuild')) == dict(error='pf_snapshot_incomplete'))
    fan_sql("UPDATE wp_fans_pf_h4_current SET full_sha256='" + backup[1] + "' WHERE member_faluss_id='" + member_b + "'")
    preserved = ledger_bytes()
    worker(dict(action='rebuild'))
    check('F1a never writes official PF or ALB ledger no parallel ledger public rank or financial Creator fields',
          ledger_bytes() == preserved and fan_sql("SHOW TABLES LIKE 'wp_token_engine%'") == ''
          and fan_sql("SHOW TABLES LIKE '%hof%'") == ''
          and [line.split('\t')[0] for line in fan_sql('SHOW COLUMNS FROM wp_fans_pf_f1a_creators').splitlines()] == ['faluss_id', 'points'])
    lease = root / 'h3-lease'; lease.chmod(0o644)
    check('F1a copied closed markers cannot bypass private lease physical isolation', 'error' in worker(dict(action='read')))
    lease.chmod(0o600)
    fan_sql("ALTER TABLE wp_fans_pf_f1a_fans ENGINE=MyISAM")
    check('F1a divergent nontransactional schema blocks reads and explicit installation without repairing production',
          worker(dict(action='read')) == dict(error='pf_projection_schema_unavailable')
          and worker(dict(action='install')) == dict(error='model_schema_divergent'))
    fan_sql('ALTER TABLE wp_fans_pf_f1a_fans ENGINE=InnoDB')
    log.flush()
    logs = ''.join(path.read_text(errors='replace') for path in (root / 'runtime.log', root / 'debug.log', root / 'fans-debug.log') if path.exists())
    check('F1a logs contain no private identities proofs cached generation or keys',
          all(value not in logs for value in [member_a, creator_a, member_b, creator_b, old_packet['wire']])
          and 'payload_base64url' not in logs and 'signature_base64url' not in logs)
