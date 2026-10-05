"""Closed H1 model checks; all identities and evidence below are fictitious.

No economic writer, HTTP endpoint, receipt, reservation or score is exercised.
Only run.py's private, disposable Unix-socket WordPress fixture is admitted.
"""
import hashlib
import os
import secrets
import signal
import uuid


def evidence(member=None, **changes):
    return dict(dict(contract='hub.purchased-pf.h1-model/1.0.0', authority_id='fixture.purchase',
                     purchase_id='synthetic.' + uuid.uuid4().hex, source_revision='1',
                     evidence_id=str(uuid.uuid4()), member_faluss_id=member or str(uuid.uuid4()),
                     purchased_pf='100', bonus_pf='50', cancelled_purchased_pf_cumulative='0',
                     state='confirmed', confirmed_at='2026-10-01T10:00:00Z',
                     observed_at='2026-10-05T10:00:00Z', policy_version='1.0.0'), **changes)


def intent(member, **changes):
    return dict(dict(attribution_id=str(uuid.uuid4()), client_authority='fixture.fans',
                     member_faluss_id=member, creator_faluss_id=str(uuid.uuid4()),
                     purchased_pf='40', policy_version='1.0.0'), **changes)


def run_checks(root, wp, check, call, sql, start, finish, parallel, await_file):
    prefix = 'wp_token_engine_pf_h1_'
    config = wp / 'wp-config.php'
    original_config = config.read_text()
    historical = [sql('SELECT * FROM ' + table + ' ORDER BY id')
                  for table in ('wp_token_engine_pf_ledger', 'wp_token_engine_ledger')]
    schema_option = sql("SELECT option_value FROM wp_options WHERE option_name='token_engine_schema_version'")
    facade = call('inspect')['facade']

    def counts():
        return {table: int(sql('SELECT COUNT(*) FROM ' + prefix + table))
                for table in ('evidence', 'lots', 'intents', 'keys')}

    def record(data, key=None, **values):
        return call('h1-evidence', payload=data, key=key or secrets.token_hex(32), **values)

    def planned(data, key=None, **values):
        return call('h1-intent', payload=data, key=key or secrets.token_hex(32), **values)

    def lookup(scope, operation, key):
        return call('h1-lookup', scope=scope, operation=operation, key=key)

    def killed(data, key, fault):
        marker = root / uuid.uuid4().hex
        process = start(dict(action='h1-evidence', payload=data, key=key, fault=fault, marker=str(marker)))
        await_file(marker, [process])
        os.killpg(process.pid, signal.SIGKILL)
        process.wait(timeout=10)

    def install_refused():
        return call('h1-install').get('error') == 'model_schema_divergent'

    check('H1 normal Hub bootstrap installs no model tables',
          sql("SHOW TABLES LIKE 'wp_token_engine_pf_h1_%'") == '')
    check('H1 installation requires explicit isolated recipe marker',
          call('h1-install') == dict(error='isolated_h1_recipe_required')
          and sql("SHOW TABLES LIKE 'wp_token_engine_pf_h1_%'") == '')
    # This marker belongs solely to this generated fixture, not a production flag.
    config.write_text(original_config.replace("$table_prefix=", "define('FALUSS_HUB_PF_H1_RECIPE_ONLY',true);\n$table_prefix="))
    fixture_config = config.read_text()
    check('H1 marker alone still performs no automatic installation',
          not call('h1-ready')['ready'] and sql("SHOW TABLES LIKE 'wp_token_engine_pf_h1_%'") == '')
    sql('CREATE TABLE ' + prefix + 'evidence (id INT NOT NULL) ENGINE=InnoDB')
    check('H1 partial schema is refused without adopting or expanding it',
          install_refused() and len(sql("SHOW TABLES LIKE 'wp_token_engine_pf_h1_%'").splitlines()) == 1)
    sql('DROP TABLE ' + prefix + 'evidence')
    check('H1 explicit installation atomically publishes five verified InnoDB tables',
          call('h1-install') == dict(ready=True)
          and len(sql("SHOW TABLES LIKE 'wp_token_engine_pf_h1_%'").splitlines()) == 5)
    check('H1 repeated installation is inert and preserves historical schema version',
          call('h1-install') == dict(ready=True) and schema_option == '5'
          and sql("SELECT option_value FROM wp_options WHERE option_name='token_engine_schema_version'") == '5')
    check('H1 real purchase authority cannot be registered even in recipe',
          call('h1-authority', evidence_authorities=['real.purchase']) == dict(error='fixture_authority_required'))
    before = counts()
    check('H1 unadmitted source and client fail closed without rows',
          record(evidence(authority_id='fixture.unadmitted')) == dict(error='model_authority_not_admitted')
          and planned(intent(str(uuid.uuid4()), client_authority='fixture.unadmitted')) == dict(error='model_authority_not_admitted')
          and counts() == before)

    member = str(uuid.uuid4())
    first = evidence(member)
    first_key = secrets.token_hex(32)
    accepted = record(first, first_key)
    check('H1 synthetic evidence creates one provenance lot without claiming an economic receipt',
          accepted['kind'] == 'closed_h1_model' and accepted['state'] == 'model_recorded'
          and counts() == dict(evidence=1, lots=1, intents=0, keys=1))
    check('H1 exact key replay returns the same model references',
          record(first, first_key) == dict(accepted, state='model_replayed') and counts()['evidence'] == 1)
    second_key = secrets.token_hex(32)
    check('H1 same purchase revision under another key retains a single lot and proof',
          record(first, second_key) == dict(accepted, state='model_replayed')
          and counts()['lots'] == counts()['evidence'] == 1)
    before = counts()
    check('H1 key content conflicts never overwrite the original',
          'error' in record(dict(first, purchased_pf='101'), first_key) and counts() == before
          and lookup('fixture.purchase', 'evidence', first_key) == dict(accepted, state='model_replayed'))
    check('H1 same purchase revision with changed quantity or holder is refused',
          'error' in record(dict(first, purchased_pf='101'))
          and 'error' in record(dict(first, member_faluss_id=str(uuid.uuid4()))) and counts() == before)
    check('H1 evidence UUID cannot be reused for another purchase',
          record(dict(first, purchase_id='synthetic.other')) == dict(error='evidence_conflict') and counts() == before)
    original_json = sql("SELECT payload_json FROM " + prefix + "evidence WHERE evidence_id='" + first['evidence_id'] + "'")
    revised = dict(first, source_revision='2', evidence_id=str(uuid.uuid4()), state='disputed')
    revision = record(revised)
    check('H1 monotone source revision appends evidence and preserves original lot and bytes',
          revision['lot_id'] == accepted['lot_id'] and counts()['lots'] == 1 and counts()['evidence'] == 2
          and sql("SELECT payload_json FROM " + prefix + "evidence WHERE evidence_id='" + first['evidence_id'] + "'") == original_json)
    before = counts()
    check('H1 stale new-key revision cannot restore a superseded lot',
          record(first) == dict(error='stale_revision') and counts() == before)
    check('H1 exact historical replay remains a historical model result, not current admissibility',
          record(first, first_key)['source_revision'] == '1'
          and planned(intent(member)) == dict(error='model_quantity_unavailable'))
    check('H1 revised holder policy or confirmation date cannot rewrite purchase origin',
          all('error' in record(dict(revised, source_revision='3', evidence_id=str(uuid.uuid4()), **change))
              for change in [dict(member_faluss_id=str(uuid.uuid4())), dict(policy_version='2.0.0'),
                             dict(confirmed_at='2026-10-02T10:00:00Z')]) and counts() == before)
    current = dict(revised, source_revision='3', evidence_id=str(uuid.uuid4()), state='confirmed')
    record(current)
    plan_input = intent(member, purchased_pf='100')
    plan_key = secrets.token_hex(32)
    plan = planned(plan_input, plan_key)
    check('H1 plan uses only purchased units; bonus or historical balances cannot fill a deficit',
          plan['model_allocations'] == [dict(lot_id=accepted['lot_id'], evidence_id=current['evidence_id'],
                                            source_revision='3', purchased_pf='100')]
          and planned(intent(member, purchased_pf='101')) == dict(error='model_quantity_unavailable'))
    historical_member = sql('SELECT faluss_id FROM wp_token_engine_pf_ledger ORDER BY id LIMIT 1')
    check('H1 earned historic claims alone cannot become purchased provenance',
          planned(intent(historical_member, purchased_pf='1')) == dict(error='model_quantity_unavailable'))
    check('H1 self attribution is rejected',
          planned(intent(member, creator_faluss_id=member)) == dict(error='self_attribution'))
    before = counts()
    check('H1 same global attribution with new key replays the stored model intention',
          planned(plan_input)['model_allocations'] == plan['model_allocations'] and counts()['intents'] == before['intents'])
    before = counts()
    check('H1 attribution identity binds client creator holder and quantity across different keys',
          all('error' in planned(dict(plan_input, **change)) for change in
              [dict(client_authority='fixture.other'), dict(creator_faluss_id=str(uuid.uuid4())),
               dict(member_faluss_id=str(uuid.uuid4())), dict(purchased_pf='99')]) and counts() == before)
    check('H1 plans neither reserve nor consume units',
          planned(intent(member, purchased_pf='100'))['model_allocations'] == plan['model_allocations'])

    fifo_member = str(uuid.uuid4())
    pending = evidence(fifo_member, state='pending', confirmed_at=None, purchased_pf='30')
    pending_result = record(pending)
    check('H1 pending source has no Hub admission date and cannot enter a plan',
          sql("SELECT accepted_at IS NULL FROM " + prefix + "lots WHERE lot_id='" + pending_result['lot_id'] + "'") == '1'
          and planned(intent(fifo_member, purchased_pf='1')) == dict(error='model_quantity_unavailable'))
    confirmed = dict(pending, source_revision='2', evidence_id=str(uuid.uuid4()), state='confirmed',
                     confirmed_at='2026-10-01T10:00:00Z')
    confirmed_result = record(confirmed)
    older_source = evidence(fifo_member, purchased_pf='50', confirmed_at='2020-01-01T10:00:00Z')
    later_result = record(older_source)
    fifo = planned(intent(fifo_member, purchased_pf='75'))['model_allocations']
    check('H1 FIFO uses first confirmed Hub admission, not an older source date or WordPress identity date',
          confirmed_result['lot_id'] == pending_result['lot_id']
          and [row['lot_id'] for row in fifo] == [confirmed_result['lot_id'], later_result['lot_id']]
          and [row['purchased_pf'] for row in fifo] == ['30', '45'])
    check('H1 model lookup is scoped by admitted authority and operation',
          lookup('fixture.other', 'intent', plan_key) == dict(kind='closed_h1_model', state='model_not_found')
          and lookup('fixture.fans', 'intent', plan_key)['attribution_id'] == plan_input['attribution_id']
          and lookup('fixture.purchase', 'unknown', plan_key) == dict(error='invalid_model_operation'))

    before = counts()
    concurrent = evidence()
    same_key = secrets.token_hex(32)
    results = parallel([dict(action='h1-evidence', payload=concurrent, key=same_key)] * 8)
    check('H1 eight concurrent identical evidence calls commit one proof lot and key',
          all(row.get('kind') == 'closed_h1_model' for row in results)
          and sum(row['state'] == 'model_recorded' for row in results) == 1
          and counts() == dict(before, evidence=before['evidence'] + 1, lots=before['lots'] + 1, keys=before['keys'] + 1))
    conflict = evidence()
    results = parallel([dict(action='h1-evidence', payload=dict(conflict, purchased_pf=str(amount)), key=secrets.token_hex(32))
                        for amount in [100, 101]])
    check('H1 concurrent changed purchase revision has only one winner',
          sum('error' not in row for row in results) == 1)
    before = counts()
    same_key = secrets.token_hex(32)
    results = parallel([dict(action='h1-evidence', payload=evidence(), key=same_key) for _ in range(2)])
    check('H1 concurrent key reuse across purchases has no orphan proof or lot',
          sum('error' not in row for row in results) == 1 and counts()['evidence'] == before['evidence'] + 1
          and counts()['lots'] == before['lots'] + 1 and counts()['keys'] == before['keys'] + 1)
    before = counts()
    concurrent_intent = intent(concurrent['member_faluss_id'])
    results = parallel([dict(action='h1-intent', payload=concurrent_intent, key=secrets.token_hex(32)) for _ in range(8)])
    check('H1 eight concurrent new keys for one attribution create one immutable intention',
          all('error' not in row for row in results) and sum(row['state'] == 'model_recorded' for row in results) == 1
          and counts()['intents'] == before['intents'] + 1)

    before = counts()
    interrupted = evidence()
    interrupted_key = secrets.token_hex(32)
    killed(interrupted, interrupted_key, 'before-commit')
    check('H1 termination before COMMIT rolls back proof lot and key together',
          counts() == before and lookup('fixture.purchase', 'evidence', interrupted_key)['state'] == 'model_not_found')
    check('H1 retry after rollback persists the model once',
          record(interrupted, interrupted_key)['state'] == 'model_recorded' and counts()['evidence'] == before['evidence'] + 1)
    before = counts()
    committed = evidence()
    committed_key = secrets.token_hex(32)
    killed(committed, committed_key, 'after-commit')
    found = lookup('fixture.purchase', 'evidence', committed_key)
    check('H1 lost process response after COMMIT is recoverable by same key without duplication',
          found['state'] == 'model_replayed' and record(committed, committed_key) == found
          and counts()['evidence'] == before['evidence'] + 1 and counts()['lots'] == before['lots'] + 1)
    before = counts()
    uncertain = evidence()
    uncertain_key = secrets.token_hex(32)
    unknown = record(uncertain, uncertain_key, fault='lost-commit-ack', marker=str(root / 'unused'))
    check('H1 injected lost COMMIT acknowledgement reports unknown with durable model',
          unknown == dict(error='model_commit_unknown') and counts()['evidence'] == before['evidence'] + 1
          and lookup('fixture.purchase', 'evidence', uncertain_key)['state'] == 'model_replayed')
    check('H1 retry after ambiguous COMMIT has no additional rows',
          record(uncertain, uncertain_key)['state'] == 'model_replayed' and counts()['evidence'] == before['evidence'] + 1)
    before = counts()
    check('H1 final key insertion failure rolls back earlier proof and lot insertions',
          record(evidence(), fault='h1-key-insert', marker=str(root / 'unused')) == dict(error='model_storage_unavailable')
          and counts() == before)

    sql('ALTER TABLE ' + prefix + 'evidence DROP INDEX purchase_revision')
    check('H1 missing uniqueness refuses reads and installation without silent repair',
          call('h1-ready') == dict(ready=False) and install_refused()
          and record(evidence()) == dict(error='model_schema_or_lock_unavailable'))
    sql('ALTER TABLE ' + prefix + 'evidence ADD UNIQUE KEY purchase_revision (authority_id,purchase_id,source_revision)')
    sql('ALTER TABLE ' + prefix + 'keys ENGINE=MyISAM')
    check('H1 nontransactional model table is refused', call('h1-ready') == dict(ready=False) and install_refused())
    sql('ALTER TABLE ' + prefix + 'keys ENGINE=InnoDB')
    sql('ALTER TABLE ' + prefix + 'keys ADD COLUMN unexpected INT NULL')
    check('H1 divergent column set is refused without adoption', call('h1-ready') == dict(ready=False) and install_refused())
    sql('ALTER TABLE ' + prefix + 'keys DROP COLUMN unexpected')
    check('H1 restored fixture shape is revalidated', call('h1-ready') == dict(ready=True))
    before = counts()
    config.write_text(fixture_config.replace('"local"', '"production"'))
    check('H1 production environment is refused even with recipe marker',
          call('h1-install') == dict(error='isolated_h1_recipe_required')
          and record(evidence()) == dict(error='isolated_h1_recipe_required') and counts() == before)
    config.write_text(fixture_config)

    key_hash = hashlib.sha256(first_key.encode()).hexdigest()
    sql("UPDATE " + prefix + "keys SET record_id='" + uncertain['evidence_id'] + "' WHERE key_sha256='" + key_hash + "'")
    check('H1 corrupt key-to-record mapping is detected without returning another model proof',
          lookup('fixture.purchase', 'evidence', first_key) == dict(error='model_integrity_failure'))
    sql("UPDATE " + prefix + "keys SET record_id='" + first['evidence_id'] + "' WHERE key_sha256='" + key_hash + "'")
    sql("UPDATE " + prefix + "intents SET plan_sha256='" + '0' * 64 + "' WHERE attribution_id='" + plan_input['attribution_id'] + "'")
    check('H1 corrupt stored allocation fingerprint is detected',
          lookup('fixture.fans', 'intent', plan_key) == dict(error='model_integrity_failure'))
    sql("UPDATE " + prefix + "intents SET plan_sha256=SHA2(plan_json,256) WHERE attribution_id='" + plan_input['attribution_id'] + "'")
    check('H1 all historical PF and ALB ledger rows remain byte-identical',
          historical == [sql('SELECT * FROM ' + table + ' ORDER BY id')
                         for table in ('wp_token_engine_pf_ledger', 'wp_token_engine_ledger')])
    check('H1 historical schema and narrow public facade remain unchanged',
          sql("SELECT option_value FROM wp_options WHERE option_name='token_engine_schema_version'") == schema_option
          and call('inspect')['facade'] == facade)
    config.write_text(original_config)
