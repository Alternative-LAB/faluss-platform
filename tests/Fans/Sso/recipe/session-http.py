"""Run only against session-wordpress.py. Never prints secrets or authorization URLs."""
import argparse, base64, html, json, pathlib, re, shutil, subprocess, time, urllib.parse, uuid
p=argparse.ArgumentParser(); p.add_argument('--root',required=True); p.add_argument('--cli',required=True); a=p.parse_args()
root=pathlib.Path(a.root); assert root.name.startswith('fans-session-wp-')
fixtures=json.loads((root/'fixtures.json').read_text()); checks=[]
def check(name,condition):
    assert condition,name
    checks.append(name); print('PASS '+name,flush=True)
def cli(site,code):
    script=root/'inspect.php'; script.write_text('<?php\n'+code)
    r=subprocess.run(['php',a.cli,'--allow-root','--path='+str(root/site),'eval-file',str(script)],capture_output=True,text=True)
    assert r.returncode==0,'Local inspection failed'
    return r.stdout.strip()
def request(url,jar,data=None,headers=()):
    host=urllib.parse.urlsplit(url).hostname; assert host in ('faluss.me','fans.local.test')
    key=uuid.uuid4().hex; head=root/(key+'.headers'); body=root/(key+'.body')
    cmd=['curl','--silent','--show-error','--noproxy','*','--resolve',host+':443:127.0.0.1','--cacert',str(root/'cert.pem'),'-b',str(jar),'-c',str(jar),'-D',str(head),'-o',str(body),url]
    if data is not None: cmd+=['--data',urllib.parse.urlencode(data)]
    for value in headers: cmd+=['-H',value]
    result=subprocess.run(cmd,capture_output=True)
    assert result.returncode==0,'Local HTTPS transport failed (details retained privately)'
    result=(head.read_text(),body.read_text()); head.unlink();body.unlink();return result
def location(headers):
    found=re.search(r'^Location: (.+)$',headers,re.M|re.I); assert found,'Expected redirect'; return html.unescape(found[1].strip())
def nonce(page,name):
    found=re.search(r'name="'+name+r'" value="([^"]+)"',page);assert found,'Expected nonce '+name;return html.unescape(found[1])
def jar(name):
    f=fixtures['success']; target=root/(name+'.cookies');target.write_text('# Netscape HTTP Cookie File\nfaluss.me\tFALSE\t/\tTRUE\t0\t'+f['cookie_name']+'\t'+f['cookie']+'\n');target.chmod(0o600);return target
def prepare(target):
    h,page=request('https://fans.local.test/sso-local/',target)
    h,_=request('https://fans.local.test/wp-admin/admin-post.php',target,{'action':'faluss_fans_sso_start','faluss_fans_sso_nonce':nonce(page,'faluss_fans_sso_nonce'),'faluss_fans_return_to':'/app/fan/accueil'})
    h,page=request(location(h),target)
    if 'Location:' not in h:
        h,_=request('https://faluss.me/oauth/authorize',target,{'decision':'approve','faluss_identity_authorization_nonce':nonce(page,'faluss_identity_authorization_nonce')})
    return location(h)
def login(name):
    target=jar(name);callback=prepare(target);h,_=request(callback,target);assert urllib.parse.urlsplit(location(h)).path=='/app/fan/accueil','Clean SSO failed';return target,h
def cookies(target):
    result=[]
    for line in target.read_text().splitlines():
        if line.startswith('#HttpOnly_'):line=line[len('#HttpOnly_'):]
        elif line.startswith('#'):continue
        if not line:continue
        domain,_,path,secure,expires,name,value=line.split('\t');result.append({'domain':domain,'path':path,'secure':secure=='TRUE','expires':int(expires),'name':name,'value':value})
    return result

started=int(time.time()); target,h=login('clean'); logged=next(x for x in cookies(target) if x['domain']=='fans.local.test' and x['name'].startswith('wordpress_logged_in'))
payload=urllib.parse.unquote(logged['value']).split('|'); expiration=int(payload[1])
check('Single clean real Me authorization/token/Fans callback succeeds',True)
check('Persistent secure cookie deadline is exactly signed expiry, eight hours after login',started+28800<=expiration<=int(time.time())+28800 and logged['expires']==expiration and logged['secure'] and 'HttpOnly' in h and 'SameSite=Lax' in h)
user=cli('fans',"$u=get_user_by('login',"+json.dumps(payload[0])+"); echo $u->ID;")
token_exp=int(cli('fans',"$s=WP_Session_Tokens::get_instance("+user+")->get("+json.dumps(payload[2])+");echo $s['expiration'];"))
check('Server token shares the exact cookie deadline',token_exp==expiration)
h,page=request('https://fans.local.test/app/fan/accueil',target)
check('Private page has no-store and exposes logout with nonce','no-store' in h and 'data-fans-logout' in page and nonce(page,'fans_logout_nonce')!='')
h2,_=request('https://fans.local.test/app/fan/accueil',target)
check('Visits never renew the session or emit authentication cookies','Set-Cookie: wordpress_' not in h2 and next(x for x in cookies(target) if x['name']==logged['name'])['expires']==expiration)
rest_nonce=re.search(r'data-session-nonce="([^"]+)"',page)[1]
h,status=request('https://fans.local.test/wp-json/faluss-fans/v1/session',target,headers=['X-WP-Nonce: '+rest_nonce])
check('Session endpoint verifies real WordPress token without exposing identity',json.loads(status)=={'active':True,'expires':expiration} and 'no-store' in h)
endpoint='https://fans.local.test/wp-admin/admin-post.php'
for method,data in [('GET',None),('forged',{'action':'faluss_fans_logout','fans_logout_nonce':'invalid'})]:
    h,_=request(endpoint+('?action=faluss_fans_logout' if data is None else ''),target,data)
    check(method+' logout denied without destroying session',' 403 ' in h.splitlines()[0])
replay=root/'old.cookies';shutil.copyfile(target,replay)
h,_=request(endpoint,target,{'action':'faluss_fans_logout','fans_logout_nonce':nonce(page,'fans_logout_nonce')})
check('Native POST logout redirects to public Explorer with local logout notice',' 303 ' in h.splitlines()[0] and location(h)=='https://fans.local.test/app/fan/explorer?faluss_fans_session=closed')
check('Logout destroys the server token',cli('fans',"echo WP_Session_Tokens::get_instance("+user+")->verify("+json.dumps(payload[2])+")?'valid':'invalid';")=='invalid')
h,page=request('https://fans.local.test/app/fan/accueil',replay)
check('Replayed old authentication cookie cannot read private pages',' 403 ' in h.splitlines()[0] and 'data-fans-logout' not in page)
h,status=request('https://fans.local.test/wp-json/faluss-fans/v1/session',replay,headers=['X-WP-Nonce: '+rest_nonce])
check('Replayed cookie cannot authorize REST after logout',' 403 ' in h.splitlines()[0] or ' 401 ' in h.splitlines()[0])
check('Me central session remains valid after local logout',cli('me',"echo wp_validate_auth_cookie("+json.dumps(fixtures['success']['cookie'])+",'logged_in')?'valid':'invalid';")=='valid')
check('Fans administrator duration remains WordPress default',cli('fans',"echo apply_filters('auth_cookie_expiration',172800,1,false).':'.apply_filters('auth_cookie_expiration',1209600,1,true);")=='172800:1209600')
check('Me member duration remains one hour',cli('me',"$u=get_user_by('email',"+json.dumps(fixtures['success']['email'])+");echo apply_filters('auth_cookie_expiration',172800,$u->ID,false);")=='3600')
admin=json.loads(cli('fans',"wp_set_current_user(1);$t=WP_Session_Tokens::get_instance(1)->create(time()+172800);$l=wp_generate_auth_cookie(1,time()+172800,'logged_in',$t);$_COOKIE[LOGGED_IN_COOKIE]=$l;echo wp_json_encode(['cookies'=>[LOGGED_IN_COOKIE=>$l,SECURE_AUTH_COOKIE=>wp_generate_auth_cookie(1,time()+172800,'secure_auth',$t)],'nonce'=>wp_create_nonce('faluss_fans_logout')]);"))
admin_jar=root/'admin.cookies';admin_jar.write_text('# Netscape HTTP Cookie File\n'+''.join('fans.local.test\tFALSE\t/\tTRUE\t0\t'+k+'\t'+v+'\n' for k,v in admin['cookies'].items()))
h,page=request('https://fans.local.test/sso-local/',admin_jar)
check('Administrator SSO button remains intentionally absent','faluss_fans_sso_nonce' not in page)
h,_=request(endpoint,admin_jar,{'action':'faluss_fans_logout','fans_logout_nonce':admin['nonce']})
check('Fans member logout cannot destroy administrator session even with a valid admin nonce',' 403 ' in h.splitlines()[0])

# Shared browser cookie jar: authorizations obtained serially, callbacks left pending.
# This distinguishes overwritten browser state from concurrent DB linking in separate browsers.
shared=jar('shared-old-first'); first=prepare(shared);second=prepare(shared)
h,_=request(first,shared);h2,_=request(second,shared)
check('Two overlapping flows, older callback first: both denied after browser-state mismatch clears cookie',all('faluss_fans_sso=invalid' in location(x) for x in (h,h2)))
shared=jar('shared-new-first');first=prepare(shared);second=prepare(shared)
h,_=request(second,shared);h2,_=request(first,shared)
check('Two overlapping flows, latest callback first: latest succeeds, older denied',urllib.parse.urlsplit(location(h)).path=='/app/fan/accueil' and 'faluss_fans_sso=invalid' in location(h2))

expired,_=login('expired'); entry=next(x for x in cookies(expired) if x['name'].startswith('wordpress_logged_in') and x['domain']=='fans.local.test');parts=urllib.parse.unquote(entry['value']).split('|')
cli('fans',"$m=WP_Session_Tokens::get_instance("+user+");$s=$m->get("+json.dumps(parts[2])+");$s['expiration']=time()-1;$m->update("+json.dumps(parts[2])+",$s);")
h,page=request('https://fans.local.test/app/fan/accueil',expired)
check('Server expiry denies still-present correctly signed browser cookie',' 403 ' in h.splitlines()[0] and 'data-fans-logout' not in page)

browser,_=login('browser'); native,_=login('browser-native')
def browser_cookies(target):
    return [dict(x,httpOnly=True,sameSite='Lax',expires=x['expires'] or -1) for x in cookies(target)]
(root/'browser.json').write_text(json.dumps({'cookies':browser_cookies(browser),'native':browser_cookies(native)}));(root/'browser.json').chmod(0o600)
(root/'checks.json').write_text(json.dumps({'checks':checks,'limits':['Me initial session seeded; OTP delivery not exercised','No target site or third-party cache accessed','Concurrent failure reproduced locally; not proof of target incident cause']},indent=2))
