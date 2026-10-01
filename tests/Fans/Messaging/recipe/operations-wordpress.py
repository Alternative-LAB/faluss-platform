"""Operator CLI and real WordPress REST/cron; synthetic SSO links, loopback only."""
import argparse, json, pathlib, re, subprocess, urllib.request, urllib.error, uuid

p = argparse.ArgumentParser()
p.add_argument('--root', required=True); p.add_argument('--base', required=True); p.add_argument('--cli', required=True)
a = p.parse_args(); root = pathlib.Path(a.root)
assert re.fullmatch(r'/var/tmp/fans-admission-wp-[A-Za-z0-9_]+', str(root))
assert re.fullmatch(r'http://127\.0\.0\.1:[0-9]+', a.base)
session = json.loads((root/'session.json').read_text()); passed = []
command = ['php', a.cli, '--allow-root', '--path='+str(root/'wordpress')]
mod = session['sessions']['admin-two']['id']; fan = session['sessions']['member']['id']
creator = session['profiles']['other']
def check(name, condition):
    assert condition, name
    passed.append(name)
def cli(*args, user=None):
    return subprocess.run(command+(['--user='+str(user)] if user else [])+list(args), capture_output=True, text=True)
def evaluate(code):
    r = cli('eval', code); assert r.returncode == 0, 'Fixture setup failed'; return r.stdout.strip()
def operation(name, user=mod, healthy=False):
    return cli('faluss', 'fans-messaging', name, *(['--require-healthy'] if healthy else []), user=user)
def flags(**values):
    config = root/'wordpress/wp-config.php'; text = config.read_text()
    for key, value in values.items():
        text = re.sub(r'define\("'+key+r'",(?:true|false)\);\n', '', text)
        text = text.replace('<?php\n', '<?php\ndefine("'+key+'",'+json.dumps(value)+');\n', 1)
    config.write_text(text)
def api(route, who=None, data=None, nonce=True):
    headers = {}
    if who:
        headers['Cookie'] = '; '.join(k+'='+v for k,v in session['sessions'][who]['cookies'].items())
        if nonce: headers['X-WP-Nonce'] = session['sessions'][who]['nonce']
    if data is not None: headers['Content-Type'] = 'application/json'
    req = urllib.request.Request(a.base+'/wp-json/faluss-fans/v1/'+route, headers=headers, data=json.dumps(data).encode() if data is not None else None)
    try: response = urllib.request.urlopen(req, timeout=20)
    except urllib.error.HTTPError as error: response = error
    return response.status, json.loads(response.read()), dict(response.headers)
def panel(who):
    req = urllib.request.Request(a.base+'/wp-admin/admin.php?page=faluss-fans-moderation&view=messages', headers={'Cookie':'; '.join(k+'='+v for k,v in session['sessions'][who]['cookies'].items())})
    try: response = urllib.request.urlopen(req, timeout=20)
    except urllib.error.HTTPError as error: response = error
    return response.status, response.read().decode()
evaluate("get_user_by('id',"+str(mod)+")->add_cap('moderate_faluss_fans_messages');")
for who in [None, fan, session['sessions']['admin']['id']]:
    check('operator requires explicit double capability '+str(who), operation('prepare', who).returncode != 0)
evaluate("get_user_by('id',"+str(fan)+")->add_cap('moderate_faluss_fans_messages');")
check('dedicated capability alone is insufficient', operation('status', fan).returncode != 0)
evaluate("get_user_by('id',"+str(fan)+")->remove_cap('moderate_faluss_fans_messages');")
check('invalid operation denied', operation('invent').returncode != 0)
check('healthy status rejects unprepared storage', operation('status', healthy=True).returncode != 0)
prepared = operation('prepare'); check('prepare without attestation/admission', prepared.returncode == 0)
state = json.loads(prepared.stdout)
check('all schemas and hourly recurrence verified', state['schemas_ready'] and state['hourly_event'])
check('no attestation or opening from prepare', not state['policy_attested'] and not state['admission_open'] and not state['private_access'])
check('prepare repeat is idempotent', operation('prepare').returncode == 0)
check('six InnoDB tables are present', evaluate("global $wpdb; echo count($wpdb->get_results(\"SHOW TABLES LIKE '%faluss_fans_dm_%'\"));") == '6')
check('fresh purge succeeds with admission closed', operation('purge').returncode == 0)
check('healthy operational status does not attest policy', operation('status', healthy=True).returncode == 0)
evaluate("wp_clear_scheduled_hook('faluss_fans_messages_retention'); wp_schedule_event(time()+60,'daily','faluss_fans_messages_retention');")
check('conflicting cron is reported without silent replacement', operation('prepare').returncode != 0)
evaluate("wp_clear_scheduled_hook('faluss_fans_messages_retention');")
check('correct hourly event restored explicitly', operation('prepare').returncode == 0)
evaluate("global $wpdb; $wpdb->query('ALTER TABLE wp_faluss_fans_dm_reports ENGINE=MyISAM');")
check('non-InnoDB schema refused', operation('prepare').returncode != 0 and operation('purge').returncode != 0)
evaluate("global $wpdb; $wpdb->query('ALTER TABLE wp_faluss_fans_dm_reports ENGINE=InnoDB');")
check('restored schema verifies', operation('prepare').returncode == 0)
# Fixture-only permission/configuration faults, never applied to a target installation.
mu = root/'wordpress/wp-content/mu-plugins/failure.php'
mu.write_text("<?php add_filter('pre_schedule_event','__return_false'); wp_clear_scheduled_hook('faluss_fans_messages_retention');")
check('scheduler failure is nonzero', operation('prepare').returncode != 0)
mu.unlink(); check('scheduler recovery', operation('prepare').returncode == 0)
evaluate("update_option('faluss_fans_messages_retention_status','invalid');")
mu.write_text("<?php add_filter('pre_update_option_faluss_fans_messages_retention_status',static fn($value,$old)=>$old,10,2);")
check('diagnostic write failure is nonzero', operation('purge').returncode != 0)
mu.unlink(); check('diagnostic recovery', operation('purge').returncode == 0)
evaluate("update_option('faluss_fans_messages_retention_status',wp_json_encode(['checked_at'=>time()-7201,'ordinary_purged'=>0,'reports_purged'=>0,'more'=>false,'error'=>'']));")
check('stale purge blocks healthy status', operation('status', healthy=True).returncode != 0)
check('native cron runs with no session', cli('cron','event','run','faluss_fans_messages_retention').returncode == 0)
check('native cron updates successful diagnostics', operation('status', healthy=True).returncode == 0)
panel_source = root/'wordpress/wp-content/plugins/faluss-platform/src/Fans/Moderation/ModerationPanel.php'
fixed = panel_source.read_text()
assert "add_action('admin_menu', [self::class, 'menu'], 20);" in fixed
try:
    panel_source.write_text(fixed.replace("add_action('admin_menu', [self::class, 'menu'], 20);", "add_action('admin_menu', [self::class, 'menu']);"))
    check('0.11.0 menu order reproduces WordPress HTTP 403', panel('admin-two')[0]==403)
finally:
    panel_source.write_text(fixed)
check('moderator panel accessible before attestation and admission', panel('admin-two')[0]==200)
check('ordinary administrator denied proof panel', panel('admin')[0]==403)
evaluate("global $wpdb; for($i=0;$i<1001;$i++){$wpdb->query($wpdb->prepare(\"INSERT INTO wp_faluss_fans_dm_threads VALUES(%s,%d,%s,999999,'pending',1,1,UTC_TIMESTAMP()-INTERVAL 13 MONTH,UTC_TIMESTAMP()-INTERVAL 13 MONTH)\",wp_generate_uuid4(),100000+$i,wp_generate_uuid4()));}")
backlog = operation('purge'); check('bounded purge reports remaining batches as nonzero', backlog.returncode != 0 and json.loads(backlog.stdout)['more'])
check('next bounded purge drains remainder', operation('purge').returncode == 0)
check('backlog fully erased', evaluate('global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM wp_faluss_fans_dm_threads");')=='0')
config = root/'wordpress/wp-config.php'; original_config = config.read_text()
config.write_text(original_config.replace('define("FALUSS_PLATFORM_ROLE","fans");','define("FALUSS_PLATFORM_ROLE","hub");'))
check('operator command refuses other site role', operation('prepare').returncode != 0)
config.write_text(original_config)
evaluate("\\Faluss\\Platform\\Fans\\Profiles\\CreatorStatusSchema::installOrVerify(); wp_set_current_user("+str(mod)+"); \\Faluss\\Platform\\Fans\\Profiles\\CreatorProfileService::setStatus('"+creator+"','active');")
flags(FALUSS_FANS_MESSAGING_POLICY_ATTESTED=True, FALUSS_PLATFORM_FANS_MESSAGING=True)
for who in [None, 'unlinked', 'admin']:
    check('inbox denied to guest/unlinked/admin '+str(who), api('messages',who)[0] in (401,403))
check('inbox requires real WP nonce', api('messages','member',nonce=False)[0] in (401,403))
code, body, headers = api('messages','member'); check('linked fan private no-store', code==200 and 'no-store' in headers.get('Cache-Control',''))
request = {'creator_id':creator,'body':'Demande textuelle de recette isolée.','key':str(uuid.uuid4())}
code, result, _ = api('messages/requests','member',request); check('actual request created', code==200 and result['state']=='pending')
thread = result['thread_id']; route = 'messages/'+thread
check('replayed request keeps same thread', api('messages/requests','member',request)[1]['thread_id']==thread)
check('no premature bilateral send', api(route+'/send','member',{'body':'Trop tôt','key':str(uuid.uuid4())})[0]==403)
check('third party cannot read thread', api(route,'waiting')[0]==404)
check('creator accepts request', api(route+'/decision','other',{'revision':1,'action':'accept'})[0]==200)
code, sent, _ = api(route+'/send','other',{'body':'Réponse de recette isolée.','key':str(uuid.uuid4())}); check('bilateral send succeeds', code==200)
conversation = api(route,'member')[1]
message = conversation['messages'][-1]['message_id']
code, case, _ = api(route+'/report','member',{'message_id':message,'reason':'other'}); check('minimal peer report created', code==200)
caseid = case['case_id']; report = 'message-reports/'+caseid
check('ordinary admin denied proof', api(report,'admin')[0]==403)
check('designated moderator reads minimal proof', api(report,'admin-two')[0]==200)
decision = {'revision':1,'action':'no_action','reason':'Examen synthétique uniquement.','recourse_complete':False,'days':0}
check('provisional decision is journaled', api(report+'/decision','admin-two',decision)[0]==200)
flags(FALUSS_PLATFORM_FANS_MESSAGING=False)
check('moderator panel survives closing admission', panel('admin-two')[0]==200)
check('closed admission retains private thread', api(route,'member')[0]==200 and not api(route,'member')[1]['can_send'])
check('closed admission refuses sends', api(route+'/send','other',{'body':'Interdit','key':str(uuid.uuid4())})[0]==503)
check('closed admission refuses requests', api('messages/requests','waiting',dict(request,key=str(uuid.uuid4())))[0]==503)
check('closed admission permits appeal', api(report+'/appeal','member',{'revision':2,'reason':'Recours synthétique.'})[0]==200)
check('pending appeal prevents finality', api(report+'/decision','admin-two',dict(decision,revision=3,action='finalize',recourse_complete=True))[0]==409)
check('moderation available when admission closed', api(report+'/decision','admin-two',dict(decision,revision=3))[0]==200)
check('no automatic final attestation', api(report+'/decision','admin-two',dict(decision,revision=4,action='finalize'))[0]==409)
check('explicit synthetic finality for test only', api(report+'/decision','admin-two',dict(decision,revision=4,action='finalize',recourse_complete=True))[0]==200)
check('stale block revision refused', api(route+'/decision','member',{'revision':1,'action':'block'})[0]==409)
revision = api(route,'member')[1]['revision']
check('closure preserves block action', api(route+'/decision','member',{'revision':revision,'action':'block'})[0]==200)
# Failing DELETE must surface a nonzero exit and diagnostic; the live fixture thread stays intact.
evaluate("global $wpdb; $wpdb->query(\"UPDATE wp_faluss_fans_dm_threads SET last_sent_at=UTC_TIMESTAMP()-INTERVAL 13 MONTH\"); $wpdb->query(\"CREATE TRIGGER deny_purge BEFORE DELETE ON wp_faluss_fans_dm_threads FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture failure'\");")
check('SQL purge failure is nonzero', operation('purge').returncode != 0)
check('error diagnostic prevents healthy status', operation('status', healthy=True).returncode != 0)
evaluate("global $wpdb; $wpdb->query('DROP TRIGGER deny_purge');")
check('expired ordinary thread is purged with admission closed', operation('purge').returncode == 0)
check('minimal proof survives ordinary purge', api(report,'admin-two')[0]==200)
check('block survives ordinary purge', len(api('messages/blocks','member')[1]['items'])==1)
evaluate("global $wpdb; $wpdb->query(\"UPDATE wp_faluss_fans_dm_reports SET final_at=UTC_TIMESTAMP()-INTERVAL 13 MONTH,hold_until=UTC_TIMESTAMP()+INTERVAL 1 DAY\");")
check('active legal hold survives purge', operation('purge').returncode == 0 and api(report,'admin-two')[0]==200)
evaluate("global $wpdb; $wpdb->query(\"UPDATE wp_faluss_fans_dm_reports SET hold_until=UTC_TIMESTAMP()-INTERVAL 1 SECOND\");")
check('proof and journals purge after finality and hold expiry', operation('purge').returncode == 0 and api(report,'admin-two')[0]==404)
for table in ['reports','report_events','legal_holds']:
    check('no expired case content in '+table, evaluate('global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM wp_faluss_fans_dm_'+table+'");')=='0')
check('revoke dedicated authorization', cli('user','remove-cap',str(mod),'moderate_faluss_fans_messages').returncode==0 and operation('status').returncode!=0)
check('regrant only designated account', cli('user','add-cap',str(mod),'moderate_faluss_fans_messages').returncode==0)
blockid = api('messages/blocks','member')[1]['items'][0]['block_id']
check('explicit unblock after ordinary expiry', api('messages/blocks/'+blockid+'/unblock','member',{'confirm':True})[0]==200)
flags(FALUSS_PLATFORM_FANS_MESSAGING=True)
code, result, _ = api('messages/requests','member',dict(request,key=str(uuid.uuid4())))
check('fresh intention after expiry and explicit unblock '+str(code)+' '+str(result.get('code','')), code==200)
thread = result['thread_id']; route = 'messages/'+thread
check('new request still requires acceptance', api(route+'/decision','other',{'revision':1,'action':'accept'})[0]==200)
code, sent, _ = api(route+'/send','other',{'body':'Réponse synthétique pour la capture locale.','key':str(uuid.uuid4())})
check('capture text comes from actual send', code==200)
code, case, _ = api(route+'/report','member',{'message_id':sent['message_id'],'reason':'other'})
check('capture proof comes from actual report', code==200)
caseid = case['case_id']; report = 'message-reports/'+caseid
check('capture provisional decision', api(report+'/decision','admin-two',decision)[0]==200)
flags(FALUSS_PLATFORM_FANS_MESSAGING=False)
check('capture actual appeal with admission closed', api(report+'/appeal','member',{'revision':2,'reason':'Recours de recette isolée, aucune donnée réelle.'})[0]==200)
(root/'capture.json').write_text(json.dumps({'base':a.base,'sessions':session['sessions'],'thread':thread,'case':caseid}))
(root/'capture.json').chmod(0o600)
(root/'checks.json').write_text(json.dumps({'checks':passed,'count':len(passed),'limits':'Real WordPress/MariaDB; local link fixtures, no Identity network, sites or human production attestation.'},indent=2))
