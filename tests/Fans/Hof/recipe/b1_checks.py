"""Private B1 SQL recipe on fresh WordPress/MariaDB. No Hub, network, or score activation."""
import argparse, json, os, pathlib, subprocess, time, uuid

p = argparse.ArgumentParser()
p.add_argument('--root', required=True)
p.add_argument('--base', required=True)
p.add_argument('--cli', required=True)
a = p.parse_args()
root = pathlib.Path(a.root).resolve()
if root.parent != pathlib.Path('/var/tmp') or not root.name.startswith('fans-admission-wp-'):
    raise RuntimeError('Unexpected disposable root')
wp = root/'wordpress'
worker = wp/'wp-content/plugins/faluss-platform/tests/Fans/Hof/recipe/worker.php'
state = json.loads((root/'session.json').read_text())
actors = {name: session['id'] for name, session in state['sessions'].items()}
actors['guest'] = 0
checks = []

def check(name, condition):
    if not condition: raise AssertionError(name)
    checks.append(name)
    print('PASS '+name, flush=True)

def start(actor, action, **values):
    request = root/(str(uuid.uuid4())+'.json')
    request.write_text(json.dumps(dict(actor=actors[actor], action=action, **values)))
    request.chmod(0o600)
    return subprocess.Popen(['php', a.cli, '--allow-root', '--path='+str(wp), 'eval-file', str(worker), str(request), '--use-include'],
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)

def finish(process):
    out, err = process.communicate(timeout=25)
    if process.returncode: raise RuntimeError('Private fixture failed: '+err[-1200:])
    return json.loads(out)

def call(actor, action, **values): return finish(start(actor, action, **values))
def data(actor, action, **values):
    result = call(actor, action, **values)
    if 'error' in result: raise AssertionError(action+': '+result['error'])
    return result['data']

def sql(query):
    process = subprocess.run(['mariadb', '--no-defaults', '--socket='+str(root/'sql.sock'), '-uroot', '--batch', '--skip-column-names', 'admission_recipe', '-e', query], capture_output=True, text=True)
    if process.returncode: raise RuntimeError('Private SQL fixture failed')
    return process.stdout.strip()

check('No HoF schema installed by the normal plugin activation', sql("SHOW TABLES LIKE 'wp_fans_hof_schema'") == '')
for actor in ['guest', 'member', 'other', 'unlinked', 'editor', 'waiting']:
    check('Explicit schema installation denied to '+actor, call(actor, 'install')['error'] == 'hof_forbidden')
check('Explicit administrator schema installation succeeds', data('admin','install')['ready'])
check('Explicit schema installation is idempotent', data('admin','install')['ready'])
check('Nested schema installation refuses implicit COMMIT', call('admin','nested_install')['error'] == 'hof_nested_transaction')
check('Schema installation creates no origin', data('admin','read_origin') is None)
origin = str(uuid.uuid4())
prepared = data('admin','origin',origin=origin)
check('Prepared origin has no real opening timestamp or implicit activation', prepared['state'] == 'prepared' and 'opened_at' not in prepared)
check('Origin is immutable and replay is stable', data('admin','origin',origin=origin) == prepared and call('admin','origin',origin=str(uuid.uuid4()))['error'] == 'hof_origin_conflict')
check('Origin preparation is administrator-only', call('member','origin',origin=origin)['error'] == 'hof_forbidden')
general = data('admin','dimension',kind='general')
category = data('admin','dimension',kind='category',value='arts')
month = data('admin','dimension',kind='month',value='2026-03')
check('General and category dimensions are distinct immutable metadata', general['dimension_id'] != category['dimension_id'] and data('admin','dimension',kind='general') == general)
check('Persisted March boundaries use Paris civil month', month['starts_at'] == '2026-02-28 23:00:00.000000' and month['ends_at'] == '2026-03-31 22:00:00.000000')
check('Unknown categories cannot create a dimension', 'error' in call('admin','dimension',kind='category',value='invented'))
check('Dimensions are private operator metadata', call('member','dimension',kind='general')['error'] == 'hof_forbidden')
for actor in ['guest', 'unlinked', 'editor', 'admin']:
    check('Member alias access refuses '+actor, call(actor,'own')['error'] == 'hof_linked_member_required')
initial = data('member','own')
check('Member visibility is private by default', initial['revision']=='0' and initial['fan_public']=='0' and initial['creator_public']=='0')
check('Pending pseudonym is never publicly delivered', data('member','submit',alias='Fixture fan A',revision=0)['alias_state']=='pending' and data('other','visible_fan',member=actors['member']) is None)
check('Member cannot moderate their own alias', call('member','decide',member=actors['member'],revision=1,decision='approve',reason='allowed_alias')['error']=='hof_forbidden')
check('Consent alone does not approve an alias', data('member','consent',family='fan',enabled=True,revision=1)['fan_public']=='1' and data('other','visible_fan',member=actors['member']) is None)
check('Stale moderation revision is rejected', call('admin','decide',member=actors['member'],revision=1,decision='approve',reason='allowed_alias')['error']=='hof_revision_conflict')
check('Approval uses the exact current revision', data('admin','decide',member=actors['member'],revision=2,decision='approve',reason='allowed_alias')['approved_revision']=='3')
check('Approved alias plus consent is visible through the server contract', data('other','visible_fan',member=actors['member'])=='Fixture fan A')
data('member','submit',alias='Pending replacement',revision=3)
check('Pending revision does not leak or replace the approved alias', data('other','visible_fan',member=actors['member'])=='Fixture fan A')
data('admin','decide',member=actors['member'],revision=4,decision='reject',reason='needs_revision')
check('Rejected revision keeps the last approved presentation', data('other','visible_fan',member=actors['member'])=='Fixture fan A')
data('member','consent',family='fan',enabled=False,revision=5)
check('Consent revocation hides immediately without economic mutation', data('other','visible_fan',member=actors['member']) is None)
data('member','consent',family='fan',enabled=True,revision=6)
data('admin','decide',member=actors['member'],revision=7,decision='revoke',reason='prohibited_content')
check('Alias revocation clears approved fields and public consent', data('member','own')['fan_public']=='0' and data('other','visible_fan',member=actors['member']) is None)
data('member','submit',alias='Replacement A',revision=8)
barrier = root/('barrier-'+str(uuid.uuid4()))
processes = [start(actor,'decide',member=actors['member'],revision=9,decision=decision,reason=reason,barrier=str(barrier),worker=i)
             for i,(actor,decision,reason) in enumerate([('admin','approve','allowed_alias'),('admin-two','reject','needs_revision')])]
deadline = time.monotonic()+15
while not all(pathlib.Path(str(barrier)+'.'+str(i)+'.ready').exists() for i in range(2)):
    if time.monotonic()>deadline: raise RuntimeError('Concurrent fixture did not become ready')
    time.sleep(.02)
pathlib.Path(str(barrier)+'.go').touch()
results = [finish(process) for process in processes]
check('Concurrent decisions produce one commit and one revision conflict', sum('data' in result for result in results)==1 and sum(result.get('error')=='hof_revision_conflict' for result in results)==1)
check('Every member mutation has one matching durable decision', sql('SELECT COUNT(*) FROM wp_fans_hof_decisions WHERE wp_user_id='+str(actors['member']))=='10')
check('Member does not see other members through the moderation query', call('member','review')['error']=='hof_forbidden')
data('member','withdraw',revision=10)
check('Owner withdrawal clears both submitted and approved alias', data('member','own')['approved_alias']=='' and data('member','own')['pending_alias']=='')
check('HTML, email and control characters are refused as aliases', all('error' in call('member','submit',alias=alias,revision=11) for alias in ['<script>','x@y.test','bad\nname','x']))
creator = state['profiles']['member']
check('Creator consent cannot bypass profile admission', call('member','consent',family='creator',enabled=True,revision=11)['error']=='hof_approved_creator_required')
data('admin','admit',creator=creator,status='active')
check('Profile admission alone cannot bypass presentation approval', call('member','consent',family='creator',enabled=True,revision=11)['error']=='hof_approved_creator_required')
data('member','editorial_submit',revision=0)
data('admin','editorial_decide',creator=creator,revision=1,decision='approve',reason='allowed_editorial')
data('member','consent',family='creator',enabled=True,revision=11)
check('Approved active creator with independent consent is visible', data('other','visible_creator',creator=creator)=='Local creator fixture')
data('admin','admit',creator=creator,status='suspended')
check('Suspension hides Creator projection without rewriting consent', data('other','visible_creator',creator=creator) is None and data('member','own')['creator_public']=='1')
data('admin','admit',creator=creator,status='active')
check('Readmission revalidates approved presentation and consent', data('other','visible_creator',creator=creator)=='Local creator fixture')
data('admin','editorial_decide',creator=creator,revision=2,decision='revoke',reason='prohibited_content')
check('Editorial removal hides Creator despite remaining ranking consent', data('other','visible_creator',creator=creator) is None)
sql('ALTER TABLE wp_fans_hof_members ENGINE=MyISAM')
check('Nontransactional schema is refused without repairing it silently', 'error' in call('admin','install'))
sql('ALTER TABLE wp_fans_hof_members ENGINE=InnoDB')
check('Correct restored schema remains readable after new worker processes', data('admin','install')['ready'] and data('member','own')['revision']=='12')
sql("CREATE TRIGGER hof_audit_failure BEFORE INSERT ON wp_fans_hof_decisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Controlled fixture failure'")
check('Journal failure rolls back consent and revision together', 'error' in call('member','consent',family='fan',enabled=True,revision=12) and data('member','own')['revision']=='12' and data('member','own')['fan_public']=='0')
sql('DROP TRIGGER hof_audit_failure')
sql('ALTER TABLE wp_fans_hof_dimensions DROP INDEX origin_kind_value')
check('Divergent dimension uniqueness is refused', 'error' in call('admin','install'))
sql('ALTER TABLE wp_fans_hof_dimensions ADD UNIQUE KEY origin_kind_value (origin_id,kind,value)')
check('Restored dimension schema preserves immutable metadata', data('admin','install')['ready'] and data('admin','dimension',kind='general')==general)
check('No PF ledger or score storage is created by B1', sql("SHOW TABLES LIKE '%pf_ledger'")=='' and sql("SHOW TABLES LIKE 'wp_fans_hof_scores'")=='')
(root/'checks.json').write_text(json.dumps({'scope':'B1 private WordPress/MariaDB recipe only; seeded local SSO links, no central SSO proof', 'checks':checks,'count':len(checks)},indent=2))
