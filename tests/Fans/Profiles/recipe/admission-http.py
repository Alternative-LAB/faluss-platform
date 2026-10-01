"""Real WordPress cookies/nonces + private MariaDB. Only loopback HTTP is accepted."""
import argparse, concurrent.futures, json, pathlib, re, subprocess, urllib.request, urllib.error, urllib.parse

p = argparse.ArgumentParser()
p.add_argument('--root', required=True); p.add_argument('--base', required=True); p.add_argument('--cli', required=True)
a = p.parse_args(); root = pathlib.Path(a.root)
assert re.fullmatch(r'/var/tmp/fans-admission-wp-[A-Za-z0-9_]+', str(root))
assert re.fullmatch(r'http://127\.0\.0\.1:[0-9]+', a.base)
session = json.loads((root/'session.json').read_text())
member = session['profiles']['member']; other = session['profiles']['other']
passed = []
def check(name, condition):
    assert condition, name
    passed.append(name)

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs): return None

def http(path, who=None, data=None, rest=False, nonce=True):
    headers = {}
    if who:
        headers['Cookie'] = '; '.join(k+'='+v for k,v in session['sessions'][who]['cookies'].items())
        if rest and nonce: headers['X-WP-Nonce'] = session['sessions'][who]['nonce']
    if data is not None:
        headers['Content-Type'] = 'application/json' if rest else 'application/x-www-form-urlencoded'
        data = json.dumps(data).encode() if rest else urllib.parse.urlencode(data).encode()
    request = urllib.request.Request(a.base+path, data=data, headers=headers)
    try: response = urllib.request.build_opener(NoRedirect()).open(request, timeout=20)
    except urllib.error.HTTPError as error: response = error
    return response.status, response.read().decode(), dict(response.headers)

def api(route, who=None, data=None, nonce=True):
    code, body, headers = http('/wp-json/faluss-fans/v1/'+route.replace('&','?',1), who, data, True, nonce)
    return code, json.loads(body), headers

def cli(code):
    result = subprocess.run(['php', a.cli, '--allow-root', '--path='+str(root/'wordpress'), 'eval', code], capture_output=True, text=True)
    assert result.returncode == 0, 'Local fixture CLI failed'
    return result.stdout.strip()

panel = '/wp-admin/admin.php?page=faluss-fans-moderation&view=profiles'
status_route = 'creators/'+member+'/status'
private_route = 'creators/'+member+'/private'
public_route = 'creators/'+member
page = '/app/creators/'+member
check('legacy pending profile not public REST', api(public_route)[0] == 404)
check('pending page itself is HTTP 404', http(page)[0] == 404)
check('pending profile absent from Explorer API', api('creators')[1] == [])
check('status journal absent before admin upgrade', cli("echo get_option('faluss_fans_creator_status_schema_version','absent');") == 'absent')
for who in [None, 'member', 'other', 'editor', 'unlinked']:
    check('private profile denied '+str(who), api(private_route, who)[0] in (401,403))
    check('private queue denied '+str(who), api('creators/moderation', who)[0] in (401,403))
    check('status mutation denied '+str(who), api(status_route, who, {'status':'active','revision':0})[0] in (401,403))
check('member cannot render moderation panel', http(panel, 'member')[0] == 302)
check('nonadmin does not install journal', cli("echo get_option('faluss_fans_creator_status_schema_version','absent');") == 'absent')
check('admin REST fails closed before journal installation', api(private_route,'admin')[0]==503)
check('status REST does not install journal or mutate on absence', api(status_route,'admin',{'status':'active','revision':0})[0]==503)
check('REST absence preserves schema and pending visibility', cli("echo get_option('faluss_fans_creator_status_schema_version','absent');")=='absent' and api(public_route)[0]==404)
code, body, headers = http(panel, 'admin')
check('existing panel shows new admission tab', code == 200 and 'Profils Créateur' in body and member in body)
check('panel private no-store', 'no-store' in headers.get('Cache-Control',''))
check('isolated opted-in admin upgrade creates journal', cli("echo get_option('faluss_fans_creator_status_schema_version','absent');") == '1')
check('upgrade leaves existing profile pending', api(private_route,'admin')[1]['status'] == 'pending')
check('admin cannot read private profile without nonce', api(private_route,'admin',nonce=False)[0] in (401,403))
check('admin cannot mutate without nonce', api(status_route,'admin',{'status':'active','revision':0},False)[0] in (401,403))
detail = panel+'&item='+member
code, body, headers = http(detail,'admin')
check('detail renders native POST form and nonce', code==200 and 'method="post"' in body and 'name="_wpnonce"' in body)
check('activation warning separates all approvals', 'ne valide ni le partenariat commercial, ni les contenus éditoriaux, ni les images' in body)
nonce_value = re.search(r'name="_wpnonce" value="([^"]+)"', body).group(1)
form = {'kind':'creator-profile','item_id':member,'revision':'0','status':'active','confirm':'yes','_wpnonce':nonce_value}
check('invalid WordPress form nonce rejected', http(detail,'admin',dict(form,_wpnonce='invalid'))[0] == 403)
check('explicit confirmation required server side', http(detail,'admin',{k:v for k,v in form.items() if k!='confirm'})[0] == 400)
check('malformed form revision rejected', http(detail,'admin',dict(form,revision='-1'))[0] == 400)
check('injected form field rejected', http(detail,'admin',dict(form,first_party='true'))[0] == 400)
check('invalid REST status rejected', api(status_route,'admin',{'status':'pending','revision':0})[0] == 400)
check('REST revision required', api(status_route,'admin',{'status':'active'})[0] == 400)
check('string REST revision rejected', api(status_route,'admin',{'status':'active','revision':'0'})[0] == 400)
check('absent creator HTTP 404', api('creators/'+('f'*8+'-ffff-4fff-8fff-'+ 'f'*12)+'/private','admin')[0] == 404)
with concurrent.futures.ThreadPoolExecutor(2) as pool:
    outcomes = list(pool.map(lambda spec: api(status_route,spec[0],{'status':spec[1],'revision':0})[0], [('admin','active'),('admin-two','suspended')]))
check('real concurrent decisions: exactly one 200 and one 409', sorted(outcomes) == [200,409])
code, current, headers = api(private_route,'admin')
check('winning transition journaled once', code==200 and current['status_revision']==1 and len(current['journal'])==1)
check('journal identifies deciding administrator', int(current['journal'][0]['actor_id']) in [session['sessions']['admin']['id'],session['sessions']['admin-two']['id']])
check('journal records before after UTC', current['journal'][0]['previous_status']=='pending' and current['journal'][0]['status']==current['status'] and bool(current['journal'][0]['occurred_at']))
check('private REST no-store', 'no-store' in headers.get('Cache-Control',''))
check('public visibility matches winning decision', api(public_route)[0] == (200 if current['status']=='active' else 404))
check('native admin form stale replay returns conflict', http(detail,'admin',form)[0] == 409)
revision = current['status_revision']
if current['status'] != 'active':
    code,current,_ = api(status_route,'admin',{'status':'active','revision':revision}); revision=current['status_revision']
    check('administrator activates suspended profile', code==200)
check('active page itself HTTP 200', http(page)[0] == 200)
code,public,_ = api(public_route)
check('public has no admission revision or audit or WP user ID', not any(k in public for k in ('status_revision','journal','wp_user_id','actor_id')))
check('activation does not verify identity or invent presentation', code==200 and public['identity_verified'] is False and public['editorial'] is None)
check('active structured profile appears in Explorer API', any(row['creator_id']==member for row in api('creators')[1]))
check('pending other profile remains private', api('creators/'+other)[0] == 404)
before = api(private_route,'admin')[1]
check('same-status no-op succeeds without new revision', api(status_route,'admin',{'status':'active','revision':revision})[1]['status_revision']==revision)
check('same-status creates no spurious audit', len(api(private_route,'admin')[1]['journal'])==len(before['journal']))
# Inject an actual database failure to prove that status cannot commit without its journal.
cli("global $wpdb; $wpdb->query(\"CREATE TRIGGER fail_status_audit BEFORE INSERT ON wp_faluss_fans_creator_status_decisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture audit failure'\");")
check('real audit insert failure returns 503', api(status_route,'admin',{'status':'suspended','revision':revision})[0]==503)
cli("global $wpdb; $wpdb->query('DROP TRIGGER fail_status_audit');")
after = api(private_route,'admin')[1]
check('InnoDB rollback preserves status revision and journal', after==before)
check('failed suspension does not hide approved profile', api(public_route)[0]==200)
cli("global $wpdb; $wpdb->query('ALTER TABLE wp_faluss_fans_creator_status_decisions ENGINE=MyISAM');")
check('nontransactional audit schema refuses decision',api(status_route,'admin',{'status':'suspended','revision':revision})[0]==503)
check('invalid audit engine preserves public profile',api(public_route)[0]==200)
cli("global $wpdb; $wpdb->query('ALTER TABLE wp_faluss_fans_creator_status_decisions ENGINE=InnoDB');")
check('restored transactional journal has no fabricated transition',api(private_route,'admin')[1]==before)
# Pending editorial content must not be approved by an admission decision.
cli("global $wpdb; $wpdb->insert('wp_faluss_fans_editorial',['creator_id'=>'"+member+"','revision'=>1,'state'=>'pending','public_name'=>'Fixture private pending name','bio'=>'PRIVATE_UNAPPROVED_BIO','portrait_id'=>'','portrait_revision'=>0,'updated_at'=>gmdate('Y-m-d H:i:s')]);")
check('pending name bio not leaked on public profile', api(public_route)[1]['editorial'] is None and 'PRIVATE_UNAPPROVED_BIO' not in http(page)[1])
code,current,_ = api(status_route,'admin',{'status':'suspended','revision':revision}); revision=current['status_revision']
check('suspension committed', code==200)
check('suspended REST and actual page are 404', api(public_route)[0]==404 and http(page)[0]==404)
check('suspended profile removed from Explorer', not any(row['creator_id']==member for row in api('creators')[1]))
check('suspension does not approve or purge unrelated pending editorial', cli("global $wpdb; echo $wpdb->get_var(\"SELECT state FROM wp_faluss_fans_editorial WHERE creator_id='"+member+"'\");")=='pending')
code,current,_ = api(status_route,'admin',{'status':'active','revision':revision}); revision=current['status_revision']
check('reactivation keeps editorial moderation pending', code==200 and api(public_route)[1]['editorial'] is None)
check('ABA stale decision cannot win', api(status_route,'admin',{'status':'suspended','revision':1})[0]==409)
# Exercise a successful native form action using the second creator's actual revision zero.
code,body,_ = http(panel+'&item='+other,'admin')
nonce_value = re.search(r'name="_wpnonce" value="([^"]+)"',body).group(1)
check('native form activates profile', http(panel+'&item='+other,'admin',dict(form,item_id=other,_wpnonce=nonce_value))[0]==200)
check('native form decision journal visible on reload', 'En attente → Actif' in http(panel+'&item='+other,'admin')[1])
check('native form repeated POST is rejected', http(panel+'&item='+other,'admin',dict(form,item_id=other,_wpnonce=nonce_value))[0]==409)
check('invalid queue filter rejected', api('creators/moderation&status=unknown','admin')[0]==400)
check('invalid queue cursor rejected', api('creators/moderation&cursor=garbage','admin')[0]==400)
check('active queue works', len(api('creators/moderation&status=active','admin')[1]['items'])==2)
# Approved editorial content remains independently approved across admission suspension.
check('separate editorial approval succeeds', api('editorial/'+member+'/moderate','admin',{'revision':1,'decision':'approve','reason':'allowed_editorial'})[0]==200)
check('approved editorial is public only while profile active', api(public_route)[1]['editorial']['public_name']=='Fixture private pending name')
code,current,_=api(status_route,'admin',{'status':'suspended','revision':revision}); revision=current['status_revision']
check('suspension also hides separately approved editorial', code==200 and api(public_route)[0]==404 and http(page)[0]==404)
check('admission suspension does not alter editorial approval', cli("global $wpdb; echo $wpdb->get_var(\"SELECT state FROM wp_faluss_fans_editorial WHERE creator_id='"+member+"'\");")=='approved')
code,current,_=api(status_route,'admin',{'status':'active','revision':revision})
check('reactivation restores only previously approved editorial', code==200 and api(public_route)[1]['editorial']['public_name']=='Fixture private pending name')
# Create additional actual linked local member requests for pagination (no product fixture data).
cli("for($i=0;$i<21;$i++){ $id=wp_insert_user(['user_login'=>'page_'.$i,'user_pass'=>wp_generate_password(40),'role'=>'subscriber']); global $wpdb; $wpdb->insert('wp_faluss_fans_identity_links',['wp_user_id'=>$id,'faluss_id'=>wp_generate_uuid4(),'created_at'=>gmdate('Y-m-d H:i:s'),'last_proved_at'=>gmdate('Y-m-d H:i:s')]); wp_set_current_user($id); $r=\\Faluss\\Platform\\Fans\\Profiles\\CreatorProfileService::create('arts'); if(is_wp_error($r)){throw new RuntimeException('Pagination fixture failed');} }")
first=api('creators/moderation','admin')[1]
check('queue bounded to 20 with cursor',len(first['items'])==20 and isinstance(first['next_cursor'],str))
second=api('creators/moderation&cursor='+first['next_cursor'],'admin')[1]
check('pagination no duplicate or omitted pending profile',len(second['items'])==2 and second['next_cursor'] is None and len({x['creator_id'] for x in first['items']+second['items']})==22)
debug=(root/'debug.log').read_text() if (root/'debug.log').exists() else ''
check('no PHP fatal error during recipe', 'Fatal error' not in debug and 'Uncaught' not in debug)
(root/'checks.json').write_text(json.dumps({'passed':len(passed),'checks':passed},ensure_ascii=False,indent=2))
print('PASS '+str(len(passed))+' real WordPress / MariaDB HTTP checks')
