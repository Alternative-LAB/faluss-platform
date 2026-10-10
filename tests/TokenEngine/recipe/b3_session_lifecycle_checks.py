"""B2 governance to actual signed Hub barriers, fictitious local Identity links only."""
import concurrent.futures
import datetime
import json
import os
import secrets
import signal
import subprocess
import time
import uuid


def run_checks(root,source,cli_path,check,command,hub_sql,network):
    local=network['recovery'];fans=local['fans'];sql=local['sql'];table='wp_fans_hof_b3_'
    config=fans/'wp-config.php';text=config.read_text()
    for flag in ('FALUSS_PLATFORM_FANS_SSO','FALUSS_PLATFORM_FANS_CREATOR_PROFILES','FALUSS_PLATFORM_FANS_EDITORIAL'):
        text=text.replace("$table_prefix=",'define('+json.dumps(flag)+',true);\n$table_prefix=')
    # Satisfy the existing editorial service guard with fictional configuration, never a central SSO request.
    text=text.replace("define(\"WP_HOME\",\"http://127.0.0.1:9\");",'define("WP_HOME","https://fans.example.test");')
    for name,value in [('FALUSS_FANS_SSO_CLIENT_ID','fixture.fans.identity'),('FALUSS_FANS_SSO_CLIENT_SECRET',secrets.token_urlsafe(32))]:
        text=text.replace('$table_prefix=','define('+json.dumps(name)+','+json.dumps(value)+');\n$table_prefix=')
    config.write_text(text);config.chmod(0o600)
    _,_,objects=network['fixture']();origin=objects[0]['content']['origin_id'];clock=root/'barrier-primary-clock'
    endpoint=local['endpoint'];recipe=source/'tests/TokenEngine/recipe/b3-session-lifecycle-worker.php'
    def invoke(action,actor=None,**extra):
        path=root/(uuid.uuid4().hex+'.json');value=dict(action=action,origin=origin,endpoint=endpoint,**extra)
        if actor is not None:value['actor']=actor
        path.write_text(json.dumps(value));path.chmod(0o600)
        return json.loads(command(['php',cli_path,'--allow-root','--path='+str(fans),'eval-file',str(recipe),str(path),'--use-include']))
    members=invoke('seed')
    if 'error' in members:raise RuntimeError('Expurgated B2 fixture initialization: '+members['error'])
    owner=members['owner']['id'];admin=members['admin']['id']
    check('B3sl ordinary activation installed no lifecycle binding',sql("SHOW TABLES LIKE '"+table+"%'")=='')
    check('B3sl readiness creates no binding schema',invoke('ready')==dict(ready=False))
    sql('CREATE TABLE '+table+'bindings (id INT NOT NULL) ENGINE=InnoDB')
    check('B3sl partial schema is refused without adoption',invoke('install')==dict(error='model_schema_divergent'))
    sql('DROP TABLE '+table+'bindings')
    check('B3sl explicit physical installation verifies two private InnoDB tables',invoke('install')==dict(ready=True) and invoke('install')==dict(ready=True))
    def create():
        now=datetime.datetime.now(datetime.timezone.utc);fmt=lambda value:value.strftime('%Y-%m-%d %H:%M:%S.%f')
        rules=dict(title='Fictitious creator session',rules_text='Voluntary individual participation.',category='arts',scope='international',
                   country='',territory_ref='',timezone='Europe/Paris',starts_at=fmt(now-datetime.timedelta(seconds=30)),ends_at=fmt(now+datetime.timedelta(hours=1)))
        row=invoke('create',owner,rules=rules);assert row.get('state')=='draft'
        review=invoke('review-submit',owner,session=row['session_id'],revision=int(row['revision']))
        assert invoke('review-decide',admin,session=row['session_id'],review_revision=int(review['revision']),decision='approve',reason='allowed_session')['state']=='approved'
        return invoke('open',owner,session=row['session_id'],revision=int(row['revision']))
    def state(row):return invoke('inspect',owner,session=row['session_id'])['session']
    def count():return int(sql('SELECT COUNT(*) FROM '+table+'bindings'))
    historical=hub_sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')
    row=create();before=count()
    for role in ('other','fan','unlinked'):
        check('B3sl '+role+' cannot prepare another creator lifecycle','error' in invoke('prepare',members[role]['id'],session=row['session_id'],revision=int(row['revision'])) and count()==before)
    check('B3sl guest cannot prepare a private lifecycle','error' in invoke('prepare',0,session=row['session_id'],revision=int(row['revision'])) and count()==before)
    check('B3sl stale revision creates no durable action',invoke('prepare',owner,session=row['session_id'],revision=1)==dict(error='hof_session_revision_conflict') and count()==before)
    check('B3sl invalid advance budget creates no durable action',invoke('advance',owner,session=row['session_id'],revision=int(row['revision']),steps=0)==dict(error='pf_local_barrier_budget') and count()==before)
    prepared=invoke('prepare',owner,session=row['session_id'],revision=int(row['revision']))
    check('B3sl server frozen rules persist an action without claiming open',prepared['local_state']=='opening' and state(row)['state']=='opening' and count()==before+1)
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        outcomes=list(pool.map(lambda _:invoke('prepare',owner,session=row['session_id'],revision=int(row['revision'])),range(4)))
    check('B3sl concurrent preparations preserve one immutable action',all(value['action_id']==prepared['action_id'] for value in outcomes) and count()==before+1)
    check('B3sl local opening without signed acknowledgement cannot become open',invoke('apply',owner,session=row['session_id'],action_id=prepared['action_id'])==dict(error='pf_local_barrier_acknowledgement_required'))
    done=invoke('advance',owner,session=row['session_id'],revision=int(row['revision']))
    check('B3sl real Hub acknowledgement atomically opens the approved creator session',done['state']=='acknowledged' and done['session_state']=='open' and state(row)['state']=='open')
    opened=state(row);revision=opened['revision'];events=network['events']()
    check('B3sl repeated application makes no new local decision or Hub event',invoke('apply',owner,session=row['session_id'],action_id=prepared['action_id'])['revision']==revision and network['events']()==events)
    closing=invoke('close',owner,session=row['session_id'],revision=int(revision),state='cancelled');pending=invoke('prepare',owner,session=row['session_id'],revision=int(closing['revision']))
    check('B3sl old register ACK cannot reopen a concurrent local closure',invoke('apply',owner,session=row['session_id'],action_id=prepared['action_id'])['state']=='closing')
    done=invoke('advance',owner,session=row['session_id'],revision=int(closing['revision']))
    check('B3sl cancellation reaches its attested final state and preserves history',done['session_state']=='cancelled' and state(row)['state']=='cancelled')

    row=create();prepared=invoke('prepare',owner,session=row['session_id'],revision=int(row['revision']))
    assert invoke('advance',owner,session=row['session_id'],revision=int(row['revision']),fault='decision-insert')==dict(error='hof_storage_unavailable')
    assert invoke('profile-status',admin,creator=members['owner']['creator'],state='suspended')['status']=='suspended'
    check('B3sl creator suspension after primary ACK prevents the local opening decision',
          invoke('apply',owner,session=row['session_id'],action_id=prepared['action_id'])==dict(error='hof_approved_creator_required') and state(row)['state']=='opening')
    assert invoke('profile-status',admin,creator=members['owner']['creator'],state='active')['status']=='active'
    opened=invoke('apply',owner,session=row['session_id'],action_id=prepared['action_id'])
    closing=invoke('close',owner,session=row['session_id'],revision=int(opened['revision']),state='cancelled')
    assert invoke('advance',owner,session=row['session_id'],revision=int(closing['revision']))['session_state']=='cancelled'

    row=create();prepared=invoke('prepare',owner,session=row['session_id'],revision=int(row['revision']))
    assert invoke('advance',owner,session=row['session_id'],revision=int(row['revision']),fault='decision-insert')==dict(error='hof_storage_unavailable')
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        applied=pool.submit(invoke,'apply',owner,session=row['session_id'],action_id=prepared['action_id'])
        closed=pool.submit(invoke,'close',owner,session=row['session_id'],revision=int(row['revision']),state='cancelled')
        applied_result,closed_result=applied.result(),closed.result()
    actual=state(row)
    check('B3sl concurrent acknowledgement and creator closure serialize with an exact revision',
          applied_result.get('state') in ('open','closing') and (closed_result.get('state')=='closing'
          or closed_result==dict(error='hof_session_revision_conflict')) and actual['state'] in ('open','closing'))
    if actual['state']=='open':actual=invoke('close',owner,session=row['session_id'],revision=int(actual['revision']),state='cancelled')
    done=invoke('advance',owner,session=row['session_id'],revision=int(actual['revision']))
    check('B3sl closure after the race settles once and an old ACK never restores open',
          done['session_state']=='cancelled' and invoke('apply',owner,session=row['session_id'],action_id=prepared['action_id'])['state']=='cancelled')
    # Completion uses only the frozen primary deadline, never the local browser clock.
    row=create();assert invoke('advance',owner,session=row['session_id'],revision=int(row['revision']))['session_state']=='open'
    opened=state(row);closing=invoke('close',owner,session=row['session_id'],revision=int(opened['revision']),state='closed')
    waiting=invoke('advance',owner,session=row['session_id'],revision=int(closing['revision']),steps=2)
    check('B3sl early completion stays closing with the original action',waiting['state']=='pending' and state(row)['state']=='closing')
    at=datetime.datetime.fromisoformat(row['ends_at']).replace(tzinfo=datetime.timezone.utc)
    clock.write_text(format(at.timestamp()+1,'.6f'));clock.chmod(0o600)
    try:
        done=invoke('advance',owner,session=row['session_id'],revision=int(closing['revision']))
        check('B3sl frozen primary deadline permits completed rather than cancelled',done['session_state']=='closed' and state(row)['state']=='closed')
    finally:clock.unlink(missing_ok=True)

    # The local B2/inbox boundary is separately committed; a primary ACK never excuses a partial local decision.
    row=create();before=count()
    check('B3sl failed binding insert rolls back the durable B2 checkpoint',
          invoke('prepare',owner,session=row['session_id'],revision=int(row['revision']),fault='binding-insert')==dict(error='hof_storage_unavailable') and count()==before)
    unknown=invoke('prepare',owner,session=row['session_id'],revision=int(row['revision']),fault='commit-unknown')
    prepared=invoke('prepare',owner,session=row['session_id'],revision=int(row['revision']))
    check('B3sl uncertain binding COMMIT resumes the committed action without replacement',
          unknown==dict(error='hof_commit_unknown') and count()==before+1 and state(row)['state']=='opening')
    assert invoke('advance',owner,session=row['session_id'],revision=int(row['revision']),steps=1)['state']=='pending'
    before_events=network['events']();(root/'barrier-http-fault').write_text('drop-after-commit')
    lost=invoke('advance',owner,session=row['session_id'],revision=int(row['revision']),steps=1)
    check('B3sl lost register response leaves B2 opening after the one committed primary event',
          lost.get('reason')=='pf_transport_unknown' and state(row)['state']=='opening' and network['events']()==before_events+1)
    refused=invoke('advance',owner,session=row['session_id'],revision=int(row['revision']),fault='decision-insert')
    check('B3sl local audit failure rolls back opening despite the recovered signed primary ACK',
          refused==dict(error='hof_storage_unavailable') and state(row)['state']=='opening' and network['events']()==before_events+1)
    opened=invoke('apply',owner,session=row['session_id'],action_id=prepared['action_id'])
    check('B3sl same primary ACK opens exactly once after local rollback without another Hub event',
          opened['state']=='open' and network['events']()==before_events+1)
    closing=invoke('close',owner,session=row['session_id'],revision=int(opened['revision']),state='cancelled')
    close_action=invoke('prepare',owner,session=row['session_id'],revision=int(closing['revision']))['action_id']
    assert invoke('advance',owner,session=row['session_id'],revision=int(closing['revision']),fault='decision-insert')==dict(error='hof_storage_unavailable')
    unknown=invoke('apply',owner,session=row['session_id'],action_id=close_action,fault='commit-unknown');settled=state(row)
    check('B3sl uncertain local closure COMMIT is inspected and not misreported as rollback',
          unknown==dict(error='pf_local_barrier_commit_unknown') and settled['state']=='cancelled'
          and invoke('apply',owner,session=row['session_id'],action_id=close_action)['revision']==settled['revision'])

    # Close before the first inbox checkpoint still registers and closes the same frozen version.
    row=create();closing=invoke('close',owner,session=row['session_id'],revision=int(row['revision']),state='cancelled');before=count()
    done=invoke('advance',owner,session=row['session_id'],revision=int(closing['revision']))
    check('B3sl closure during the initial crash gap reconciles both immutable actions without publishing open',
          done.get('session_state')=='cancelled' and count()==before+2 and state(row)['state']=='cancelled')

    # Before/after COMMIT process termination proves the actual local decision and audit remain atomic.
    for point in ('before-commit','after-commit'):
        row=create();prepared=invoke('prepare',owner,session=row['session_id'],revision=int(row['revision']))
        assert invoke('advance',owner,session=row['session_id'],revision=int(row['revision']),fault='decision-insert')==dict(error='hof_storage_unavailable')
        marker=root/(uuid.uuid4().hex+'.marker');path=root/(uuid.uuid4().hex+'.json')
        path.write_text(json.dumps(dict(action='apply',origin=origin,endpoint=endpoint,actor=owner,session=row['session_id'],
                                      action_id=prepared['action_id'],fault=point,marker=str(marker))));path.chmod(0o600)
        process=subprocess.Popen(['php',cli_path,'--allow-root','--path='+str(fans),'eval-file',str(recipe),str(path),'--use-include'],
                                 stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
        try:
            deadline=time.monotonic()+20
            while not marker.exists() and process.poll() is None and time.monotonic()<deadline:time.sleep(.02)
            assert marker.exists(),'Actual local COMMIT marker was not reached.'
        finally:
            if process.poll() is None:os.killpg(process.pid,signal.SIGKILL)
            process.wait(timeout=5)
        actual=state(row);expected='opening' if point=='before-commit' else 'open'
        check('B3sl '+point+' process death preserves the exact atomic local lifecycle',actual['state']==expected)
        resumed=invoke('apply',owner,session=row['session_id'],action_id=prepared['action_id'])
        check('B3sl '+point+' recovery applies the same ACK once without duplicating the decision',
              resumed['state']=='open' and int(resumed['revision'])==int(row['revision'])+1)
        closing=invoke('close',owner,session=row['session_id'],revision=int(resumed['revision']),state='cancelled')
        assert invoke('advance',owner,session=row['session_id'],revision=int(closing['revision']))['session_state']=='cancelled'

    row=create();assert invoke('advance',owner,session=row['session_id'],revision=int(row['revision']))['session_state']=='open'
    opened=state(row)
    check('B3sl creator cannot grant themselves the administrative suspension decision',
          invoke('close',owner,session=row['session_id'],revision=int(opened['revision']),state='suspended')==dict(error='hof_session_forbidden')
          and state(row)==opened)
    closing=invoke('close',admin,session=row['session_id'],revision=int(opened['revision']),state='suspended')
    old_action=invoke('prepare',owner,session=row['session_id'],revision=int(closing['revision']))['action_id']
    assert invoke('advance',owner,session=row['session_id'],revision=int(closing['revision']))['session_state']=='suspended'
    suspended=state(row);readmitted=invoke('readmit',owner,session=row['session_id'],revision=int(suspended['revision']))
    check('B3sl readmission prepares a new barrier version without editing the frozen rules',
          readmitted['state']=='opening' and int(readmitted['barrier_version'])==int(row['barrier_version'])+1 and readmitted['frozen_sha256']==row['frozen_sha256'])
    check('B3sl an old closure ACK cannot settle the new readmission version',
          invoke('apply',owner,session=row['session_id'],action_id=old_action)==dict(error='hof_lifecycle_binding_conflict') and state(row)['state']=='opening')
    assert invoke('advance',owner,session=row['session_id'],revision=int(readmitted['revision']))['session_state']=='open'
    opened=state(row);closing=invoke('close',owner,session=row['session_id'],revision=int(opened['revision']),state='cancelled')
    assert invoke('advance',owner,session=row['session_id'],revision=int(closing['revision']))['session_state']=='cancelled'
    check('B3sl lifecycle changes no official ledger and creates no parallel Fan ledger',hub_sql('SELECT * FROM wp_token_engine_pf_ledger ORDER BY id')==historical and sql("SHOW TABLES LIKE 'wp_fans%ledger%'")=='')
