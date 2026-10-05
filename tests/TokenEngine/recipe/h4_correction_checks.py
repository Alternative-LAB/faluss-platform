"""Closed H4 real owner transactions; fictional source proofs and private data."""
import os
import secrets
import signal
import uuid


def run_checks(root, wp, check, call, sql, start, finish, parallel, await_file):
    prefix = 'wp_token_engine_pf_h4_'
    old_max = sql('SELECT COALESCE(MAX(id),0) FROM wp_token_engine_pf_ledger')
    old_pf = sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')
    old_alb = sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')
    config = wp / 'wp-config.php'
    config.write_text(config.read_text().replace('$table_prefix=', "define('FALUSS_PF_H4_RECIPE',true);\n$table_prefix="))

    def credit(quantity='100', member=None):
        proof = dict(contract='hub.purchased-pf.h1-model/1.0.0', authority_id='fixture.purchase',
                     purchase_id='synthetic.' + uuid.uuid4().hex, source_revision='1', evidence_id=str(uuid.uuid4()),
                     member_faluss_id=member or str(uuid.uuid4()), purchased_pf=quantity, bonus_pf='50',
                     cancelled_purchased_pf_cumulative='0', state='confirmed',
                     confirmed_at='2026-10-01T10:00:00Z', observed_at='2026-10-05T10:00:00Z', policy_version='1.0.0')
        lot = call('h1-evidence', payload=proof, key=secrets.token_hex(32))['lot_id']
        entry = call('h2-admit', lot_id=lot, member=proof['member_faluss_id'], key=secrets.token_hex(32))['ledger_entry_uuid']
        return proof, lot, entry

    def intent(member, quantity):
        return dict(attribution_id=str(uuid.uuid4()), client_authority='fixture.fans', member_faluss_id=member,
                    creator_faluss_id=str(uuid.uuid4()), purchased_pf=quantity, policy_version='1.0.0')

    def consume(member, quantity):
        data = intent(member, quantity)
        assert call('h2-reserve', payload=data, key=secrets.token_hex(32))['state'] == 'reserved'
        assert call('h2c-confirm', payload=data, key=secrets.token_hex(32))['state'] == 'confirmed'
        return data

    def revision(proof, number, cancelled, state=None):
        return dict(proof, source_revision=str(number), evidence_id=str(uuid.uuid4()),
                    cancelled_purchased_pf_cumulative=str(cancelled), state=state or
                    ('cancelled' if str(cancelled) == proof['purchased_pf'] else 'partially_cancelled' if cancelled else 'confirmed'))

    def begin(proof, key=None, **extra):
        key = key or secrets.token_hex(32)
        return key, call('h4-begin', payload=proof, key=key, **extra)

    def resume(proof, plan, fragment, **extra):
        return call('h4-resume', member=proof['member_faluss_id'], plan_id=plan['plan_id'], fragment=str(fragment), **extra)

    def complete(proof, plan):
        result = plan
        for fragment in range(int(plan['next_fragment']) + 1, int(plan['fragment_count']) + 1):
            result = resume(proof, plan, fragment)
        assert result['state'] == 'complete', result
        return result

    def state(data, lot):
        return sql("SELECT original_pf,cancelled_pf,suspended_pf,net_pf FROM " + prefix + "states WHERE attribution_id='"
                   + data['attribution_id'] + "' AND lot_id='" + lot + "'").split('\t')

    def count():
        return int(sql("SELECT COUNT(*) FROM wp_token_engine_pf_ledger WHERE source_event_reference LIKE 'fixture.h4.%'"))

    check('H4 bootstrap creates no correction tables or guards',
          sql("SHOW TABLES LIKE 'wp_token_engine_pf_h4_%'") == '' and call('h4-ready') == dict(ready=False))
    sql('CREATE TABLE ' + prefix + 'plans (id INT NOT NULL) ENGINE=InnoDB')
    check('H4 partial schema refuses repair', call('h4-install') == dict(error='model_schema_divergent'))
    sql('DROP TABLE ' + prefix + 'plans')
    check('H4 explicit isolated schema and recipe guard are verified', call('h4-install') == dict(ready=True)
          and call('h4-ready') == dict(ready=True) and call('h4-install') == dict(ready=True))

    proof, lot, entry = credit()
    member = proof['member_faluss_id']
    first, second = consume(member, '25'), consume(member, '15')
    original = sql("SELECT * FROM wp_token_engine_pf_ledger WHERE faluss_id='" + member + "' ORDER BY id")
    pending = intent(member, '10')
    call('h2-reserve', payload=pending, key=secrets.token_hex(32))
    corrected = revision(proof, 2, 30)
    key, plan = begin(corrected)
    check('H4 available PF first debits 30 and releases outstanding reservation',
          plan['state'] == 'reconciling' and plan['available_cancelled_pf'] == '30' and plan['allocated_cancelled_pf'] == '0'
          and call('balances', member)['funded'] == 30
          and sql("SELECT state FROM wp_token_engine_pf_h2_reservations WHERE attribution_id='" + pending['attribution_id'] + "'") == 'released')
    check('H4 reconciling lot cannot reserve or confirm old unconsumed intent',
          'error' in call('h2-reserve', payload=intent(member, '1'), key=secrets.token_hex(32))
          and call('h2c-confirm', payload=pending, key=secrets.token_hex(32)) == dict(error='h2_reservation_closed'))
    done = complete(corrected, plan)
    check('H4 completed available cancellation preserves both confirmed allocations',
          state(first, lot) == ['25', '0', '0', '25'] and state(second, lot) == ['15', '0', '0', '15'])
    before = count()
    check('H4 correction replay and primary lookup keep the same plan and ledger references',
          call('h4-begin', payload=corrected, key=key) == done
          and call('h4-lookup', member=member, key=key) == done and resume(corrected, plan, 1) == done and count() == before)
    check('H4 new key cannot replace committed correction and foreign member cannot recover it',
          begin(corrected)[1] == dict(error='stable_key_required')
          and call('h4-lookup', member=str(uuid.uuid4()), key=key) == dict(state='not_found'))
    check('H4 same correction key rejects changed source payload',
          call('h4-begin', payload=dict(corrected, cancelled_purchased_pf_cumulative='31'), key=key)
          == dict(error='idempotency_or_attribution_conflict'))
    # The remaining admissible PF really pass through H2, never a synthetic new ledger.
    extra = consume(member, '10')
    check('H4 net available remainder can be reserved and consumed through the official owner', call('balances', member)['funded'] == 20)
    # Original product example on a fresh lot, without the extra attribution.
    proof, lot, entry = credit()
    member = proof['member_faluss_id']
    first, second = consume(member, '25'), consume(member, '15')
    r2 = revision(proof, 2, 80)
    key, plan = begin(r2)
    done = complete(r2, plan)
    check('H4 cumulative 80 cancels available 60 then latest 15 then older 5',
          state(second, lot) == ['15', '15', '0', '0'] and state(first, lot) == ['25', '5', '0', '20']
          and call('balances', member)['funded'] == 0)
    r3 = revision(r2, 3, 90)
    key3, plan3 = begin(r3)
    complete(r3, plan3)
    check('H4 cumulative 90 applies only ten additional allocated units', state(first, lot) == ['25', '15', '0', '10']
          and sql("SELECT SUM(restore_pf) FROM " + prefix + "fragments WHERE plan_id='" + plan3['plan_id'] + "'") == '10')
    check('H4 stale source revision and cancelled cumulative regression refuse mutations',
          'error' in begin(r2)[1] and 'error' in begin(revision(r3, 4, 89))[1])
    disputed = revision(r3, 4, 90, 'disputed')
    _, dispute_plan = begin(disputed)
    complete(disputed, dispute_plan)
    check('H4 dispute suspends only uncancelled net without ledger effect', state(first, lot) == ['25', '15', '10', '0']
          and call('balances', member)['funded'] == 0)
    resolved = revision(disputed, 5, 90)
    _, resolution_plan = begin(resolved)
    complete(resolved, resolution_plan)
    check('H4 newer authenticated resolution restores ten net units, never cancelled thirty', state(first, lot) == ['25', '15', '0', '10'])
    terminal = revision(resolved, 6, 100)
    _, terminal_plan = begin(terminal)
    complete(terminal, terminal_plan)
    check('H4 terminal cancellation cannot become confirmed', 'error' in begin(revision(terminal, 7, 0, 'confirmed'))[1])
    check('H4 original H2 rows and all historical PF and ALB bytes are untouched',
          sql('SELECT * FROM wp_token_engine_pf_ledger WHERE id<=' + old_max + ' ORDER BY id') == old_pf
          and sql('SELECT * FROM wp_token_engine_ledger ORDER BY id') == old_alb)
    # The old public server compensation must not provide a second correction path.
    check('H4 closed guard refuses full legacy compensation of new provenanced entry',
          'error' in call('compensate', original=entry, event='fixture.forbidden.full.correction'))
    historical_member = str(uuid.uuid4())
    call('hub', historical_member)
    historical_entry = sql("SELECT entry_uuid FROM wp_token_engine_pf_ledger WHERE faluss_id='" + historical_member + "'")
    check('H4 guard preserves historical earned claim and full compensation',
          'error' not in call('compensate', original=historical_entry, event='fixture.allowed.historical.correction')
          and call('balances', historical_member)['earned'] == 0)

    proof, lot, _ = credit()
    r2 = revision(proof, 2, 20)
    key = secrets.token_hex(32)
    contenders = parallel([dict(action='h4-begin', payload=r2, key=key)] * 6)
    check('H4 six concurrent same-key corrections commit one plan and one available debit',
          all(result == contenders[0] for result in contenders) and contenders[0]['state'] == 'complete'
          and call('balances', proof['member_faluss_id'])['funded'] == 80)
    proof, lot, _ = credit()
    decisions = parallel([dict(action='h4-begin', payload=revision(proof, 2, 20), key=secrets.token_hex(32)),
                          dict(action='h4-begin', payload=revision(proof, 3, 30), key=secrets.token_hex(32))])
    check('H4 concurrent source revisions converge on the highest cumulative correction without duplicate debit',
          sql("SELECT source_revision FROM wp_token_engine_pf_h1_lots WHERE lot_id='" + lot + "'") == '3'
          and call('balances', proof['member_faluss_id'])['funded'] == 70
          and sum('error' not in result for result in decisions) in (1, 2))
    proof, lot, _ = credit()
    member = proof['member_faluss_id']
    pending = intent(member, '60')
    call('h2-reserve', payload=pending, key=secrets.token_hex(32))
    correction = revision(proof, 2, 50)
    confirm_result, correction_result = parallel([dict(action='h2c-confirm', payload=pending, key=secrets.token_hex(32)),
                                                 dict(action='h4-begin', payload=correction, key=secrets.token_hex(32))])
    complete(correction, correction_result)
    check('H4 confirmation versus correction has one serialized admissible outcome and no negative balance',
          (confirm_result.get('state') == 'confirmed' and call('balances', member)['funded'] == 0
           and state(pending, lot) == ['60', '10', '0', '50'])
          or ('error' in confirm_result and call('balances', member)['funded'] == 50))
    proof, lot, _ = credit('50')
    other, other_lot, _ = credit('50', proof['member_faluss_id'])
    combined = consume(proof['member_faluss_id'], '75')
    _, partial = begin(revision(proof, 2, 25))
    complete(revision(proof, 2, 25), partial)
    # FIFO order may put either synthetic lot first; the other lot is immutable in both cases.
    other_original = sql("SELECT purchased_pf FROM wp_token_engine_pf_h2_allocations WHERE attribution_id='"
                         + combined['attribution_id'] + "' AND lot_id='" + other_lot + "'")
    check('H4 partial cancellation of a multi-lot attribution leaves its foreign lot unchanged',
          sql("SELECT COUNT(*) FROM " + prefix + "states WHERE lot_id='" + other_lot + "'") == '0'
          and other_original in ('25', '50'))
    proof, lot, _ = credit()
    r2 = revision(proof, 2, 20)
    key, error = begin(r2, fault='h4-fragment-insert', marker=str(root / 'unused'))
    check('H4 journal insertion failure rolls back source revision, plan and available debit',
          error == dict(error='h2_storage_unavailable')
          and call('h4-lookup', member=proof['member_faluss_id'], key=key) == dict(state='not_found')
          and sql("SELECT source_revision FROM wp_token_engine_pf_h1_lots WHERE lot_id='" + lot + "'") == '1'
          and call('balances', proof['member_faluss_id'])['funded'] == 100)
    lost = begin(r2, key, fault='lost-commit-ack', marker=str(root / 'unused'))[1]
    recovered = call('h4-lookup', member=proof['member_faluss_id'], key=key)
    check('H4 lost COMMIT acknowledgement is unknown then primary lookup recovers without second effect',
          lost == dict(error='h2_commit_unknown') and recovered['state'] == 'complete'
          and call('h4-begin', payload=r2, key=key) == recovered and call('balances', proof['member_faluss_id'])['funded'] == 80)
    proof, lot, _ = credit()
    consume(proof['member_faluss_id'], '25')
    sql("DELETE FROM wp_token_engine_pf_h2c_journal WHERE consumption_id IN (SELECT consumption_id FROM wp_token_engine_pf_h2c_consumptions WHERE member_faluss_id='" + proof['member_faluss_id'] + "')")
    _, damaged = begin(revision(proof, 2, 80))
    check('H4 incomplete filiation persists review_required and invents no debit', damaged['state'] == 'review_required'
          and call('balances', proof['member_faluss_id'])['funded'] == 75
          and 'error' in call('h2-reserve', payload=intent(proof['member_faluss_id'], '1'), key=secrets.token_hex(32)))

    proof, lot, _ = credit('101')
    member = proof['member_faluss_id']
    seeded = call('h4-seed-many', member=member, count=101)
    assert len(seeded['attributions']) == 101
    r2 = revision(proof, 2, 101)
    key, plan = begin(r2)
    check('H4 more than one hundred real consumptions produce two bounded stable fragments', plan['fragment_count'] == '2'
          and plan['state'] == 'reconciling' and plan['allocated_cancelled_pf'] == '101')
    check('H4 out-of-order fragment cannot skip the durable checkpoint', resume(r2, plan, 2) == dict(error='h4_fragment_out_of_order'))
    marker = root / uuid.uuid4().hex
    process = start(dict(action='h4-resume', member=member, plan_id=plan['plan_id'], fragment='1', fault='before-commit', marker=str(marker)))
    await_file(marker, [process]); os.killpg(process.pid, signal.SIGKILL); process.wait(timeout=10)
    check('H4 process killed before fragment COMMIT rolls back states ledger pair and checkpoint',
          call('h4-lookup', member=member, key=key)['next_fragment'] == '0'
          and sql("SELECT COUNT(*) FROM " + prefix + "states WHERE plan_id='" + plan['plan_id'] + "'") == '0')
    marker = root / uuid.uuid4().hex
    process = start(dict(action='h4-resume', member=member, plan_id=plan['plan_id'], fragment='1', fault='after-commit', marker=str(marker)))
    await_file(marker, [process]); os.killpg(process.pid, signal.SIGKILL); process.wait(timeout=10)
    partial = call('h4-lookup', member=member, key=key)
    before = count()
    check('H4 process killed after fragment COMMIT retains one hundred cumulative states and same-key replay is inert',
          partial['next_fragment'] == '1' and partial['state'] == 'reconciling'
          and sql("SELECT COUNT(*) FROM " + prefix + "states WHERE plan_id='" + plan['plan_id'] + "'") == '100'
          and resume(r2, plan, 1) == partial and count() == before)
    check('H4 pending source successor cannot replace an incomplete plan',
          begin(revision(r2, 3, 101))[1] == dict(error='h4_reconciliation_incomplete'))
    finished = resume(r2, plan, 2)
    check('H4 recovery after crash between fragments verifies complete totals and net zero',
          finished['state'] == 'complete' and finished['next_fragment'] == '2'
          and sql("SELECT SUM(cancelled_pf),SUM(net_pf) FROM " + prefix + "states WHERE lot_id='" + lot + "'") == '101\t0'
          and call('balances', member)['funded'] == 0)
    check('H4 fragment journal contains deterministic cumulative evidence without actual Events delivery',
          sql("SELECT COUNT(*),MAX(delivery_state) FROM " + prefix + "fragments WHERE plan_id='" + plan['plan_id'] + "'") == '3\tpending')
    check('H4 existing historical ledger and ALB rows remain byte identical after every closed scenario',
          sql('SELECT * FROM wp_token_engine_pf_ledger WHERE id<=' + old_max + ' ORDER BY id') == old_pf
          and sql('SELECT * FROM wp_token_engine_ledger ORDER BY id') == old_alb)
