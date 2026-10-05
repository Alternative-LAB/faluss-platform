"""Complete frozen owner pages, cross-page linkage, primary fence and recovery."""
import os
import secrets
import signal
import uuid


def run_checks(root, wp, check, call, sql, start, finish, parallel, await_file):
    prefix = 'wp_token_engine_pf_h4s_'
    old_max = sql('SELECT COALESCE(MAX(id),0) FROM wp_token_engine_pf_ledger')
    old_pf = sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')
    old_alb = sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')

    def credit(quantity='101', member=None):
        proof = dict(contract='hub.purchased-pf.h1-model/1.0.0', authority_id='fixture.purchase',
                     purchase_id='synthetic.' + uuid.uuid4().hex, source_revision='1', evidence_id=str(uuid.uuid4()),
                     member_faluss_id=member or str(uuid.uuid4()), purchased_pf=quantity, bonus_pf='0',
                     cancelled_purchased_pf_cumulative='0', state='confirmed', confirmed_at='2026-10-01T10:00:00Z',
                     observed_at='2026-10-05T10:00:00Z', policy_version='1.0.0')
        lot = call('h1-evidence', payload=proof, key=secrets.token_hex(32))['lot_id']
        call('h2-admit', lot_id=lot, member=proof['member_faluss_id'], key=secrets.token_hex(32))
        return proof, lot

    check('H4 snapshots are not installed by bootstrap or correction schema',
          sql("SHOW TABLES LIKE 'wp_token_engine_pf_h4s_%'") == '' and call('h4s-ready') == dict(ready=False))
    sql('CREATE TABLE ' + prefix + 'pages (id INT NOT NULL) ENGINE=InnoDB')
    check('H4 incomplete snapshot schema refuses adoption', call('h4s-install') == dict(error='model_schema_divergent'))
    sql('DROP TABLE ' + prefix + 'pages')
    check('H4 explicit materialization schema initializes an epoch without retention or purge',
          call('h4s-install') == dict(ready=True) and call('h4s-ready') == dict(ready=True)
          and call('h4s-install') == dict(ready=True) and sql('SELECT revision FROM ' + prefix + 'counter') == '0')
    empty_member = str(uuid.uuid4())
    empty = call('h4s-create', member=empty_member, key=secrets.token_hex(32))
    check('H4 empty complete snapshot has one empty page and an exact zero manifest',
          empty['rows'] == [] and empty['manifest']['row_count'] == '0' and empty['manifest']['page_count'] == '1'
          and empty['manifest']['net_pf'] == '0' and empty['next_cursor'] is None
          and call('h4s-finish', member=empty_member, snapshot_id=empty['manifest']['snapshot_id'])['state'] == 'current')
    proof, lot = credit()
    member = proof['member_faluss_id']
    assert len(call('h4-seed-many', member=member, count=101)['attributions']) == 101
    key = secrets.token_hex(32)
    concurrent = parallel([dict(action='h4s-create', member=member, key=key)] * 6)
    first = concurrent[0]
    snapshot_id = first['manifest']['snapshot_id']
    check('H4 six same-key snapshot requests materialize a single immutable document',
          all(result == first for result in concurrent)
          and sql("SELECT COUNT(*) FROM " + prefix + "snapshots WHERE member_faluss_id='" + member + "'") == '1')
    second = call('h4s-page', member=member, snapshot_id=snapshot_id, cursor=first['next_cursor'])
    assert 'error' not in second, second.get('error')
    check('H4 exact 100-row pages cover every admitted lot and one hundred one allocations',
          len(first['rows']) == 100 and len(second['rows']) == 2 and first['manifest']['row_count'] == '102'
          and first['manifest'] == second['manifest'] and second['page_index'] == '1' and second['next_cursor'] is None
          and first['manifest']['original_pf'] == '101' and first['manifest']['net_pf'] == '101')
    check('H4 snapshot cursor is bound to client member and snapshot, not an arbitrary page offset',
          'error' in call('h4s-page', member=str(uuid.uuid4()), snapshot_id=snapshot_id, cursor=first['cursor'])
          and 'error' in call('h4s-page', member=member, client='fixture.other', snapshot_id=snapshot_id, cursor=first['cursor'])
          and 'error' in call('h4s-page', member=member, snapshot_id=str(uuid.uuid4()), cursor=first['cursor']))
    check('H4 complete primary fence attests only the exact frozen current document',
          call('h4s-finish', member=member, snapshot_id=snapshot_id)['manifest'] == first['manifest'])
    revised = dict(proof, source_revision='2', evidence_id=str(uuid.uuid4()), state='partially_cancelled', cancelled_purchased_pf_cumulative='20')
    plan = call('h4-begin', payload=revised, key=secrets.token_hex(32))
    check('H4 fresh materialization and final fence refuse an incomplete correction',
          call('h4s-create', member=member, key=secrets.token_hex(32)) == dict(error='h4_reconciliation_incomplete')
          and call('h4s-finish', member=member, snapshot_id=snapshot_id) == dict(error='h4_reconciliation_incomplete'))
    check('H4 frozen pages stay byte identical while a newer source is reconciling',
          call('h4s-page', member=member, snapshot_id=snapshot_id, cursor=first['cursor']) == first
          and call('h4s-page', member=member, snapshot_id=snapshot_id, cursor=second['cursor']) == second)
    for fragment in (1, 2):
        assert 'error' not in call('h4-resume', member=member, plan_id=plan['plan_id'], fragment=str(fragment))
    check('H4 finished later correction supersedes old snapshot without changing its pages',
          call('h4s-finish', member=member, snapshot_id=snapshot_id) == dict(error='pf_snapshot_superseded')
          and call('h4s-create', member=member, key=key) == first)
    fresh = call('h4s-create', member=member, key=secrets.token_hex(32))
    check('H4 new snapshot contains cumulative corrected totals at a strictly newer document revision',
          int(fresh['manifest']['revision']) > int(first['manifest']['revision'])
          and fresh['manifest']['cancelled_pf'] == '20' and fresh['manifest']['net_pf'] == '81'
          and fresh['manifest']['epoch'] == first['manifest']['epoch'])
    fresh_id = fresh['manifest']['snapshot_id']
    saved_page = sql("SELECT `cursor`,next_cursor,HEX(payload_json),payload_sha256 FROM " + prefix + "pages WHERE snapshot_id='" + fresh_id + "' AND page_index=1").split('\t')
    sql("DELETE FROM " + prefix + "pages WHERE snapshot_id='" + fresh_id + "' AND page_index=1")
    check('H4 missing materialized page prevents complete primary attestation',
          call('h4s-finish', member=member, snapshot_id=fresh_id) == dict(error='pf_snapshot_incomplete'))
    sql("INSERT INTO " + prefix + "pages VALUES ('" + fresh_id + "',1,'" + saved_page[0] + "',NULL,UNHEX('" + saved_page[2] + "'),'" + saved_page[3] + "')")
    check('H4 exact page recovery restores completeness without a new revision or economic write',
          call('h4s-finish', member=member, snapshot_id=fresh_id)['state'] == 'current')
    sql("UPDATE " + prefix + "pages SET payload_sha256=REPEAT('0',64) WHERE snapshot_id='" + fresh_id + "' AND page_index=1")
    check('H4 corrupted materialized page fails digest verification',
          call('h4s-page', member=member, snapshot_id=fresh_id, cursor=saved_page[0]) == dict(error='pf_snapshot_digest_mismatch'))
    sql("UPDATE " + prefix + "pages SET payload_sha256='" + saved_page[3] + "' WHERE snapshot_id='" + fresh_id + "' AND page_index=1")
    # Real multi-lot consumption, correction and dispute: no synthetic projection rows.
    origin, _ = credit('20')
    member = origin['member_faluss_id']
    other, _ = credit('30', member)
    intent = dict(attribution_id=str(uuid.uuid4()), client_authority='fixture.fans', member_faluss_id=member,
                  creator_faluss_id=str(uuid.uuid4()), purchased_pf='40', policy_version='1.0.0')
    assert call('h2-reserve', payload=intent, key=secrets.token_hex(32))['state'] == 'reserved'
    assert call('h2c-confirm', payload=intent, key=secrets.token_hex(32))['state'] == 'confirmed'
    for source in (dict(origin, source_revision='2', evidence_id=str(uuid.uuid4()), state='partially_cancelled', cancelled_purchased_pf_cumulative='10'),
                   dict(other, source_revision='2', evidence_id=str(uuid.uuid4()), state='disputed')):
        plan = call('h4-begin', payload=source, key=secrets.token_hex(32))
        assert 'error' not in call('h4-resume', member=member, plan_id=plan['plan_id'], fragment='1')
    disputed = call('h4s-create', member=member, key=secrets.token_hex(32))
    allocations = [row for row in disputed['rows'] if row['kind'] == 'allocation']
    check('H4 complete multi-lot snapshot carries both linked legs and cumulative cancelled suspended net states',
          len(allocations) == 2 and all(row['attribution_original_pf'] == '40' for row in allocations)
          and disputed['manifest']['original_pf'] == '40' and disputed['manifest']['cancelled_pf'] == '10'
          and disputed['manifest']['suspended_pf'] == '20' and disputed['manifest']['net_pf'] == '10')
    resolved = dict(other, source_revision='3', evidence_id=str(uuid.uuid4()))
    plan = call('h4-begin', payload=resolved, key=secrets.token_hex(32))
    assert 'error' not in call('h4-resume', member=member, plan_id=plan['plan_id'], fragment='1')
    resolution = call('h4s-create', member=member, key=secrets.token_hex(32))
    check('H4 resolved complete snapshot restores only uncancelled multi-lot facts and supersedes disputed document',
          resolution['manifest']['net_pf'] == '30' and resolution['manifest']['cancelled_pf'] == '10'
          and resolution['manifest']['suspended_pf'] == '0'
          and call('h4s-finish', member=member, snapshot_id=disputed['manifest']['snapshot_id']) == dict(error='pf_snapshot_superseded'))
    proof, _ = credit('10')
    member = proof['member_faluss_id']
    key = secrets.token_hex(32)
    before = sql('SELECT revision FROM ' + prefix + 'counter')
    failed = call('h4s-create', member=member, key=key, fault='h4s-page-insert', marker=str(root / 'unused'))
    check('H4 failed page INSERT rolls back document pages and global sequence together',
          failed == dict(error='h2_storage_unavailable') and sql('SELECT revision FROM ' + prefix + 'counter') == before
          and sql("SELECT COUNT(*) FROM " + prefix + "snapshots WHERE member_faluss_id='" + member + "'") == '0')
    lost = call('h4s-create', member=member, key=key, fault='lost-commit-ack', marker=str(root / 'unused'))
    known = call('h4s-create', member=member, key=key)
    check('H4 ambiguous snapshot COMMIT recovers the same read key document without rematerializing',
          lost == dict(error='h2_commit_unknown') and known == call('h4s-create', member=member, key=key)
          and int(sql('SELECT revision FROM ' + prefix + 'counter')) == int(before) + 1)
    for fault in ('before-commit', 'after-commit'):
        proof, _ = credit('10')
        member = proof['member_faluss_id']
        key = secrets.token_hex(32)
        marker = root / uuid.uuid4().hex
        process = start(dict(action='h4s-create', member=member, key=key, fault=fault, marker=str(marker)))
        await_file(marker, [process]); os.killpg(process.pid, signal.SIGKILL); process.wait(timeout=10)
        existing = sql("SELECT COUNT(*) FROM " + prefix + "snapshots WHERE member_faluss_id='" + member + "'")
        recovered = call('h4s-create', member=member, key=key)
        check('H4 snapshot process death ' + fault + ' recovers one atomic complete document',
              existing == ('0' if fault == 'before-commit' else '1')
              and recovered == call('h4s-create', member=member, key=key)
              and call('h4s-finish', member=member, snapshot_id=recovered['manifest']['snapshot_id'])['state'] == 'current')
    check('H4 snapshots add no ledger rows and never change prior PF or ALB bytes',
          sql('SELECT * FROM wp_token_engine_pf_ledger WHERE id<=' + old_max + ' ORDER BY id') == old_pf
          and sql('SELECT * FROM wp_token_engine_ledger ORDER BY id') == old_alb)
