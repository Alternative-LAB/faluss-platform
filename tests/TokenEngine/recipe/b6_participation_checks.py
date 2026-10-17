"""Existing admission barriers, real disposable Hub/Fans transactions; no production/SSO proof."""
import concurrent.futures
import json
import os
import signal
import subprocess
import time
import uuid


def run_checks(root,cli_path,fans,recipe,origin,endpoint,members,create,state,invoke,sql,network,check):
    table='wp_fans_hof_b6p_';owner=members['owner']['id'];other=members['other']['id'];admin=members['admin']['id']
    creator=members['other']['creator']
    check('B6pa ordinary activation has installed no participation bindings',sql("SHOW TABLES LIKE '"+table+"%'")=='')
    check('B6pa readiness has no installation side effect',invoke('participation-ready')==dict(ready=False))
    sql('CREATE TABLE '+table+'versions (id INT NOT NULL) ENGINE=InnoDB')
    check('B6pa partial private schema is refused rather than adopted',invoke('participation-install')==dict(error='model_schema_divergent'))
    sql('DROP TABLE '+table+'versions')
    check('B6pa explicit installation verifies three private InnoDB tables idempotently',
          invoke('participation-install')==dict(ready=True) and invoke('participation-install')==dict(ready=True))

    def participant(session):
        return next((role for role in invoke('inspect',owner,session=session['session_id'])['roles']
                     if role['role']=='participant' and role['creator_id']==creator),None)
    def call(action,row,actor=owner,**extra):
        return invoke('participation-'+action,actor,session=row['session_id'],revision=int(state(row)['revision']),creator=creator,**extra)
    def request(row):return call('request',row,other,rules_sha256=state(row)['frozen_sha256'])
    def opened():
        row=create();assert invoke('advance',owner,session=row['session_id'],revision=int(row['revision']))['session_state']=='open'
        return state(row)
    def close_session(row):
        current=state(row);closing=invoke('close',owner,session=row['session_id'],revision=int(current['revision']),state='cancelled')
        assert invoke('advance',owner,session=row['session_id'],revision=int(closing['revision']))['session_state']=='cancelled'
    def count(kind='bindings'):return int(sql('SELECT COUNT(*) FROM '+table+kind))
    def scalar(field,row):
        return sql('SELECT '+field+' FROM '+table+'versions WHERE session_id=\''+row['session_id']+'\' ORDER BY barrier_version DESC LIMIT 1')

    row=create();requested=request(row);before=count()
    check('B6pa a local opening is not a signed Hub admission',
          call('admit',row)==dict(error='hof_session_acknowledgement_required') and participant(row)['state']=='requested' and count()==before)
    assert invoke('advance',owner,session=row['session_id'],revision=int(state(row)['revision']))['session_state']=='open'
    for name,actor in [('guest',0),('fan',members['fan']['id']),('unlinked',members['unlinked']['id']),('unrelated creator',other)]:
        result=call('admit',row,actor)
        check('B6pa '+name+' cannot self-admit or authorize another creator',
              'error' in result and participant(row)['state']=='requested' and count()==before)
    before_revision=state(row)['revision']
    for fault in ('participation-version-insert','participation-binding-insert'):
        result=call('admit',row,fault=fault)
        check('B6pa '+fault+' rolls back the admission audit and immutable checkpoint together',
              result==dict(error='hof_storage_unavailable') and participant(row)['state']=='requested' and count()==before
              and state(row)['revision']==before_revision)
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        args=dict(session=row['session_id'],revision=int(state(row)['revision']),creator=creator)
        decisions=list(pool.map(lambda _:invoke('participation-admit',owner,**args),range(2)))
    check('B6pa concurrent admissions commit once with one independent barrier version',
          sum(value.get('session_id')==row['session_id'] for value in decisions)==1
          and any(value.get('error')=='hof_session_revision_conflict' for value in decisions)
          and count()==before+1 and scalar('barrier_version',row)=='1' and scalar('role_revision',row)=='2')
    subject=sql('SELECT faluss_id FROM wp_faluss_fans_identity_links WHERE wp_user_id='+str(other))
    check('B6pa the barrier subject is the verified local Identity binding rather than profile UUID',
          scalar('subject_id',row)==subject and subject!=creator)
    with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:
        prepared=list(pool.map(lambda _:call('prepare',row),range(3)))
    action=prepared[0]['action_id']
    check('B6pa concurrent recovery keeps one immutable registration action and no implicit acknowledgement',
          all(value.get('action_id')==action for value in prepared) and participant(row)['state']=='admitted'
          and call('apply',row,action_id=action)==dict(error='pf_local_barrier_acknowledgement_required'))
    assert call('advance',row,steps=1)['state']=='pending'
    events=network['events']();(root/'barrier-http-fault').write_text('drop-after-commit')
    lost=call('advance',row,steps=1)
    check('B6pa lost primary registration response remains pending with the same action',
          lost.get('reason')=='pf_transport_unknown' and lost['action_id']==action and network['events']()==events+1)
    done=call('advance',row)
    check('B6pa primary lookup recovers the confirmed admission without a second owner event',
          done.get('participation_state')=='admitted' and network['events']()==events+1)
    before_revision=state(row)['revision'];call('apply',row,action_id=action)
    check('B6pa repeated acknowledgement is a read of the same applied decision',state(row)['revision']==before_revision)

    call('withdraw',row,other);closing=participant(row)
    check('B6pa withdrawal closes local eligibility immediately before the first close inbox checkpoint',closing['state']=='closing')
    call('apply',row,action_id=action)
    check('B6pa a delayed opening acknowledgement cannot undo a concurrent withdrawal',participant(row)==closing)
    result=call('advance',row,other)
    check('B6pa only a signed primary close settles withdrawal',result.get('participation_state')=='withdrawn' and participant(row)['state']=='withdrawn')
    request(row);call('admit',row)
    check('B6pa readmission uses barrier version two although the local role revision is six',
          scalar('barrier_version',row)=='2' and scalar('role_revision',row)=='6')
    current=participant(row);call('apply',row,action_id=action)
    check('B6pa an older registration cannot rewrite a readmitted role',participant(row)==current)
    call('advance',row);call('withdraw',row,other)
    assert invoke('profile-status',admin,creator=creator,state='suspended')['status']=='suspended'
    try:
        check('B6pa an ineligible creator can still reconcile an already bound withdrawal',
              call('advance',row,other).get('participation_state')=='withdrawn')
    finally:assert invoke('profile-status',admin,creator=creator,state='active')['status']=='active'
    close_session(row)

    row=opened();request(row);call('refuse',row)
    refused=participant(row);before=count()
    case=call('appeal',row,other,role_revision=int(refused['revision']),explanation='Fictitious review of participation criteria.')
    for actor in (0,owner,other):
        check('B6pa non-moderators cannot bypass a refusal through a favourable appeal '+str(actor),
              'error' in call('appeal-admit',row,actor,case=case['case_id'],case_revision=1) and participant(row)==refused and count()==before)
    check('B6pa case composition refuses use without an owned primary transaction and session mutex',
          call('appeal-outside',row,admin,case=case['case_id'],case_revision=1)==dict(error='hof_appeal_transaction_required'))
    failed=call('appeal-admit',row,admin,case=case['case_id'],case_revision=1,fault='participation-binding-insert')
    check('B6pa checkpoint failure rolls back the favourable case decision its log and admission together',
          failed==dict(error='hof_storage_unavailable') and participant(row)==refused and count()==before
          and call('appeal-own',row,admin,case=case['case_id'])['state']=='pending')
    decided=call('appeal-admit',row,admin,case=case['case_id'],case_revision=1)
    check('B6pa the actual favourable case and future admission commit once with their checkpoint',
          decided.get('state')=='decided' and decided.get('revision')=='2' and participant(row)['state']=='admitted' and count()==before+1
          and scalar('role_revision',row)=='3' and scalar('barrier_version',row)=='1')
    check('B6pa a decided case cannot create a second participation version',
          call('appeal-admit',row,admin,case=case['case_id'],case_revision=1)==dict(error='hof_appeal_revision_conflict') and count()==before+1)
    assert call('advance',row,admin)['participation_state']=='admitted'
    call('withdraw',row,other);assert call('advance',row,other)['participation_state']=='withdrawn';close_session(row)

    row=opened();request(row);before=count()
    unknown=call('admit',row,fault='commit-unknown')
    check('B6pa uncertain local COMMIT is recovered from the committed role and immutable action',
          unknown==dict(error='pf_local_barrier_commit_unknown') and participant(row)['state']=='admitted' and count()==before+1)
    call('withdraw',row,other);events=network['events']()
    result=call('advance',row,other)
    check('B6pa withdrawal before the first inbox checkpoint registers and closes the original version without reopening',
          result.get('participation_state')=='withdrawn' and network['events']()==events+2)
    close_session(row)

    # The admission checkpoint survives actual process death before/after its one primary COMMIT.
    for point in ('before-commit','after-commit'):
        row=opened();request(row);before=count();revision=int(state(row)['revision'])
        marker=root/(uuid.uuid4().hex+'.marker');path=root/(uuid.uuid4().hex+'.json')
        path.write_text(json.dumps(dict(action='participation-admit',origin=origin,endpoint=endpoint,actor=owner,
                                       session=row['session_id'],revision=revision,creator=creator,fault=point,marker=str(marker))))
        path.chmod(0o600)
        process=subprocess.Popen(['php',cli_path,'--allow-root','--path='+str(fans),'eval-file',str(recipe),str(path),'--use-include'],
                                 stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
        try:
            deadline=time.monotonic()+20
            while not marker.exists() and process.poll() is None and time.monotonic()<deadline:time.sleep(.02)
            assert marker.exists(),'Actual participation COMMIT checkpoint was not reached; output omitted.'
        finally:
            if process.poll() is None:os.killpg(process.pid,signal.SIGKILL)
            process.wait(timeout=5)
        committed=point=='after-commit'
        check('B6pa '+point+' process death preserves an atomic admission and action',
              participant(row)['state']==('admitted' if committed else 'requested') and count()==before+int(committed))
        if not committed:call('admit',row)
        stable=call('prepare',row)['action_id'];result=call('advance',row)
        check('B6pa '+point+' recovery uses the durable original admission action',
              result.get('participation_state')=='admitted' and call('prepare',row)['action_id']==stable)
        call('withdraw',row,other);assert call('advance',row,other)['participation_state']=='withdrawn';close_session(row)

    from b6_participation_requalification_checks import run_checks as requalification_checks
    requalification_checks(root,members,opened,state,invoke,call,request,participant,close_session,scalar,sql,network,check)
