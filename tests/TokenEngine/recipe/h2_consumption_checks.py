"""Actual closed H2b transactions, concurrent consumption and durable recovery."""
import os
import secrets
import signal
import uuid


def run_checks(root, wp, check, call, sql, start, finish, parallel, await_file):
    prefix = 'wp_token_engine_pf_h2c_'
    reserved = 'wp_token_engine_pf_h2_'
    historical_max = sql('SELECT COALESCE(MAX(id),0) FROM wp_token_engine_pf_ledger')
    historical = sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')
    alb = sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')

    def credit(member=None, quantity='100'):
        data = dict(contract='hub.purchased-pf.h1-model/1.0.0', authority_id='fixture.purchase',
                    purchase_id='synthetic.' + uuid.uuid4().hex, source_revision='1', evidence_id=str(uuid.uuid4()),
                    member_faluss_id=member or str(uuid.uuid4()), purchased_pf=quantity, bonus_pf='50',
                    cancelled_purchased_pf_cumulative='0', state='confirmed',
                    confirmed_at='2026-10-01T10:00:00Z', observed_at='2026-10-05T10:00:00Z', policy_version='1.0.0')
        lot = call('h1-evidence', payload=data, key=secrets.token_hex(32))['lot_id']
        entry = call('h2-admit', lot_id=lot, member=data['member_faluss_id'], key=secrets.token_hex(32))['ledger_entry_uuid']
        return data, lot, entry

    def intent(member, quantity='60', **extra):
        return dict(dict(attribution_id=str(uuid.uuid4()), client_authority='fixture.fans',
                         member_faluss_id=member, creator_faluss_id=str(uuid.uuid4()),
                         purchased_pf=quantity, policy_version='1.0.0'), **extra)

    def reserve(data):
        key = secrets.token_hex(32)
        result = call('h2-reserve', payload=data, key=key)
        assert result['state'] == 'reserved'
        return key, result

    def confirm(data, key, **extra):
        return call('h2c-confirm', payload=data, key=key, **extra)

    def lookup(data, key, operation='confirm'):
        return call('h2c-lookup', payload=data, key=key, operation=operation)

    def counts():
        return dict(consumptions=int(sql('SELECT COUNT(*) FROM ' + prefix + 'consumptions')),
                    journal=int(sql('SELECT COUNT(*) FROM ' + prefix + 'journal')),
                    debits=int(sql("SELECT COUNT(*) FROM wp_token_engine_pf_ledger WHERE direction='debit'")))

    def state(data):
        return sql("SELECT state FROM " + reserved + "reservations WHERE attribution_id='" + data['attribution_id'] + "'")

    def expire(data):
        sql("UPDATE " + reserved + "reservations SET expires_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 SECOND) WHERE attribution_id='" + data['attribution_id'] + "'")

    def killed(data, key, fault):
        marker = root / uuid.uuid4().hex
        process = start(dict(action='h2c-confirm', payload=data, key=key, fault=fault, marker=str(marker)))
        await_file(marker, [process])
        os.killpg(process.pid, signal.SIGKILL)
        process.wait(timeout=10)

    check('H2b no consumption schema is installed by bootstrap or H2a',
          sql("SHOW TABLES LIKE 'wp_token_engine_pf_h2c_%'") == '' and call('h2c-ready') == dict(ready=False))
    sql('CREATE TABLE ' + prefix + 'journal (id INT NOT NULL) ENGINE=InnoDB')
    check('H2b partial consumption schema is refused without repair',
          call('h2c-install') == dict(error='model_schema_divergent'))
    sql('DROP TABLE ' + prefix + 'journal')
    check('H2b explicit isolated installation atomically creates three verified tables',
          call('h2c-install') == dict(ready=True)
          and len(sql("SHOW TABLES LIKE 'wp_token_engine_pf_h2c_%'").splitlines()) == 3)
    check('H2b repeated installation is inert with zero consumption or delivery rows',
          call('h2c-install') == dict(ready=True) and counts()['consumptions'] == counts()['journal'] == 0)

    proof, lot, credit_entry = credit()
    member = proof['member_faluss_id']
    first, second = intent(member), intent(member, '40')
    reserve_key, reservation = reserve(first)
    reserve(second)
    confirm_key = secrets.token_hex(32)
    before = counts()
    contenders = parallel([dict(action='h2c-confirm', payload=first, key=confirm_key)] * 8)
    confirmed = contenders[0]
    check('H2b eight concurrent same-key confirmations return one immutable consumption',
          all(r == confirmed for r in contenders) and confirmed['state'] == 'confirmed'
          and counts() == dict(consumptions=before['consumptions'] + 1, journal=before['journal'] + 1, debits=before['debits'] + 1))
    check('H2b official debit exact purchased quantity and class preserve the remaining reservation',
          call('balances', member) == dict(earned=0, funded=40, promotional=0) and state(second) == 'reserved'
          and confirmed['consumption']['purchased_pf'] == '60'
          and confirmed['consumption']['allocations'] == reservation['allocations'])
    check('H2b consumes member units without crediting any creator wallet or PC',
          call('balances', first['creator_faluss_id']) == dict(earned=0, funded=0, promotional=0)
          and call('inspect')['generic_count'] == 0)
    check('H2b journal is atomically pending without signed receipt or announced Events delivery',
          confirmed['kind'] == 'closed_h2_consumption' and confirmed['delivery_state'] == 'pending'
          and 'signature' not in confirmed and 'receipt' not in confirmed)
    immutable = sql('SELECT * FROM ' + prefix + 'consumptions ORDER BY consumption_id')
    journal = sql('SELECT * FROM ' + prefix + 'journal ORDER BY event_id')
    check('H2b same-key replay and lookup preserve exact consumption journal and debit references',
          confirm(first, confirm_key) == confirmed and lookup(first, confirm_key) == confirmed
          and lookup(first, reserve_key, 'reserve') == confirmed
          and sql('SELECT * FROM ' + prefix + 'consumptions ORDER BY consumption_id') == immutable
          and sql('SELECT * FROM ' + prefix + 'journal ORDER BY event_id') == journal)
    before = counts()
    check('H2b a new confirmation key cannot bypass timeout or debit a confirmed attribution',
          confirm(first, secrets.token_hex(32)) == dict(error='stable_key_required') and counts() == before)
    check('H2b confirmed consumption cannot be released or expired back into availability',
          call('h2-release', payload=first, key=secrets.token_hex(32)) == dict(error='h2_reservation_closed'))
    expire(first)
    check('H2b primary recovery keeps a committed consumption after the reserve deadline',
          lookup(first, confirm_key) == confirmed and confirm(first, confirm_key) == confirmed)
    confirm(second, secrets.token_hex(32))
    check('H2b second explicit attribution consumes remaining provenance exactly once',
          call('balances', member)['funded'] == 0
          and call('h2-reserve', payload=intent(member, '1'), key=secrets.token_hex(32)) == dict(error='model_quantity_unavailable'))
    check('H2b changed creator quantity member client policy or foreign key cannot recover another result',
          all('error' in lookup(dict(first, **change), confirm_key) for change in
              [dict(creator_faluss_id=str(uuid.uuid4())), dict(purchased_pf='59'),
               dict(member_faluss_id=str(uuid.uuid4())), dict(policy_version='2.0.0')])
          and lookup(dict(first, client_authority='fixture.other'), confirm_key)['state'] == 'not_found'
          and lookup(first, secrets.token_hex(32))['state'] == 'not_found')

    # Multiple distinct keys racing for one attribution produce one winner,
    # not eight valid consumption attempts.
    proof, _, _ = credit()
    data = intent(proof['member_faluss_id'])
    reserve(data)
    keys = [secrets.token_hex(32) for _ in range(8)]
    results = parallel([dict(action='h2c-confirm', payload=data, key=k) for k in keys])
    check('H2b concurrent different keys for one attribution commit one debit and reject replacements',
          sum(r.get('state') == 'confirmed' for r in results) == 1
          and all(r.get('state') == 'confirmed' or r.get('error') == 'stable_key_required' for r in results)
          and call('balances', proof['member_faluss_id'])['funded'] == 40)

    proof, _, _ = credit()
    member = proof['member_faluss_id']
    first, second = intent(member, '50'), intent(member, '50')
    reserve(first)
    reserve(second)
    before = counts()
    results = parallel([dict(action='h2c-confirm', payload=d, key=secrets.token_hex(32)) for d in (first, second)])
    check('H2b distinct reserved attributions consume exactly their allocated units concurrently',
          all(r.get('state') == 'confirmed' for r in results) and call('balances', member)['funded'] == 0
          and counts()['debits'] == before['debits'] + 2)
    call('hub', member)
    call('me', member)
    check('H2b historical Hub and Me claims stay earned cumulative and independent after consumption',
          call('balances', member) == dict(earned=95, funded=0, promotional=0)
          and not call('hub', member)['claimed_now'] and not call('me', member)['claimed_now'])

    proof, _, _ = credit()
    data = intent(proof['member_faluss_id'])
    reserve(data)
    results = parallel([dict(action='h2c-confirm', payload=data, key=secrets.token_hex(32)),
                        dict(action='h2-release', payload=data, key=secrets.token_hex(32))])
    committed = results[0].get('state') == 'confirmed'
    check('H2b confirmation versus release has one terminal winning order',
          (committed and results[1].get('error') == 'h2_reservation_closed' and state(data) == 'confirmed')
          or (not committed and results[0].get('error') == 'h2_reservation_closed'
              and results[1].get('state') == 'released' and state(data) == 'released'))
    check('H2b release race never refunds a committed debit or double consumes',
          call('balances', proof['member_faluss_id'])['funded'] == (40 if committed else 100))
    proof, _, _ = credit()
    data = intent(proof['member_faluss_id'])
    reserve(data)
    expire(data)
    before = counts()
    check('H2b expired reserve cannot debit or journal even with concurrent confirmations',
          all(r.get('error') == 'h2_reservation_closed' for r in
              parallel([dict(action='h2c-confirm', payload=data, key=secrets.token_hex(32))] * 8)) and counts() == before)

    proof, _, _ = credit()
    data = intent(proof['member_faluss_id'])
    reserve(data)
    before = counts()
    check('H2b expiration during the guarded final transition rolls back earlier debit and journal',
          confirm(data, secrets.token_hex(32), fault='h2c-transition-expiry', marker=str(root / 'unused')) == dict(error='h2_reservation_closed')
          and counts() == before and state(data) == 'reserved'
          and call('balances', proof['member_faluss_id'])['funded'] == 100)

    for new_state, cancelled in [('disputed', '0'), ('partially_cancelled', '1'), ('cancelled', '100')]:
        proof, _, _ = credit()
        data = intent(proof['member_faluss_id'])
        reserve(data)
        updated = dict(proof, evidence_id=str(uuid.uuid4()), source_revision='2', state=new_state,
                       cancelled_purchased_pf_cumulative=cancelled)
        call('h1-evidence', payload=updated, key=secrets.token_hex(32))
        before = counts()
        check('H2b revalidates ' + new_state + ' source before consuming without inventing H4 corrections',
              confirm(data, secrets.token_hex(32)) == dict(error='h2_source_changed') and counts() == before
              and call('balances', proof['member_faluss_id'])['funded'] == 100)
    proof, _, _ = credit()
    data = intent(proof['member_faluss_id'])
    reserve(data)
    updated = dict(proof, evidence_id=str(uuid.uuid4()), source_revision='2', state='disputed')
    key = secrets.token_hex(32)
    results = parallel([dict(action='h2c-confirm', payload=data, key=key),
                        dict(action='h1-evidence', payload=updated, key=secrets.token_hex(32))])
    committed = results[0].get('state') == 'confirmed'
    check('H2b source revision versus confirm serializes with the H1 owner mutex',
          results[1].get('state') == 'model_recorded'
          and (committed or results[0].get('error') == 'h2_source_changed')
          and call('balances', proof['member_faluss_id'])['funded'] == (40 if committed else 100))

    for fault in ('h2c-record-insert', 'h2c-journal-insert', 'h2-key-insert'):
        proof, _, _ = credit()
        data = intent(proof['member_faluss_id'])
        reserve(data)
        key = secrets.token_hex(32)
        before = counts()
        check('H2b failure ' + fault + ' atomically rolls back debit record journal state and key',
              confirm(data, key, fault=fault, marker=str(root / 'unused')) == dict(error='h2_storage_unavailable')
              and counts() == before and state(data) == 'reserved'
              and lookup(data, key)['state'] == 'not_found' and call('balances', proof['member_faluss_id'])['funded'] == 100)
        check('H2b exact retry after ' + fault + ' consumes once', confirm(data, key)['state'] == 'confirmed')

    for fault in ('before-commit', 'after-commit'):
        proof, _, _ = credit()
        data = intent(proof['member_faluss_id'])
        reserve_key, _ = reserve(data)
        key = secrets.token_hex(32)
        before = counts()
        killed(data, key, fault)
        found = lookup(data, key)
        committed = fault == 'after-commit'
        check('H2b process killed ' + fault + ' has atomic primary consumption and pending journal outcome',
              found['state'] == ('confirmed' if committed else 'not_found')
              and counts() == dict(consumptions=before['consumptions'] + int(committed),
                                   journal=before['journal'] + int(committed), debits=before['debits'] + int(committed))
              and state(data) == ('confirmed' if committed else 'reserved'))
        recovered = confirm(data, key)
        check('H2b same-key recovery ' + fault + ' never debits twice even via original reservation lookup',
              recovered['state'] == 'confirmed' and lookup(data, reserve_key, 'reserve') == recovered
              and call('balances', proof['member_faluss_id'])['funded'] == 40
              and counts()['debits'] == before['debits'] + 1)
    proof, _, _ = credit()
    data = intent(proof['member_faluss_id'])
    reserve(data)
    key = secrets.token_hex(32)
    before = counts()
    check('H2b injected lost COMMIT acknowledgement is unknown rather than false failure',
          confirm(data, key, fault='lost-commit-ack', marker=str(root / 'unused')) == dict(error='h2_commit_unknown'))
    found = lookup(data, key)
    check('H2b primary lookup and stable retry recover debit and journal after ambiguous commit',
          found['state'] == 'confirmed' and confirm(data, key) == found
          and counts()['debits'] == before['debits'] + 1 and counts()['journal'] == before['journal'] + 1)
    check('H2b a replacement key after ambiguous commit is refused without economic effect',
          confirm(data, secrets.token_hex(32)) == dict(error='stable_key_required') and counts()['debits'] == before['debits'] + 1)

    event = found['consumption']['event_id']
    sql("UPDATE " + prefix + "journal SET payload_sha256='" + '0' * 64 + "' WHERE event_id='" + event + "'")
    check('H2b corrupt journal digest fails lookup closed without disclosing another record',
          lookup(data, key) == dict(error='h2_integrity_failure'))
    sql("UPDATE " + prefix + "journal SET payload_sha256=SHA2(payload_json,256) WHERE event_id='" + event + "'")
    entry = found['consumption']['ledger_entry_uuid']
    sql("UPDATE wp_token_engine_pf_ledger SET amount_pf=61 WHERE entry_uuid='" + entry + "'")
    check('H2b corrupt official debit linkage is rejected rather than used as a receipt',
          lookup(data, key) == dict(error='h2_ledger_filiation_failure'))
    sql("UPDATE wp_token_engine_pf_ledger SET amount_pf=60 WHERE entry_uuid='" + entry + "'")
    check('H2b restored immutable fixture links allow the same stable recovery', lookup(data, key) == found)

    # More than 32 lots cannot silently consume a prefix or merge provenance.
    member = str(uuid.uuid4())
    for _ in range(33):
        credit(member, '1')
    before = counts()
    check('H2b reservation spanning more than 32 lots is entirely refused',
          call('h2-reserve', payload=intent(member, '33'), key=secrets.token_hex(32)) == dict(error='model_allocation_limit')
          and counts() == before and call('balances', member)['funded'] == 33)
    data = intent(member, '32')
    _, plan = reserve(data)
    check('H2b 32-lot limit consumes exact allocations with one official debit',
          len(plan['allocations']) == 32 and confirm(data, secrets.token_hex(32))['state'] == 'confirmed'
          and call('balances', member)['funded'] == 1)

    sql('ALTER TABLE ' + prefix + 'journal DROP INDEX consumption')
    check('H2b missing journal uniqueness blocks consumption and lookup without repair',
          call('h2c-ready') == dict(ready=False) and lookup(data, secrets.token_hex(32)) == dict(error='h2_consumption_schema_unavailable')
          and call('h2c-install') == dict(error='model_schema_divergent'))
    sql('ALTER TABLE ' + prefix + 'journal ADD UNIQUE KEY consumption (consumption_id)')
    check('H2b preserves every earlier official ledger row and complete ALB ledger byte',
          sql('SELECT * FROM wp_token_engine_pf_ledger WHERE id<=' + historical_max + ' ORDER BY id') == historical
          and sql('SELECT * FROM wp_token_engine_ledger ORDER BY id') == alb
          and sql("SELECT option_value FROM wp_options WHERE option_name='token_engine_schema_version'") == '5')
