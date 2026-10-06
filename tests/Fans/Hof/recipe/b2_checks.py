"""Session metadata, exact quotas and permissions on disposable WordPress/MariaDB."""
import argparse, datetime, json, pathlib, subprocess, time, uuid

p=argparse.ArgumentParser()
p.add_argument('--root',required=True); p.add_argument('--base',required=True); p.add_argument('--cli',required=True)
a=p.parse_args(); root=pathlib.Path(a.root).resolve()
if root.parent!=pathlib.Path('/var/tmp') or not root.name.startswith('fans-admission-wp-'): raise RuntimeError('Disposable root required')
wp=root/'wordpress'; worker=wp/'wp-content/plugins/faluss-platform/tests/Fans/Hof/recipe/worker.php'
fixture=json.loads((root/'session.json').read_text())
actors={name:value['id'] for name,value in fixture['sessions'].items()};actors['guest']=0
profiles=fixture['profiles']; checks=[]

def start(actor, action, **values):
    path=root/(str(uuid.uuid4())+'.json');path.write_text(json.dumps(dict(actor=actors[actor],action=action,**values)));path.chmod(0o600)
    return subprocess.Popen(['php',a.cli,'--allow-root','--path='+str(wp),'eval-file',str(worker),str(path),'--use-include'],stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
def finish(process):
    out,err=process.communicate(timeout=25)
    if process.returncode:raise RuntimeError('Session fixture failed: '+err[-1200:])
    return json.loads(out)
def call(actor,action,**values):return finish(start(actor,action,**values))
def data(actor,action,**values):
    if action=='session_open':
        approval=call(actor,'fixture_approve_session',session=values['session'])
        if 'error' in approval:raise AssertionError('Explicit fixture moderation: '+approval['error'])
    result=call(actor,action,**values)
    if 'error' in result:raise AssertionError(action+': '+result['error'])
    return result['data']
def check(name,condition):
    if not condition:raise AssertionError(name)
    checks.append(name);print('PASS '+name,flush=True)
def sql(query):
    result=subprocess.run(['mariadb','--no-defaults','--socket='+str(root/'sql.sock'),'-uroot','--batch','--skip-column-names','admission_recipe','-e',query],capture_output=True,text=True)
    if result.returncode:raise RuntimeError('Private session SQL failed')
    return result.stdout.strip()
def parallel(requests):
    barrier=root/('barrier-'+str(uuid.uuid4()))
    processes=[start(actor,action,**dict(values,barrier=str(barrier),worker=i)) for i,(actor,action,values) in enumerate(requests)]
    deadline=time.monotonic()+15
    while not all(pathlib.Path(str(barrier)+'.'+str(i)+'.ready').exists() for i in range(len(processes))):
        if time.monotonic()>deadline:raise RuntimeError('Concurrent session workers timed out')
        time.sleep(.02)
    pathlib.Path(str(barrier)+'.go').touch()
    return [finish(process) for process in processes]

now=datetime.datetime.now(datetime.timezone.utc)
rules=dict(title='Session individuelle fictive — lumières',rules_text='Participation volontaire avec résultats individuels corrigés.',category='arts',scope='international',country='',territory_ref='',timezone='Europe/Paris',
           starts_at=(now+datetime.timedelta(hours=1)).strftime('%Y-%m-%d %H:%M:%S.%f'),ends_at=(now+datetime.timedelta(days=2)).strftime('%Y-%m-%d %H:%M:%S.%f'))
check('Normal activation creates no session schema',sql("SHOW TABLES LIKE 'wp_fans_hof_session_schema'")=='')
data('admin','install');data('admin','session_install')
check('Explicit session schema installation is idempotent',data('admin','session_install')['ready'])
check('Session installation remains administrator-only',call('member','session_install')['error']=='hof_forbidden')
for actor in ['guest','unlinked','member','admin']:
    check('Creation refuses ineligible '+actor,'error' in call(actor,'session_create',rules=rules))
for actor in ['member','other','waiting']:
    data('admin','admit',creator=profiles[actor],status='active')
    data(actor,'editorial_submit',revision=0)
    data('admin','editorial_decide',creator=profiles[actor],revision=1,decision='approve',reason='allowed_editorial')
session=data('member','session_create',rules=dict(reversed(list(rules.items()))));sid=session['session_id']
check('Creator owns a stable draft with canonical input ordering',session['state']=='draft' and session['title']==rules['title'] and session['category']=='arts')
check('Organizer is not automatically a participant',all(role['role']!='participant' for role in data('member','session_inspect',session=sid)['roles']))
check('Unrelated creator cannot read or edit private session',call('other','session_inspect',session=sid)['error']=='hof_session_forbidden' and call('other','session_edit',session=sid,revision=1,rules=rules)['error']=='hof_session_forbidden')
check('Stale edit is rejected',call('member','session_edit',session=sid,revision=2,rules=rules)['error']=='hof_session_revision_conflict')
edited=data('member','session_edit',session=sid,revision=1,rules=dict(rules,title='Updated fixture title'))
check('Pre-opening edits version structural rules',edited['rules_version']=='2' and edited['revision']=='2')
data('member','session_invite',session=sid,revision=2,creator=profiles['other'])
check('Invitation does not implicitly grant coorganization',data('other','session_inspect',session=sid)['roles'][0]['state']=='invited')
check('Unaccepted coorganization blocks opening',call('member','session_open',session=sid,revision=3)['error']=='hof_coorganizer_acceptance_required')
check('Invitation can only be accepted by its recipient','error' in call('waiting','session_answer',session=sid,revision=3,accept=True))
data('other','session_answer',session=sid,revision=3,accept=True)
opening=data('member','session_open',session=sid,revision=4)
check('Opening reserves metadata but never fabricates Hub acknowledgement',opening['state']=='opening' and len(opening['frozen_sha256'])==64 and opening['barrier_version']=='1')
check('All structural changes are refused after freeze',call('member','session_edit',session=sid,revision=5,rules=rules)['error']=='hof_session_rules_frozen')
check('Organizers still have no implicit participant admission',all(role['role']=='organizer' for role in data('other','session_inspect',session=sid)['roles']))
check('Participation requires exact acceptance of frozen rules',call('waiting','session_apply',session=sid,revision=5,digest='0'*64)['error']=='hof_session_rules_acceptance_required')
data('waiting','session_apply',session=sid,revision=5,digest=opening['frozen_sha256'])
check('A participant does not receive other private role rows',len(data('waiting','session_inspect',session=sid)['roles'])==1)
check('Unadmitted participant cannot moderate admissions',call('waiting','session_admit',session=sid,revision=6,creator=profiles['waiting'],allow=True)['error']=='hof_session_forbidden')
before=sql('SELECT UTC_TIMESTAMP(6)')
data('other','session_admit',session=sid,revision=6,creator=profiles['waiting'],allow=True)
role=data('waiting','session_inspect',session=sid)['roles'][0]
check('Accepted coorganizer can admit individual future participation',role['state']=='admitted' and role['admitted_at']>=before and role['rules_sha256']==opening['frozen_sha256'])
check('Repeated application does not duplicate admission',call('waiting','session_apply',session=sid,revision=7,digest=opening['frozen_sha256'])['error']=='hof_participation_conflict')
data('member','session_apply',session=sid,revision=7,digest=opening['frozen_sha256'])
data('other','session_admit',session=sid,revision=8,creator=profiles['member'],allow=True)
check('Organizer can voluntarily become an individual participant',any(role['creator_id']==profiles['member'] and role['role']=='participant' and role['state']=='admitted' for role in data('member','session_inspect',session=sid)['roles']))
data('waiting','session_withdraw',session=sid,revision=9)
check('Admitted withdrawal immediately stops choices and waits for close proof',data('waiting','session_inspect',session=sid)['roles'][0]['state']=='closing')
check('Coorganizer cannot change owner-only programming or cancel',call('other','session_close',session=sid,revision=10,state='cancelled')['error']=='hof_session_forbidden')
closed=data('member','session_close',session=sid,revision=10,state='cancelled')
check('Cancellation records closing and does not rewrite original frozen rules',closed['state']=='closing' and closed['closure_action']=='cancelled' and closed['frozen_sha256']==opening['frozen_sha256'])
check('No new participation is admitted during closing','error' in call('other','session_apply',session=sid,revision=11,digest=opening['frozen_sha256']))
check('Cancellation neither deletes history nor assigns a winner',sql("SELECT COUNT(*) FROM wp_fans_hof_session_decisions WHERE session_id='"+sid+"'")=='11' and 'winner' not in closed)

# The first closing session still consumes one organizer slot. Two racing openings must not create a fourth.
drafts=[data('member','session_create',rules=dict(rules,title='Quota fixture '+str(i))) for i in range(3)]
data('member','session_open',session=drafts[0]['session_id'],revision=1)
for draft in drafts[1:]:data('member','fixture_approve_session',session=draft['session_id'])
results=parallel([('member','session_open',dict(session=draft['session_id'],revision=1)) for draft in drafts[1:]])
check('Concurrent openings reserve at most three organizer slots',sum('data' in result for result in results)==1 and sum(result.get('error')=='hof_session_quota' for result in results)==1)
check('Coorganization counts in the recipient quota',sql("SELECT COUNT(*) FROM wp_fans_hof_session_roles r JOIN wp_fans_hof_session_records s ON r.session_id=s.session_id WHERE r.creator_id='"+profiles['other']+"' AND r.role='organizer' AND r.state='accepted' AND s.state='closing'")=='1')
coorganized=[]
for i in range(2):
    draft=data('waiting','session_create',rules=dict(rules,title='Coorganization quota fixture '+str(i)))
    data('waiting','session_invite',session=draft['session_id'],revision=1,creator=profiles['other'])
    data('other','session_answer',session=draft['session_id'],revision=2,accept=True)
    coorganized.append(data('waiting','session_open',session=draft['session_id'],revision=3))
own_other=data('other','session_create',rules=dict(rules,title='Fourth coorganizer fixture'))
check('Three accepted coorganizations also prevent a fourth personal opening',call('other','session_open',session=own_other['session_id'],revision=1)['error']=='hof_session_quota')

participation=coorganized[0]
data('other','session_apply',session=participation['session_id'],revision=4,digest=participation['frozen_sha256'])
data('waiting','session_admit',session=participation['session_id'],revision=5,creator=profiles['other'],allow=True)
data('admin','admit',creator=profiles['other'],status='suspended')
data('other','session_withdraw',session=participation['session_id'],revision=6)
check('Suspended creator can still withdraw their own existing participation',any(role['role']=='participant' and role['state']=='closing' for role in data('other','session_inspect',session=participation['session_id'])['roles']))
data('admin','admit',creator=profiles['other'],status='active')
local=data('waiting','session_create',rules=dict(rules,scope='local',country='FR',territory_ref='fixture-town'))
national=data('waiting','session_create',rules=dict(rules,scope='national',country='FR'))
check('Local and national drafts preserve their target scope without inventing admission',local['scope']=='local' and national['scope']=='national' and call('waiting','session_open',session=local['session_id'],revision=1)['error']=='hof_reviewed_territory_required')
refused=data('waiting','session_create',rules=rules)
data('waiting','session_invite',session=refused['session_id'],revision=1,creator=profiles['other'])
data('other','session_answer',session=refused['session_id'],revision=2,accept=False)
check('Refused coorganization is explicit and never accepted',any(role['state']=='refused' and role['role']=='organizer' for role in data('waiting','session_inspect',session=refused['session_id'])['roles']))
check('Ordinary creator cannot suspend another session','error' in call('other','session_close',session=refused['session_id'],revision=3,state='suspended'))
check('Moderator may suspend a draft without an economic rewrite',data('admin','session_close',session=refused['session_id'],revision=3,state='suspended')['state']=='suspended')
sql("CREATE TRIGGER session_audit_failure BEFORE INSERT ON wp_fans_hof_session_decisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Controlled recipe failure'")
check('Session and automatic organizer roll back when audit cannot commit','error' in call('waiting','session_create',rules=dict(rules,title='Rollback fixture')) and sql("SELECT COUNT(*) FROM wp_fans_hof_session_records WHERE title='Rollback fixture'")=='0')
sql('DROP TRIGGER session_audit_failure')
check('Every successful session revision has exactly one durable journal decision',sql('SELECT COUNT(*) FROM wp_fans_hof_session_records s WHERE s.revision<>(SELECT COUNT(*) FROM wp_fans_hof_session_decisions d WHERE d.session_id=s.session_id)')=='0')
sql('ALTER TABLE wp_fans_hof_session_roles ENGINE=MyISAM')
check('Nontransactional session metadata is refused without implicit repair','error' in call('member','session_inspect',session=sid))
sql('ALTER TABLE wp_fans_hof_session_roles ENGINE=InnoDB')
check('Restored compatible session schema retains frozen history',data('member','session_inspect',session=sid)['session']['frozen_sha256']==opening['frozen_sha256'])
check('Closed B2 exposes no normal route or score/ledger table',sql("SHOW TABLES LIKE '%pf_ledger'")=='' and sql("SHOW TABLES LIKE 'wp_fans_hof_scores'")=='')
(root/'checks.json').write_text(json.dumps({'scope':'B2 private session metadata on disposable WordPress/MariaDB; no Hub acknowledgement, economic opening or central SSO proof','count':len(checks),'checks':checks},indent=2))
