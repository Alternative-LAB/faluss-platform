"""Real owner transactions: delegation expiry behind locks and at the commit boundary."""
import pathlib
import secrets
import time
import uuid


def run_checks(root, check, call, sql, start, finish, await_file, proof, fixture, fresh):
    prefix = 'wp_token_engine_pf_b3r_'

    def window(seconds=60):
        now = int(time.time())
        return dict(fresh_from=time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime(now)),
                    fresh_until=time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime(now+seconds)))

    def state(data):
        attribution = data['attribution_id']
        # Compare owner persistence, including keys/order and all receipt/journal side effects.
        tables = [prefix+'bindings', prefix+'receipts', prefix+'journal',
                  'wp_token_engine_pf_h2_reservations', 'wp_token_engine_pf_h2_allocations',
                  'wp_token_engine_pf_h2c_consumptions', 'wp_token_engine_pf_h3_receipts']
        return (sql('SELECT * FROM '+prefix+'counter'),
                sql("SELECT * FROM wp_token_engine_pf_ledger WHERE faluss_id='"+data['member_faluss_id']+"' ORDER BY id"),
                sql('SELECT COUNT(*) FROM wp_token_engine_pf_h2_keys'),
                sql('SELECT COUNT(*) FROM wp_token_engine_pf_h2c_journal'),
                *(sql("SELECT * FROM "+table+" WHERE attribution_id='"+attribution+"'") for table in tables))

    def input_for(operation):
        if operation == 'reserve':
            evidence, _ = proof()
            data, refs = fixture(evidence['member_faluss_id'])
            key = secrets.token_hex(32)
        else:
            data, refs, _, key, _, _ = fresh()
        inputs = dict(action='b3r-'+operation,payload=data,key=key)
        if operation == 'lookup':
            inputs['operation'] = 'confirm'
        return inputs, refs

    for operation in ('reserve','confirm','release','lookup'):
        inputs, _ = input_for(operation); before = state(inputs['payload'])
        check('B3f expired delegation refuses '+operation+' before changing owner state',
              call(**inputs,**window(-1)) == dict(error='pf_context_expired') and state(inputs['payload']) == before)
        answer = call(**inputs,**window())
        check('B3f fresh delegation authorizes '+operation+' with its original key',
              answer['state'] == {'reserve':'reserved','confirm':'confirmed','release':'released','lookup':'not_found'}[operation])

    # Each operation must revalidate after the real owner global mutex, including known lookups.
    for operation in ('reserve','confirm','release','lookup'):
        inputs, _ = input_for(operation)
        holder, _ = input_for('lookup'); marker = root / uuid.uuid4().hex; wait = root / uuid.uuid4().hex
        before = state(inputs['payload'])
        held = start(dict(holder,fault='b3o-hold-global',marker=str(marker)))
        await_file(marker,[held])
        pending = start(dict(inputs,**window(2),observe=True,wait_marker=str(wait)))
        await_file(wait,[held,pending]); time.sleep(2.2); was_waiting = pending.poll() is None
        pathlib.Path(str(marker)+'.release').touch(); finish(held); answer = finish(pending)
        check('B3f '+operation+' expiry while waiting for owner mutex fails closed',
              was_waiting and answer == dict(error='pf_context_expired') and state(inputs['payload']) == before)

    # Counter and barrier row waits occur after the first freshness check inside the transaction.
    for kind,table in [('counter','token_engine_pf_b3r_counter'),('barrier','token_engine_pf_b3b_barriers')]:
        inputs, refs = input_for('confirm'); before = state(inputs['payload'])
        marker = root / uuid.uuid4().hex; wait = root / uuid.uuid4().hex
        held = start(dict(action='b3r-hold-row',row_kind=kind,row_key=refs[0]['barrier_key'],marker=str(marker)))
        await_file(marker,[held])
        pending = start(dict(inputs,**window(2),observe=True,row_wait_marker=str(wait),row_wait_table=table))
        await_file(wait,[held,pending]); time.sleep(2.2); was_waiting = pending.poll() is None
        pathlib.Path(str(marker)+'.release').touch(); finish(held); answer = finish(pending)
        check('B3f expiry behind '+kind+' row rolls back before the official debit',
              was_waiting and answer == dict(error='pf_context_expired') and state(inputs['payload']) == before)

    # A stalled last idempotency write must not let earlier debit/receipt staging commit after expiry.
    for operation in ('reserve','confirm','release'):
        inputs, _ = input_for(operation); before = state(inputs['payload']); marker = root / uuid.uuid4().hex
        pending = start(dict(inputs,**window(3),fault='b3r-hold-tail',marker=str(marker)))
        await_file(marker,[pending]); time.sleep(3.2); pathlib.Path(str(marker)+'.release').touch()
        check('B3f '+operation+' expiry at final write rolls back every staged effect',
              finish(pending) == dict(error='pf_context_expired') and state(inputs['payload']) == before)
        answer = call(**inputs,**window())
        check('B3f fresh '+operation+' retry reuses the same key after definite rollback',
              answer['state'] == {'reserve':'reserved','confirm':'confirmed','release':'released'}[operation])

    inputs, _ = input_for('confirm'); before = int(sql('SELECT last_order FROM '+prefix+'counter'))
    check('B3f lost acknowledgement after real commit remains uncertain',
          call(**inputs,**window(),fault='commit-unknown') == dict(error='h2_commit_unknown'))
    lookup = dict(inputs,action='b3r-lookup',operation='confirm')
    recovered = call(**lookup,**window()); replayed = call(**inputs,**window())
    check('B3f fresh lookup and retry recover the one committed receipt and order',
          recovered['state'] == 'confirmed' and replayed == recovered
          and int(sql('SELECT last_order FROM '+prefix+'counter')) == before+1)
