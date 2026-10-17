"""Atomic territorial invalidation and exhaustive checkpoints, using genuine closed admissions."""
from datetime import datetime,timedelta,timezone
import uuid


def run_checks(root,members,invoke,sql,network,check):
    owner=members['owner']['id'];other=members['other']['id'];admin=members['admin']['id'];creator=members['other']['creator']
    table='wp_fans_hof_b6t_';policy=str(uuid.uuid4())
    criteria=dict(criteria_url='https://criteria.example.invalid/territory',criteria_text='Fictitious reviewed principal activity territory.',
        countries=['FR'],territories={'FR-PARIS':dict(country='FR',name='Fictitious Paris activity area')})
    def territory(action,actor=other,**extra):return invoke('participation-territory-'+action,actor,**extra)
    def own(actor=other):return territory('own',actor)
    def events():return sql('SELECT * FROM '+table+'changes ORDER BY creator_id,approval_revision')
    def items(change):return sql("SELECT * FROM "+table+"items WHERE change_id='"+change+"' ORDER BY kind,session_id,barrier_version")
    assert territory('schema',admin)==dict(ready=True),'Existing B2 territory schema was not explicitly installed in the fixture'
    registered=territory('policy',admin,policy=policy,criteria=criteria)
    assert registered.get('policy_id')==policy,'Fictitious territory policy refused: '+str(registered.get('error'))
    for actor,profile in ((owner,members['owner']['creator']),(other,creator)):
        row=own(actor);submitted=territory('submit',actor,policy=policy,country='FR',reference='FR-PARIS',revision=int(row['revision']))
        assert territory('decide',admin,creator=profile,revision=int(submitted['revision']),decision='approve',reason='criteria_satisfied')['state']=='approved'
    now=datetime.now(timezone.utc);stamp=lambda value:value.strftime('%Y-%m-%d %H:%M:%S.%f')
    rules=dict(title='Fictitious territorial checkpoint session',rules_text='Voluntary individual participation under frozen rules.',category='arts',
        scope='national',country='FR',territory_ref='',timezone='Europe/Paris',starts_at=stamp(now-timedelta(seconds=30)),ends_at=stamp(now+timedelta(hours=1)))
    row=invoke('create',owner,rules=rules);id=row['session_id']
    review=invoke('review-submit',owner,session=id,revision=int(row['revision']))
    assert invoke('review-decide',admin,session=id,review_revision=int(review['revision']),decision='approve',reason='allowed_session')['state']=='approved'
    row=invoke('open',owner,session=id,revision=int(row['revision']),territory_policy=policy)
    assert invoke('advance',owner,session=id,revision=int(row['revision']))['session_state']=='open'
    def snapshot():return invoke('inspect',owner,session=id)
    def participation(action,actor=owner,**extra):
        current=snapshot()['session']
        return invoke('participation-'+action,actor,session=id,revision=int(current['revision']),creator=creator,rules_sha256=current['frozen_sha256'],**extra)
    for index in range(23):
        assert participation('request',other)['session_id']==id
        assert participation('admit')['session_id']==id
        assert participation('advance')['participation_state']=='admitted'
        if index<22:
            participation('withdraw',other);assert participation('advance',other)['participation_state']=='withdrawn'
    check('B6tc twenty-three territorial versions are genuine primary-confirmed admissions under unchanged voluntary rules',
          sql("SELECT COUNT(*) FROM wp_fans_hof_b6p_versions WHERE session_id='"+id+"' AND creator_id='"+creator+"'")=='23'
          and sql("SELECT COUNT(*) FROM wp_fans_hof_b6p_bindings WHERE session_id='"+id+"' AND operation='register' AND applied_at<>''")=='23')
    original=own();before=snapshot();primary_events=network['events']()
    check('B6tc a missing checkpoint schema refuses invalidation before its territory decision commits',
          territory('withdraw',revision=int(original['revision']))==dict(error='hof_territory_checkpoint_schema_unavailable')
          and own()==original and snapshot()==before and network['events']()==primary_events)
    check('B6tc readiness creates no territorial checkpoint tables',territory('ready')==dict(ready=False) and sql("SHOW TABLES LIKE '"+table+"%'")=='')
    sql('CREATE TABLE '+table+'items (id INT NOT NULL) ENGINE=InnoDB')
    check('B6tc partial territorial metadata refuses adoption',territory('install')==dict(error='model_schema_divergent'))
    sql('DROP TABLE '+table+'items')
    check('B6tc explicit isolated installation verifies three private InnoDB tables without automatic migration',
          territory('install')==dict(ready=True) and territory('install')==dict(ready=True) and len(sql("SHOW TABLES LIKE '"+table+"%'").splitlines())==3)
    for actor in (0,members['fan']['id'],members['unlinked']['id']):
        check('B6tc an unlinked or noncreator actor cannot withdraw another territorial declaration '+str(actor),
              'error' in territory('withdraw',actor,revision=int(original['revision'])) and own()==original and events()=='')
    check('B6tc checkpoint insertion failure rolls back the approval decision journal and pending metadata together',
          territory('withdraw',revision=int(original['revision']),fault='territory-change-insert')==dict(error='hof_storage_unavailable')
          and own()==original and events()=='' and snapshot()==before)
    result=territory('decide',admin,creator=creator,revision=int(original['revision']),decision='revoke',reason='needs_revision',fault='commit-unknown')
    current=own();change=sql("SELECT change_id FROM "+table+"changes WHERE creator_id='"+creator+"' AND approval_revision="+original['approved_revision'])
    check('B6tc an uncertain invalidation COMMIT keeps one authoritative decision and its durable checkpoint',
          result==dict(error='hof_commit_unknown') and current['state']=='rejected' and current['approved_revision']=='0'
          and bool(change) and len(events().splitlines())==1 and items(change)=='' and snapshot()==before)
    check('B6tc new local participation choices fail immediately after territorial revocation without a fabricated Hub close',
          participation('prepare')==dict(error='hof_reviewed_territory_required') and network['events']()==primary_events)
    check('B6tc native participation checkpoint cannot display the revoked territory as a confirmed current admission',
          participation('checkpoint')==dict(bound=False,opening_applied=False,closing_applied=False)
          and snapshot()==before and network['events']()==primary_events)
    prior=events()
    check('B6tc a stale invalidation cannot replace the original checkpoint action',
          territory('decide',admin,creator=creator,revision=int(original['revision']),decision='revoke',reason='needs_revision')==dict(error='hof_territory_revision_conflict') and events()==prior)
    for actor in (0,members['fan']['id'],owner):
        check('B6tc another member cannot advance the private territorial cursor '+str(actor),
              'error' in territory('collect',actor,change=change) and events()==prior and items(change)=='')
    for limit in (0,21):
        check('B6tc unbounded territorial collection is refused '+str(limit),
              territory('collect',change=change,limit=limit)==dict(error='hof_territory_checkpoint_budget') and events()==prior)
    pages=0
    while True:
        before_page=events();before_items=items(change)
        failed=territory('collect',change=change,fault='territory-item-insert')
        if failed.get('error')=='hof_storage_unavailable':break
        pages+=1
        assert failed['phase']=='collecting' and pages<10,'Affected admission was not reached by the bounded cursor'
    check('B6tc item insertion failure preserves the cursor and every prior checkpoint atomically',
          events()==before_page and items(change)==before_items)
    while True:
        row=territory('collect',change=change);pages+=1
        assert pages<=10,'Territory checkpoint cursor repeated a page'
        if row['phase']=='draining':break
    expected=sql("SELECT barrier_version FROM wp_fans_hof_b6p_versions WHERE session_id='"+id+"' ORDER BY barrier_version").splitlines()
    actual=sql("SELECT barrier_version FROM "+table+"items WHERE change_id='"+change+"' AND session_id='"+id+"' ORDER BY barrier_version").splitlines()
    check('B6tc bounded durable pages enumerate every affected admission beyond twenty exactly once',
          pages>1 and actual==expected and len(actual)==23 and network['events']()==primary_events)
    saved=events();pending=items(change)
    check('B6tc a completed collection replay does not repeat targets or claim primary retirement',
          territory('collect',change=change)['phase']=='draining' and events()==saved and items(change)==pending
          and sql("SELECT COUNT(*) FROM "+table+"items WHERE change_id='"+change+"' AND state='queued'")=='23')
    participant=lambda:next(row for row in snapshot()['roles'] if row['creator_id']==creator and row['role']=='participant')
    voluntary=participant();historical=sql("SELECT * FROM wp_fans_hof_b6p_versions WHERE session_id='"+id+"' ORDER BY barrier_version")
    for actor in (0,members['fan']['id'],owner):
        check('B6tc another member cannot dispatch the territorial retirement '+str(actor),
              'error' in territory('retire',actor,change=change,session=id,version='23')
              and participant()==voluntary and items(change)==pending and network['events']()==primary_events)
    for version in expected[:-1]:
        assert territory('retire',change=change,session=id,version=version)['territory_state']=='applied'
    check('B6tc already retired historical contexts retain their original action and add no Hub operation',
          network['events']()==primary_events and participant()==voluntary
          and sql("SELECT COUNT(*) FROM "+table+"items WHERE change_id='"+change+"' AND state='applied'")=='22')
    args=dict(change=change,session=id,version=expected[-1])
    before_items=items(change)
    check('B6tc closing binding failure rolls back the item action before any network call',
          territory('retire',**args,fault='participation-binding-insert')==dict(error='hof_storage_unavailable')
          and items(change)==before_items and network['events']()==primary_events and participant()==voluntary)
    check('B6tc uncertain local preparation COMMIT preserves the exact action before any HTTP',
          territory('retire',**args,fault='commit-unknown')==dict(error='hof_commit_unknown')
          and network['events']()==primary_events and participant()==voluntary)
    durable=sql("SELECT action_id FROM "+table+"items WHERE change_id='"+change+"' AND barrier_version=23")
    assert territory('retire',**args,steps=1)['state']=='pending'
    (root/'barrier-http-fault').write_text('drop-after-commit')
    lost=territory('retire',**args,steps=1);action=lost['action_id']
    check('B6tc lost territorial close reply preserves the same action and voluntary admitted role',
          lost.get('reason')=='pf_transport_unknown' and action==durable and participant()==voluntary and network['events']()==primary_events+1
          and sql("SELECT state FROM "+table+"items WHERE change_id='"+change+"' AND barrier_version=23")=='closing')
    check('B6tc a local item failure cannot falsely complete an already primary-confirmed close',
          territory('retire',**args,fault='territory-item-apply')==dict(error='hof_storage_unavailable')
          and network['events']()==primary_events+1 and participant()==voluntary
          and sql("SELECT state FROM "+table+"items WHERE change_id='"+change+"' AND barrier_version=23")=='closing')
    check('B6tc primary lookup settles the exact territorial retirement without a second close or voluntary withdrawal',
          territory('retire',**args)['territory_state']=='applied' and participant()==voluntary and network['events']()==primary_events+1
          and sql("SELECT action_id FROM "+table+"items WHERE change_id='"+change+"' AND barrier_version=23")==action
          and sql("SELECT * FROM wp_fans_hof_b6p_versions WHERE session_id='"+id+"' ORDER BY barrier_version")==historical)
    saved_items=items(change);retired_session=snapshot()['session']
    check('B6tc replay of every retired fragment preserves all acknowledgements and adds no operation',
          all(territory('retire',change=change,session=id,version=value)['territory_state']=='applied' for value in expected)
          and items(change)==saved_items and participant()==voluntary and snapshot()['session']==retired_session
          and network['events']()==primary_events+1)
    check('B6tc the change completes only after collection and every exact retirement acknowledgement',
          territory('collect',change=change)['phase']=='completed'
          and sql("SELECT COUNT(*) FROM "+table+"items WHERE change_id='"+change+"' AND state='applied'")=='23')
    new_declaration=territory('submit',policy=policy,country='FR',reference='FR-PARIS',revision=int(current['revision']))
    check('B6tc a new pending territorial declaration cannot restore a retired admission',
          participation('requalify',other)==dict(error='hof_reviewed_territory_required') and participant()==voluntary)
    approved=territory('decide',admin,creator=creator,revision=int(new_declaration['revision']),decision='approve',reason='criteria_satisfied')
    check('B6tc failure of the future version rolls back voluntary revision and timestamp after attested retirement',
          participation('requalify',other,fault='participation-version-insert')==dict(error='hof_storage_unavailable')
          and participant()==voluntary
          and sql("SELECT COUNT(*) FROM wp_fans_hof_b6p_versions WHERE session_id='"+id+"'")=='23')
    check('B6tc uncertain future admission COMMIT preserves one future version without claiming its Hub opening',
          participation('requalify',other,fault='requalification-commit-unknown')==dict(error='pf_local_barrier_commit_unknown')
          and sql("SELECT COUNT(*) FROM wp_fans_hof_b6p_versions WHERE session_id='"+id+"'")=='24'
          and network['events']()==primary_events+1)
    future=participation('requalify',other);new_role=participant()
    check('B6tc renewed territory admits only future contributions with unchanged voluntary rules and corrected revision',
          future.get('participation_state')=='admitted' and network['events']()==primary_events+2
          and new_role['admitted_at']>voluntary['admitted_at'] and new_role['rules_sha256']==voluntary['rules_sha256']
          and sql("SELECT JSON_UNQUOTE(JSON_EXTRACT(descriptor_json,'$.content.territory_admission_revision')) FROM wp_fans_hof_b6p_versions WHERE session_id='"+id+"' AND barrier_version=24")==approved['approved_revision']
          and sql("SELECT * FROM wp_fans_hof_b6p_versions WHERE session_id='"+id+"' AND barrier_version<=23 ORDER BY barrier_version")==historical)
    check('B6tc native checkpoint confirms only the newly approved and actually opened future admission',
          participation('checkpoint')==dict(bound=True,opening_applied=True,closing_applied=False))
    revision=snapshot()['session']['revision']
    check('B6tc an old territorial retirement cannot remove the new admission or repeat its close',
          territory('retire',**args)['territory_state']=='applied' and participant()==new_role
          and snapshot()['session']['revision']==revision and network['events']()==primary_events+2)
    co=invoke('create',owner,rules=dict(rules,title='Fictitious coorganized territorial session'));co_id=co['session_id']
    co=territory('invite',owner,session=co_id,revision=int(co['revision']),creator=creator)
    co=territory('accept',other,session=co_id,revision=int(co['revision']))
    review=invoke('review-submit',owner,session=co_id,revision=int(co['revision']))
    assert invoke('review-decide',admin,session=co_id,review_revision=int(review['revision']),decision='approve',reason='allowed_session')['state']=='approved'
    co=invoke('open',owner,session=co_id,revision=int(co['revision']),territory_policy=policy)
    assert invoke('advance',owner,session=co_id,revision=int(co['revision']))['session_state']=='open'
    latest=own();changed_co=territory('submit',policy=policy,country='FR',reference='FR-PARIS',revision=int(latest['revision']))
    co_change=sql("SELECT change_id FROM "+table+"changes WHERE creator_id='"+creator+"' AND approval_revision="+latest['approved_revision'])
    co_local=invoke('inspect',owner,session=co_id)['session'];before_co=network['events']()
    check('B6tc an invalidated accepted coorganizer closes local choices without pretending to be the session owner',
          changed_co['state']=='pending' and co_local['state']=='closing' and co_local['closure_action']=='suspended'
          and invoke('advance',other,session=co_id,revision=int(co_local['revision']))==dict(error='hof_session_forbidden'))
    co_args=dict(change=co_change,session=co_id,version=co_local['barrier_version'])
    check('B6tc a different creator cannot borrow a territorial organizer checkpoint',
          'error' in territory('retire-session',owner,**co_args) and network['events']()==before_co)
    assert territory('retire-session',**co_args,steps=1)['state']=='pending'
    (root/'barrier-http-fault').write_text('drop-after-commit')
    co_lost=territory('retire-session',**co_args,steps=1)
    check('B6tc a lost organizer suspension reply keeps the exact parent closing and one durable action',
          co_lost.get('reason')=='pf_transport_unknown' and network['events']()==before_co+1
          and invoke('inspect',owner,session=co_id)['session']['state']=='closing')
    co_result=territory('retire-session',**co_args)
    check('B6tc coorganizer checkpoint recovery applies the true primary session suspension without a general owner bypass',
          co_result.get('session_state')=='suspended' and co_result.get('territory_state')=='applied'
          and co_result['action_id']==co_lost['action_id'] and network['events']()==before_co+1
          and invoke('inspect',owner,session=co_id)['session']['state']=='suspended')
    for page in range(10):
        if territory('collect',change=co_change)['phase']!='collecting':break
    else:raise RuntimeError('The later territorial checkpoint did not terminate in bounded pages')
    assert territory('retire',change=co_change,session=id,version='24')['territory_state']=='applied'
    check('B6tc a dual participant and coorganizer invalidation completes both independent dimensions without economic writes',
          territory('collect',change=co_change)['phase']=='completed'
          and sql("SELECT COUNT(*) FROM "+table+"items WHERE change_id='"+co_change+"' AND state='applied'")=='2')
    assert territory('decide',admin,creator=creator,revision=int(changed_co['revision']),decision='approve',reason='criteria_satisfied')['state']=='approved'
    co_state=invoke('inspect',owner,session=co_id)['session']
    co_future=invoke('readmit',owner,session=co_id,revision=int(co_state['revision']))
    assert invoke('advance',owner,session=co_id,revision=int(co_future['revision']))['session_state']=='open'
    co_state=invoke('inspect',owner,session=co_id)['session'];before_replay=network['events']()
    check('B6tc replay of the old territorial session ACK cannot suspend its readmitted parent version',
          territory('retire-session',**co_args)['territory_state']=='applied'
          and invoke('inspect',owner,session=co_id)['session']==co_state and co_state['barrier_version']=='2'
          and network['events']()==before_replay)
    cancelled=invoke('close',owner,session=co_id,revision=int(co_state['revision']),state='cancelled')
    assert invoke('advance',owner,session=co_id,revision=int(cancelled['revision']))['session_state']=='cancelled'
    original_owner=own(owner);session=snapshot()['session']
    withdrawn=territory('withdraw',owner,revision=int(original_owner['revision']))
    owner_change=sql("SELECT change_id FROM "+table+"changes WHERE creator_id='"+members['owner']['creator']+"' AND approval_revision="+original_owner['approved_revision'])
    local=snapshot()['session']
    check('B6tc organizer territorial loss snapshots the exact parent and closes local choices atomically',
          withdrawn['state']=='withdrawn' and local['state']=='closing' and local['closure_action']=='suspended'
          and local['barrier_version']==session['barrier_version'] and local['frozen_sha256']==session['frozen_sha256']
          and sql("SELECT barrier_version FROM "+table+"items WHERE change_id='"+owner_change+"' AND kind='session'")==session['barrier_version'])
    assert invoke('advance',owner,session=id,revision=int(local['revision']))['session_state']=='suspended'
    check('B6tc existing session suspension transport remains a distinct real owner closure',snapshot()['session']['state']=='suspended')
    before=network['events']()
    check('B6tc an already applied owner suspension settles its territorial checkpoint without another close',
          territory('retire-session',owner,change=owner_change,session=id,version=local['barrier_version'])['territory_state']=='applied'
          and territory('collect',owner,change=owner_change)['phase']=='completed' and network['events']()==before)
