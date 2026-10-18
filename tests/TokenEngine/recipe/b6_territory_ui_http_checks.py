"""Native Creator declaration, administrator examination and exact durable closure on the real isolated pair."""
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime,timedelta,timezone
import urllib.parse
import uuid

from b6_privacy_http_checks import Forms


def run_checks(root,read,cookies,actors,deliver,check):
    path='/app/creator/hof?manage=territory';admin=cookies['admin'];owner=cookies['creator']
    creator=actors['creator_profile'];panel='/app/admin?view=territories&creator='+creator
    own=lambda:deliver('territory-ui-own',actor=actors['creator_user'])
    detail=lambda:deliver('territory-ui-examine',actor=actors['admin_user'],creator=creator)
    before=own()
    check('B6tu a native territorial GET cannot install a missing schema',
          'error' in before and read(path,owner)[0]==503 and 'error' in own())
    check('B6tu guest Fan and an unlinked administrator cannot use Creator territory forms',
          all(read(path,cookie)[0]==403 for cookie in (None,cookies['fan'],admin)))
    assert deliver('territory-ui-install',actor=actors['admin_user'])==dict(ready=True)
    policy_ids=sorted(str(uuid.uuid4()) for _ in range(21))
    criteria=dict(criteria_url='https://criteria.example.invalid/native-territory',criteria_text='Critères fictifs de recette : activité principale examinée, sans preuve de domicile.',
        countries=['FR'],territories={'FR-A':dict(country='FR',name='Territoire fictif de recette')})
    assert deliver('territory-ui-policies',actor=actors['admin_user'],policies=policy_ids,criteria=criteria)==policy_ids
    first=read(path,owner);second=read(path+'&policy_cursor='+policy_ids[19],owner)
    first_forms=[row for row in Forms(first[1]).forms if row.get('hof_session_action')=='territory_submit']
    second_forms=[row for row in Forms(second[1]).forms if row.get('hof_session_action')=='territory_submit']
    check('B6tu native immutable criteria pagination covers more than twenty without defaults or duplicate policies',
          first[0]==second[0]==200 and [row['policy_id'] for row in first_forms]==policy_ids[:20]
          and [row['policy_id'] for row in second_forms]==policy_ids[20:] and own()['state']=='absent')
    def form(action,url=path,cookie=owner):
        response=read(url,cookie)
        assert response[0]==200
        return Forms(response[1]).action('hof_session_action',action)
    def moderator(action,reason='criteria_satisfied',changes=None):
        fields=next(row for row in Forms(read(panel,admin)[1]).forms if row.get('creator_id')==creator)
        fields.update(territory_review_action=action,reason=reason);fields.update(changes or {})
        return read(panel,admin,fields)
    initial=own();fields=dict(first_forms[0],territory_ref='FR-A')
    check('B6tu invalid owner nonce cannot create a territorial decision',
          read(path,owner,dict(fields,hof_session_nonce='invalid'))[0]==403 and own()==initial)
    check('B6tu a foreign session cannot reuse an owner form or expose the private moderation panel',
          read(path,cookies['fan'],fields)[0]==403 and read(panel,owner)[0]==403 and own()==initial)
    with ThreadPoolExecutor(max_workers=2) as pool:responses=list(pool.map(lambda _:read(path,owner,fields)[0],range(2)))
    check('B6tu concurrent native declarations commit one revision and one audit decision',
          sorted(responses)==[200,409] and own()['revision']=='1' and len(detail()['history'])==1)
    check('B6tu merely reviewing a declaration records no decision',read(panel,admin)[0]==200 and len(detail()['history'])==1)
    check('B6tu target-bound administrator nonce refuses approval without changing the pending declaration',
          moderator('approve',changes=dict(territory_review_nonce='invalid'))[0]==403 and own()['state']=='pending')
    assert moderator('reject','territory_unsubstantiated')[0]==200
    check('B6tu refusal is visible to its owner with a native appeal and public criteria',
          own()['state']=='rejected' and 'Le territoire n’a pas pu être établi' in read(path,owner)[1]
          and 'territory_appeal' in read(path,owner)[1] and criteria['criteria_url'] in read(path,owner)[1])
    appeal=form('territory_appeal');appeal['explanation']='Explication fictive à examiner.'
    assert read(path,owner,appeal)[0]==200
    check('B6tu native appeal stays private and never grants territorial eligibility',
          own()['state']=='appealed' and own()['approved_revision']=='0' and 'Explication fictive à examiner.' in read(panel,admin)[1])
    decision=next(row for row in Forms(read(panel,admin)[1]).forms if row.get('creator_id')==creator)
    decision.update(territory_review_action='approve',reason='criteria_satisfied')
    with ThreadPoolExecutor(max_workers=2) as pool:responses=list(pool.map(lambda _:read(panel,admin,decision)[0],range(2)))
    check('B6tu concurrent native examinations approve once with the exact submitted revision',
          sorted(responses)==[200,409] and own()['state']=='approved' and len(detail()['history'])==4)
    # Real native draft/review/open: the chosen policy comes from the owner's approved declaration.
    now=datetime.now(timezone.utc);new='/app/creator/hof?manage=new'
    draft=Forms(read(new,owner)[1]).action('hof_session_action','create')
    draft.update(title='Session territoriale native fictive',rules_text='Participation volontaire selon les critères de recette.',category='arts',scope='local',country='FR',territory_ref='FR-A',
        timezone='UTC',starts_local=(now-timedelta(minutes=1)).strftime('%Y-%m-%dT%H:%M:%S'),ends_local=(now+timedelta(minutes=30)).strftime('%Y-%m-%dT%H:%M:%S'),starts_offset='',ends_offset='')
    response=read(new,owner,draft,follow=False)
    assert response[0]==303
    location=response[2]['Location'];session_id=urllib.parse.parse_qs(urllib.parse.urlsplit(location).query)['session'][0]
    session_path='/app/creator/hof?manage=session&session='+session_id
    submission=form('submit',session_path);assert read(session_path,owner,submission)[0]==200
    session_panel='/app/admin?view=sessions&session='+session_id
    approval=Forms(read(session_panel,admin)[1]).action('session_review_action','approve');approval['reason']='allowed_session'
    assert read(session_panel,admin,approval)[0]==200
    assert read(session_path,owner,form('open',session_path))[0]==200
    check('B6tu reviewed native local session opens through the existing primary-confirmed policy bridge',
          deliver('private-session',actor=actors['creator_user'],session=session_id)['session']['state']=='open')
    assert moderator('revoke','needs_revision',dict(confirm_revoke='yes'))[0]==200
    changes=deliver('territory-ui-listing',actor=actors['creator_user'],creator=creator)
    assert len(changes)==1
    change=changes[0]['change_id'];resume_path=path+'&change='+change
    check('B6tu committed territorial revocation closes new local choices before any network recovery',
          own()['state']=='rejected' and deliver('private-session',actor=actors['creator_user'],session=session_id)['session']['state']=='closing'
          and 'territory_resume' in read(resume_path,owner)[1])
    progress=lambda:deliver('territory-ui-progress',actor=actors['creator_user'],change=change)
    saved=progress();invalid=form('territory_resume',resume_path);invalid['hof_session_nonce']='invalid'
    check('B6tu a recovery GET or invalid nonce cannot advance its durable cursor',
          read(resume_path,owner)[0]==200 and progress()==saved and read(resume_path,owner,invalid)[0]==403 and progress()==saved)
    for _ in range(12):
        if progress()['change']['phase']!='collecting':break
        assert read(resume_path,owner,form('territory_resume',resume_path))[0] in (200,202)
    assert progress()['change']['phase']=='draining'
    fault=root/'b6-ui-drop-response';fault.write_text('2');fault.chmod(0o600)
    response=read(resume_path,owner,form('territory_resume',resume_path));pending=progress()
    check('B6tu a lost committed territorial close response preserves an explicit pending native checkpoint',
          response[0]==202 and pending['change']['phase']=='draining' and pending['next']['action_id']!='')
    action=pending['next']['action_id']
    assert read(resume_path,owner,form('territory_resume',resume_path))[0]==200
    check('B6tu native same-action primary lookup completes without another decision or reopening history',
          progress()['change']['phase']=='completed' and progress()['applied']==progress()['total']==1
          and deliver('private-session',actor=actors['creator_user'],session=session_id)['session']['state']=='suspended'
          and any(row['operation']=='close' and row['action_id']==action for row in deliver('private-session-bindings',actor=actors['creator_user'],session=session_id))
          and len(detail()['history'])==5)
    # Restore an examined declaration for repeatable UI inspection; reopening stays a separate explicit action.
    restored=dict(form('territory_submit'),territory_ref='FR-A');assert read(path,owner,restored)[0]==200
    assert moderator('approve')[0]==200
    return dict(path=path,panel=panel,resume_path=resume_path,session=session_id)
