"""B3 atomic ranked consumption on the official Hub ledger, fictitious owner proofs only."""
import base64
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
    prefix = 'wp_token_engine_pf_b3r_'
    old_max = sql('SELECT COALESCE(MAX(id),0) FROM wp_token_engine_pf_ledger')
    historic = sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')
    old_h3 = sql('SELECT * FROM wp_token_engine_pf_h3_receipts ORDER BY attribution_id')
    old_alb = sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')
    check('B3r bootstrap installs no ranked schema', sql("SHOW TABLES LIKE '" + prefix + "%'") == '')
    check('B3r explicit gate does not auto install', call('b3r-ready') == dict(ready=False))
    sql('CREATE TABLE ' + prefix + 'counter (id INT NOT NULL) ENGINE=InnoDB')
    check('B3r partial schema refuses adoption', call('b3r-install') == dict(error='model_schema_divergent'))
    sql('DROP TABLE ' + prefix + 'counter')
    check('B3r explicit installation atomically creates five InnoDB tables and one owner epoch',
          call('b3r-install') == dict(ready=True) and len(sql("SHOW TABLES LIKE '" + prefix + "%'").splitlines()) == 5
          and sql("SELECT last_order,LENGTH(last_confirmed_at) FROM " + prefix + 'counter') == '0\t0')

    def counts():
        return tuple(int(sql('SELECT COUNT(*) FROM ' + table)) for table in
                     ('wp_token_engine_pf_ledger WHERE direction=\'debit\'', 'wp_token_engine_pf_h2c_consumptions',
                      'wp_token_engine_pf_h2c_journal', 'wp_token_engine_pf_h3_receipts',
                      prefix + 'receipts', prefix + 'journal'))

    def bind_counts():
        return tuple(int(sql('SELECT COUNT(*) FROM ' + table)) for table in
                     (prefix + 'bindings','wp_token_engine_pf_h2_reservations','wp_token_engine_pf_h2_allocations'))

    def proof(quantity='100', member=None):
        data = dict(contract='hub.purchased-pf.h1-model/1.0.0', authority_id='fixture.purchase',
                    purchase_id='synthetic.' + uuid.uuid4().hex, source_revision='1', evidence_id=str(uuid.uuid4()),
                    member_faluss_id=member or str(uuid.uuid4()), purchased_pf=quantity, bonus_pf='50',
                    cancelled_purchased_pf_cumulative='0', state='confirmed', confirmed_at='2026-10-01T10:00:00Z',
                    observed_at='2026-10-05T10:00:00Z', policy_version='1.0.0')
        lot = call('h1-evidence', payload=data, key=secrets.token_hex(32))['lot_id']
        assert call('h2-admit', lot_id=lot, member=data['member_faluss_id'], key=secrets.token_hex(32))['lot_id'] == lot
        return data, lot

    def redigest(payload):
        payload['context_sha256'] = hashlib.sha256(json.dumps(payload['ranking_context'],sort_keys=True,separators=(',', ':')).encode()).hexdigest()
        return payload

    def fixture(member=None, quantity='40', sessions=1, admit=True):
        now = datetime.datetime.fromisoformat(sql('SELECT UTC_TIMESTAMP(6)'))
        lower, upper = [(now + offset).strftime('%Y-%m-%d %H:%M:%S.%f') for offset in
                        (-datetime.timedelta(hours=1),datetime.timedelta(days=1))]
        contexts = [dict(session_id=str(uuid.uuid4()), rules_revision='2', rules_sha256='e' * 64,
                         admission_revision='3', barrier_version='1', starts_at=lower, ends_at=upper,
                         admitted_at=lower, scope='international', territory_policy_revision='',
                         territory_admission_revision='0', country='', territory_ref='') for _ in range(sessions)]
        contexts.sort(key=lambda value: value['session_id'])
        context = dict(origin_id=str(uuid.uuid4()),policy_version='1.0.0',creator_category='arts',category_revision='2',
                       country_policy_revision=str(uuid.uuid4()),sessions=contexts)
        payload = redigest(dict(attribution_id=str(uuid.uuid4()),client_authority='fixture.fans',member_faluss_id=member or str(uuid.uuid4()),
                               creator_faluss_id=str(uuid.uuid4()),purchased_pf=quantity,policy_version='1.0.0',ranking_context=context))
        refs = call('b3b-refs',payload=payload)['references']
        if admit:
            for ref in refs:
                assert call('b3b-register',payload=dict(content=ref['content'],valid_from=lower,valid_until=upper),key=secrets.token_hex(32))['state'] == 'active'
        return payload, refs

    def base(payload):
        return {key: value for key, value in payload.items() if key not in ('ranking_context','context_sha256')}

    def receipt(result):
        return json.loads(base64.urlsafe_b64decode(result['receipt']['payload_base64url'] + '==='))

    def fresh(quantity='40', sessions=1):
        evidence, lot = proof()
        payload, refs = fixture(evidence['member_faluss_id'],quantity,sessions)
        reserve_key, confirm_key = secrets.token_hex(32), secrets.token_hex(32)
        assert call('b3r-reserve',payload=payload,key=reserve_key)['state'] == 'reserved'
        return payload, refs, reserve_key, confirm_key, evidence, lot

    evidence, lot = proof()
    payload, refs = fixture(evidence['member_faluss_id'])
    before = bind_counts()
    check('B3r dedicated operational permission and owner are mandatory',
          call('b3r-reserve',payload=payload,key=secrets.token_hex(32),permissions=[]) == dict(error='pf_permission_denied')
          and call('b3r-reserve',payload=payload,key=secrets.token_hex(32),peer='fixture.other') == dict(error='pf_invalid_peer')
          and bind_counts() == before)
    reserve_key, confirm_key = secrets.token_hex(32), secrets.token_hex(32)
    reserved = call('b3r-reserve',payload=payload,key=reserve_key)
    check('B3r binding and official reservation commit together without debit or order',
          reserved['state'] == 'reserved' and bind_counts() == tuple(value + 1 for value in before)
          and sql('SELECT last_order FROM ' + prefix + 'counter') == '0')
    changed = copy.deepcopy(payload); changed['ranking_context']['creator_category'] = 'music'; redigest(changed)
    check('B3r same base identity cannot mutate the bound category or context',
          call('b3r-reserve',payload=changed,key=reserve_key) == dict(error='pf_ranking_context_conflict')
          and call('b3r-confirm',payload=changed,key=confirm_key) == dict(error='pf_ranking_context_conflict'))
    check('B3r old endpoints cannot reserve release confirm or look up a ranked attribution',
          all(call(action,payload=base(payload),key=key,operation='confirm') == dict(error='pf_ranking_context_required')
              for action,key in [('h2-reserve',reserve_key),('h2-release',secrets.token_hex(32)),('h3-confirm',confirm_key),('h3-lookup',confirm_key)]))
    before = counts()
    concurrent = parallel([dict(action='b3r-confirm',payload=payload,key=confirm_key)] * 8)
    confirmed = concurrent[0]; fact = receipt(confirmed)
    check('B3r eight confirmations atomically write one debit consumption two receipts and both pending journals',
          all(value == confirmed for value in concurrent) and confirmed['state'] == 'confirmed'
          and counts() == tuple(value + 1 for value in before) and fact['consumption_order'] == '1')
    check('B3r receipt attests exact context primary six decimals and one official allocation',
          fact['ranking_context'] == payload['ranking_context'] and fact['context_sha256'] == payload['context_sha256']
          and fact['confirmed_at'] == confirmed['consumption']['confirmed_at'] and len(fact['confirmed_at']) == 26
          and len(fact['allocations']) == 1 and fact['allocations'][0]['lot_id'] == lot)
    check('B3r replay and primary lookup retain the same order receipt bytes and debit',
          call('b3r-confirm',payload=payload,key=confirm_key) == confirmed
          and call('b3r-lookup',payload=payload,key=confirm_key,operation='confirm') == confirmed
          and counts() == tuple(value + 1 for value in before))
    check('B3r no replacement confirmation key bypasses an uncertain result',
          call('b3r-confirm',payload=payload,key=secrets.token_hex(32)) == dict(error='stable_key_required'))
    check('B3r known reserve key recovers a confirmed attribution with original order',
          call('b3r-lookup',payload=payload,key=reserve_key,operation='reserve') == confirmed)
    check('B3r a caller cannot allocate an unattested order through the receipt primitive',
          call('b3r-raw-record',payload=payload,order={name: fact[name] for name in ('ordering_epoch','consumption_order','confirmed_at')})
          == dict(error='pf_ranking_order_not_staged'))

    foreign, _ = fixture()
    foreign['attribution_id'] = payload['attribution_id']; redigest(foreign)
    check('B3r another member cannot recover or rewrite the bound attribution',
          call('b3r-lookup',payload=foreign,key=confirm_key,operation='confirm') == dict(error='pf_ranking_context_conflict'))
    # No retroactive attachment of context to a 0.2 reservation, even with a new key.
    proof_old, _ = proof(); old_payload, _ = fixture(proof_old['member_faluss_id'])
    old_key = secrets.token_hex(32); assert call('h2-reserve',payload=base(old_payload),key=old_key)['state'] == 'reserved'
    check('B3r an old reservation cannot acquire ranking context retroactively',
          call('b3r-reserve',payload=old_payload,key=old_key) == dict(error='pf_ranking_retroactive_context_refused'))
    old_confirmed = call('h3-confirm',payload=base(old_payload),key=secrets.token_hex(32))
    check('B3r unrelated historical contract still confirms with its original key',old_confirmed['state'] == 'confirmed')
    check('B3r a raw receipt cannot give an old consumption a ranking context or order',
          call('b3r-raw-record',payload=old_payload,order=dict(ordering_epoch=fact['ordering_epoch'],consumption_order='1',
                                                           confirmed_at=old_confirmed['consumption']['confirmed_at']))
          == dict(error='pf_ranking_retroactive_context_refused'))

    # Distinct owner subjects receive a unique Hub order at an identical authoritative timestamp.
    one, _, _, one_key, _, _ = fresh(); two, _, _, two_key, _, _ = fresh()
    clock = str(int(time.time()) + 1) + '.123456'
    before = counts()
    simultaneous = parallel([dict(action='b3r-confirm',payload=data,key=key,clock_value=clock) for data,key in [(one,one_key),(two,two_key)]])
    ordered = [receipt(value) for value in simultaneous]
    check('B3r distinct members at identical primary timestamps receive unique stable owner orders',
          all(value['state'] == 'confirmed' for value in simultaneous) and ordered[0]['confirmed_at'] == ordered[1]['confirmed_at']
          and sorted(int(value['consumption_order']) for value in ordered) == [2,3]
          and all(value['ordering_epoch'] == fact['ordering_epoch'] for value in ordered)
          and counts() == tuple(value + 2 for value in before))
    time.sleep(1.2)
    # One attribution, multiple lots and multiple selected session dimensions.
    member = str(uuid.uuid4()); proof('25',member); proof('25',member)
    multi, _ = fixture(member,'40',sessions=2)
    call('b3r-reserve',payload=multi,key=secrets.token_hex(32)); before = counts()
    multi_fact = receipt(call('b3r-confirm',payload=multi,key=secrets.token_hex(32)))
    check('B3r two lots and two selected sessions share one consumption debit and Hub order',
          len(multi_fact['allocations']) == 2 and len(multi_fact['ranking_context']['sessions']) == 2
          and sum(int(value['purchased_pf']) for value in multi_fact['allocations']) == 40
          and counts() == tuple(value + 1 for value in before))

    for fault in ('b3r-receipt-insert','b3r-journal-insert','h3-receipt-insert','h2-key-insert'):
        data, _, _, key, _, _ = fresh(); before = counts(); order_before = sql('SELECT last_order FROM ' + prefix + 'counter')
        check('B3r ' + fault + ' rolls back order debit receipt and journals together',
              call('b3r-confirm',payload=data,key=key,fault=fault,marker='') == dict(error='h2_storage_unavailable')
              and counts() == before and sql('SELECT last_order FROM ' + prefix + 'counter') == order_before)
        check('B3r exact retry after ' + fault + ' commits one next order',
              call('b3r-confirm',payload=data,key=key)['state'] == 'confirmed' and counts() == tuple(value + 1 for value in before))
    data, _, _, key, _, _ = fresh(); before = counts(); order_before = sql('SELECT last_order FROM ' + prefix + 'counter')
    check('B3r signing failure rolls back the official economic write and counter',
          call('b3r-confirm',payload=data,key=key,key_id='invalid key') == dict(error='pf_invalid_key')
          and counts() == before and sql('SELECT last_order FROM ' + prefix + 'counter') == order_before)

    evidence_new, _ = proof(); failed, _ = fixture(evidence_new['member_faluss_id']); before = bind_counts()
    check('B3r binding failure leaves no reservation or allocation',
          call('b3r-reserve',payload=failed,key=secrets.token_hex(32),fault='b3r-binding-insert',marker='') == dict(error='h2_storage_unavailable')
          and bind_counts() == before)
    missing, _ = fixture(evidence_new['member_faluss_id'],admit=False); before = bind_counts()
    check('B3r unregistered dimensions refuse the whole reservation and binding',
          call('b3r-reserve',payload=missing,key=secrets.token_hex(32)) == dict(error='pf_barrier_not_admitted')
          and bind_counts() == before)

    data, refs_new, _, key, _, _ = fresh()
    session_ref = next(value for value in refs_new if value['content']['kind'] == 'session')
    closure = {name: session_ref[name] for name in ('barrier_key','version','content_sha256')} | dict(reason='session_cancelled')
    call('b3b-close',payload=closure,key=secrets.token_hex(32)); before = counts()
    check('B3r closure before confirmation rejects the complete selection without debit',
          call('b3r-confirm',payload=data,key=key) == dict(error='pf_barrier_not_admitted') and counts() == before)
    check('B3r release stays possible after closure without changing the binding or economics',
          call('b3r-release',payload=data,key=secrets.token_hex(32))['state'] == 'released' and counts() == before)
    data, refs_new, _, key, _, _ = fresh(); marker = root / uuid.uuid4().hex
    confirmer = start(dict(action='b3r-confirm',payload=data,key=key,fault='b3r-hold-counter',marker=str(marker)))
    await_file(marker,[confirmer]); session_ref = next(value for value in refs_new if value['content']['kind'] == 'session')
    closure = {name: session_ref[name] for name in ('barrier_key','version','content_sha256')} | dict(reason='session_suspended')
    closer = start(dict(action='b3b-close',payload=closure,key=secrets.token_hex(32)))
    time.sleep(.3); waiting = closer.poll() is None; (root / (marker.name + '.release')).touch()
    race_result, closed = finish(confirmer), finish(closer)
    race_fact = receipt(race_result)
    check('B3r confirmation holding barriers precedes an effective concurrent close without deadlock',
          waiting and race_result['state'] == 'confirmed' and closed['state'] == 'closed'
          and closed['effective_at'] >= race_fact['confirmed_at'])
    check('B3r confirmed fact remains recoverable after closure without second debit',
          call('b3r-lookup',payload=data,key=key,operation='confirm') == race_result)
    expired, _, _, expired_key, _, _ = fresh(); before = counts()
    check('B3r expired reservation cannot consume or allocate an order',
          call('b3r-confirm',payload=expired,key=expired_key,clock_offset=121) == dict(error='h2_reservation_closed') and counts() == before)
    # Reuse barriers admitted before the earlier primary timestamp. Newly registered
    # barriers would correctly refuse that clock first as not_current.
    regression = copy.deepcopy(one); regression['attribution_id'] = str(uuid.uuid4()); regression['purchased_pf'] = '10'
    assert call('b3r-reserve',payload=regression,key=secrets.token_hex(32))['state'] == 'reserved'
    regression_key = secrets.token_hex(32); before = counts()
    check('B3r a regressed primary clock cannot invent an earlier attainment timestamp',
          call('b3r-confirm',payload=regression,key=regression_key,clock_value=clock) == dict(error='pf_primary_clock_regression') and counts() == before)

    for fault in ('before-commit','after-commit','commit-unknown'):
        data, _, _, key, _, _ = fresh(); before = counts(); order_before = int(sql('SELECT last_order FROM ' + prefix + 'counter'))
        if fault == 'commit-unknown':
            result = call('b3r-confirm',payload=data,key=key,fault=fault,marker='')
            check('B3r lost COMMIT acknowledgement reports unknown instead of rollback', result == dict(error='h2_commit_unknown'))
        else:
            marker = root / uuid.uuid4().hex
            process = start(dict(action='b3r-confirm',payload=data,key=key,fault=fault,marker=str(marker)))
            await_file(marker,[process]); os.killpg(process.pid,signal.SIGKILL); process.wait(timeout=10)
        lookup = call('b3r-lookup',payload=data,key=key,operation='confirm'); committed = fault != 'before-commit'
        check('B3r primary lookup after ' + fault + ' resolves one atomic order and debit',
              lookup['state'] == ('confirmed' if committed else 'not_found')
              and counts() == (tuple(value + 1 for value in before) if committed else before)
              and int(sql('SELECT last_order FROM ' + prefix + 'counter')) == order_before + int(committed))
        retried = call('b3r-confirm',payload=data,key=key)
        check('B3r stable retry after ' + fault + ' never consumes twice or loses its order',
              retried['state'] == 'confirmed' and (retried == lookup if committed else True)
              and counts() == tuple(value + 1 for value in before))

    # Corrections run through the approved H4 owner, never through the new metadata.
    config = wp / 'wp-config.php'
    if 'FALUSS_PF_H4_RECIPE' not in config.read_text():
        config.write_text(config.read_text().replace('$table_prefix=',"define('FALUSS_PF_H4_RECIPE',true);\n$table_prefix="))
    assert call('h4-install') == dict(ready=True)
    corrected, _, _, correction_key, origin_proof, correction_lot = fresh()
    original = call('b3r-confirm',payload=corrected,key=correction_key); original_fact = receipt(original)
    for number,cancelled,state,expected in [(2,75,'partially_cancelled','25'),(3,90,'partially_cancelled','10'),
                                            (4,90,'disputed','0'),(5,90,'partially_cancelled','10'),(6,100,'cancelled','0')]:
        evidence = dict(origin_proof,source_revision=str(number),evidence_id=str(uuid.uuid4()),cancelled_purchased_pf_cumulative=str(cancelled),state=state)
        correction_id = secrets.token_hex(32); plan = call('h4-begin',payload=evidence,key=correction_id)
        for fragment in range(int(plan['next_fragment'])+1,int(plan['fragment_count'])+1):
            plan = call('h4-resume',member=origin_proof['member_faluss_id'],plan_id=plan['plan_id'],fragment=str(fragment))
        current = sql("SELECT net_pf FROM wp_token_engine_pf_h4_states WHERE attribution_id='" + corrected['attribution_id'] + "' AND lot_id='" + correction_lot + "'")
        check('B3r H4 ' + state + ' revision ' + str(number) + ' changes only corrected net and preserves the original attested fact',
              plan['state'] == 'complete' and current == expected
              and receipt(call('b3r-lookup',payload=corrected,key=correction_key,operation='confirm')) == original_fact)
        assert call('h4-begin',payload=evidence,key=correction_id) == plan
    check('B3r old receipt replay cannot rewrite an H4 total cancellation',
          call('b3r-confirm',payload=corrected,key=correction_key) == original
          and sql("SELECT net_pf FROM wp_token_engine_pf_h4_states WHERE attribution_id='" + corrected['attribution_id'] + "'") == '0')
    check('B3r historical PF claims and generic ALB remain byte identical and old H3 receipts retain their bytes',
          sql('SELECT * FROM wp_token_engine_pf_ledger WHERE id<=' + old_max + ' ORDER BY id') == historic
          and sql('SELECT * FROM wp_token_engine_ledger ORDER BY id') == old_alb
          and all(row in sql('SELECT * FROM wp_token_engine_pf_h3_receipts ORDER BY attribution_id').splitlines() for row in old_h3.splitlines()))

    data, _, _, key, _, _ = fresh(); before = counts(); counter = sql('SELECT last_order FROM ' + prefix + 'counter')
    sql('UPDATE ' + prefix + 'counter SET last_order=last_order+1')
    check('B3r inconsistent owner counter refuses consumption rather than repairing its authority',
          call('b3r-confirm',payload=data,key=key) == dict(error='pf_ranking_counter_divergent') and counts() == before)
    sql('UPDATE ' + prefix + 'counter SET last_order=' + counter)
    original_epoch = sql('SELECT ordering_epoch FROM ' + prefix + 'counter')
    sql("UPDATE " + prefix + "receipts SET ordering_epoch='" + str(uuid.uuid4()) + "' WHERE consumption_order=1")
    check('B3r a foreign epoch in an earlier restored receipt refuses any new debit',
          call('b3r-confirm',payload=data,key=key) == dict(error='pf_ranking_counter_divergent') and counts() == before)
    sql("UPDATE " + prefix + "receipts SET ordering_epoch='" + original_epoch + "' WHERE consumption_order=1")
    sql('UPDATE ' + prefix + 'receipts SET consumption_order=0 WHERE consumption_order=1')
    check('B3r a restored gap cannot be hidden by an equal receipt count and latest order',
          call('b3r-confirm',payload=data,key=key) == dict(error='pf_ranking_counter_divergent') and counts() == before)
    sql('UPDATE ' + prefix + 'receipts SET consumption_order=1 WHERE consumption_order=0')
    sql('UPDATE ' + prefix + 'counter SET last_order=9007199254740991')
    check('B3r exhausted order refuses a debit without rollover or a new epoch',
          call('b3r-confirm',payload=data,key=key) == dict(error='pf_ranking_counter_exhausted') and counts() == before)
    sql('UPDATE ' + prefix + 'counter SET last_order=' + counter)
    sql('ALTER TABLE ' + prefix + 'journal ENGINE=MyISAM')
    check('B3r nontransactional metadata refuses both ranked and legacy mutations',
          call('b3r-confirm',payload=data,key=key) == dict(error='pf_ranking_schema_unavailable')
          and call('h2c-confirm',payload=base(data),key=key) == dict(error='pf_ranking_schema_unavailable') and counts() == before)
    sql('ALTER TABLE ' + prefix + 'journal ENGINE=InnoDB')

    from b3_ranked_freshness_checks import run_checks as freshness_checks
    freshness_checks(root, check, call, sql, start, finish, await_file, proof, fixture, fresh)
    return proof, fixture, fresh
