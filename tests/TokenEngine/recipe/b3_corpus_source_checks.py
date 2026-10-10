"""Exhaustive primary facts, not a published corpus or production dimensioning."""
import copy
import datetime
import hashlib
import json
import secrets
import time
import uuid


def run_checks(root, wp, check, call, sql, start, finish, parallel, await_file):
    measurements = []
    digest = lambda value: hashlib.sha256(json.dumps(value,sort_keys=True,separators=(',',':')).encode()).hexdigest()
    instant = lambda value: value.strftime('%Y-%m-%d %H:%M:%S.%f')

    def clock():
        last=sql('SELECT last_confirmed_at FROM wp_token_engine_pf_b3r_counter')
        previous=int(datetime.datetime.fromisoformat(last).replace(tzinfo=datetime.timezone.utc).timestamp()) if last else 0
        return str(max(previous,int(sql('SELECT UNIX_TIMESTAMP()')))+7)+'.000000'

    def evidence(quantity='120',member=None):
        proof=dict(contract='hub.purchased-pf.h1-model/1.0.0',authority_id='fixture.purchase',purchase_id='synthetic.'+uuid.uuid4().hex,
                   source_revision='1',evidence_id=str(uuid.uuid4()),member_faluss_id=member or str(uuid.uuid4()),purchased_pf=quantity,bonus_pf='0',
                   cancelled_purchased_pf_cumulative='0',state='confirmed',confirmed_at='2026-10-01T10:00:00Z',observed_at='2026-10-05T10:00:00Z',policy_version='1.0.0')
        lot=call('h1-evidence',payload=proof,key=secrets.token_hex(32))['lot_id']
        assert call('h2-admit',lot_id=lot,member=proof['member_faluss_id'],key=secrets.token_hex(32))['state']=='admitted'
        return proof,lot

    def context(member,origin=None,creator=None,quantity='1'):
        now=datetime.datetime.now(datetime.timezone.utc)
        selected=dict(origin_id=origin or str(uuid.uuid4()),policy_version='1.0.0',creator_category='arts',category_revision='1',
                      country_policy_revision=str(uuid.uuid4()),sessions=[dict(session_id=str(uuid.uuid4()),rules_revision='1',rules_sha256='a'*64,
                      admission_revision='1',barrier_version='1',starts_at=instant(now-datetime.timedelta(days=1)),ends_at=instant(now+datetime.timedelta(days=2)),
                      admitted_at=instant(now-datetime.timedelta(seconds=5)),scope='international',territory_policy_revision='',territory_admission_revision='0',country='',territory_ref='')])
        value=dict(attribution_id=str(uuid.uuid4()),member_faluss_id=member,creator_faluss_id=creator or str(uuid.uuid4()),client_authority='fixture.fans',
                   purchased_pf=quantity,policy_version='1.0.0',ranking_context=selected,context_sha256=digest(selected))
        refs=call('b3b-refs',payload=value)['references']
        for ref in refs:
            barrier=dict(content=ref['content'],valid_from=selected['sessions'][0]['starts_at'],valid_until=selected['sessions'][0]['ends_at'])
            if ref['content']['kind']=='admission':barrier['valid_from']=max(ref['content']['starts_at'],ref['content']['admitted_at'])
            # The exact origin/creator barrier may already have been admitted for the other member.
            lookup=call('b3b-register',payload=barrier,key=secrets.token_hex(32))
            assert lookup.get('state')=='active' or lookup==dict(error='pf_barrier_stable_key_or_version_required')
        return value,refs

    def consume(value):
        current=clock(); key=secrets.token_hex(32)
        assert call('b3r-reserve',payload=value,key=key,clock_value=current)['state']=='reserved'
        return call('b3r-confirm',payload=value,key=secrets.token_hex(32),clock_value=current)

    def read(origin,**kw):
        return call('b3o-read',origin=origin,clock_value=clock(),**kw)

    def apply(proof,revision,cancelled,state):
        updated=dict(proof,source_revision=str(revision),evidence_id=str(uuid.uuid4()),cancelled_purchased_pf_cumulative=str(cancelled),state=state)
        plan=call('h4-begin',payload=updated,key=secrets.token_hex(32))
        for fragment in range(int(plan['next_fragment'])+1,int(plan['fragment_count'])+1):
            assert call('h4-resume',member=proof['member_faluss_id'],plan_id=plan['plan_id'],fragment=str(fragment))['next_fragment']==str(fragment)
        return updated

    def ledger():
        return sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')

    proof,lot=evidence(); member=proof['member_faluss_id']; values,refs=context(member); origin=values['ranking_context']['origin_id']
    before=ledger(); empty=read(origin)
    check('B3o admitted origin without attribution yields an explicit exhaustive empty inventory',empty['facts']==[] and ledger()==before)
    check('B3o global corpus permission is not inherited from snapshot wallet or delegated member',
          read(origin,permissions=['pf.snapshot','wallet.read','pf.context.delegate'])==dict(error='pf_permission_denied'))
    check('B3o other fixture peer or audience cannot read the Fans owner origin',
          read(origin,peer='fixture.other')==dict(error='pf_invalid_peer') and read(origin,audience='fixture.other')==dict(error='pf_invalid_peer'))
    check('B3o unknown origin or different policy is never treated as an empty eligible inventory',
          read(str(uuid.uuid4()))==dict(error='pf_corpus_origin_not_admitted') and read(origin,policy='2.0.0')==dict(error='pf_corpus_origin_not_admitted'))
    check('B3o raw owner composition outside its transaction is refused',
          call('b3o-outside',origin=origin)==dict(error='pf_corpus_owner_transaction_required'))
    check('B3o ordinary global write mutex without distinct read lease cannot enumerate another member',
          call('b3o-global-only',member=member)==dict(error='owner_transaction_required'))
    guard=call('b3o-guard',member=member,lot=lot,attribution=values['attribution_id'])
    check('B3o read lease does not acquire member mutex or authorize credit debit correction or nested writes',
          guard['subject_acquired']=='0' and guard['attempts']==dict(subject='owner_transaction_required',credit='owner_transaction_required',
          debit='owner_transaction_required',nested='nested_transaction_refused',correction='owner_transaction_required') and ledger()==before)

    seeded=call('b3s-seed-ranked',payload=values,count='101')['attributions']
    current=read(origin,observe=True); assert 'error' not in current,current.get('error')
    measurements.append(dict(facts=len(current['facts']),allocations=sum(len(f['allocations']) for f in current['facts']),
                             bytes=len(json.dumps(current['facts'],sort_keys=True,separators=(',',':'))),global_lock_ms=current['global_lock_ms']))
    check('B3o owner enumeration includes more than one page of genuine protocol consumptions',
          len(current['facts'])==101 and {f['attribution_id'] for f in current['facts']}==set(seeded))
    orders=[int(f['consumption_order']) for f in current['facts']]
    check('B3o Hub order epoch and primary confirmation agree across the entire owner inventory',
          orders==sorted(set(orders)) and all(f['ordering_epoch']==current['ordering_epoch'] for f in current['facts'])
          and all(f['confirmed_at']<=current['created_at'] for f in current['facts']) and float(current['global_lock_ms'])>0)
    snapshot=call('b3s-create',member=member,key=secrets.token_hex(32)); approved_identity={f['attribution_id']:(f['confirmed_at'],f['consumption_order'],f['context_sha256']) for f in current['facts']}
    check('B3o pack alone contributes no fact and no available balance or payment reference is transmitted',
          len(current['facts'])==101 and all(not {'available_pf','pc','purchase_reference','price','email'} & set(f) for f in current['facts'])
          and all(not {'purchase_reference','available_pf'} & set(a) for f in current['facts'] for a in f['allocations']))

    second_proof,second_lot=evidence('50'); second_member=second_proof['member_faluss_id']
    second_values,_=context(second_member,origin,values['creator_faluss_id']); assert consume(second_values)['state']=='confirmed'
    both=read(origin)
    check('B3o newly consuming member unknown to Fans is discovered without a supplied member list',
          len(both['facts'])==102 and {f['member_faluss_id'] for f in both['facts']}=={member,second_member})
    foreign_proof,_=evidence('5'); foreign_values,_=context(foreign_proof['member_faluss_id']); consume(foreign_values)
    check('B3o confirmed attribution of another origin does not enter the requested origin',len(read(origin)['facts'])==102)
    base=dict(attribution_id=str(uuid.uuid4()),member_faluss_id=foreign_proof['member_faluss_id'],creator_faluss_id=str(uuid.uuid4()),
              client_authority='fixture.fans',purchased_pf='1',policy_version='1.0.0')
    call('h2-reserve',payload=base,key=secrets.token_hex(32)); call('h3-confirm',payload=base,key=secrets.token_hex(32))
    check('B3o historical unranked consumption is never promoted into a ranked corpus',len(read(origin)['facts'])==102)

    multi_proof,_=evidence('10'); multi_member=multi_proof['member_faluss_id']; evidence('10',multi_member)
    multi_values,_=context(multi_member,quantity='20'); multi_origin=multi_values['ranking_context']['origin_id']; consume(multi_values)
    multi=read(multi_origin)['facts']
    check('B3o multi lot attribution remains one exhaustive fact with one original consumption',
          len(multi)==1 and len(multi[0]['allocations'])==2 and multi[0]['purchased_pf']=='20' and multi[0]['net_pf']=='20')
    for revision,cancelled,state,net in [(2,3,'partially_cancelled','17'),(3,3,'disputed','10'),(4,3,'partially_cancelled','17'),(5,10,'cancelled','10')]:
        apply(multi_proof,revision,cancelled,state); revised_multi=read(multi_origin)['facts']
        check('B3o multi lot '+state+' revision '+str(revision)+' composes latest source vector without duplicating consumption',
              len(revised_multi)==1 and revised_multi[0]['net_pf']==net and len(revised_multi[0]['allocations'])==2
              and revised_multi[0]['consumption_id']==multi[0]['consumption_id'] and revised_multi[0]['consumption_order']==multi[0]['consumption_order'])

    updated=dict(proof,source_revision='2',evidence_id=str(uuid.uuid4()),state='partially_cancelled',cancelled_purchased_pf_cumulative='40')
    correction_key=secrets.token_hex(32); plan=call('h4-begin',payload=updated,key=correction_key)
    check('B3o incomplete H4 reconciliation refuses the whole origin rather than silently skipping its member',
          read(origin)==dict(error='h4_reconciliation_incomplete'))
    assert int(plan['fragment_count'])>1
    call('h4-resume',member=member,plan_id=plan['plan_id'],fragment='1')
    check('B3o remaining correction fragment still prevents a falsely exact origin read',read(origin)==dict(error='h4_reconciliation_incomplete'))
    for fragment in range(2,int(plan['fragment_count'])+1):call('h4-resume',member=member,plan_id=plan['plan_id'],fragment=str(fragment))
    corrected=read(origin)
    check('B3o cumulative reduction cancels available first then newest allocations with exact net',
          len(corrected['facts'])==102 and sum(int(f['net_pf']) for f in corrected['facts'])==81
          and sum(int(f['cancelled_pf']) for f in corrected['facts'])==21)
    check('B3o latest H4 correction never changes original confirmation order or selected dimensions',
          all((f['confirmed_at'],f['consumption_order'],f['context_sha256'])==approved_identity[f['attribution_id']]
              for f in corrected['facts'] if f['member_faluss_id']==member))
    for revision,state,net in [(3,'disputed',1),(4,'partially_cancelled',81),(5,'cancelled',1)]:
        apply(proof,revision,120 if state=='cancelled' else 40,state); after=read(origin)
        check('B3o complete '+state+' revision '+str(revision)+' keeps every zero fact and exact current net',
              len(after['facts'])==102 and sum(int(f['net_pf']) for f in after['facts'])==net)
    call('h4-begin',payload=updated,key=correction_key)
    check('B3o replay of an older correction never replaces the latest complete source revision',
          sum(int(f['net_pf']) for f in read(origin)['facts'])==1)

    original_ref=next(ref for ref in refs if ref['content']['kind']=='origin')
    close={k:original_ref[k] for k in ('barrier_key','version','content_sha256')}|dict(reason='origin_closed')
    assert call('b3b-close',payload=close,key=secrets.token_hex(32))['state']=='closed'
    check('B3o closing origin preserves the attested historical inventory without reopening choices',len(read(origin)['facts'])==102)
    check('B3o old receipt or H4 generation never restores cancelled points in a new owner read',
          sum(int(f['net_pf']) for f in read(origin)['facts'])==1 and snapshot['manifest']['net_pf']=='101')

    # New independent origin to measure reads against a blocked subsequent consumption.
    racing_values,_=context(second_member); racing_origin=racing_values['ranking_context']['origin_id']
    consume(racing_values); pending=dict(racing_values,attribution_id=str(uuid.uuid4()))
    marker=root/uuid.uuid4().hex; waiting=root/uuid.uuid4().hex
    reader=start(dict(action='b3o-read',origin=racing_origin,clock_value=clock(),observe=True,fault='b3o-hold-global',marker=str(marker)))
    await_file(marker,[reader]); key=secrets.token_hex(32)
    writer=start(dict(action='b3r-reserve',payload=pending,key=key,clock_value=clock(),observe=True,wait_marker=str(waiting)))
    await_file(waiting,[reader,writer]); check('B3o concurrent consumption waits on the same owner mutex without acquiring it in reverse order',writer.poll() is None)
    marker.with_name(marker.name+'.release').write_text('release'); old=finish(reader); reserved=finish(writer)
    check('B3o concurrent read is complete before subsequent reservation and preserves stable keys',len(old['facts'])==1 and reserved['state']=='reserved')
    assert call('b3r-confirm',payload=pending,key=secrets.token_hex(32),clock_value=clock())['state']=='confirmed'
    check('B3o reread after concurrent confirmed consumption includes it exactly once',len(read(racing_origin)['facts'])==2)

    marker=root/uuid.uuid4().hex; waiting=root/uuid.uuid4().hex
    reader=start(dict(action='b3o-read',origin=racing_origin,clock_value=clock(),fault='b3o-hold-global',marker=str(marker)))
    await_file(marker,[reader]); latest=dict(second_proof,source_revision='2',evidence_id=str(uuid.uuid4()),state='partially_cancelled',cancelled_purchased_pf_cumulative='45')
    corrector=start(dict(action='h4-begin',payload=latest,key=secrets.token_hex(32),observe=True,wait_marker=str(waiting)))
    await_file(waiting,[reader,corrector]); check('B3o concurrent correction waits for the same global read mutex',corrector.poll() is None)
    marker.with_name(marker.name+'.release').write_text('release'); old=finish(reader); plan=finish(corrector)
    check('B3o complete read linearizes before correction and subsequent pending source is unavailable',
          len(old['facts'])==2 and read(racing_origin)==dict(error='h4_reconciliation_incomplete'))
    for fragment in range(1,int(plan['fragment_count'])+1):call('h4-resume',member=second_member,plan_id=plan['plan_id'],fragment=str(fragment))
    check('B3o read recovers after the entire concurrent correction is durably reconciled',len(read(racing_origin)['facts'])==2)

    # Contradictory restored metadata or damaged original bytes never create a partial answer.
    epoch=sql('SELECT ordering_epoch FROM wp_token_engine_pf_b3r_counter')
    sql("UPDATE wp_token_engine_pf_b3r_counter SET ordering_epoch='"+str(uuid.uuid4())+"'")
    check('B3o contradictory owner ordering epoch refuses every partial inventory',read(origin)==dict(error='pf_ranking_counter_divergent'))
    sql("UPDATE wp_token_engine_pf_b3r_counter SET ordering_epoch='"+epoch+"'")
    attribution=seeded[0]; saved=sql("SELECT payload_sha256 FROM wp_token_engine_pf_b3r_receipts WHERE attribution_id='"+attribution+"'")
    sql("UPDATE wp_token_engine_pf_b3r_receipts SET payload_sha256=REPEAT('0',64) WHERE attribution_id='"+attribution+"'")
    check('B3o altered original receipt bytes or digest never silently exclude its member',read(origin)==dict(error='pf_corpus_missing_filiation'))
    sql("UPDATE wp_token_engine_pf_b3r_receipts SET payload_sha256='"+saved+"' WHERE attribution_id='"+attribution+"'")
    current=read(origin,observe=True)
    measurements.append(dict(facts=len(current['facts']),allocations=sum(len(f['allocations']) for f in current['facts']),
                             bytes=len(json.dumps(current['facts'],sort_keys=True,separators=(',',':'))),global_lock_ms=current['global_lock_ms']))
    check('B3o restored exact original metadata recovers the corrected complete inventory without economic repair',
          len(current['facts'])==102 and sum(int(f['net_pf']) for f in current['facts'])==1)

    # Economic tables are unchanged by every standalone read and rejected write attempt.
    before=ledger(); repeated=read(origin)
    check('B3o exhaustive owner read emits no new PF ledger entry or delivery receipt',ledger()==before and len(repeated['facts'])==102)
    return measurements
