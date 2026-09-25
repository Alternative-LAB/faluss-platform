import subprocess,json,re,html,urllib.parse,concurrent.futures,shutil
from pathlib import Path
root=Path('/var/tmp/faluss-fans-http')
fixtures=json.loads((root/'fixtures.json').read_text())
def request(url,jar,data=None,extra_headers=None):
    host=urllib.parse.urlsplit(url).hostname
    assert host in ['faluss.me','fans.local.test']
    token=__import__('uuid').uuid4().hex
    head=root/(token+'.headers'); body=root/(token+'.body')
    cmd=['curl','--silent','--show-error','--noproxy','*','--resolve',host+':443:127.0.0.1','--cacert',str(root/'cert.pem'),'-b',str(jar),'-c',str(jar),'-D',str(head),'-o',str(body),url]
    if data is not None: cmd += ['--data',urllib.parse.urlencode(data)]
    for header in extra_headers or []: cmd += ['-H',header]
    subprocess.run(cmd,check=True)
    headers=head.read_text(); content=body.read_text(); head.unlink(); body.unlink()
    return headers,content
def location(headers):
    match=re.search(r'^Location: (.+)$',headers,re.M|re.I)
    assert match, headers[:120]
    return match[1].strip()
def prepare(name,suffix,linked=False):
    f=fixtures[name]; jar=root/(suffix+'.cookies')
    jar.write_text('# Netscape HTTP Cookie File\nfaluss.me\tFALSE\t/\tTRUE\t0\t'+f['cookie_name']+'\t'+f['cookie']+'\n')
    if linked:
        with jar.open('a') as stream: stream.write('fans.local.test\tFALSE\t/\tTRUE\t0\t'+f['local_cookie_name']+'\t'+f['local_cookie']+'\n')
    headers,page=request('https://fans.local.test/sso-local/',jar)
    if 'Location:' in headers: headers,page=request(location(headers),jar)
    nonce=re.search(r'name="faluss_fans_sso_nonce" value="([^"]+)"',page)
    assert nonce, 'Fans button missing: '+headers[:80]
    headers,_=request('https://fans.local.test/wp-admin/admin-post.php',jar,{'action':'faluss_fans_sso_start','faluss_fans_sso_nonce':nonce[1]})
    assert all(x in headers.lower() for x in ['secure','httponly','samesite=lax'])
    auth=location(headers)
    headers,page=request(auth,jar)
    nonce=re.search(r'name="faluss_identity_authorization_nonce" value="([^"]+)"',page)
    assert nonce,'Me consent missing: '+headers[:120]
    headers,_=request('https://faluss.me/oauth/authorize',jar,{'decision':'approve','faluss_identity_authorization_nonce':nonce[1]})
    return location(headers),jar
def check(name,condition):
    assert condition,name
    print('PASS '+name,flush=True)
callback,jar=prepare('success','success')
backup=root/'replay.cookies'; shutil.copyfile(jar,backup)
empty=root/'empty.cookies'; empty.write_text('# Netscape HTTP Cookie File\n')
rejected,_=request(callback,empty)
check('callback without browser cookie denied','faluss_fans_sso=invalid' in location(rejected))
cookie_row=next(line for line in backup.read_text().splitlines() if '\tfaluss_fans_sso_state\t' in line)
import base64
raw=base64.urlsafe_b64decode(cookie_row.split('\t')[-1])
verifier=base64.urlsafe_b64encode(raw[32:64]).decode().rstrip('=')
headers,_=request(callback,jar)
check('real Me authorize/token and Fans callback',location(headers)=='https://fans.local.test/')
check('Fans logged-in cookie issued', 'wordpress_logged_in_' in headers)
headers,_=request(callback,backup)
check('callback replay denied', 'faluss_fans_sso=invalid' in location(headers) and 'wordpress_logged_in_' not in headers)
code=urllib.parse.parse_qs(urllib.parse.urlsplit(callback).query)['code'][0]
headers,body=request('https://faluss.me/oauth/token',empty,{'grant_type':'authorization_code','client_id':'fans-local-recipe','client_secret':(root/'secret').read_text(),'redirect_uri':'https://fans.local.test/faluss-fans/sso/callback','code':code,'code_verifier':verifier})
check('Me authorization code replay denied',' 400 ' in headers.splitlines()[0] and json.loads(body)['error']=='invalid_grant')
(root/'success-result.json').write_text(json.dumps({'http_sso':True,'secure_cookie':True,'replay_denied':True}))
def sql(query):
    return subprocess.check_output(['mariadb','--skip-column-names','faluss_fans_recipe','-e',query],text=True).strip()
def failed(callback,jar):
    h,_=request(callback,jar)
    return 'faluss_fans_sso=invalid' in location(h) and 'wordpress_logged_in_' not in h
callback,jar=prepare('collision','collision')
check('email collision denies implicit linking',failed(callback,jar))
check('collision preserves existing account',sql("SELECT COUNT(*) FROM wp_users WHERE ID="+str(fixtures['collision']['local_id']))=='1')
sql("CREATE TRIGGER fans_http_fail BEFORE INSERT ON wp_faluss_fans_identity_links FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Local injected link failure'")
try:
    callback,jar=prepare('failure','failure')
    check('link insert failure denies callback',failed(callback,jar))
    check('no orphan after HTTP callback failure',sql("SELECT COUNT(*) FROM wp_users WHERE user_email='"+fixtures['failure']['email']+"'")=='0')
    callback,jar=prepare('link','link',True)
    check('explicit link failure denied',failed(callback,jar))
    check('preexisting explicit-link account preserved',sql('SELECT COUNT(*) FROM wp_users WHERE ID='+str(fixtures['link']['local_id']))=='1')
finally:
    sql('DROP TRIGGER fans_http_fail')
callback,jar=prepare('failure','retry')
h,_=request(callback,jar)
check('new authorization succeeds after rollback',location(h)=='https://fans.local.test/')
callbacks=[prepare('concurrent','parallel'+str(i)) for i in range(2)]
with concurrent.futures.ThreadPoolExecutor(2) as pool:
    responses=list(pool.map(lambda args:request(*args),callbacks))
check('concurrent callbacks succeed for one subject',all(location(h)=='https://fans.local.test/' for h,_ in responses))
check('concurrent subject has exactly one local account',sql("SELECT COUNT(*) FROM wp_users WHERE user_email='"+fixtures['concurrent']['email']+"'")=='1')
check('concurrent subject has exactly one link',sql("SELECT COUNT(*) FROM wp_faluss_fans_identity_links WHERE faluss_id='"+fixtures['concurrent']['subject']+"'")=='1')
callback,jar=prepare('success','expired')
subprocess.run(['mariadb','faluss_fans_me_recipe','-e',"UPDATE wp_faluss_identity_auth_codes SET expires_at='2000-01-01 00:00:00' WHERE consumed_at IS NULL"],check=True)
check('expired Me code denied over HTTP',failed(callback,jar))
check('no Link tables despite erroneous flag',sql("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE 'wp_faluss_link%'")=='0')
