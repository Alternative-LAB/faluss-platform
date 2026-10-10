"""Exhaustive frozen corpus, stable recovery and final current-primary fence."""
import copy
import datetime
import hashlib
import json
import os
import secrets
import signal
import uuid


def run_checks(root, wp, check, call, sql, start, finish, parallel, await_file):
    prefix='wp_token_engine_pf_b3c_'; measurements=[]
    old_pf=sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')
    old_alb=sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')
    old_h3=sql('SELECT * FROM wp_token_engine_pf_h3_receipts ORDER BY attribution_id')

    def digest(value):return hashlib.sha256(json.dumps(value,sort_keys=True,separators=(',',':')).encode()).hexdigest()
    def instant(value):return value.strftime('%Y-%m-%d %H:%M:%S.%f')
    def clock():
        last=sql('SELECT last_confirmed_at FROM wp_token_engine_pf_b3r_counter')
        latest=datetime.datetime.fromisoformat(last).replace(tzinfo=datetime.timezone.utc) if last else datetime.datetime.now(datetime.timezone.utc)
        return str(max(int(sql('SELECT UNIX_TIMESTAMP()')),int(latest.timestamp()))+7)+'.000000'

    def corpus(action,origin,**kw):return call('b3c-'+action,origin=origin,clock_value=clock(),**kw)

    def evidence(quantity='120'):
        proof=dict(contract='hub.purchased-pf.h1-model/1.0.0',authority_id='fixture.purchase',purchase_id='synthetic.'+uuid.uuid4().hex,
                   source_revision='1',evidence_id=str(uuid.uuid4()),member_faluss_id=str(uuid.uuid4()),purchased_pf=quantity,bonus_pf='0',
                   cancelled_purchased_pf_cumulative='0',state='confirmed',confirmed_at='2026-10-01T10:00:00Z',observed_at='2026-10-05T10:00:00Z',policy_version='1.0.0')
        lot=call('h1-evidence',payload=proof,key=secrets.token_hex(32))['lot_id']
        assert call('h2-admit',lot_id=lot,member=proof['member_faluss_id'],key=secrets.token_hex(32))['state']=='admitted'
        return proof

    def context(member,origin=None,creator=None):
        now=datetime.datetime.now(datetime.timezone.utc)
        selected=dict(origin_id=origin or str(uuid.uuid4()),policy_version='1.0.0',creator_category='arts',category_revision='1',country_policy_revision=str(uuid.uuid4()),
                      sessions=[dict(session_id=str(uuid.uuid4()),rules_revision='1',rules_sha256='a'*64,admission_revision='1',barrier_version='1',
                                     starts_at=instant(now-datetime.timedelta(days=1)),ends_at=instant(now+datetime.timedelta(days=2)),admitted_at=instant(now-datetime.timedelta(seconds=5)),
                                     scope='international',territory_policy_revision='',territory_admission_revision='0',country='',territory_ref='')])
        value=dict(attribution_id=str(uuid.uuid4()),member_faluss_id=member,creator_faluss_id=creator or str(uuid.uuid4()),client_authority='fixture.fans',
                   purchased_pf='1',policy_version='1.0.0',ranking_context=selected,context_sha256=digest(selected))
        refs=call('b3b-refs',payload=value)['references']
        for ref in refs:
            barrier=dict(content=ref['content'],valid_from=selected['sessions'][0]['starts_at'],valid_until=selected['sessions'][0]['ends_at'])
            if ref['content']['kind']=='admission':barrier['valid_from']=max(ref['content']['starts_at'],ref['content']['admitted_at'])
            result=call('b3b-register',payload=barrier,key=secrets.token_hex(32))
            assert result.get('state')=='active' or result==dict(error='pf_barrier_stable_key_or_version_required')
        return value,refs

    def consume(value):
        now=clock();assert call('b3r-reserve',payload=value,key=secrets.token_hex(32),clock_value=now)['state']=='reserved'
        assert call('b3r-confirm',payload=value,key=secrets.token_hex(32),clock_value=now)['state']=='confirmed'

    def complete(page):
        facts=page['facts'][:]; manifest=page['manifest']; origin=manifest['origin_id']
        while page['next_cursor'] is not None:
            page=corpus('page',origin,corpus_id=manifest['corpus_id'],cursor=page['next_cursor']);assert page['manifest']==manifest
            facts+=page['facts']
        assert corpus('verify',origin,manifest=manifest,facts=facts)==dict(valid=True)
        return facts

    def correct(proof,revision,cancelled,state):
        updated=dict(proof,source_revision=str(revision),evidence_id=str(uuid.uuid4()),cancelled_purchased_pf_cumulative=str(cancelled),state=state)
        plan=call('h4-begin',payload=updated,key=secrets.token_hex(32))
        for fragment in range(int(plan['next_fragment'])+1,int(plan['fragment_count'])+1):call('h4-resume',member=proof['member_faluss_id'],plan_id=plan['plan_id'],fragment=str(fragment))
        return updated

    check('B3c normal bootstrap installs no corpus table or transport',sql("SHOW TABLES LIKE '"+prefix+"%'")=='' and call('b3c-ready')==dict(ready=False))
    sql('CREATE TABLE '+prefix+'pages (id INT NOT NULL) ENGINE=InnoDB')
    check('B3c partial metadata refuses installation without adopting historical data',call('b3c-install')==dict(error='model_schema_divergent'))
    sql('DROP TABLE '+prefix+'pages')
    check('B3c explicit isolated installation atomically publishes four verified InnoDB tables',call('b3c-install')==dict(ready=True) and call('b3c-ready')==dict(ready=True))
    check('B3c repeated install preserves the original generation epoch',call('b3c-install')==dict(ready=True) and sql('SELECT revision FROM '+prefix+'counter')=='0')
    proof=evidence(); values,refs=context(proof['member_faluss_id']); origin=values['ranking_context']['origin_id']
    empty=corpus('create',origin,key=secrets.token_hex(32)); eid=empty['manifest']['corpus_id']
    check('B3c admitted empty origin has one immutable empty page and exact scope',empty['facts']==[] and empty['next_cursor'] is None and empty['manifest']['fact_count']=='0'
          and empty['manifest']['scope']=='all_confirmed_ranked_0.3_including_zero' and corpus('finish',origin,corpus_id=eid)['state']=='current')
    check('B3c snapshot or wallet permissions cannot create look up or read the global corpus',
          corpus('create',origin,key=secrets.token_hex(32),permissions=['pf.snapshot','wallet.read'])==dict(error='pf_permission_denied')
          and corpus('page',origin,corpus_id=eid,cursor=empty['cursor'],permissions=['pf.lookup'])==dict(error='pf_permission_denied'))
    check('B3c wrong owner audience or unknown origin is never an eligible empty corpus',
          corpus('create',origin,key=secrets.token_hex(32),peer='fixture.other')==dict(error='pf_invalid_peer')
          and corpus('create',origin,key=secrets.token_hex(32),audience='fixture.other')==dict(error='pf_invalid_peer')
          and corpus('create',str(uuid.uuid4()),key=secrets.token_hex(32))==dict(error='pf_corpus_origin_not_admitted'))
    batch=call('b3s-seed-ranked',payload=values,count='101')
    if 'error' in batch:raise RuntimeError('Isolated ranked seed refused: '+batch['error'])
    seeded=batch['attributions'];key=secrets.token_hex(32)
    firsts=parallel([dict(action='b3c-create',origin=origin,key=key,clock_value=clock())]*6);first=firsts[0];manifest=first['manifest'];cid=manifest['corpus_id']
    check('B3c six concurrent same-key creations commit one frozen generation and no economic write',all(p==first for p in firsts)
          and sql("SELECT COUNT(*) FROM "+prefix+"corpora WHERE origin_id='"+origin+"'")=='2')
    second=corpus('page',origin,corpus_id=cid,cursor=first['next_cursor']);facts=complete(first)
    check('B3c more than one hundred facts paginate exactly once with a chained immutable manifest',len(first['facts'])==100 and len(second['facts'])==1
          and second['next_cursor'] is None and second['manifest']==manifest and {f['attribution_id'] for f in facts}==set(seeded))
    check('B3c final primary fence confirms all pages and current source',corpus('finish',origin,corpus_id=cid)['manifest']==manifest)
    check('B3c primary lookup recovers exact materialized bytes and unknown stable key reports absence',corpus('lookup',origin,key=key)==dict(state='materialized',page=first)
          and corpus('lookup',origin,key=secrets.token_hex(32))==dict(state='absent'))
    check('B3c key binds origin and policy rather than permitting a changed retry',corpus('create',str(uuid.uuid4()),key=key)==dict(error='pf_corpus_key_conflict')
          and corpus('lookup',origin,key=key,policy='2.0.0')==dict(error='pf_corpus_key_conflict'))
    check('B3c cursors cannot cross origin corpus or another policy',corpus('page',str(uuid.uuid4()),corpus_id=cid,cursor=first['cursor'])==dict(error='pf_corpus_unavailable')
          and corpus('page',origin,corpus_id=eid,cursor=first['cursor'])==dict(error='pf_corpus_page_unavailable')
          and corpus('page',origin,corpus_id=cid,cursor=first['cursor'],policy='2.0.0')==dict(error='pf_corpus_unavailable'))
    check('B3c incomplete or duplicate cross-page facts cannot form an exhaustive attestation',
          'error' in corpus('verify',origin,manifest=manifest,facts=facts[:-1]) and 'error' in corpus('verify',origin,manifest=manifest,facts=facts+[facts[0]]))

    newproof=evidence('5'); newvalues,_=context(newproof['member_faluss_id'],origin,values['creator_faluss_id']); consume(newvalues)
    check('B3c member unknown to the earlier generation invalidates its final primary fence',corpus('finish',origin,corpus_id=cid)==dict(error='pf_corpus_superseded'))
    check('B3c superseded pages and replay stay immutable and never claim current',corpus('create',origin,key=key)==first
          and corpus('page',origin,corpus_id=cid,cursor=second['cursor'])==second)
    current=corpus('create',origin,key=secrets.token_hex(32),observe=True);measurements.append(dict(operation='materialize',facts=102,global_lock_ms=current.pop('global_lock_ms')))
    check('B3c explicit next generation discovers every member and advances owner revision',len(complete(current))==102 and int(current['manifest']['revision'])>int(manifest['revision']))

    old=current; correct(proof,2,1,'partially_cancelled'); revised=corpus('create',origin,key=secrets.token_hex(32))
    check('B3c available-only correction invalidates source vector even when attributed net is unchanged',old['manifest']['net_pf']==revised['manifest']['net_pf']=='102'
          and old['manifest']['full_sha256']!=revised['manifest']['full_sha256'] and corpus('finish',origin,corpus_id=old['manifest']['corpus_id'])==dict(error='pf_corpus_superseded'))
    partial=dict(proof,source_revision='3',evidence_id=str(uuid.uuid4()),cancelled_purchased_pf_cumulative='40',state='partially_cancelled');plan=call('h4-begin',payload=partial,key=secrets.token_hex(32))
    check('B3c partial H4 reconciliation closes new materialization and the entire final fence',corpus('create',origin,key=secrets.token_hex(32))==dict(error='h4_reconciliation_incomplete')
          and corpus('finish',origin,corpus_id=revised['manifest']['corpus_id'])==dict(error='h4_reconciliation_incomplete'))
    check('B3c frozen historical pages remain available during a partial correction',corpus('page',origin,corpus_id=cid,cursor=first['cursor'])==first)
    for fragment in range(1,int(plan['fragment_count'])+1):call('h4-resume',member=proof['member_faluss_id'],plan_id=plan['plan_id'],fragment=str(fragment))
    corrected=corpus('create',origin,key=secrets.token_hex(32))
    check('B3c fully reconciled cumulative partial correction produces exact latest net and preserved original order',corrected['manifest']['net_pf']=='81'
          and len(complete(corrected))==102 and corpus('finish',origin,corpus_id=corrected['manifest']['corpus_id'])['state']=='current')
    for revision,cancelled,state,net in [(4,40,'disputed','1'),(5,40,'partially_cancelled','81'),(6,120,'cancelled','1')]:
        correct(proof,revision,cancelled,state);latest=corpus('create',origin,key=secrets.token_hex(32))
        check('B3c '+state+' revision '+str(revision)+' retains every zero fact and corrected exhaustive net',latest['manifest']['net_pf']==net
              and len(complete(latest))==102 and corpus('finish',origin,corpus_id=latest['manifest']['corpus_id'])['state']=='current')
    closed=next(ref for ref in refs if ref['content']['kind']=='origin');close={k:closed[k] for k in ('barrier_key','version','content_sha256')}|dict(reason='origin_closed')
    call('b3b-close',payload=close,key=secrets.token_hex(32))
    check('B3c effective origin closure preserves confirmed corrected history without restoring cancelled net',corpus('finish',origin,corpus_id=latest['manifest']['corpus_id'])['state']=='current')

    # Create and final-fence mutex durations are measured on actual owner SQL, without identities.
    measured=corpus('finish',origin,corpus_id=latest['manifest']['corpus_id'],observe=True)
    measurements.append(dict(operation='finish',facts=102,global_lock_ms=measured['global_lock_ms']))
    for fault in ('b3c-corpus-insert','b3c-page-insert'):
        stable=secrets.token_hex(32);before=sql('SELECT revision FROM '+prefix+'counter')
        check('B3c '+fault+' rolls back corpus pages durable key and revision together',corpus('create',origin,key=stable,fault=fault,marker='')==dict(error='h2_storage_unavailable')
              and sql('SELECT revision FROM '+prefix+'counter')==before and corpus('lookup',origin,key=stable)==dict(state='absent'))
        recovered=corpus('create',origin,key=stable)
        check('B3c same-key retry after '+fault+' produces one complete generation',corpus('lookup',origin,key=stable)==dict(state='materialized',page=recovered))
    for fault in ('before-commit','after-commit','commit-unknown'):
        stable=secrets.token_hex(32);before=int(sql('SELECT COUNT(*) FROM '+prefix+'corpora'))
        if fault=='commit-unknown':
            check('B3c lost COMMIT acknowledgement is unknown rather than an asserted rollback',corpus('create',origin,key=stable,fault=fault,marker='')==dict(error='pf_corpus_commit_unknown'))
        else:
            marker=root/uuid.uuid4().hex;process=start(dict(action='b3c-create',origin=origin,key=stable,clock_value=clock(),fault=fault,marker=str(marker)))
            await_file(marker,[process]);os.killpg(process.pid,signal.SIGKILL);process.wait(timeout=10)
        lookup=corpus('lookup',origin,key=stable)
        check('B3c primary lookup resolves '+fault+' before exact recovery',lookup.get('state')==('absent' if fault=='before-commit' else 'materialized')
              and int(sql('SELECT COUNT(*) FROM '+prefix+'corpora'))==before+int(fault!='before-commit'))
        recovered=corpus('create',origin,key=stable)
        check('B3c '+fault+' stable recovery never creates a second generation',int(sql('SELECT COUNT(*) FROM '+prefix+'corpora'))==before+1
              and corpus('create',origin,key=stable)==recovered)

    race_values,_=context(newproof['member_faluss_id']);race_origin=race_values['ranking_context']['origin_id'];consume(race_values)
    next_values=dict(race_values,attribution_id=str(uuid.uuid4()));marker=root/uuid.uuid4().hex;waiting=root/uuid.uuid4().hex
    reader=start(dict(action='b3c-create',origin=race_origin,key=secrets.token_hex(32),clock_value=clock(),fault='b3o-hold-global',marker=str(marker)))
    await_file(marker,[reader]);writer=start(dict(action='b3r-reserve',payload=next_values,key=secrets.token_hex(32),clock_value=clock(),observe=True,wait_marker=str(waiting)))
    await_file(waiting,[reader,writer]);check('B3c materialization serializes with consumption on the existing owner mutex',writer.poll() is None)
    marker.with_name(marker.name+'.release').write_text('release');frozen=finish(reader);reserved=finish(writer)
    check('B3c concurrent materialization contains the complete pre-consumption generation',len(frozen['facts'])==1 and reserved['state']=='reserved')
    assert call('b3r-confirm',payload=next_values,key=secrets.token_hex(32),clock_value=clock())['state']=='confirmed'
    check('B3c subsequent concurrent consumption supersedes the primary fence without changing frozen pages',corpus('finish',race_origin,corpus_id=frozen['manifest']['corpus_id'])==dict(error='pf_corpus_superseded'))
    latest_race=corpus('create',race_origin,key=secrets.token_hex(32));marker=root/uuid.uuid4().hex;waiting=root/uuid.uuid4().hex
    reader=start(dict(action='b3c-finish',origin=race_origin,corpus_id=latest_race['manifest']['corpus_id'],clock_value=clock(),fault='b3o-hold-global',marker=str(marker)))
    await_file(marker,[reader]);correction=dict(newproof,source_revision='2',evidence_id=str(uuid.uuid4()),cancelled_purchased_pf_cumulative='3',state='partially_cancelled')
    writer=start(dict(action='h4-begin',payload=correction,key=secrets.token_hex(32),observe=True,wait_marker=str(waiting)))
    await_file(waiting,[reader,writer]);check('B3c final primary fence serializes with concurrent correction',writer.poll() is None)
    marker.with_name(marker.name+'.release').write_text('release');fenced=finish(reader);plan=finish(writer)
    check('B3c fence attests its linearization instant then pending correction closes exactness',fenced['state']=='current'
          and corpus('finish',race_origin,corpus_id=latest_race['manifest']['corpus_id'])==dict(error='h4_reconciliation_incomplete'))
    for fragment in range(1,int(plan['fragment_count'])+1):call('h4-resume',member=newproof['member_faluss_id'],plan_id=plan['plan_id'],fragment=str(fragment))
    refreshed=corpus('create',race_origin,key=secrets.token_hex(32))
    check('B3c complete correction requires a refreshed generation and never revalidates old source vector',corpus('finish',race_origin,corpus_id=latest_race['manifest']['corpus_id'])==dict(error='pf_corpus_superseded')
          and corpus('finish',race_origin,corpus_id=refreshed['manifest']['corpus_id'])['state']=='current')

    page_saved=sql("SELECT payload_sha256 FROM "+prefix+"pages WHERE corpus_id='"+cid+"' AND page_index=1")
    sql("UPDATE "+prefix+"pages SET payload_sha256=REPEAT('0',64) WHERE corpus_id='"+cid+"' AND page_index=1")
    check('B3c altered page bytes or digest never form an accepted final chain','error' in corpus('page',origin,corpus_id=cid,cursor=second['cursor'])
          and 'error' in corpus('finish',origin,corpus_id=cid))
    sql("UPDATE "+prefix+"pages SET payload_sha256='"+page_saved+"' WHERE corpus_id='"+cid+"' AND page_index=1")
    saved_cursor=sql("SELECT next_cursor FROM "+prefix+"pages WHERE corpus_id='"+cid+"' AND page_index=0")
    sql("UPDATE "+prefix+"pages SET next_cursor='"+str(uuid.uuid4())+"' WHERE corpus_id='"+cid+"' AND page_index=0")
    check('B3c contradictory stored cursor refuses page delivery instead of rebuilding its chain','error' in corpus('page',origin,corpus_id=cid,cursor=first['cursor']))
    sql("UPDATE "+prefix+"pages SET next_cursor='"+saved_cursor+"' WHERE corpus_id='"+cid+"' AND page_index=0")
    sql("DELETE FROM "+prefix+"pages WHERE corpus_id='"+cid+"' AND page_index=1")
    check('B3c missing frozen page cannot be regenerated silently from current facts',corpus('finish',origin,corpus_id=cid)==dict(error='pf_corpus_incomplete')
          and corpus('page',origin,corpus_id=cid,cursor=second['cursor'])==dict(error='pf_corpus_page_unavailable'))
    epoch=sql('SELECT epoch FROM '+prefix+'counter');sql("UPDATE "+prefix+"counter SET epoch='"+str(uuid.uuid4())+"'")
    check('B3c restored foreign materialization epoch refuses lookups and final fence',corpus('lookup',origin,key=key)==dict(error='pf_corpus_counter_divergent')
          and corpus('finish',origin,corpus_id=cid)==dict(error='pf_corpus_counter_divergent'))
    sql("UPDATE "+prefix+"counter SET epoch='"+epoch+"'")
    revision=sql('SELECT revision FROM '+prefix+'counter');sql('UPDATE '+prefix+'counter SET revision=revision+1')
    check('B3c contradictory generation count cannot allocate a reused or skipped revision',corpus('create',origin,key=secrets.token_hex(32))==dict(error='pf_corpus_counter_divergent'))
    sql('UPDATE '+prefix+'counter SET revision='+revision)
    order=sql('SELECT last_order FROM wp_token_engine_pf_b3r_counter');sql('UPDATE wp_token_engine_pf_b3r_counter SET last_order=1001')
    check('B3c a contradictory order counter is refused before evaluating capacity',corpus('create',origin,key=secrets.token_hex(32))==dict(error='pf_ranking_counter_divergent'))
    sql('UPDATE wp_token_engine_pf_b3r_counter SET last_order='+order)
    # Synthetic metadata only: exercise the refusal boundary, not 1001 verified economic consumptions.
    # Restore it before checking immutable historical economic rows.
    numbers=' UNION ALL '.join('SELECT '+str(n)+' AS n' for n in range(int(order)+1,1002))
    sql('INSERT INTO wp_token_engine_pf_b3r_receipts SELECT UUID(),UUID(),r.ordering_epoch,n.n,r.confirmed_at,r.payload_json,r.payload_sha256,r.original_envelope_json '
        'FROM wp_token_engine_pf_b3r_receipts r CROSS JOIN ('+numbers+') n WHERE r.consumption_order='+order)
    sql('UPDATE wp_token_engine_pf_b3r_counter SET last_order=1001')
    check('B3c coherent oversized metadata refuses the entire corpus without truncation',corpus('create',origin,key=secrets.token_hex(32))==dict(error='pf_corpus_capacity_unvalidated'))
    sql('DELETE FROM wp_token_engine_pf_b3r_receipts WHERE consumption_order>'+order)
    sql('UPDATE wp_token_engine_pf_b3r_counter SET last_order='+order)
    sql('ALTER TABLE '+prefix+'pages ENGINE=MyISAM')
    check('B3c nontransactional metadata refuses delivery and installation without repair',corpus('page',origin,corpus_id=cid,cursor=first['cursor'])==dict(error='pf_corpus_schema_unavailable')
          and call('b3c-install')==dict(error='model_schema_divergent'))
    sql('ALTER TABLE '+prefix+'pages ENGINE=InnoDB')
    # Historical inserts may interleave by UUID, so compare the original signed rows by exact line membership.
    check('B3c all historical signed receipts and ledger facts remain exact immutable rows',set(old_h3.splitlines()).issubset(set(sql('SELECT * FROM wp_token_engine_pf_h3_receipts ORDER BY attribution_id').splitlines()))
          and set(old_pf.splitlines()).issubset(set(sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id').splitlines()))
          and sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')==old_alb)
    return measurements
