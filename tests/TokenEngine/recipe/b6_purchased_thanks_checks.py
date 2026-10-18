"""Actual owner consumption and signed inbox/corpus, with durable ordinary-message delivery."""
from concurrent.futures import ThreadPoolExecutor
import copy
import hashlib
import json
import secrets
import uuid


def run_checks(fixture,origin,actors,member,deliver,hub,hub_sql,sql,check):
    creator=actors['creator_profile'];sender=actors['creator_user'];admin=actors['admin_user'];fan=actors['fan_user']
    assert deliver('thanks-install',actor=admin)==dict(ready=True)
    def own():return deliver('thanks-settings',actor=sender)
    def save(body,enabled=True,kind='thanks'):
        return deliver('thanks-save',actor=sender,kind=kind,body=body,enabled=enabled,revision=own()[kind]['revision'])
    def stats():return deliver('thanks-stats',actor=admin,creator=creator)
    def events(kind='thanks'):return [row for row in stats()['events'] if row['kind']==kind]
    def due():sql("UPDATE wp_faluss_fans_dm_automatic_events SET next_attempt_at=UTC_TIMESTAMP() WHERE state='retry'")
    def refresh():
        fixture['set_clock']();read=str(uuid.uuid4())
        assert fixture['inbox']('advance',origin,read_id=read,steps=4)['state']=='verified'
        fixture['inbox']('cache-rebuild',origin)
    def create(amount='1',lost=False):
        purchase=dict(fixture['proof'],purchase_id='synthetic.'+uuid.uuid4().hex,evidence_id=str(uuid.uuid4()),member_faluss_id=member,purchased_pf=amount)
        lot=hub('h1-evidence',payload=purchase,key=secrets.token_hex(32))['lot_id']
        admitted=hub('h2-admit',lot_id=lot,member=member,key=secrets.token_hex(32));assert admitted.get('state')=='admitted'
        intent=copy.deepcopy(fixture['intent']);intent.update(attribution_id=str(uuid.uuid4()),member_faluss_id=member,purchased_pf=amount)
        intent['ranking_context'].update(origin_id=origin,sessions=[])
        intent['context_sha256']=hashlib.sha256(json.dumps(intent['ranking_context'],sort_keys=True,separators=(',',':')).encode()).hexdigest()
        reserve=deliver('thanks-prepare',intent=intent,operation='reserve')['key']
        confirm=deliver('thanks-prepare',intent=intent,operation='confirm')['key']
        fixture['set_clock']();clock=fixture['set_clock'].path.read_text().strip()
        reservation=hub('b3r-reserve',payload=intent,key=reserve,clock_value=clock)
        assert reservation.get('state')=='reserved','Real PF thanks reservation refused: '+str(reservation.get('error'))
        confirmation=hub('b3r-confirm',payload=intent,key=confirm,clock_value=clock,**(dict(fault='commit-unknown') if lost else {}))
        if lost:
            assert 'error' in confirmation
            confirmation=hub('b3r-lookup',payload=intent,key=confirm,operation='confirm',clock_value=clock)
        assert confirmation.get('state')=='confirmed','Real PF thanks confirmation refused: '+str(confirmation.get('error'))
        return purchase,intent,confirmation['receipt']
    def receive(intent,receipt,**extra):return deliver('thanks-receive',intent=intent,receipt=receipt,**extra)
    def correct(purchase,revision,state,cancelled):
        evidence=dict(purchase,source_revision=str(revision),evidence_id=str(uuid.uuid4()),state=state,cancelled_purchased_pf_cumulative=str(cancelled))
        plan=hub('h4-begin',payload=evidence,key=secrets.token_hex(32))
        for fragment in range(int(plan['next_fragment'])+1,int(plan['fragment_count'])+1):
            hub('h4-resume',member=member,plan_id=plan['plan_id'],fragment=str(fragment))
        refresh()
    check('B6at a real typed closed source permits configuration independently of welcome and without Fan options',
          own()['thanks_delivery_available'] is True and save('Merci fictif sélectionné à la confirmation.')['enabled'] is True
          and own()['welcome']['enabled'] is False)
    purchase,intent,receipt=create('2',lost=True)
    receipts=int(hub_sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3r_receipts'))
    # The owner debit already exists; every local retry below must leave it and the same receipt intact.
    ledger=hub_sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')
    check('B6at failed local event capture rolls back its receipt and snapshot while leaving the confirmed owner debit',
          receive(intent,receipt,fault='thanks-insert')==dict(error='pf_thanks_storage_unavailable')
          and sql('SELECT COUNT(*) FROM wp_fans_pf_b3r_receipts')=='0' and events()==[]
          and hub_sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')==ledger)
    with ThreadPoolExecutor(max_workers=2) as pool:responses=list(pool.map(lambda _:receive(intent,receipt),range(2)))
    check('B6at concurrent authenticated receipts capture one frozen event for all persistent projections',
          sorted(row['inserted'] for row in responses)==[False,True] and len(events())==1 and events()[0]['body']=='Merci fictif sélectionné à la confirmation.')
    assert save('Nouveau texte fictif pour les prochaines attributions.')['enabled']
    assert deliver('thanks-run')['processed']==1
    check('B6at an incomplete current corpus sends nothing and preserves the original key with increasing delay',
          stats()['messages']==[] and events()[0]['state']=='retry' and int(events()[0]['wait'])>0)
    refresh();due()
    failed=deliver('thanks-run',fault='message-outcome')
    check('B6at failure after real message insertion rolls back message notification and queue outcome together',
          stats()['messages']==[] and events()[0]['state']=='retry' and failed.get('processed')==1)
    due();assert deliver('thanks-run')['processed']==1
    check('B6at positive attested attribution delivers one original text through the ordinary automatic conversation',
          len(stats()['messages'])==1 and stats()['messages'][0]['body']=='Merci fictif sélectionné à la confirmation.'
          and stats()['messages'][0]['state']=='automatic' and events()[0]['state']=='sent' and events()[0]['body']=='')
    assert receive(intent,receipt)==dict(inserted=False)
    assert deliver('thanks-run')['processed']==0
    check('B6at receipt replay never sends again or creates a second official consumption',
          len(stats()['messages'])==1 and len(events())==1 and int(hub_sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3r_receipts'))==receipts
          and hub_sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')==ledger)
    # The settings and actual free follow are independent from PF delivery, with the same ordinary conversation.
    assert save('Bienvenue fictive indépendante.',kind='welcome')['enabled']
    assert deliver('thanks-follow',actor=fan,creator=creator)['following'] is True
    assert deliver('thanks-run')['processed']==1
    check('B6at an actual follow still delivers its own welcome once independently from the consumed attribution',
          len(stats()['messages'])==2 and len(events('welcome'))==1 and events('welcome')[0]['state']=='sent')
    second,other,other_receipt=create()
    assert receive(other,other_receipt,fault='commit-unknown')==dict(error='pf_local_commit_unknown')
    assert receive(other,other_receipt)==dict(inserted=False)
    refresh();assert deliver('thanks-run',fault='delivery-commit-unknown')['processed']==1
    check('B6at response lost after local capture COMMIT is recovered without a new event or new text selection',
          len(stats()['messages'])==3 and sum(row['state']=='sent' for row in events())==2)
    check('B6at a lost delivery COMMIT acknowledgement preserves the committed message and never redelivers it',
          deliver('thanks-run')['processed']==0 and len(stats()['messages'])==3 and events()[0]['body']=='')
    third,pending,pending_receipt=create()
    assert receive(pending,pending_receipt)['inserted']
    assert save('Texte désactivé.',False)['enabled'] is False
    refresh();assert deliver('thanks-run')['processed']==0
    assert save('Texte réactivé pour événements futurs.')['enabled']
    check('B6at disable cancels pending thanks and reenable or receipt replay never resurrects it',
          receive(pending,pending_receipt)==dict(inserted=False) and deliver('thanks-run')['processed']==0 and len(stats()['messages'])==3)
    fourth,disputed,disputed_receipt=create()
    assert receive(disputed,disputed_receipt)['inserted']
    correct(fourth,2,'disputed',0);assert deliver('thanks-run')['processed']==1
    check('B6at an attested zero-net disputed attribution cancels its pending message without changing other messages',
          len(stats()['messages'])==3 and any(row['error']=='source_not_positive' for row in events()))
    correct(fourth,3,'confirmed',0)
    check('B6at dispute restoration and complete corpus rebuild do not create a new thank-you',
          receive(disputed,disputed_receipt)==dict(inserted=False) and deliver('thanks-run')['processed']==0 and len(stats()['messages'])==3)
    fifth,blocked,blocked_receipt=create()
    assert receive(blocked,blocked_receipt)['inserted']
    thread=stats()['messages'][0]
    assert 'error' not in deliver('thanks-decide',actor=fan,thread=thread['thread_id'],revision=int(thread['revision']),decision='block')
    refresh();assert deliver('thanks-run')['processed']==1
    check('B6at current Fan blocking cancels delivery even for an already confirmed positive attribution',
          len(stats()['messages'])==3 and any(row['error']=='recipient_unavailable' for row in events()))
    thread=stats()['messages'][0]
    assert 'error' not in deliver('thanks-decide',actor=fan,thread=thread['thread_id'],revision=int(thread['revision']),decision='unblock')
    assert deliver('thanks-run')['processed']==0
    check('B6at unblocking does not replay suppressed automations and never creates a Fans ledger',
          len(stats()['messages'])==3 and sql("SHOW TABLES LIKE 'wp_fans%ledger%'")=='')
    partial,partial_intent,partial_receipt=create('2')
    assert receive(partial_intent,partial_receipt)['inserted']
    correct(partial,2,'partially_cancelled',1)
    assert deliver('thanks-run')['processed']==1
    check('B6at a latest partial correction with positive net still permits the original attribution message once',
          len(stats()['messages'])==4 and receive(partial_intent,partial_receipt)==dict(inserted=False))
    correct(partial,3,'cancelled',2)
    check('B6at a later total correction never sends a new message or retracts the ordinary delivered history',
          deliver('thanks-run')['processed']==0 and len(stats()['messages'])==4)
    batch=[create() for _ in range(2)]
    for _,batch_intent,batch_receipt in batch:assert receive(batch_intent,batch_receipt)['inserted']
    refresh();assert deliver('thanks-run')['processed']==2
    check('B6at two distinct close attributions deliver separately without duplication across projections',
          len(stats()['messages'])==6 and deliver('thanks-run')['processed']==0)
