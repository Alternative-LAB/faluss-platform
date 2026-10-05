"""Closed Hub H3 signed proofs on actual WP/SQL. No HTTP or central SSO proof."""
import base64
import hashlib
import json
import os
import secrets
import signal
import uuid


def run_checks(root, wp, check, call, sql, start, finish, parallel, await_file):
    prefix = 'wp_token_engine_pf_h3_'
    historic_max = sql('SELECT COALESCE(MAX(id),0) FROM wp_token_engine_pf_ledger')
    historic = sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')
    alb = sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')
    config = wp / 'wp-config.php'
    original = config.read_text()
    check('H3 bootstrap creates no protocol tables or HTTP admission', sql("SHOW TABLES LIKE '" + prefix + "%'") == '')
    check('H3 without explicit recipe gate is rejected', call('h3-ready') == dict(error='isolated_h3_recipe_required'))
    lease = root / 'h3-lease'
    lease.write_bytes(secrets.token_bytes(32))
    lease.chmod(0o600)
    seed = base64.urlsafe_b64encode(secrets.token_bytes(32)).decode().rstrip('=')
    declarations = "define('FALUSS_PF_H3_RECIPE_ONLY',true);\n"
    declarations += "define('FALUSS_PF_H3_LEASE_SHA256','" + hashlib.sha256(lease.read_bytes()).hexdigest() + "');\n"
    declarations += "define('FALUSS_FEDERATION_PRIVATE_SEED','" + seed + "');\n"
    config.write_text(original.replace('$table_prefix=', declarations + '$table_prefix='))
    lease.chmod(0o644)
    check('H3 copied constants cannot bypass unsafe fixture lease permissions',
          call('h3-ready') == dict(error='isolated_h3_recipe_required'))
    lease.chmod(0o600)
    check('H3 markers never install schema automatically', call('h3-ready') == dict(ready=False))
    sql('CREATE TABLE ' + prefix + 'nonces (id INT NOT NULL) ENGINE=InnoDB')
    check('H3 partial schema is refused without adoption', call('h3-install') == dict(error='model_schema_divergent'))
    sql('DROP TABLE ' + prefix + 'nonces')
    check('H3 explicit installation atomically publishes three verified tables',
          call('h3-install') == dict(ready=True) and len(sql("SHOW TABLES LIKE '" + prefix + "%'").splitlines()) == 3)
    check('H3 repeated install leaves original schema and ledger intact', call('h3-install') == dict(ready=True)
          and sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id') == historic)
    public = call('h3-public')['public_key']

    def counts():
        return tuple(int(sql('SELECT COUNT(*) FROM ' + table)) for table in
                     (prefix + 'receipts', 'wp_token_engine_pf_h2c_consumptions',
                      'wp_token_engine_pf_h2c_journal', 'wp_token_engine_pf_ledger WHERE direction=\'debit\''))

    def credit():
        proof = dict(contract='hub.purchased-pf.h1-model/1.0.0', authority_id='fixture.purchase',
                     purchase_id='synthetic.' + uuid.uuid4().hex, source_revision='1', evidence_id=str(uuid.uuid4()),
                     member_faluss_id=str(uuid.uuid4()), purchased_pf='100', bonus_pf='0',
                     cancelled_purchased_pf_cumulative='0', state='confirmed', confirmed_at='2026-10-01T10:00:00Z',
                     observed_at='2026-10-05T10:00:00Z', policy_version='1.0.0')
        lot = call('h1-evidence', payload=proof, key=secrets.token_hex(32))['lot_id']
        call('h2-admit', lot_id=lot, member=proof['member_faluss_id'], key=secrets.token_hex(32))
        intent = dict(attribution_id=str(uuid.uuid4()), client_authority='fixture.fans',
                      member_faluss_id=proof['member_faluss_id'], creator_faluss_id=str(uuid.uuid4()),
                      purchased_pf='40', policy_version='1.0.0')
        reserve_key = secrets.token_hex(32)
        assert call('h2-reserve', payload=intent, key=reserve_key)['state'] == 'reserved'
        return intent, reserve_key, secrets.token_hex(32)

    intent, reserve_key, key = credit()
    before = counts()
    results = parallel([dict(action='h3-confirm', payload=intent, key=key)] * 8)
    confirmed = results[0]
    check('H3 eight confirmations create one official debit consumption journal and signed receipt',
          all(result == confirmed for result in results) and confirmed['state'] == 'confirmed'
          and counts() == tuple(value + 1 for value in before))
    check('H3 private receipt verifies via native public crypto on actual WordPress',
          call('h3-verify', payload=intent, receipt=confirmed['receipt'], public_key=public) == dict(verified=True))
    payload_bytes = base64.urlsafe_b64decode(confirmed['receipt']['payload_base64url'] + '===')
    payload = json.loads(payload_bytes)
    check('H3 PHP canonical receipt equals independent Python ASCII JCS vector',
          payload_bytes == json.dumps(payload, separators=(',', ':'), sort_keys=True).encode()
          and hashlib.sha256(payload_bytes).hexdigest() == confirmed['receipt']['payload_sha256'])
    check('H3 receipt links original purchased allocations and ledger without score or wallet',
          payload['receipt_id'] == confirmed['consumption']['consumption_id']
          and payload['ledger_entry_uuid'] == confirmed['consumption']['ledger_entry_uuid']
          and payload['purchased_pf'] == '40' and payload['revision'] == '1'
          and not {'balance', 'eur', 'score', 'pc'}.intersection(payload))
    rows = counts()
    check('H3 primary lookup by reserve key returns identical historical receipt without new debit',
          call('h3-lookup', payload=intent, operation='reserve', key=reserve_key) == confirmed and counts() == rows)
    check('H3 another confirmation key cannot mint a receipt or second consumption',
          call('h3-confirm', payload=intent, key=secrets.token_hex(32)) == dict(error='stable_key_required') and counts() == rows)

    second, _, second_key = credit()
    before = counts()
    check('H3 final receipt INSERT failure rolls back debit consumption and journal together',
          call('h3-confirm', payload=second, key=second_key, fault='h3-receipt-insert', marker='')
          == dict(error='h2_storage_unavailable') and counts() == before
          and sql("SELECT state FROM wp_token_engine_pf_h2_reservations WHERE attribution_id='" + second['attribution_id'] + "'") == 'reserved')
    check('H3 same key succeeds after known rolled back receipt storage fault',
          call('h3-confirm', payload=second, key=second_key)['state'] == 'confirmed'
          and counts() == tuple(value + 1 for value in before))

    for fault in ('before-commit', 'after-commit'):
        third, _, third_key = credit()
        before = counts()
        marker = root / uuid.uuid4().hex
        process = start(dict(action='h3-confirm', payload=third, key=third_key, fault=fault, marker=str(marker)))
        await_file(marker, [process])
        os.killpg(process.pid, signal.SIGKILL)
        process.wait(timeout=10)
        lookup = call('h3-lookup', payload=third, operation='confirm', key=third_key)
        if fault == 'before-commit':
            check('H3 process death before COMMIT leaves neither signed receipt nor debit',
                  lookup['state'] == 'not_found' and counts() == before)
        else:
            check('H3 process death after COMMIT preserves receipt and lookup without second debit',
                  lookup['state'] == 'confirmed' and counts() == tuple(value + 1 for value in before)
                  and call('h3-confirm', payload=third, key=third_key) == lookup)

    fourth, _, fourth_key = credit()
    before = counts()
    check('H3 injected lost COMMIT acknowledgement reports unknown rather than false rollback',
          call('h3-confirm', payload=fourth, key=fourth_key, fault='commit-unknown', marker='')
          == dict(error='h2_commit_unknown') and counts() == tuple(value + 1 for value in before))
    check('H3 primary recovery after unknown finds same durable receipt',
          call('h3-lookup', payload=fourth, operation='confirm', key=fourth_key)['state'] == 'confirmed'
          and counts() == tuple(value + 1 for value in before))

    nonce, member = secrets.token_hex(32), str(uuid.uuid4())
    data = dict(action='h3-nonce', nonce=nonce, digest=secrets.token_hex(32), member=member)
    accepted = parallel([data] * 8)
    check('H3 concurrent transport nonce is admitted once and seven replays refused durably',
          accepted.count(dict(accepted=True)) == 1 and accepted.count(dict(error='pf_network_replay')) == 7)
    for change in (dict(nonce='short'), dict(digest='bad'), dict(member='not-an-identity')):
        check('H3 invalid nonce digest or identity fails closed ' + next(iter(change)),
              'error' in finish(start(dict(data, **change))))

    check('H3 all prior PF rows remain byte identical and ALB untouched',
          sql('SELECT * FROM wp_token_engine_pf_ledger WHERE id<=' + historic_max + ' ORDER BY id') == historic
          and sql('SELECT * FROM wp_token_engine_ledger ORDER BY id') == alb)
    check('H3 keeps schema v5 daily facade and future economic operations closed',
          call('inspect')['schema'] == '5' and sorted(call('inspect')['facade']) == ['claimHubDaily', 'hubDailyStatus']
          and call('future', subject=str(uuid.uuid4()), category='fans_support')['error'] == 'pf_feature_not_enabled')
