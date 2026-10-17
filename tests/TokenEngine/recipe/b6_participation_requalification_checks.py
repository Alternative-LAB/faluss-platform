"""Crossed parent/admission lifecycle on the same actual disposable Hub/Fans pair."""
from concurrent.futures import ThreadPoolExecutor


def run_checks(root,members,opened,state,invoke,call,request,participant,close_session,scalar,sql,network,check):
    owner=members['owner']['id'];other=members['other']['id'];admin=members['admin']['id'];creator=members['other']['creator']
    prefix='wp_fans_hof_b6p_'
    def admitted():
        row=opened();request(row);assert call('admit',row).get('session_id')==row['session_id']
        assert call('advance',row).get('participation_state')=='admitted'
        return row
    def readmit(row):
        closing=invoke('close',admin,session=row['session_id'],revision=int(state(row)['revision']),state='suspended')
        assert invoke('advance',owner,session=row['session_id'],revision=int(closing['revision']))['session_state']=='suspended'
        check('B6pq a suspended session cannot requalify a bound participant or invent a new parent',
              'error' in call('requalify',row,other) and scalar('barrier_version',row)=='1')
        pending=invoke('readmit',owner,session=row['session_id'],revision=int(state(row)['revision']))
        assert invoke('advance',owner,session=row['session_id'],revision=int(pending['revision']))['session_state']=='open'
    def historical(row):
        return sql("SELECT descriptor_json,descriptor_sha256 FROM "+prefix+"versions WHERE session_id='"+row['session_id']+"' AND barrier_version=1")
    def versions(row):return int(sql("SELECT COUNT(*) FROM "+prefix+"versions WHERE session_id='"+row['session_id']+"'"))

    row=admitted();old=historical(row);initial=participant(row);events=network['events']()
    check('B6pq recovery of an unchanged admission makes no new version or primary event',
          call('requalify',row,other).get('participation_state')=='admitted' and versions(row)==1 and network['events']()==events)
    readmit(row);events=network['events']();before=participant(row)
    check('B6pq an old admission is not silently usable for the readmitted parent',
          call('prepare',row)==dict(error='hof_participation_binding_conflict') and participant(row)==before)
    for actor in (0,members['fan']['id'],members['unlinked']['id']):
        check('B6pq an unauthorized actor cannot requalify the creator '+str(actor),
              'error' in call('requalify',row,actor) and versions(row)==1 and participant(row)==before)
    assert call('requalify',row,steps=1)['state']=='pending'
    (root/'barrier-http-fault').write_text('drop-after-commit')
    lost=call('requalify',row,steps=1);action=lost['action_id']
    check('B6pq a lost retirement reply leaves the old admission unqualified and its original action recoverable',
          lost.get('reason')=='pf_transport_unknown' and versions(row)==1 and network['events']()==events+1
          and participant(row)==before and call('prepare',row)==dict(error='hof_participation_binding_conflict'))
    failure=call('requalify',row,fault='participation-version-insert')
    check('B6pq a new-version storage failure rolls back role timestamp revision and audit after attested retirement',
          failure==dict(error='hof_storage_unavailable') and versions(row)==1 and participant(row)==before and historical(row)==old
          and network['events']()==events+1)
    committed=call('requalify',row,fault='requalification-commit-unknown')
    check('B6pq a lost local readmission COMMIT acknowledgement preserves one new bound version without claiming its Hub admission',
          committed==dict(error='pf_local_barrier_commit_unknown') and versions(row)==2
          and scalar('session_barrier_version',row)=='2' and network['events']()==events+1)
    prepared=call('prepare',row);new_action=prepared['action_id'];result=call('requalify',row,other)
    check('B6pq primary recovery confirms only the future admission with the same action and original rules',
          result.get('participation_state')=='admitted' and result['action_id']==new_action and new_action!=action
          and participant(row)['admitted_at']>initial['admitted_at'] and participant(row)['rules_sha256']==initial['rules_sha256']
          and versions(row)==2 and historical(row)==old and network['events']()==events+2)
    before=participant(row);before_revision=state(row)['revision'];call('apply',row,action_id=action)
    check('B6pq an old retirement acknowledgement cannot remove or rewrite the new admission',
          participant(row)==before and state(row)['revision']==before_revision)
    call('withdraw',row,other);assert call('advance',row,other)['participation_state']=='withdrawn';close_session(row)

    row=admitted();readmit(row);events=network['events']();before=participant(row)
    assert call('requalify',row,fault='participation-version-insert')==dict(error='hof_storage_unavailable')
    call('withdraw',row,other);result=call('advance',row,other)
    check('B6pq withdrawal after retired-parent acknowledgement settles without new admission or second primary close',
          result.get('participation_state')=='withdrawn' and versions(row)==1 and network['events']()==events+1
          and int(participant(row)['revision'])==int(before['revision'])+2)
    close_session(row)

    row=admitted();readmit(row);events=network['events']();revision=int(state(row)['revision'])
    arguments=dict(session=row['session_id'],revision=revision,creator=creator)
    with ThreadPoolExecutor(max_workers=2) as pool:
        outcomes=list(pool.map(lambda actor:invoke('participation-requalify',actor,**arguments),(owner,other)))
    result=call('requalify',row,other)
    check('B6pq concurrent owner and participant recovery create one retired stream and one future admission',
          result.get('participation_state')=='admitted' and versions(row)==2 and network['events']()==events+2
          and all(outcome.get('state') in ('pending','acknowledged') or outcome.get('error') in
                  ('hof_session_revision_conflict','hof_participation_binding_conflict','pf_local_barrier_checkpoint_moved') for outcome in outcomes))
    call('withdraw',row,other);assert call('advance',row,other)['participation_state']=='withdrawn';close_session(row)
