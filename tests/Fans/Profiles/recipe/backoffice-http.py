"""Native private back-office proof on disposable real WordPress/MariaDB."""
from pathlib import Path
exec((Path(__file__).with_name('admission-http.py')).read_text().split("panel = ")[0])

bo = '/app/admin'
def native(view, who='admin', data=None): return http(bo+'?view='+view,who,data)
def nonce(view, who='admin'):
    code,body,_=native(view,who); assert code==200
    found=re.search(r'name="_wpnonce" value="([^"]+)"',body); assert found
    return found.group(1)
for who in [None,'member','other','fan','editor','unlinked']:
    check('private root denied '+str(who),http(bo,who)[0]==403)
code,body,headers=http(bo,'admin')
check('root native shell no WordPress sidebar',code==200 and 'fb-pills' in body and 'id="adminmenu"' not in body)
check('root no shared cache','no-store' in headers.get('Cache-Control','') and 'no-store' in headers.get('CDN-Cache-Control',''))
check('root fallback WordPress link','Administration WordPress' in body)
check('unavailable report operation not offered','view=operations' not in body and 'view=messages' not in body)
check('denied module cannot be forced',native('messages')[0]==403 and native('operations')[0]==403)
check('arbitrary view denied',native('unknown')[0]==403)
for view in ['profiles','editorial','texts','images','accounts','search','catalog','staff','modules']:
    check('available native section '+view,native(view)[0]==200)
code,body,_=native('profiles&status=active&item='+member)
check('shared admission form stays in standalone shell','action="https://fans.example.test/app/admin?' in body and 'member@example.invalid' in body)
check('member search real email','member@example.invalid' in native('search&q=member%40example.invalid')[1])
check('cross-domain creator reference resolves','Présentation' in native('search&q='+member)[1] and 'Examiner →' in native('search&q='+member)[1])
check('absent account page 404',native('accounts&user=999999')[0]==404)
check('invalid cursor refused',native('accounts&cursor=-1')[0]==400)

# Decisions use exactly the native panel adapter, including CAS and its durable journal.
profile=api('creators/'+other+'/private','admin')[1]
decision={'kind':'creator-profile','item_id':other,'revision':str(profile['status_revision']),'status':'suspended','confirm':'yes','_wpnonce':nonce('profiles&item='+other)}
check('standalone suspension committed',native('profiles&item='+other,data=decision)[0]==200)
check('standalone suspension hides actual public page',http('/app/creators/'+other)[0]==404)
check('standalone stale decision conflicts',native('profiles&item='+other,data=decision)[0]==409)
decision.update(status='active',revision=str(api('creators/'+other+'/private','admin')[1]['status_revision']))
check('standalone reactivation committed',native('profiles&item='+other,data=decision)[0]==200)
check('standalone reactivation restores page',http('/app/creators/'+other)[0]==200)
check('two standalone transitions share original journal',len(api('creators/'+other+'/private','admin')[1]['journal'])==len(profile['journal'])+2)
pub=json.loads(cli("wp_set_current_user("+str(session['sessions']['member']['id'])+");echo wp_json_encode(\\Faluss\\Platform\\Fans\\Publications\\TextPublicationService::create('Publication de recette back-office.','hosted_allowed_content',wp_generate_uuid4()));"))
check('real publication created for moderation','publication_id' in pub)
decision={'kind':'text-publications','item_id':pub['publication_id'],'revision':str(pub['revision']),'reason':'allowed_text','_wpnonce':nonce('texts')}
check('standalone publication approval',native('texts',data=decision)[0]==200)
check('standalone publication stale rejection refused',native('texts',data={**decision,'reason':'needs_revision'})[0]==409)
check('real publication count is one approved',cli("wp_set_current_user("+str(session['sessions']['admin']['id'])+");$n=\\Faluss\\Platform\\Fans\\Publications\\TextPublicationService::administrationCounts('"+member+"');echo $n['approved'];")=='1')

target=session['sessions']['admin-two']['id']; admin=session['sessions']['admin']['id']
form={'_wpnonce':nonce('staff'),'target':str(target),'expected':'0','grant':'1','confirm':'yes'}
check('staff missing nonce denied',native('staff',data={**form,'_wpnonce':'invalid'})[0]==403)
check('staff cannot elevate SSO member',native('staff',data={**form,'target':str(session['sessions']['member']['id'])})[0]==403)
check('staff forged field denied',native('staff',data={**form,'role':'administrator'})[0]==400)
check('staff ordinary member cannot mutate',native('staff','member',form)[0]==403)
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
    codes=list(pool.map(lambda _:native('staff',data=form)[0],range(2)))
check('concurrent staff grant single winner',sorted(codes)==[200,409])
check('grant committed once with journal',cli("global $wpdb;echo $wpdb->get_var(\"SELECT COUNT(*) FROM wp_faluss_fans_admin_actions WHERE action='staff_grant' AND outcome='completed'\");")=='1')
check('new moderator operations appear next request',native('operations','admin-two')[0]==200)
check('ordinary admin still denied operations',native('operations')[0]==403)
operation={'_wpnonce':nonce('operations','admin-two'),'operation':'messages_prepare','confirm':'yes'}
check('prepare CSRF denied',native('operations','admin-two',{**operation,'_wpnonce':'bad'})[0]==403)
check('prepare native form succeeds',native('operations','admin-two',operation)[0]==200)
check('prepare installs hourly event',cli("$e=wp_get_scheduled_event('faluss_fans_messages_retention');echo $e&&$e->schedule==='hourly'?'yes':'no';")=='yes')
check('prepare does not attest policy',cli("echo defined('FALUSS_FANS_MESSAGING_POLICY_ATTESTED')&&FALUSS_FANS_MESSAGING_POLICY_ATTESTED?'yes':'no';")=='no')
check('prepare does not open messages',cli("echo defined('FALUSS_PLATFORM_FANS_MESSAGING')&&FALUSS_PLATFORM_FANS_MESSAGING?'yes':'no';")=='no')
check('purge native form succeeds',native('operations','admin-two',{**operation,'operation':'messages_purge'})[0]==200)
check('retention healthy real service',cli("wp_set_current_user("+str(target)+");$s=\\Faluss\\Platform\\Fans\\Messaging\\MessageOperations::status();echo $s['retention_healthy']?'yes':'no';")=='yes')
check('report queue available with admission closed',native('messages','admin-two')[0]==200)
cleanup={'_wpnonce':nonce('images'),'cleanup':'yes','confirm':'yes'}
check('image cleanup native confirmed',native('images',data=cleanup)[0]==200)
key=cli('echo wp_generate_uuid4();')
catalog={'_wpnonce':nonce('catalog'),'creator':member,'creation_key':key,'confirm':'yes'}
check('catalog native technical entry succeeds',native('catalog',data=catalog)[0]==200)
check('catalog replay idempotent',native('catalog',data=catalog)[0]==200 and cli('global $wpdb;echo $wpdb->get_var("SELECT COUNT(*) FROM wp_faluss_fans_store_catalog");')=='1')
check('catalog private creator context','member@example.invalid' in native('catalog')[1])
revoke={**form,'expected':'1','grant':'0'}
check('staff revocation succeeds',native('staff',data=revoke)[0]==200)
check('revocation takes effect next request',native('operations','admin-two')[0]==403)
check('revocation does not remove WordPress administrator',cli('echo in_array("administrator",get_userdata('+str(target)+')->roles,true)?"yes":"no";')=='yes')
cli("global $wpdb;$wpdb->query(\"CREATE TRIGGER fail_staff_audit BEFORE UPDATE ON wp_faluss_fans_admin_actions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture staff audit failure'\");")
try:
    check('staff audit failure explicit',native('staff',data=form)[0]==503)
    check('staff audit failure rolls back capability',cli('echo user_can('+str(target)+',"moderate_faluss_fans_messages")?"yes":"no";')=='no')
finally: cli('global $wpdb;$wpdb->query("DROP TRIGGER fail_staff_audit");')
debug=(root/'debug.log').read_text() if (root/'debug.log').exists() else ''
check('no PHP fatal during backoffice','PHP Fatal' not in debug)
(root/'backoffice-checks.json').write_text(json.dumps({'passed':len(passed),'checks':passed},indent=2))
print(json.dumps({'passed':len(passed),'checks':passed},indent=2))
