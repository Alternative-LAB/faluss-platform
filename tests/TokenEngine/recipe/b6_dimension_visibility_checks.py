"""One real consumption in three genuine session dimensions, then primary visibility changes."""
import copy
import hashlib
import json
import secrets
import uuid


def run_checks(fixture,origin,actors,deliver,hub,hub_sql,sql,check):
    admin=actors['admin_user'];participant=actors['creator_user'];profile=actors['creator_profile']
    other=deliver('fixture-extra-creator');owner=other['creator_user'];policy=str(uuid.uuid4())
    assert deliver('dimension-install',actor=admin)==dict(ready=True)
    criteria=dict(criteria_url='https://criteria.example.invalid/dimensions',criteria_text='Fictitious principal activity criteria.',
        countries=['FR'],territories={'FR-PARIS':dict(country='FR',name='Fictitious activity area')})
    assert deliver('dimension-policy',actor=admin,policy=policy,criteria=criteria)['policy_id']==policy
    def own(actor):return deliver('dimension-own',actor=actor)
    def approve(actor,creator):
        current=own(actor)
        row=deliver('dimension-submit',actor=actor,policy=policy,country='FR',reference='FR-PARIS',revision=int(current['revision']))
        return deliver('dimension-decide',actor=admin,creator=creator,revision=int(row['revision']),decision='approve',reason='criteria_satisfied')
    def revoke(actor,creator):
        return deliver('dimension-decide',actor=admin,creator=creator,revision=int(own(actor)['revision']),decision='revoke',reason='needs_revision')
    assert approve(owner,other['creator_profile'])['state']=='approved'
    assert approve(participant,profile)['state']=='approved'
    category=fixture['intent']['ranking_context']['creator_category'];sessions={};contexts=[]
    for scope in ('local','national','international'):
        session=deliver('dimension-create',actor=owner,scope=scope,policy=policy,category=category)
        assert 'id' in session,'Genuine dimension opening refused: '+str(session.get('error'))
        deliver('prepare-read',action_id=session['action_id'])
        assert deliver('advance-read',action_id=session['action_id'])['phase']=='current'
        context=deliver('dimension-admit',actor=owner,session=session['id'],participant_actor=participant)
        assert 'session_id' in context,'Genuine dimension admission refused: '+str(context.get('error'))
        sessions[scope]=session;contexts.append(context)
    purchase=dict(fixture['proof'],purchase_id='synthetic.'+uuid.uuid4().hex,evidence_id=str(uuid.uuid4()),
        member_faluss_id=fixture['dimension_member'],purchased_pf='4')
    lot=hub('h1-evidence',payload=purchase,key=secrets.token_hex(32))['lot_id']
    hub('h2-admit',lot_id=lot,member=purchase['member_faluss_id'],key=secrets.token_hex(32))
    intent=copy.deepcopy(fixture['intent']);intent.update(attribution_id=str(uuid.uuid4()),member_faluss_id=purchase['member_faluss_id'],purchased_pf='4')
    intent['ranking_context'].update(origin_id=origin,sessions=sorted(contexts,key=lambda value:value['session_id']))
    intent['context_sha256']=hashlib.sha256(json.dumps(intent['ranking_context'],sort_keys=True,separators=(',',':')).encode()).hexdigest()
    receipts=int(hub_sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3r_receipts'))
    fixture['consume'](intent)
    def refresh():
        fixture['set_clock']();read=str(uuid.uuid4())
        assert fixture['inbox']('advance',origin,read_id=read,steps=4)['state']=='verified'
        fixture['inbox']('cache-rebuild',origin)
        return fixture['inbox']('cache-read',origin)
    refresh();deliver('consent',actor=actors['fan_user'],family='fan',enabled=True)
    def session(scope,family='creator'):return deliver('session',session=sessions[scope]['id'],family=family)
    def points(scope,family='creator'):return [row['points'] for row in session(scope,family).get('items',[])]
    check('B6dv one confirmed consumption feeds three actual dimensions and both families without three debits',
          int(hub_sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3r_receipts'))==receipts+1
          and all(points(scope)==['4'] and points(scope,'fan')==['4'] for scope in sessions))
    before=fixture['inbox']('cache-read',origin)
    ledger=hub_sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')
    history=hub_sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')
    assert revoke(participant,profile)['state']=='rejected'
    check('B6dv revoked principal territory immediately masks only local and national Creator rows',
          points('local')==[] and points('national')==[] and points('international')==['4']
          and all(points(scope,'fan')==['4'] for scope in sessions))
    check('B6dv territorial exclusion leaves persistent general category and private monthly points intact',
          deliver('ranking',family='creator')['items'][0]['points']=='6'
          and deliver('ranking',family='creator',category=category)['items'][0]['points']=='6'
          and sum(int(deliver('statement',actor=participant,family='creator',month=month)['points']) for month in before['document']['months'])==6)
    check('B6dv a visibility change neither edits the authenticated projection nor either official ledger',
          fixture['inbox']('cache-read',origin)==before and hub_sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')==ledger
          and hub_sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')==history and sql("SHOW TABLES LIKE 'wp_fans%ledger%'")=='')
    correction=dict(purchase,source_revision='2',evidence_id=str(uuid.uuid4()),state='partially_cancelled',cancelled_purchased_pf_cumulative='1')
    plan=hub('h4-begin',payload=correction,key=secrets.token_hex(32))
    for fragment in range(int(plan['next_fragment'])+1,int(plan['fragment_count'])+1):
        hub('h4-resume',member=purchase['member_faluss_id'],plan_id=plan['plan_id'],fragment=str(fragment))
    refresh()
    check('B6dv corrections continue while the territorial Creator dimensions are hidden',
          points('local')==[] and points('national')==[] and points('international')==['3']
          and all(points(scope,'fan')==['3'] for scope in sessions)
          and deliver('ranking',family='creator')['items'][0]['points']=='5')
    assert approve(participant,profile)['state']=='approved'
    check('B6dv reviewed readmission reconstructs corrected historical rows without restoring cancelled points',
          all(points(scope)==['3'] for scope in sessions)
          and int(hub_sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3r_receipts'))==receipts+1)
    table='wp_fans_hof_territory_creators';temporary=table+'_read_failure'
    sql('RENAME TABLE '+table+' TO '+temporary)
    try:
        check('B6dv a missing territorial schema fails the whole affected delivery rather than returning an exact-looking empty ranking',
              'error' in session('national') and points('international')==['3']
              and deliver('ranking',family='creator')['items'][0]['points']=='5')
    finally:sql('RENAME TABLE '+temporary+' TO '+table)
    closed=deliver('close-session',actor=owner,session=sessions['national']['id'],acknowledge=True)
    assert closed.get('session_state')=='cancelled'
    deliver('prepare-read',action_id=closed['action_id']);assert deliver('advance-read',action_id=closed['action_id'])['phase']=='current'
    assert points('national')==['3']
    assert revoke(owner,other['creator_profile'])['state']=='rejected'
    check('B6dv historical territorial metadata is hidden on organizer territory revocation without hiding its international session',
          session('national')==dict(error='hof_session_unavailable') and points('international')==['3']
          and deliver('ranking',family='creator')['items'][0]['points']=='5')
