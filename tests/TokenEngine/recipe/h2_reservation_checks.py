"""Closed H2a on the actual Hub, database and isolated synthetic purchase model."""
import os
import secrets
import signal
import uuid


def run_checks(root, wp, check, call, sql, start, finish, parallel, await_file):
    prefix = 'wp_token_engine_pf_h2_'
    config = wp / 'wp-config.php'
    original = config.read_text()
    historical_max = sql('SELECT COALESCE(MAX(id),0) FROM wp_token_engine_pf_ledger')
    historical = sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')
    alb = sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')

    def evidence(member=None, **changes):
        data = dict(contract='hub.purchased-pf.h1-model/1.0.0', authority_id='fixture.purchase',
                    purchase_id='synthetic.' + uuid.uuid4().hex, source_revision='1', evidence_id=str(uuid.uuid4()),
                    member_faluss_id=member or str(uuid.uuid4()), purchased_pf='100', bonus_pf='50',
                    cancelled_purchased_pf_cumulative='0', state='confirmed',
                    confirmed_at='2026-10-01T10:00:00Z', observed_at='2026-10-05T10:00:00Z', policy_version='1.0.0')
        return dict(data, **changes)

    def intent(member, **changes):
        return dict(dict(attribution_id=str(uuid.uuid4()), client_authority='fixture.fans',
                         member_faluss_id=member, creator_faluss_id=str(uuid.uuid4()),
                         purchased_pf='60', policy_version='1.0.0'), **changes)

    def source(data):
        return call('h1-evidence', payload=data, key=secrets.token_hex(32))

    def admit(lot, member, key=None, **extra):
        return call('h2-admit', lot_id=lot, member=member, key=key or secrets.token_hex(32), **extra)

    def credit(member=None, **changes):
        data = evidence(member, **changes)
        model = source(data)
        result = admit(model['lot_id'], data['member_faluss_id'])
        assert result['state'] == 'admitted'
        return data, model['lot_id'], result['ledger_entry_uuid']

    def reserve(data, key=None, **extra):
        return call('h2-reserve', payload=data, key=key or secrets.token_hex(32), **extra)

    def lookup(data, key, operation='reserve'):
        return call('h2-lookup', payload=data, operation=operation, key=key)

    def counts():
        return {kind: int(sql('SELECT COUNT(*) FROM ' + prefix + kind))
                for kind in ('credits', 'reservations', 'allocations', 'keys')}

    def killed(data, fault):
        marker = root / uuid.uuid4().hex
        process = start(dict(data, fault=fault, marker=str(marker)))
        await_file(marker, [process])
        os.killpg(process.pid, signal.SIGKILL)
        process.wait(timeout=10)

    check('H2 ordinary Hub bootstrap creates no reservation tables', sql("SHOW TABLES LIKE 'wp_token_engine_pf_h2_%'") == '')
    # H1 restores its original configuration after its checks. H2 depends on
    # that explicit isolated guard as well as its own separate marker.
    original = original.replace('$table_prefix=', "define('FALUSS_HUB_PF_H1_RECIPE_ONLY',true);\n$table_prefix=")
    config.write_text(original)
    check('H2 explicit installation without isolated marker is refused',
          call('h2-install') == dict(error='isolated_h2_recipe_required'))
    config.write_text(original.replace('$table_prefix=', "define('FALUSS_HUB_PF_H2_RECIPE_ONLY',true);\n$table_prefix="))
    fixture_config = config.read_text()
    check('H2 marker never auto-installs a schema or registers a producer',
          call('h2-ready') == dict(ready=False) and sql("SHOW TABLES LIKE 'wp_token_engine_pf_h2_%'") == '')
    sql('CREATE TABLE ' + prefix + 'credits (id INT NOT NULL) ENGINE=InnoDB')
    check('H2 partial schema refuses installation without repair',
          call('h2-install') == dict(error='model_schema_divergent')
          and len(sql("SHOW TABLES LIKE 'wp_token_engine_pf_h2_%'").splitlines()) == 1)
    sql('DROP TABLE ' + prefix + 'credits')
    check('H2 explicit installation publishes five verified InnoDB tables',
          call('h2-install') == dict(ready=True)
          and len(sql("SHOW TABLES LIKE 'wp_token_engine_pf_h2_%'").splitlines()) == 5)
    check('H2 repeated installation preserves schema v5 and all existing rows',
          call('h2-install') == dict(ready=True)
          and sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id') == historical)
    check('H2 nonfixture sources and clients cannot be admitted',
          call('h2-authority', client_authorities=['real.fans']) == dict(error='fixture_authority_required')
          and call('h2-authority', evidence_authorities=['real.purchase']) == dict(error='fixture_authority_required'))

    member = str(uuid.uuid4())
    data = evidence(member)
    lot = source(data)['lot_id']
    key = secrets.token_hex(32)
    results = parallel([dict(action='h2-admit', lot_id=lot, member=member, key=key)] * 8)
    check('H2 concurrent synthetic admission credits once in the official ledger with one provenance link',
          len({r['ledger_entry_uuid'] for r in results}) == 1 and counts()['credits'] == 1
          and call('balances', member) == dict(earned=0, funded=100, promotional=0))
    check('H2 bonus units are not credited as purchased eligible PF',
          sql("SELECT SUM(amount_pf) FROM wp_token_engine_pf_ledger WHERE faluss_id='" + member + "'") == '100')
    check('H2 a different admission key cannot credit the same lot again',
          admit(lot, member) == dict(error='stable_key_required') and counts()['credits'] == 1)
    check('H2 lot holder and source allowlist are enforced without a credit',
          'error' in admit(lot, str(uuid.uuid4()))
          and 'error' in admit(lot, member, key, evidence_authorities=['fixture.other']))
    uncredited = evidence()
    source(uncredited)
    before = counts()
    check('H2 H1 model plans and proofs alone do not constitute available official PF',
          reserve(intent(uncredited['member_faluss_id'])) == dict(error='model_quantity_unavailable') and counts() == before)
    history_member = sql('SELECT faluss_id FROM wp_token_engine_pf_ledger ORDER BY id LIMIT 1')
    check('H2 historical earned or unfiliated funded balance cannot replace provenance',
          reserve(intent(history_member, purchased_pf='1')) == dict(error='model_quantity_unavailable'))
    pending = evidence(state='pending', confirmed_at=None)
    pending_lot = source(pending)['lot_id']
    check('H2 pending evidence cannot enter the official ledger',
          admit(pending_lot, pending['member_faluss_id']) == dict(error='h2_source_unavailable'))
    check('H2 self attribution and unadmitted clients are refused',
          reserve(intent(member, creator_faluss_id=member)) == dict(error='self_attribution')
          and reserve(intent(member, client_authority='fixture.unadmitted')) == dict(error='model_authority_not_admitted'))

    demand = intent(member)
    reserve_key = secrets.token_hex(32)
    before_balance = call('balances', member)
    contenders = parallel([dict(action='h2-reserve', payload=demand, key=reserve_key)] * 8)
    result = contenders[0]
    check('H2 eight same-key reservations commit one global attribution with exact allocations',
          all(r == result for r in contenders) and result['state'] == 'reserved'
          and sum(int(a['purchased_pf']) for a in result['allocations']) == 60 and counts()['reservations'] == 1)
    check('H2 reservation never debits or produces a score or receipt',
          call('balances', member) == before_balance and 'receipt' not in result)
    interval = sql("SELECT TIMESTAMPDIFF(MICROSECOND,created_at,expires_at) FROM " + prefix + "reservations WHERE attribution_id='" + demand['attribution_id'] + "'")
    check('H2 reservation deadline is exactly 120 real DB seconds and replay never renews it',
          interval == '120000000' and reserve(demand, reserve_key) == result and lookup(demand, reserve_key) == result)
    check('H2 another key after timeout cannot replace or renew an existing attribution',
          reserve(demand) == dict(error='stable_key_required') and counts()['reservations'] == 1)
    check('H2 attribution binds quantity creator member client and policy',
          all('error' in reserve(dict(demand, **change), reserve_key) for change in
              [dict(purchased_pf='59'), dict(creator_faluss_id=str(uuid.uuid4())),
               dict(member_faluss_id=str(uuid.uuid4())), dict(client_authority='fixture.other'), dict(policy_version='2.0.0')]))
    before = counts()
    check('H2 active reserved units prevent oversubscription without partial reservation',
          reserve(intent(member, purchased_pf='41')) == dict(error='model_quantity_unavailable') and counts() == before)
    released_key = secrets.token_hex(32)
    released = call('h2-release', payload=demand, key=released_key)
    check('H2 release returns units once and retains immutable attribution and allocation history',
          released['state'] == 'released' and call('h2-release', payload=demand, key=released_key) == released
          and lookup(demand, reserve_key)['state'] == 'released'
          and reserve(demand, reserve_key)['state'] == 'released')
    check('H2 a new explicit intention can reserve units after certain release',
          reserve(intent(member, purchased_pf='100'))['state'] == 'reserved' and call('balances', member)['funded'] == 100)

    second, _, _ = credit()
    member = second['member_faluss_id']
    different = [intent(member) for _ in range(8)]
    contenders = parallel([dict(action='h2-reserve', payload=i, key=secrets.token_hex(32)) for i in different])
    check('H2 concurrent distinct intentions cannot reserve the same purchased units twice',
          sum(r.get('state') == 'reserved' for r in contenders) == 1
          and all(r.get('state') == 'reserved' or r.get('error') == 'model_quantity_unavailable' for r in contenders))
    expired_demand = different[next(i for i, r in enumerate(contenders) if r.get('state') == 'reserved')]
    sql("UPDATE " + prefix + "reservations SET expires_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 SECOND) WHERE attribution_id='" + expired_demand['attribution_id'] + "'")
    check('H2 expired DB deadline makes units available without increasing official balance',
          reserve(intent(member, purchased_pf='100'))['state'] == 'reserved' and call('balances', member)['funded'] == 100
          and sql("SELECT state FROM " + prefix + "reservations WHERE attribution_id='" + expired_demand['attribution_id'] + "'") == 'expired')

    limits, _, _ = credit(purchased_pf='1000')
    member = limits['member_faluss_id']
    demands = [intent(member, purchased_pf='1') for _ in range(4)]
    for d in demands[:3]:
        reserve(d)
    check('H2 fourth active reservation is refused by per-member bound', reserve(demands[3]) == dict(error='h2_reservation_limit'))
    for d in demands[:3]:
        call('h2-release', payload=d, key=secrets.token_hex(32))
    for _ in range(7):
        d = intent(member, purchased_pf='1')
        reserve(d)
        call('h2-release', payload=d, key=secrets.token_hex(32))
    check('H2 new reservation rate limit counts released attempts but not replays',
          reserve(intent(member, purchased_pf='1')) == dict(error='h2_reservation_limit'))

    fifo_member = str(uuid.uuid4())
    first, lot_a, _ = credit(fifo_member, purchased_pf='30')
    second, lot_b, _ = credit(fifo_member, purchased_pf='40')
    sql("UPDATE " + prefix + "credits SET accepted_at='2026-10-05 10:00:00.000000' WHERE member_faluss_id='" + fifo_member + "'")
    plan = reserve(intent(fifo_member, purchased_pf='50'))
    lowest = min(lot_a, lot_b)
    check('H2 equal admission dates use binary lot UUID FIFO with exact multi-lot total',
          sum(int(a['purchased_pf']) for a in plan['allocations']) == 50
          and next(a['purchased_pf'] for a in plan['allocations'] if a['lot_id'] == lowest) == ('30' if lowest == lot_a else '40'))
    disputed, disputed_lot, _ = credit()
    source(dict(disputed, evidence_id=str(uuid.uuid4()), source_revision='2', state='disputed'))
    check('H2 newly disputed source cannot reserve previously credited units',
          reserve(intent(disputed['member_faluss_id'])) == dict(error='model_quantity_unavailable'))

    # Fail the final key insert after a real official credit or reserve insert.
    failed = evidence()
    failed_lot = source(failed)['lot_id']
    failed_key = secrets.token_hex(32)
    before = counts()
    check('H2 final admission key failure rolls back official credit and provenance together',
          admit(failed_lot, failed['member_faluss_id'], failed_key, fault='h2-key-insert', marker=str(root / 'unused')) == dict(error='h2_storage_unavailable')
          and counts() == before and call('balances', failed['member_faluss_id'])['funded'] == 0)
    for fault in ('before-commit', 'after-commit'):
        data = evidence()
        lot = source(data)['lot_id']
        key = secrets.token_hex(32)
        killed(dict(action='h2-admit', lot_id=lot, member=data['member_faluss_id'], key=key), fault)
        balance = call('balances', data['member_faluss_id'])['funded']
        check('H2 admission process ' + fault + ' preserves atomic credit/link outcome', balance == (0 if fault == 'before-commit' else 100))
        admit(lot, data['member_faluss_id'], key)
        check('H2 admission recovery ' + fault + ' never duplicates official credit',
              call('balances', data['member_faluss_id'])['funded'] == 100)
    data, _, _ = credit()
    demand = intent(data['member_faluss_id'])
    key = secrets.token_hex(32)
    before = counts()
    check('H2 reserve key failure rolls back reservation and allocations together',
          reserve(demand, key, fault='h2-key-insert', marker=str(root / 'unused')) == dict(error='h2_storage_unavailable') and counts() == before)
    killed(dict(action='h2-reserve', payload=demand, key=key), 'before-commit')
    check('H2 killed pre-COMMIT reservation has authoritative primary absence', lookup(demand, key)['state'] == 'not_found')
    killed(dict(action='h2-reserve', payload=demand, key=key), 'after-commit')
    found = lookup(demand, key)
    check('H2 killed post-COMMIT reservation is recovered by same primary key',
          found['state'] == 'reserved' and reserve(demand, key) == found)
    call('h2-release', payload=demand, key=secrets.token_hex(32))
    demand = intent(data['member_faluss_id'])
    key = secrets.token_hex(32)
    check('H2 injected lost COMMIT acknowledgement reports unknown despite durable reservation',
          reserve(demand, key, fault='lost-commit-ack', marker=str(root / 'unused')) == dict(error='h2_commit_unknown')
          and lookup(demand, key)['state'] == 'reserved' and reserve(demand, key)['state'] == 'reserved')

    sql('ALTER TABLE ' + prefix + 'allocations DROP INDEX lot')
    check('H2 missing index fails all owner writes closed without silent repair',
          call('h2-ready') == dict(ready=False) and 'error' in reserve(intent(data['member_faluss_id']))
          and call('h2-install') == dict(error='model_schema_divergent'))
    sql('ALTER TABLE ' + prefix + 'allocations ADD KEY lot (lot_id)')
    config.write_text(fixture_config.replace('"local"', '"production"'))
    check('H2 production environment rejects even a copied recipe marker', call('h2-install') == dict(error='isolated_h1_recipe_required'))
    config.write_text(fixture_config)
    sql('SET GLOBAL read_only=ON')
    check('H2 refuses a nonprimary connection even when the fixture SQL user could write',
          call('h2-ready') == dict(error='primary_required'))
    sql('SET GLOBAL read_only=OFF')
    check('H2 preserves every historical ledger byte and complete ALB ledger',
          sql('SELECT * FROM wp_token_engine_pf_ledger WHERE id<=' + historical_max + ' ORDER BY id') == historical
          and sql('SELECT * FROM wp_token_engine_ledger ORDER BY id') == alb)
