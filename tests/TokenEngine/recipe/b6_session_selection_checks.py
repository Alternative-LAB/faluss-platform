"""Actual native admissions, linked local sessions and existing signed 0.3 HTTP. Fictitious pair only."""
import copy
import hashlib
import json
import secrets
import shutil
import uuid
from concurrent.futures import ThreadPoolExecutor


def run_checks(root,source,fixture,origin,actors,deliver,hub,hub_sql,sql,check):
    target=deliver('fixture-extra-creator');organizer=deliver('fixture-extra-creator');country=str(uuid.uuid4())
    admin=actors['admin_user'];fan=actors['fan_user'];profile=target['creator_profile']
    assert deliver('dimension-install',actor=admin)==dict(ready=True)
    creator_policy=deliver('selection-register',actor=admin,profile=profile,country=country,kind='creator')
    country_policy=deliver('selection-register',actor=admin,profile=profile,country=country,kind='country')
    assert 'action_id' in creator_policy and 'action_id' in country_policy,\
        'Primary policy fixture unavailable: '+str(creator_policy.get('error','unknown'))+'/'+str(country_policy.get('error','unknown'))
    cookie=deliver('fixture-cookie',actor=fan)['value']
    fixed=dict(actor=fan,cookie=cookie,profile=profile,creator_action=creator_policy['action_id'],country_action=country_policy['action_id'])
    def own(action,**fields):return deliver(action,**(fixed|fields))
    def intent(sessions=None,**fields):
        return own('selection-resolve',attribution=str(uuid.uuid4()),quantity='1',sessions=[] if sessions is None else sessions,**fields)
    check('B6sc an eligible creator without admitted sessions has an exact empty choice list',own('selection-choices')==dict(items=[],next_cursor=None))
    sessions=[]
    for _ in range(2):
        session=deliver('dimension-create',actor=organizer['creator_user'],scope='international',policy=country,category='arts')
        assert 'id' in session
        deliver('prepare-read',action_id=session['action_id']);assert deliver('advance-read',action_id=session['action_id'])['phase']=='current'
        admitted=deliver('dimension-admit',actor=organizer['creator_user'],session=session['id'],participant_actor=target['creator_user'])
        assert admitted.get('session_id')==session['id']
        check('B6sc historical admission ACK alone cannot authorize a current prospective selection','error' in own('selection-choices'))
        assert deliver('selection-refresh',actor=admin,session=session['id'],profile=profile)['phase']=='current'
        sessions.append(session)
    choices=own('selection-choices')
    check('B6sc current native admissions expose only the two eligible sessions without scores or preselection',
        sorted(row['session_id'] for row in choices['items'])==sorted(row['id'] for row in sessions)
        and choices['next_cursor'] is None and all('points' not in row and 'selected' not in row for row in choices['items']))
    first=sorted(row['id'] for row in sessions)[0]
    check('B6sc stable keyset resumes after the selected session rather than repeating it',
        len(own('selection-choices',after=first)['items'])==1 and own('selection-choices',after=first)['items'][0]['session_id']!=first)
    plain=intent();chosen=intent([row['id'] for row in reversed(sessions)])
    check('B6sc empty selection stays empty and explicit choices resolve all frozen fields on the server',
        plain['ranking_context']['sessions']==[] and chosen['creator_faluss_id']==creator_policy['subject']
        and chosen['member_faluss_id']==fixture['dimension_member']
        and [row['session_id'] for row in chosen['ranking_context']['sessions']]==sorted(row['id'] for row in sessions)
        and chosen['context_sha256']==hashlib.sha256(json.dumps(chosen['ranking_context'],sort_keys=True,separators=(',',':')).encode()).hexdigest())
    check('B6sc duplicate excessive unknown and fabricated session contexts are rejected as a whole',
        intent([first,first])==dict(error='pf_ranking_duplicate_or_order')
        and intent([str(uuid.uuid4()) for _ in range(11)])==dict(error='pf_ranking_invalid_sessions')
        and 'error' in intent([str(uuid.uuid4())]) and 'error' in intent([dict(session_id=first)]))
    self_cookie=deliver('fixture-cookie',actor=target['creator_user'])['value']
    expired=deliver('fixture-cookie',actor=fan,expired=True)['value']
    check('B6sc visitor self-attribution and expired local session cannot resolve an intention',
        'error' in intent(actor=0,cookie='invalid') and 'error' in intent(actor=target['creator_user'],cookie=self_cookie)
        and 'error' in intent(cookie=expired))
    policies={}
    for role in ('hub','fans'):
        policies[role]=copy.deepcopy(fixture['policies'][role])
        policies[role]['permissions']+=['pf.ranking.context.register','pf.ranking.context.close','pf.lookup','pf.reserve','pf.confirm','pf.release','pf.context.delegate']
        fixture['policy'](role,policies[role])
    # Existing approved gateway/clock adapters only; no runner or environment-preparation changes.
    policy_path=root/'hub-ranked-trust.json';adapter=root/'wordpress/wp-content/mu-plugins/b6-selection-ranked.php'
    clock_adapter=root/'wordpress/wp-content/mu-plugins/b6-selection-clock.php'
    assert not policy_path.exists() and not adapter.exists() and not clock_adapter.exists()
    policy_path.write_text(json.dumps(policies['hub']));policy_path.chmod(0o600)
    shutil.copy2(source/'tests/TokenEngine/recipe/b3-ranked-http-adapter.php',adapter)
    shutil.copy2(source/'tests/TokenEngine/recipe/b6-selection-clock.php',clock_adapter)
    try:
        purchased=dict(fixture['proof'],purchase_id='synthetic.'+uuid.uuid4().hex,evidence_id=str(uuid.uuid4()),member_faluss_id=chosen['member_faluss_id'],purchased_pf='5')
        lot=hub('h1-evidence',payload=purchased,key=secrets.token_hex(32))['lot_id']
        assert hub('h2-admit',lot_id=lot,member=purchased['member_faluss_id'],key=secrets.token_hex(32))['state']=='admitted'
        count=int(hub_sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3r_receipts'))
        historical=hub_sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')
        reserved=own('selection-reserve',intent=chosen)
        check('B6sc the native server selection reserves purchased units over genuine signed HTTP without scoring a pack',
            reserved==dict(outcome='ok',result=dict(state='reserved')) and int(hub_sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3r_receipts'))==count)
        fault=root/'ranked-http-fault';fault.write_text('drop-after-commit');fault.chmod(0o600)
        check('B6sc a lost successful 0.3 confirmation response is uncertain while exactly one owner consumption persists',
            own('selection-confirm',attribution=chosen['attribution_id'])==dict(error='pf_transport_unknown')
            and int(hub_sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3r_receipts'))==count+1)
        deliver('suspend',creator_profile=profile,status='suspended')
        check('B6sc lookup recovers the stored immutable choices even after the creator becomes unavailable',
            own('selection-lookup',attribution=chosen['attribution_id'],operation='confirm')==dict(outcome='ok',result=dict(state='confirmed')))
        check('B6sc exact repeat does not choose current sessions or debit twice and another identity cannot recover',
            own('selection-confirm',attribution=chosen['attribution_id'])==dict(outcome='ok',result=dict(state='confirmed'))
            and int(hub_sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3r_receipts'))==count+1
            and 'error' in own('selection-lookup',actor=target['creator_user'],cookie=self_cookie,attribution=chosen['attribution_id'],operation='confirm')
            and hub_sql('SELECT * FROM wp_token_engine_ledger ORDER BY id')==historical)
        deliver('suspend',creator_profile=profile,status='active')
        run_attempt_checks(root,own,hub_sql,first,check)
        from b6_session_selection_ui_checks import run_checks as ui_checks
        ui_checks(root,source,fixture,origin,actors,deliver,own,
            dict(profile=profile,creator_action=creator_policy['action_id'],country_action=country_policy['action_id'],sessions=[row['id'] for row in sessions]),hub_sql,check)
        closing=deliver('close-session',actor=organizer['creator_user'],session=first)
        check('B6sc local closing forbids new attachment while retaining the old confirmed immutable intention',
            closing['state']=='closing' and 'error' in intent([first])
            and own('selection-lookup',attribution=chosen['attribution_id'],operation='confirm')==dict(outcome='ok',result=dict(state='confirmed')))
    finally:
        adapter.unlink(missing_ok=True);clock_adapter.unlink(missing_ok=True);policy_path.unlink(missing_ok=True)


def run_attempt_checks(root,own,hub_sql,session,check):
    """Real SQL checkpoints and owner HTTP; no mocked consumption or client response."""
    attempt=str(uuid.uuid4());different=str(uuid.uuid4())
    args=dict(attribution=attempt,quantity='1',sessions=[session])
    def advance(operation):return own('selection-attempt-advance',attribution=attempt,operation=operation)
    count=int(hub_sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3r_receipts'))
    fault=root/'ranked-http-fault';fault.write_text('drop-after-commit');fault.chmod(0o600)
    check('B6sc a lost reserve response leaves a durable unknown member checkpoint before any confirmation',
        own('selection-attempt-start',**args)==dict(error='pf_transport_unknown')
        and own('selection-attempt-current')['phase']=='unknown'
        and int(hub_sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3r_receipts'))==count)
    check('B6sc a new intention and direct retry cannot bypass unresolved reserve uncertainty',
        own('selection-attempt-start',**(args|dict(attribution=different)))==dict(error='pf_local_attempt_pending')
        and advance('retry')==dict(error='pf_local_attempt_lookup_required')
        and advance('confirm')==dict(error='pf_local_attempt_lookup_required'))
    check('B6sc a protected primary lookup recovers the existing reservation without a new key or debit',
        advance('lookup')['phase']=='reserved'
        and own('selection-attempt-start',**args)['phase']=='reserved'
        and own('selection-attempt-start',**(args|dict(quantity='2')))==dict(error='pf_local_intent_conflict'))
    fault.write_text('drop-after-commit');fault.chmod(0o600)
    with ThreadPoolExecutor(max_workers=4) as pool:
        confirmed=list(pool.map(lambda _:advance('confirm'),range(4)))
    check('B6sc a lost confirmed reply retains the same checkpoint while exactly one new receipt commits',
        sum(answer==dict(error='pf_transport_unknown') for answer in confirmed)==1
        and all(answer.get('error') in ('pf_transport_unknown','pf_local_attempt_busy','pf_local_attempt_lookup_required') for answer in confirmed)
        and own('selection-attempt-current')['operation']=='confirm'
        and own('selection-attempt-current')['phase']=='unknown'
        and int(hub_sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3r_receipts'))==count+1)
    check('B6sc visiting or resubmitting the form does not replay an unknown operation',
        own('selection-attempt-start',**args)['phase']=='unknown'
        and own('selection-attempt-start',**(args|dict(attribution=different)))==dict(error='pf_local_attempt_pending')
        and int(hub_sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3r_receipts'))==count+1)
    check('B6sc a final primary lookup and repeated confirm preserve one authoritative consumption',
        advance('lookup')['phase']=='confirmed' and advance('confirm')['phase']=='confirmed'
        and int(hub_sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3r_receipts'))==count+1)
    another=own('selection-attempt-start',attribution=different,quantity='1',sessions=[])
    check('B6sc a later independent intention is possible only after terminal proof and an old form cannot replace it',
        another['phase']=='reserved' and own('selection-attempt-start',**args)==dict(error='pf_local_attempt_pending'))
    released=own('selection-attempt-advance',attribution=different,operation='release')
    check('B6sc releasing an unconfirmed later reservation is terminal and adds no receipt',
        released['phase']=='released' and int(hub_sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3r_receipts'))==count+1)
