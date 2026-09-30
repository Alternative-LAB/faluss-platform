"""Real disposable WordPress/admin-post + private MariaDB; never connects to a site.

Requires PHP, MariaDB binaries, a clean WordPress core directory and wp-cli.phar.
Only fixture booleans/counts are printed. Credentials, cookies and secrets stay private.
"""
import argparse, base64, hashlib, http.cookiejar, json, os, pathlib, pwd, re, secrets, uuid
import shutil, socket, subprocess, tarfile, tempfile, time, urllib.error, urllib.parse, urllib.request
from html.parser import HTMLParser

p = argparse.ArgumentParser()
p.add_argument('--core', required=True)
p.add_argument('--cli', required=True)
p.add_argument('--archive', required=True)
p.add_argument('--vendor', required=True)
p.add_argument('--overlay')
p.add_argument('--expect-bug', action='store_true')
p.add_argument('--keep-web', action='store_true')
a = p.parse_args()
root = pathlib.Path(tempfile.mkdtemp(prefix='sso-admin-wp-', dir='/var/tmp'))
wp = root / 'wordpress'
wp.mkdir()
for item in pathlib.Path(a.core).iterdir():
    if item.name in ('wp-content', 'wp-config.php', 'router.php') or not (item.name.startswith('wp-') or item.name in ('index.php','xmlrpc.php')): continue
    if item.is_dir(): shutil.copytree(item, wp/item.name)
    else: shutil.copy2(item, wp/item.name)
plugin = wp/'wp-content/plugins/faluss-platform'
plugin.mkdir(parents=True)
shutil.copytree(pathlib.Path(a.core)/'wp-content/themes/twentytwentyfive', wp/'wp-content/themes/twentytwentyfive')
with tarfile.open(a.archive) as bundle: bundle.extractall(plugin, filter='data')
if a.overlay:
    source = pathlib.Path(a.overlay)
    names = ['src/Admin/DashboardModule.php', 'src/Identity/Legacy/includes/class-faluss-identity-sso-clients-admin.php']
    for name in names:
        if name.endswith(('.php','.js','.css')): (plugin/name).write_bytes((source/name).read_bytes().replace(b'\r\n', b'\n'))
shutil.copytree(a.vendor, plugin/'vendor')
password = secrets.token_urlsafe(32)
db = web = None
log = open(root/'runtime.log','w')
def run(args, **kw):
    result = subprocess.run(args, capture_output=True, text=True, **kw)
    if result.returncode: raise RuntimeError('Fixture command failed: '+result.stderr[-1800:])
    return result.stdout
def cli(*args): return run(['php', a.cli, '--allow-root', '--path='+str(wp), *args])
def sql(query): return run(['mariadb','--no-defaults','--socket='+str(root/'sql.sock'),'-uroot','--batch','--skip-column-names','sso_recipe','-e',query]).strip()
class Forms(HTMLParser):
    def __init__(self, html):
        super().__init__(); self.forms=[]; self.form=None; self.feed(html)
    def handle_starttag(self, tag, attrs):
        d=dict(attrs)
        if tag=='form': self.form={}; self.forms.append(self.form)
        if tag=='input' and self.form is not None and 'name' in d: self.form[d['name']]=d
    def handle_endtag(self, tag):
        if tag=='form': self.form=None
def form_for(html, action, client=None):
    return next(f for f in Forms(html).forms if f.get('action',{}).get('value')==action and (client is None or f.get('client_id',{}).get('value')==client))
try:
    run(['mariadb-install-db','--no-defaults','--datadir='+str(root/'db'),'--auth-root-authentication-method=normal'])
    db=subprocess.Popen(['mariadbd','--no-defaults','--user='+pwd.getpwuid(os.geteuid()).pw_name,'--datadir='+str(root/'db'),'--socket='+str(root/'sql.sock'),'--pid-file='+str(root/'sql.pid'),'--skip-networking','--innodb-buffer-pool-size=64M'],stdout=log,stderr=log)
    for _ in range(100):
        r=subprocess.run(['mariadb','--no-defaults','--socket='+str(root/'sql.sock'),'-uroot','-e','CREATE DATABASE IF NOT EXISTS sso_recipe'],capture_output=True)
        if r.returncode==0: break
        time.sleep(.1)
    else: raise RuntimeError('Private database unavailable')
    with socket.socket() as probe: probe.bind(('127.0.0.1',0)); port=probe.getsockname()[1]
    base='http://127.0.0.1:'+str(port)
    config="<?php\n"
    values={'DB_NAME':'sso_recipe','DB_USER':'root','DB_PASSWORD':'','DB_HOST':'localhost:'+str(root/'sql.sock'),'DB_CHARSET':'utf8mb4','DB_COLLATE':'','WP_HOME':base,'WP_SITEURL':base,'WP_DEBUG':True,'WP_DEBUG_LOG':str(root/'debug.log'),'WP_DEBUG_DISPLAY':False,'DISABLE_WP_CRON':True,'WP_HTTP_BLOCK_EXTERNAL':True,'FALUSS_PLATFORM_ROLE':'me','FALUSS_PLATFORM_IDENTITY':True}
    for key in ('AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'): values[key]=secrets.token_urlsafe(48)
    for k,v in values.items(): config+='define('+json.dumps(k)+','+json.dumps(v)+');\n'
    config+="$table_prefix='wp_';\nif(!defined('ABSPATH')){define('ABSPATH',__DIR__.'/');}\nrequire_once ABSPATH.'wp-settings.php';\n"
    (wp/'wp-config.php').write_text(config); os.chmod(wp/'wp-config.php',0o600)
    cli('core','install','--url='+base,'--title=Isolated SSO incident recipe','--admin_user=recipe-admin','--admin_password='+password,'--admin_email=admin@example.invalid','--skip-email')
    cli('plugin','activate','faluss-platform')
    cli('theme','activate','twentytwentyfive')
    assert cli('eval',"echo Faluss_Identity_Schema::get_status()['ready']?'ready':'closed';").strip()=='ready'
    mu=wp/'wp-content/mu-plugins';mu.mkdir()
    (mu/'recipe-only.php').write_text("""<?php
// Fault injection lives only inside the disposable fixture, never in a package.
add_action('admin_enqueue_scripts',static function(string $hook):void {
    if(isset($_POST['action']) && str_contains((string)$_POST['action'],'sso_client') && $hook!=='settings_page_faluss-identity-sso-clients') {throw new RuntimeException('unexpected fixture screen');}
    if(($_SERVER['REQUEST_METHOD']??'')==='POST' && file_exists(ABSPATH.'../fail-header')) {throw new RuntimeException('fixture header failure');}
},999);
add_action('admin_footer',static function():void {if(($_SERVER['REQUEST_METHOD']??'')==='POST' && file_exists(ABSPATH.'../fail-footer')) {throw new RuntimeException('fixture footer failure');}});
add_filter('query',static function($query) {
    if(file_exists(ABSPATH.'../fail-write') && preg_match('/^(INSERT INTO|UPDATE) `wp_faluss_identity_clients`/', $query)) {
        $GLOBALS['wpdb']->suppress_errors(true);return 'SELECT * FROM recipe_deliberately_missing_table';
    }
    return $query;
});
add_filter('wp_mail',static function($args){return $args;});
add_filter('pre_wp_mail',static function(){return false;});
""")
    web=subprocess.Popen(['php','-S','127.0.0.1:'+str(port),'-t',str(wp)],cwd=wp,stdout=log,stderr=log)
    time.sleep(.4)
    jar=http.cookiejar.CookieJar(); browser=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    def request(path, data=None, agent=browser):
        req=urllib.request.Request(base+path,data=urllib.parse.urlencode(data,doseq=True).encode() if data is not None else None)
        try: response=agent.open(req, timeout=25)
        except urllib.error.HTTPError as e: response=e
        return response.status, response.read().decode(), dict(response.headers)
    request('/wp-login.php')
    status,html,headers=request('/wp-login.php',{'log':'recipe-admin','pwd':password,'wp-submit':'Log In','redirect_to':base+'/wp-admin/','testcookie':'1'})
    assert status==200 and 'wpadminbar' in html, 'Real WP authentication failed'
    listing='/wp-admin/options-general.php?page=faluss-identity-sso-clients'
    save='faluss_identity_save_sso_client'; rotate='faluss_identity_rotate_sso_client_secret'
    status,html,_=request(listing); assert status==200
    f=form_for(html,save,'')
    payload={'action':save,'_wpnonce':f['_wpnonce']['value'],'client_id':'','client_name':'Fans local incident fixture','status':'active','redirect_uris':'https://fans.faluss.me/faluss-fans/sso/callback','scopes[]':['identity.basic'],'confidential':'1','first_party':'1'}
    status,html,headers=request('/wp-admin/admin-post.php',payload)
    client,hash_before,first=sql("SELECT client_id,client_secret_hash,first_party FROM wp_faluss_identity_clients WHERE client_name='Fans local incident fixture'").split('\t')
    assert first=='0', 'Fans was persisted as official'
    if a.expect_bug:
        assert status==500 and 'Copiez ce secret maintenant' not in html
        _,listing_html,_=request(listing)
        checkbox=form_for(listing_html,save,client)['first_party']
        print('BASELINE creation HTTP 500; row committed; secret hash stored; first_party=0; checkbox checked='+str('checked' in checkbox),flush=True)
        f=form_for(listing_html,rotate,client)
        status,html,_=request('/wp-admin/admin-post.php',{'action':rotate,'_wpnonce':f['_wpnonce']['value'],'client_id':client})
        hash_after=sql("SELECT client_secret_hash FROM wp_faluss_identity_clients WHERE client_id='"+client+"'")
        assert status==500 and hash_after!=hash_before and 'Copiez ce secret maintenant' not in html
        assert 'DashboardModule::enqueueAssets(): Argument #1 ($hook) must be of type string, null given' in (root/'debug.log').read_text()
        print('BASELINE rotation HTTP 500; hash replaced; neither secret displayed; exact reported fatal reproduced',flush=True)
    else:
        assert status==200 and 'Copiez ce secret maintenant' in html, 'Confirmation failed'
        assert 'no-store' in headers.get('Cache-Control','') and headers.get('Referrer-Policy')=='no-referrer'
        assert 'adminmenu' in html and 'settings_page_faluss-identity-sso-clients' in html
        def secret_from(html):
            found=re.findall(r'<code data-faluss-client-secret>([A-Za-z0-9_-]{43})</code>',html)
            assert len(found)==1, 'Secret is not shown exactly once'
            return found[0]
        original=secret_from(html)
        def get_hash(): return sql("SELECT client_secret_hash FROM wp_faluss_identity_clients WHERE client_id='"+client+"'")
        def check_list():
            _,listing_html,_=request(listing)
            assert original not in listing_html and 'data-faluss-client-secret' not in listing_html
            checkbox=form_for(listing_html,save,client)['first_party']
            assert 'checked' not in checkbox and 'disabled' in checkbox, 'Fans checkbox must be unchecked and disabled'
            return listing_html
        listing_html=check_list()
        # Real token endpoint + seeded one-use code: tests secret authentication, not a simulated Fans SSO login.
        def exchange(secret, expect, code=None, verifier=None):
            code=code or secrets.token_urlsafe(32);verifier=verifier or secrets.token_urlsafe(48)
            codehash=hashlib.sha256(code.encode()).hexdigest()
            challenge=base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).decode().rstrip('=')
            sql("INSERT IGNORE INTO wp_faluss_identity_auth_codes (code_hash,faluss_id,client_id,redirect_uri,pkce_challenge,scopes,expires_at,created_at) VALUES ('"+codehash+"','"+str(uuid.uuid4())+"','"+client+"','https://fans.faluss.me/faluss-fans/sso/callback','"+challenge+"','identity.basic',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 60 SECOND),UTC_TIMESTAMP())")
            status,body,_=request('/?faluss_identity_token=1',{'grant_type':'authorization_code','client_id':client,'client_secret':secret,'code':code,'code_verifier':verifier,'redirect_uri':'https://fans.faluss.me/faluss-fans/sso/callback'})
            assert status==expect, 'Unexpected real token endpoint status '+str(status)
            data=json.loads(body)
            assert ('faluss_id' in data) if expect==200 else ('error' in data)
            return code,verifier
        code,verifier=exchange(original,200)
        exchange(original,400,code,verifier)
        f=form_for(listing_html,rotate,client)
        rotate_payload={'action':rotate,'_wpnonce':f['_wpnonce']['value'],'client_id':client}
        status,html,headers=request('/wp-admin/admin-post.php',rotate_payload)
        assert status==200
        current=secret_from(html);assert current!=original and get_hash()!=hash_before
        assert 'no-store' in headers.get('Cache-Control','')
        exchange(original,401);exchange(current,200)
        check_list()
        print('PASS real WP create/rotate 200; one-response secret; real token exchange 200; prior secret 401; code replay 400; Fans first_party=0 and disabled',flush=True)
        stable_hash=get_hash();stable_count=sql('SELECT COUNT(*) FROM wp_faluss_identity_clients')
        for failure in ('fail-header','fail-footer','fail-write'):
            (root/failure).touch()
            for data in (payload,rotate_payload):
                status,html,headers=request('/wp-admin/admin-post.php',data)
                assert status==200 and 'Le client n’a pas pu être enregistré.' in html
                assert 'data-faluss-client-secret' not in html and current not in html
                assert get_hash()==stable_hash and sql('SELECT COUNT(*) FROM wp_faluss_identity_clients')==stable_count
            (root/failure).unlink()
        for data in (dict(payload,_wpnonce='invalid'),dict(rotate_payload,_wpnonce='invalid')):
            status,html,_=request('/wp-admin/admin-post.php',data)
            assert status==403 and 'data-faluss-client-secret' not in html
        status,html,_=request('/wp-admin/admin-post.php',dict(rotate_payload,client_id='does-not-exist'))
        assert status==200 and 'Le client n’a pas pu être enregistré.' in html and 'data-faluss-client-secret' not in html
        guest=urllib.request.build_opener()
        assert request('/wp-admin/admin-post.php',payload,guest)[0]==400
        member_password=secrets.token_urlsafe(32)
        cli('user','create','recipe-member','member@example.invalid','--role=subscriber','--user_pass='+member_password)
        member=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        request('/wp-login.php',agent=member)
        request('/wp-login.php',{'log':'recipe-member','pwd':member_password,'wp-submit':'Log In','redirect_to':base+'/wp-admin/profile.php','testcookie':'1'},member)
        for data in (payload,rotate_payload):
            assert request('/wp-admin/admin-post.php',data,member)[0]==403
        assert get_hash()==stable_hash and sql('SELECT COUNT(*) FROM wp_faluss_identity_clients')==stable_count
        exchange(current,200)
        print('PASS injected header/footer/SQL failures before mutation; invalid nonce, unknown client, guest/subscriber denied; previous valid secret remains usable',flush=True)
        # A legacy/manual anomalous marker must not display or grant the official badge to Fans.
        sql("UPDATE wp_faluss_identity_clients SET first_party=1 WHERE client_id='"+client+"'")
        listing_html=check_list()
        f=form_for(listing_html,save,client)
        status,html,_=request('/wp-admin/admin-post.php',dict(payload,client_id=client,_wpnonce=f['_wpnonce']['value']))
        assert status==200 and sql("SELECT first_party FROM wp_faluss_identity_clients WHERE client_id='"+client+"'")=='0' and get_hash()==stable_hash
        for scopes in (['identity.basic'],['identity.basic','identity.email']):
            f=form_for(request(listing)[1],save,'')
            status,body,_=request('/wp-admin/admin-post.php',dict(payload,client_name='Official fixture '+str(len(scopes)),_wpnonce=f['_wpnonce']['value'],redirect_uris='https://faluss.com/faluss-identity/callback',**{'scopes[]':scopes}))
            assert status==200
        _,listing_html,_=request(listing)
        rows=sql("SELECT client_id FROM wp_faluss_identity_clients WHERE first_party=1").splitlines();assert len(rows)==2
        for official in rows:
            checkbox=form_for(listing_html,save,official)['first_party'];assert 'checked' in checkbox and 'disabled' not in checkbox
        assert current not in listing_html
        for filename in ('runtime.log','debug.log'):
            content=(root/filename).read_text() if (root/filename).exists() else ''
            assert original not in content and current not in content
            assert 'Fatal error' not in content
        print('PASS anomalous Fans marker rendered ineligible and cleared on save without rotating; official exact Faluss.com remains eligible; no secret in logs',flush=True)
    print('WordPress '+cli('core','version').strip()+'; PHP '+run(['php','-r','echo PHP_VERSION;']).strip()+'; '+sql('SELECT VERSION()'),flush=True)
    print('Private fixture: '+str(root),flush=True)
    if a.keep_web:
        (root/'browser-session.json').write_text(json.dumps({'base':base,'cookies':[{'name':c.name,'value':c.value,'domain':c.domain,'path':c.path,'httpOnly':True,'secure':False,'sameSite':'Lax'} for c in jar]}));os.chmod(root/'browser-session.json',0o600)
        print('Local browser fixture ready',flush=True);web.wait()
finally:
    if web is not None: web.terminate();web.wait(timeout=10)
    if db is not None: db.terminate();db.wait(timeout=20)
    log.close()
