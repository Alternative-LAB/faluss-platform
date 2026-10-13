"""B3 owner barrier metadata on a real disposable primary. No new economic write."""
import copy
import datetime
import hashlib
import json
import os
import secrets
import signal
import time
import uuid


def run_checks(root, wp, check, call, sql, start, finish, parallel, await_file):
    prefix = 'wp_token_engine_pf_b3b_'
    historic = sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')
    consumptions = sql('SELECT * FROM wp_token_engine_pf_h2c_consumptions ORDER BY attribution_id')
    journal = sql('SELECT * FROM wp_token_engine_pf_h2c_journal ORDER BY event_id')
    legacy_receipts = sql('SELECT * FROM wp_token_engine_pf_h3_receipts ORDER BY attribution_id')
    alb = sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')
    check('B3b bootstrap installs no barrier tables', sql("SHOW TABLES LIKE '" + prefix + "%'") == '')
    lease = root / 'h3-lease'
    lease.chmod(0o644)
    check('B3b copied constants cannot bypass the physical private lease',
          call('b3b-ready') == dict(error='isolated_h3_recipe_required'))
    lease.chmod(0o600)
    check('B3b explicit gate alone never installs metadata', call('b3b-ready') == dict(ready=False))
    sql('CREATE TABLE ' + prefix + 'operations (id INT NOT NULL) ENGINE=InnoDB')
    check('B3b partial schema is refused without adoption', call('b3b-install') == dict(error='model_schema_divergent'))
    sql('DROP TABLE ' + prefix + 'operations')
    check('B3b explicit atomic installation verifies four InnoDB metadata tables',
          call('b3b-install') == dict(ready=True) and len(sql("SHOW TABLES LIKE '" + prefix + "%'").splitlines()) == 4)
    check('B3b repeated install is idempotent', call('b3b-install') == dict(ready=True))
    sql('ALTER TABLE ' + prefix + 'events ENGINE=MyISAM')
    check('B3b unsafe engine closes operations', call('b3b-ready') == dict(ready=False)
          and call('b3b-install') == dict(error='model_schema_divergent'))
    sql('ALTER TABLE ' + prefix + 'events ENGINE=InnoDB')

    now = datetime.datetime.fromisoformat(sql('SELECT UTC_TIMESTAMP(6)'))
    stamp = lambda value: value.strftime('%Y-%m-%d %H:%M:%S.%f')
    lower, upper = stamp(now - datetime.timedelta(hours=1)), stamp(now + datetime.timedelta(days=1))

    def fixture():
        context = dict(origin_id=str(uuid.uuid4()), policy_version='1.0.0', creator_category='arts',
                       category_revision='2', country_policy_revision=str(uuid.uuid4()), sessions=[
                           dict(session_id=str(uuid.uuid4()), rules_revision='2', rules_sha256='e' * 64,
                                admission_revision='3', barrier_version='1', starts_at=lower, ends_at=upper,
                                admitted_at=lower, scope='international', territory_policy_revision='',
                                territory_admission_revision='0', country='', territory_ref='')])
        payload = dict(attribution_id=str(uuid.uuid4()), client_authority='fixture.fans',
                       member_faluss_id=str(uuid.uuid4()), creator_faluss_id=str(uuid.uuid4()),
                       purchased_pf='40', policy_version='1.0.0', ranking_context=context,
                       context_sha256=hashlib.sha256(json.dumps(context, separators=(',', ':'), sort_keys=True).encode()).hexdigest())
        refs = call('b3b-refs', payload=payload)['references']
        descriptors = [dict(content=ref['content'], valid_from=lower, valid_until=upper) for ref in refs]
        return payload, refs, descriptors

    def count():
        return tuple(int(sql('SELECT COUNT(*) FROM ' + prefix + table)) for table in ('barriers','operations','events'))

    def close_ref(ref):
        return {name: ref[name] for name in ('barrier_key','version','content_sha256')} | dict(reason='session_suspended')

    payload, refs, descriptors = fixture()
    check('B3b canonical selection contains origin country creator session and admission exactly once',
          len(refs) == 5 and sorted(ref['content']['kind'] for ref in refs) == ['admission','country','creator','origin','session']
          and [ref['barrier_key'] for ref in refs] == sorted(ref['barrier_key'] for ref in refs))
    check('B3b unregistered context cannot be selected', call('b3b-check', payload=payload) == dict(error='pf_barrier_not_admitted'))
    before = count()
    check('B3b permission and owner are enforced before any write',
          call('b3b-register', payload=descriptors[0], key=secrets.token_hex(32), permissions=[]) == dict(error='pf_permission_denied')
          and call('b3b-register', payload=descriptors[0], key=secrets.token_hex(32), peer='fixture.other') == dict(error='pf_invalid_peer')
          and count() == before)
    key = secrets.token_hex(32)
    results = parallel([dict(action='b3b-register', payload=descriptors[0], key=key)] * 8)
    registered = results[0]
    check('B3b eight registrations with one stable key write one barrier operation and audit event',
          all(result == registered for result in results) and registered['state'] == 'active'
          and count() == tuple(value + 1 for value in before))
    check('B3b primary lookup returns original effective instant',
          call('b3b-lookup', operation='register', payload=descriptors[0], key=key) == registered)
    changed = copy.deepcopy(descriptors[0]); changed['valid_until'] = stamp(now + datetime.timedelta(days=2))
    # For session/admission, preserve their frozen end while changing content itself.
    if changed['content']['kind'] in ('session','admission'):
        changed['content']['ends_at'] = changed['valid_until']
    check('B3b a stable key binds the full canonical descriptor',
          call('b3b-register', payload=changed, key=key) == dict(error='pf_barrier_key_conflict'))
    check('B3b a new key cannot circumvent an already admitted version',
          call('b3b-register', payload=descriptors[0], key=secrets.token_hex(32)) == dict(error='pf_barrier_stable_key_or_version_required'))
    check('B3b lookup itself requires the dedicated permission and lookup permission',
          call('b3b-lookup', operation='register', payload=descriptors[0], key=key,
               permissions=['pf.ranking.context.register']) == dict(error='pf_permission_denied'))
    for descriptor in descriptors[1:]:
        assert call('b3b-register', payload=descriptor, key=secrets.token_hex(32))['state'] == 'active'
    check('B3b selection locks every exact current dimension inside the official owner transaction',
          call('b3b-check', payload=payload)['checked'] is True)
    check('B3b primary clock expiration refuses all selected dimensions',
          call('b3b-check', payload=payload, clock_offset=172800) == dict(error='pf_barrier_not_current'))

    session_ref = next(ref for ref in refs if ref['content']['kind'] == 'session')
    request, close_key = close_ref(session_ref), secrets.token_hex(32)
    before = count()
    check('B3b close needs its own permission and never inherits confirm rights',
          call('b3b-close', payload=request, key=close_key, permissions=['pf.confirm']) == dict(error='pf_permission_denied')
          and count() == before)
    marker, release = root / uuid.uuid4().hex, root / uuid.uuid4().hex
    holder = start(dict(action='b3b-hold', payload=payload, marker=str(marker), release=str(release)))
    await_file(marker, [holder])
    closer = start(dict(action='b3b-close', payload=request, key=close_key))
    time.sleep(.3)
    check('B3b concurrent close waits for the selected owner transaction without an inverse economic lock', closer.poll() is None)
    release.touch()
    held, closed = finish(holder), finish(closer)
    check('B3b effective close follows the primary selection instant and writes one audit operation',
          held['checked'] and closed['state'] == 'closed' and closed['effective_at'] >= held['confirmed_at']
          and count() == (before[0], before[1] + 1, before[2] + 1))
    check('B3b closed session refuses selection without rewriting historical economics',
          call('b3b-check', payload=payload) == dict(error='pf_barrier_not_admitted'))
    check('B3b concurrent close replays preserve one effective instant',
          all(result == closed for result in parallel([dict(action='b3b-close', payload=request, key=close_key)] * 6)))
    descriptor = next(value for value in descriptors if value['content']['kind'] == 'session')
    check('B3b a closed version cannot be reopened with a fresh key',
          call('b3b-register', payload=descriptor, key=secrets.token_hex(32)) == dict(error='pf_barrier_stable_key_or_version_required'))
    revised = copy.deepcopy(descriptor); revised['content']['version'] = '3'
    check('B3b version gaps are rejected',
          call('b3b-register', payload=revised, key=secrets.token_hex(32)) == dict(error='pf_barrier_stable_key_or_version_required'))
    revised['content']['version'] = '2'
    next_key = secrets.token_hex(32)
    check('B3b next version is a new immutable admission after effective close',
          call('b3b-register', payload=revised, key=next_key)['state'] == 'active')
    check('B3b old acknowledgement reports superseded and cannot resurrect a closed version',
          call('b3b-lookup', operation='close', payload=request, key=close_key)['state'] == 'superseded'
          and sql("SELECT state FROM " + prefix + "barriers WHERE barrier_key='" + session_ref['barrier_key'] + "' AND version=1") == 'closed')

    for fault in ('b3b-event-insert','commit-unknown','before-commit','after-commit'):
        _, fresh_refs, fresh = fixture()
        before = count(); operation_key = secrets.token_hex(32)
        if fault.endswith('commit'):
            marker = root / uuid.uuid4().hex
            process = start(dict(action='b3b-register', payload=fresh[0], key=operation_key, fault=fault, marker=str(marker)))
            await_file(marker, [process]); os.killpg(process.pid, signal.SIGKILL); process.wait(timeout=10)
        else:
            result = call('b3b-register', payload=fresh[0], key=operation_key, fault=fault, marker='')
            expected = 'pf_barrier_commit_unknown' if fault == 'commit-unknown' else 'h2_storage_unavailable'
            check('B3b injected ' + fault + ' is reported honestly', result == dict(error=expected))
        lookup = call('b3b-lookup', operation='register', payload=fresh[0], key=operation_key)
        committed = fault in ('commit-unknown','after-commit')
        check('B3b primary lookup resolves ' + fault + ' without a new key',
              (lookup.get('state') == 'active' if committed else lookup == dict(state='not_found'))
              and count() == (tuple(value + 1 for value in before) if committed else before))
        if committed:
            check('B3b confirmed ' + fault + ' replay adds no row',
                  call('b3b-register', payload=fresh[0], key=operation_key) == lookup and count() == tuple(value + 1 for value in before))

    # The close ACK loss is distinct from a registration loss and remains durably closed.
    _, fresh_refs, fresh = fixture()
    registration_key, operation_key = secrets.token_hex(32), secrets.token_hex(32)
    assert call('b3b-register', payload=fresh[0], key=registration_key)['state'] == 'active'
    request = close_ref(fresh_refs[0]); before = count()
    check('B3b unknown close result never claims rollback',
          call('b3b-close', payload=request, key=operation_key, fault='commit-unknown', marker='') == dict(error='pf_barrier_commit_unknown'))
    check('B3b primary lookup resolves a committed closure and old registration reports closed',
          call('b3b-lookup', operation='close', payload=request, key=operation_key)['state'] == 'closed'
          and call('b3b-lookup', operation='register', payload=fresh[0], key=registration_key)['state'] == 'closed'
          and count() == (before[0], before[1] + 1, before[2] + 1))
    check('B3b metadata leaves every official ledger historical receipt consumption journal and ALB byte identical',
          sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id') == historic
          and sql('SELECT * FROM wp_token_engine_pf_h2c_consumptions ORDER BY attribution_id') == consumptions
          and sql('SELECT * FROM wp_token_engine_pf_h2c_journal ORDER BY event_id') == journal
          and sql('SELECT * FROM wp_token_engine_pf_h3_receipts ORDER BY attribution_id') == legacy_receipts
          and sql('SELECT * FROM wp_token_engine_ledger ORDER BY id') == alb)
    from b3_barrier_context_checks import run_checks as context_checks
    context_checks(root,check,call,sql,start,finish,parallel,await_file,fixture,close_ref)

    from b3_barrier_admission_checks import run_checks as admission_checks
    admission_checks(root,check,call,sql,start,finish,parallel,await_file,fixture)

    return fixture,close_ref
