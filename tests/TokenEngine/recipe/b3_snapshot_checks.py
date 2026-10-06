"""Complete member snapshots 2.0; authoritative original order and latest H4 net."""
import copy
import datetime
import hashlib
import json
import os
import secrets
import signal
import uuid


def run_checks(root, wp, check, call, sql, start, finish, parallel, await_file):
    prefix = 'wp_token_engine_pf_b3s_'
    old_pf = sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')
    old_alb = sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')
    old_h3 = sql('SELECT * FROM wp_token_engine_pf_h3_receipts ORDER BY attribution_id')

    def digest(value):
        return hashlib.sha256(json.dumps(value,sort_keys=True,separators=(',',':')).encode()).hexdigest()

    def evidence(quantity='120', member=None):
        proof = dict(contract='hub.purchased-pf.h1-model/1.0.0',authority_id='fixture.purchase',purchase_id='synthetic.'+uuid.uuid4().hex,
                     source_revision='1',evidence_id=str(uuid.uuid4()),member_faluss_id=member or str(uuid.uuid4()),purchased_pf=quantity,bonus_pf='0',
                     cancelled_purchased_pf_cumulative='0',state='confirmed',confirmed_at='2026-10-01T10:00:00Z',observed_at='2026-10-05T10:00:00Z',policy_version='1.0.0')
        lot = call('h1-evidence',payload=proof,key=secrets.token_hex(32))['lot_id']
        call('h2-admit',lot_id=lot,member=proof['member_faluss_id'],key=secrets.token_hex(32))
        return proof,lot

    def context(member, quantity='1'):
        now = datetime.datetime.now(datetime.timezone.utc)
        instant = lambda value: value.strftime('%Y-%m-%d %H:%M:%S.%f')
        selected = dict(origin_id=str(uuid.uuid4()),policy_version='1.0.0',creator_category='arts',category_revision='1',
                        country_policy_revision=str(uuid.uuid4()),sessions=[dict(session_id=str(uuid.uuid4()),rules_revision='1',rules_sha256='a'*64,
                        admission_revision='1',barrier_version='1',starts_at=instant(now-datetime.timedelta(days=1)),ends_at=instant(now+datetime.timedelta(days=1)),
                        admitted_at=instant(now-datetime.timedelta(seconds=5)),scope='international',territory_policy_revision='',territory_admission_revision='0',country='',territory_ref='')])
        value = dict(attribution_id=str(uuid.uuid4()),member_faluss_id=member,creator_faluss_id=str(uuid.uuid4()),client_authority='fixture.fans',
                     purchased_pf=quantity,policy_version='1.0.0',ranking_context=selected,context_sha256=digest(selected))
        for ref in call('b3b-refs',payload=value)['references']:
            barrier = dict(content=ref['content'],valid_from=selected['sessions'][0]['starts_at'],valid_until=selected['sessions'][0]['ends_at'])
            if ref['content']['kind']=='admission': barrier['valid_from']=max(ref['content']['starts_at'],ref['content']['admitted_at'])
            assert call('b3b-register',payload=barrier,key=secrets.token_hex(32))['state'] == 'active'
        return value

    def consume(value):
        # Preserve the primary chronology after the accelerated fixture-only quota windows.
        latest=datetime.datetime.fromisoformat(sql('SELECT last_confirmed_at FROM wp_token_engine_pf_b3r_counter')).replace(tzinfo=datetime.timezone.utc)
        clock=str(max(int(sql('SELECT UNIX_TIMESTAMP()')),int(latest.timestamp()))+7)+'.000000'
        reservation=call('b3r-reserve',payload=value,key=secrets.token_hex(32),clock_value=clock)
        if 'error' in reservation: raise RuntimeError('Isolated following reservation refused: '+reservation['error'])
        assert reservation['state'] == 'reserved'
        return call('b3r-confirm',payload=value,key=secrets.token_hex(32),clock_value=clock)

    def allocations(rows):
        return [row for row in rows if row['kind']=='allocation']

    def complete(page):
        rows = page['rows'][:]
        while page['next_cursor'] is not None:
            page = call('b3s-page',member=page['manifest']['member_faluss_id'],snapshot_id=page['manifest']['snapshot_id'],cursor=page['next_cursor'])
            rows += page['rows']
        return rows

    check('B3s normal bootstrap and B3 ranked installation do not install member snapshots',
          sql("SHOW TABLES LIKE '"+prefix+"%'")=='' and call('b3s-ready')==dict(ready=False))
    check('B3s explicit installation requires the complete H4 snapshot dependency',call('b3s-install')==dict(error='pf_snapshot_schema_unavailable'))
    assert call('h4s-install')==dict(ready=True)
    sql('CREATE TABLE '+prefix+'pages (id INT NOT NULL) ENGINE=InnoDB')
    check('B3s partial metadata is refused without adopting historical or new tables',call('b3s-install')==dict(error='model_schema_divergent'))
    sql('DROP TABLE '+prefix+'pages')
    check('B3s isolated explicit installation atomically publishes four verified InnoDB tables',
          call('b3s-install')==dict(ready=True) and call('b3s-ready')==dict(ready=True) and call('b3s-install')==dict(ready=True))
    member = str(uuid.uuid4()); empty=call('b3s-create',member=member,key=secrets.token_hex(32))
    check('B3s empty member snapshot attests one empty page and an owner ordering epoch',
          empty['rows']==[] and empty['manifest']['row_count']=='0' and empty['manifest']['page_count']=='1'
          and empty['manifest']['contract']=='hub.purchased-pf.snapshot/2.0.0'
          and empty['manifest']['ordering_epoch']==sql('SELECT ordering_epoch FROM wp_token_engine_pf_b3r_counter')
          and call('b3s-finish',member=member,snapshot_id=empty['manifest']['snapshot_id'])['state']=='current')
    proof,lot=evidence(); member=proof['member_faluss_id']; values=context(member)
    ids=[]
    for count in (20,20,20,20,20,1):
        batch=call('b3s-seed-ranked',payload=values,count=count)
        if 'error' in batch: raise RuntimeError('Isolated ranked seed refused: '+batch['error'])
        ids += batch['attributions']
    assert len(set(ids))==101
    key=secrets.token_hex(32); pages=parallel([dict(action='b3s-create',member=member,key=key)]*6); first=pages[0]; manifest=first['manifest']; sid=manifest['snapshot_id']
    check('B3s six concurrent same-key requests materialize one immutable generation',
          all(value==first for value in pages) and sql("SELECT COUNT(*) FROM "+prefix+"snapshots WHERE member_faluss_id='"+member+"'")=='1')
    second=call('b3s-page',member=member,snapshot_id=sid,cursor=first['next_cursor']); rows=first['rows']+second['rows']; facts=allocations(rows)
    check('B3s pagination beyond one hundred rows includes every ranked allocation once',
          len(first['rows'])==100 and len(second['rows'])==2 and second['next_cursor'] is None and second['manifest']==manifest
          and manifest['original_pf']=='101' and manifest['net_pf']=='101' and len(facts)==101 and {row['attribution_id'] for row in facts}==set(ids))
    check('B3s every fact carries the same original context and a unique owner order',
          len({row['ranking']['consumption_order'] for row in facts})==101
          and all(row['ranking']['ranking_context']==values['ranking_context'] and row['ranking']['context_sha256']==values['context_sha256']
                  and row['ranking']['ordering_epoch']==manifest['ordering_epoch'] for row in facts)
          and call('b3s-verify',manifest=manifest,rows=rows)==dict(valid=True))
    check('B3s cursor and generation are bound to member and exact authorized client',
          call('b3s-page',member=str(uuid.uuid4()),snapshot_id=sid,cursor=first['cursor'])==dict(error='pf_snapshot_unavailable')
          and call('b3s-page',member=member,client='fixture.other',snapshot_id=sid,cursor=first['cursor'])==dict(error='pf_permission_denied')
          and call('b3s-page',member=member,snapshot_id=sid,cursor=str(uuid.uuid4()))==dict(error='pf_snapshot_page_unavailable'))
    check('B3s final primary fence attests the exact full current generation',call('b3s-finish',member=member,snapshot_id=sid)['manifest']==manifest)
    wrong=copy.deepcopy(rows); wrong[-1]['ranking']['consumption_order']=wrong[-2]['ranking']['consumption_order']
    check('B3s duplicated cross-page order and missing rows cannot become a complete snapshot',
          'error' in call('b3s-verify',manifest=manifest,rows=wrong) and 'error' in call('b3s-verify',manifest=manifest,rows=rows[:-1]))

    revised=dict(proof,source_revision='2',evidence_id=str(uuid.uuid4()),state='partially_cancelled',cancelled_purchased_pf_cumulative='39')
    plan=call('h4-begin',payload=revised,key=secrets.token_hex(32))
    check('B3s materialization and primary fence reject a pending H4 correction fragment',
          call('b3s-create',member=member,key=secrets.token_hex(32))==dict(error='h4_reconciliation_incomplete')
          and call('b3s-finish',member=member,snapshot_id=sid)==dict(error='h4_reconciliation_incomplete'))
    check('B3s frozen pages remain unchanged during correction and same-key replay',
          call('b3s-page',member=member,snapshot_id=sid,cursor=first['cursor'])==first and call('b3s-create',member=member,key=key)==first)
    for fragment in range(1,int(plan['fragment_count'])+1):
        assert call('h4-resume',member=member,plan_id=plan['plan_id'],fragment=str(fragment))['next_fragment']==str(fragment)
    corrected=call('b3s-create',member=member,key=secrets.token_hex(32)); corrected_rows=complete(corrected); corrected_facts=allocations(corrected_rows)
    original_ranking={row['attribution_id']:(row['confirmed_at'],row['ranking']) for row in facts}
    check('B3s newer cumulative correction supersedes the fence but never rewrites old pages',
          call('b3s-finish',member=member,snapshot_id=sid)==dict(error='pf_snapshot_superseded')
          and call('b3s-page',member=member,snapshot_id=sid,cursor=second['cursor'])==second
          and corrected['manifest']['epoch']==manifest['epoch'] and int(corrected['manifest']['revision'])>int(manifest['revision']))
    check('B3s corrected net and original order context and timestamp remain distinct authorities',
          corrected['manifest']['cancelled_pf']=='20' and corrected['manifest']['net_pf']=='81'
          and all((row['confirmed_at'],row['ranking'])==original_ranking[row['attribution_id']] for row in corrected_facts)
          and call('b3s-finish',member=member,snapshot_id=corrected['manifest']['snapshot_id'])['state']=='current')
    newer=dict(values,attribution_id=str(uuid.uuid4()))
    # The correction consumed all still available units; add a distinct admissible lot.
    evidence('1',member); consume(newer)
    check('B3s new consumption after materialization invalidates the old complete fence',
          call('b3s-finish',member=member,snapshot_id=corrected['manifest']['snapshot_id'])==dict(error='pf_snapshot_superseded'))
    current=call('b3s-create',member=member,key=secrets.token_hex(32))
    session_ref=next(ref for ref in call('b3b-refs',payload=values)['references'] if ref['content']['kind']=='session')
    closure={name:session_ref[name] for name in ('barrier_key','version','content_sha256')}|dict(reason='session_cancelled')
    assert call('b3b-close',payload=closure,key=secrets.token_hex(32))['state']=='closed'
    check('B3s effective closure preserves already confirmed facts and their complete snapshot',
          call('b3s-finish',member=member,snapshot_id=current['manifest']['snapshot_id'])['state']=='current'
          and call('b3s-create',member=member,key=secrets.token_hex(32))['manifest']['net_pf']==current['manifest']['net_pf'])

    # Multi-lot original fact; H4 corrections change only the current net.
    multi_proof,first_lot=evidence('10'); multi_member=multi_proof['member_faluss_id']; evidence('10',multi_member)
    multi_values=context(multi_member,'20'); multi=consume(multi_values)
    multi_page=call('b3s-create',member=multi_member,key=secrets.token_hex(32)); multi_facts=allocations(multi_page['rows'])
    check('B3s multiple lot allocations retain one consumption and one immutable ranking authority',
          len(multi_facts)==2 and len({row['consumption_id'] for row in multi_facts})==1
          and multi_facts[0]['ranking']==multi_facts[1]['ranking'] and multi_page['manifest']['net_pf']=='20')
    for revision,cancelled,state,net in [(2,3,'partially_cancelled','17'),(3,3,'disputed','10'),(4,3,'partially_cancelled','17'),(5,10,'cancelled','10')]:
        proof_new=dict(multi_proof,source_revision=str(revision),evidence_id=str(uuid.uuid4()),cancelled_purchased_pf_cumulative=str(cancelled),state=state)
        plan=call('h4-begin',payload=proof_new,key=secrets.token_hex(32))
        for fragment in range(int(plan['next_fragment'])+1,int(plan['fragment_count'])+1): call('h4-resume',member=multi_member,plan_id=plan['plan_id'],fragment=str(fragment))
        updated=call('b3s-create',member=multi_member,key=secrets.token_hex(32))
        check('B3s H4 '+state+' revision '+str(revision)+' preserves the original ranking fact with latest net',
              updated['manifest']['net_pf']==net and all(row['ranking']==multi_facts[0]['ranking'] for row in allocations(updated['rows']))
              and call('b3s-finish',member=multi_member,snapshot_id=updated['manifest']['snapshot_id'])['state']=='current')
    # A historical 0.2 consumption is visible only as unranked in version 2.
    old_proof,old_lot=evidence('10'); legacy_member=old_proof['member_faluss_id']
    base=dict(attribution_id=str(uuid.uuid4()),member_faluss_id=legacy_member,creator_faluss_id=str(uuid.uuid4()),client_authority='fixture.fans',purchased_pf='10',policy_version='1.0.0')
    call('h2-reserve',payload=base,key=secrets.token_hex(32)); call('h3-confirm',payload=base,key=secrets.token_hex(32))
    legacy=call('b3s-create',member=legacy_member,key=secrets.token_hex(32))
    check('B3s old consumption is explicitly unranked without retroactive category or order',
          allocations(legacy['rows'])[0]['ranking'] is None and legacy['manifest']['net_pf']=='10')

    for fault in ('b3s-snapshot-insert','b3s-page-insert'):
        fresh_key=secrets.token_hex(32); before=sql('SELECT COUNT(*) FROM '+prefix+'snapshots'); counter=sql('SELECT revision FROM '+prefix+'counter')
        check('B3s '+fault+' rolls back materialized pages key and generation together',
              call('b3s-create',member=legacy_member,key=fresh_key,fault=fault,marker='')==dict(error='h2_storage_unavailable')
              and sql('SELECT COUNT(*) FROM '+prefix+'snapshots')==before and sql('SELECT revision FROM '+prefix+'counter')==counter)
        check('B3s exact retry after '+fault+' produces one complete recoverable snapshot',
              'manifest' in call('b3s-create',member=legacy_member,key=fresh_key))
    for fault in ('before-commit','after-commit','commit-unknown'):
        fresh_key=secrets.token_hex(32); before=int(sql('SELECT COUNT(*) FROM '+prefix+'snapshots'))
        if fault=='commit-unknown':
            lost=call('b3s-create',member=legacy_member,key=fresh_key,fault=fault,marker='')
            check('B3s lost COMMIT acknowledgement is unknown and never a false rollback',lost==dict(error='h2_commit_unknown'))
        else:
            marker=root/uuid.uuid4().hex; process=start(dict(action='b3s-create',member=legacy_member,key=fresh_key,fault=fault,marker=str(marker)))
            await_file(marker,[process]); os.killpg(process.pid,signal.SIGKILL); process.wait(timeout=10)
        check('B3s primary resolves '+fault+' materialization outcome before stable recovery',
              int(sql('SELECT COUNT(*) FROM '+prefix+'snapshots'))==before+int(fault!='before-commit'))
        recovered=call('b3s-create',member=legacy_member,key=fresh_key)
        check('B3s same-key '+fault+' recovery preserves one generation and exact page',
              int(sql('SELECT COUNT(*) FROM '+prefix+'snapshots'))==before+1
              and call('b3s-create',member=legacy_member,key=fresh_key)==recovered
              and call('b3s-finish',member=legacy_member,snapshot_id=recovered['manifest']['snapshot_id'])['state']=='current')

    saved=sql("SELECT HEX(payload_json),payload_sha256 FROM "+prefix+"pages WHERE snapshot_id='"+sid+"' AND page_index=1").split('\t')
    sql("DELETE FROM "+prefix+"pages WHERE snapshot_id='"+sid+"' AND page_index=1")
    check('B3s missing materialized page cannot be silently replaced from current rows',call('b3s-finish',member=member,snapshot_id=sid)==dict(error='pf_snapshot_incomplete'))
    sql("INSERT INTO "+prefix+"pages (snapshot_id,page_index,`cursor`,next_cursor,payload_json,payload_sha256) VALUES ('"+sid+"',1,'"+second['cursor']+"',NULL,UNHEX('"+saved[0]+"'),'"+saved[1]+"')")
    sql("UPDATE "+prefix+"pages SET payload_sha256=REPEAT('0',64) WHERE snapshot_id='"+sid+"' AND page_index=1")
    check('B3s altered stored page digest refuses private delivery',call('b3s-page',member=member,snapshot_id=sid,cursor=second['cursor'])==dict(error='pf_snapshot_digest_mismatch'))
    sql("UPDATE "+prefix+"pages SET payload_sha256='"+saved[1]+"' WHERE snapshot_id='"+sid+"' AND page_index=1")
    snapshot_epoch=sql('SELECT epoch FROM '+prefix+'counter')
    sql("UPDATE "+prefix+"counter SET epoch='"+str(uuid.uuid4())+"'")
    check('B3s restored foreign generation epoch refuses materialization and final attestation',
          call('b3s-create',member=legacy_member,key=secrets.token_hex(32))==dict(error='pf_snapshot_counter_divergent')
          and call('b3s-finish',member=legacy_member,snapshot_id=legacy['manifest']['snapshot_id'])==dict(error='pf_snapshot_counter_divergent'))
    sql("UPDATE "+prefix+"counter SET epoch='"+snapshot_epoch+"'")
    revision=sql('SELECT revision FROM '+prefix+'counter')
    sql('UPDATE '+prefix+'counter SET revision=revision-1')
    check('B3s restored generation counter cannot reuse a previously assigned revision',
          call('b3s-create',member=legacy_member,key=secrets.token_hex(32))==dict(error='pf_snapshot_counter_divergent'))
    sql('UPDATE '+prefix+'counter SET revision='+revision)
    epoch=sql('SELECT ordering_epoch FROM wp_token_engine_pf_b3r_counter')
    sql("UPDATE wp_token_engine_pf_b3r_counter SET ordering_epoch='"+str(uuid.uuid4())+"'")
    check('B3s a restored contradictory owner epoch cannot attest a current snapshot',
          call('b3s-finish',member=legacy_member,snapshot_id=legacy['manifest']['snapshot_id'])==dict(error='pf_ranking_counter_divergent'))
    sql("UPDATE wp_token_engine_pf_b3r_counter SET ordering_epoch='"+epoch+"'")
    sql('ALTER TABLE '+prefix+'pages ENGINE=MyISAM')
    check('B3s nontransactional page schema refuses reads and installs without repair',
          call('b3s-page',member=member,snapshot_id=sid,cursor=first['cursor'])==dict(error='pf_snapshot_schema_unavailable')
          and call('b3s-install')==dict(error='model_schema_divergent'))
    sql('ALTER TABLE '+prefix+'pages ENGINE=InnoDB')
    check('B3s all previous official PF and ALB rows and H3 receipts remain byte identical',
          all(row in sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id').splitlines() for row in old_pf.splitlines())
          and sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')==old_alb
          and all(row in sql('SELECT * FROM wp_token_engine_pf_h3_receipts ORDER BY attribution_id').splitlines() for row in old_h3.splitlines()))
