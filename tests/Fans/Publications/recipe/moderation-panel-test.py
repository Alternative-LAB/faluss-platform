"""Moderation panel HTTP/InnoDB recipe, destructive ONLY inside the named disposable local DB.
Four unprivileged PHP workers, synthetic sessions, no outgoing HTTP or redirects.
"""
import concurrent.futures, json, os, pwd, signal, socket, subprocess, threading, time, uuid
import urllib.request, urllib.error, urllib.parse
from html.parser import HTMLParser
from pathlib import Path

ROOT = Path('/var/tmp/faluss-text-publications-recipe')
REPO = Path(__file__).resolve().parents[4]
DB = 'faluss_text_publications_recipe'
STORE = Path('/var/tmp/faluss-panel-images')
TMP = Path('/var/tmp/faluss-panel-upload')
WP = ['php', '/var/tmp/faluss-v3-wp/wp-cli.phar', '--allow-root', '--path='+str(ROOT)]
assert os.geteuid() == 0
assert subprocess.check_output(WP+['config','get','DB_NAME'], text=True).strip() == DB
assert (ROOT/'wp-content/plugins/faluss-platform').resolve() == REPO
with socket.socket() as probe:
    assert probe.connect_ex(('127.0.0.1',8113)) != 0, 'Local port already in use'
assert (ROOT/'wp-content/mu-plugins/offline.php').is_file()

def sql(statement):
    return subprocess.check_output(['mariadb','-N',DB,'-e',statement],text=True).strip()

def config(name,value,raw=False):
    subprocess.run(WP+['config','set',name,value,'--quiet']+(['--raw'] if raw else []),check=True)

checks = 0
def check(label,condition):
    global checks
    if not condition: raise AssertionError(label)
    checks += 1
    print('PASS '+label,flush=True)

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl): return None
urllib.request.install_opener(urllib.request.build_opener(NoRedirect()))

def call(who,route,data=None,upload=None,nonce=True):
    headers={'Host':'text.local.test'}
    if who in sessions:
        a=sessions[who]; headers['Cookie']=a['cookie_name']+'='+a['cookie']
        if nonce: headers['X-WP-Nonce']=a['nonce']
    body=None
    if data is not None:
        body=json.dumps(data).encode(); headers['Content-Type']='application/json'
    if upload is not None:
        body=b'--association\r\nContent-Disposition: form-data; name="image"; filename="fixture.png"\r\nContent-Type: image/png\r\n\r\n'+upload+b'\r\n--association--\r\n'
        headers['Content-Type']='multipart/form-data; boundary=association'
    if route=='text-publications' and data is not None: headers['Idempotency-Key']=str(uuid.uuid4())
    request=urllib.request.Request('http://127.0.0.1:8113/wp-json/faluss-fans/v1/'+route,body,headers)
    try: response=urllib.request.urlopen(request,timeout=40)
    except urllib.error.HTTPError as e: response=e
    raw=response.read()
    return response.status, raw if response.headers.get_content_type()=='image/png' else json.loads(raw)

def text(who='owner'):
    status,row=call(who,'text-publications',{'text':'Texte synthétique autorisé.','category':'hosted_allowed_content'})
    check('create pending text',status==201)
    return row

def image(who='owner',approve=True):
    status,row=call(who,'images',upload=png)
    check('submit private image',status==201)
    if approve:
        status,row=call('admin','images/'+row['image_id']+'/moderate',{'revision':1,'decision':'approve','reason':'allowed_image'})
        check('approve image without distribution',status==200)
    return row

def associate(row,img,who='owner',nonce=True):
    return call(who,'text-publications/'+row['publication_id']+'/image',{'revision':int(row['revision']),
        'image_id':img['image_id'] if img else None,'image_revision':int(img['revision']) if img else None},nonce=nonce)

def private(row): return call('owner','text-publications/'+row['publication_id']+'/private')[1]
def moderate(row): return call('admin','text-publications/'+row['publication_id']+'/moderate',{'revision':int(row['revision']),'decision':'approve','reason':'allowed_text'})
def race(*operations):
    barrier=threading.Barrier(len(operations))
    def run(operation): barrier.wait(); return operation()
    with concurrent.futures.ThreadPoolExecutor(max_workers=len(operations)) as pool:
        return list(pool.map(run,operations))

old_root=subprocess.check_output(WP+['config','get','FALUSS_FANS_IMAGE_PRIVATE_ROOT'],text=True).strip()
old_user=subprocess.check_output(WP+['config','get','DB_USER'],text=True).strip()
old_attestation=subprocess.check_output(WP+['config','get','FALUSS_FANS_IMAGE_STORAGE_ATTESTED'],text=True).strip()
uid=pwd.getpwnam('www-data').pw_uid; gid=pwd.getpwnam('www-data').pw_gid
server=None; db_created=False; sessions={}
assert sql("SELECT COUNT(*) FROM mysql.user WHERE User='www-data' AND Host='localhost'")=='0'
for directory in [STORE,TMP]:
    assert not directory.exists(), 'Fresh recipe directories required'
    directory.mkdir(mode=0o700); os.chown(directory,uid,gid)
router = ROOT/'panel-router.php'
assert not router.exists()
router.write_text("""<?php
$_SERVER['HTTPS']='on'; $_SERVER['SERVER_PORT']='443'; $_SERVER['HTTP_HOST']='text.local.test';
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$file=realpath(__DIR__ . $path);
if ($file && (str_starts_with($file,__DIR__ . '/') || str_starts_with($file,realpath(__DIR__ . '/wp-content/plugins/faluss-platform/assets') . '/')) && is_file($file)) {
    if (pathinfo($file,PATHINFO_EXTENSION)==='php') { require $file; return; }
    return false;
}
require __DIR__ . '/index.php';
""")
try:
    config('FALUSS_PLATFORM_FANS_IMAGE_DELIVERY','false',True)
    config('FALUSS_PLATFORM_FANS_IMAGES','true',True)
    config('FALUSS_PLATFORM_FANS_TEXT_PUBLICATIONS','true',True)
    config('FALUSS_FANS_IMAGE_PRIVATE_ROOT',str(STORE))
    config('FALUSS_FANS_IMAGE_STORAGE_ATTESTED','true',True)
    check('text v3 already installed', sql("SELECT option_value FROM wp_options WHERE option_name='faluss_fans_text_publications_schema_version'")=='3')
    # All contents of these tables are synthetic from this explicitly disposable DB.
    for suffix in ['text_images','text_requests','text_decisions','text_publications','image_decisions','images']:
        sql('TRUNCATE TABLE wp_faluss_fans_'+suffix)
    subprocess.run(WP+['eval-file',str(REPO/'tests/Fans/Publications/recipe/panel-fixture.php')],check=True)
    sessions=json.loads(Path('/var/tmp/faluss-image-proof/sessions.json').read_text())
    png=subprocess.check_output(['php','-r','$i=imagecreatetruecolor(1800,900); imagefill($i,0,0,imagecolorallocate($i,20,160,60)); imagepng($i);'])
    sql("CREATE USER 'www-data'@'localhost' IDENTIFIED VIA unix_socket"); db_created=True
    sql("GRANT ALL ON faluss_text_publications_recipe.* TO 'www-data'@'localhost'")
    config('DB_USER','www-data')
    def demote(): os.setgroups([]); os.setgid(gid); os.setuid(uid)
    server=subprocess.Popen(['php','-d','opcache.enable=0','-d','upload_tmp_dir='+str(TMP),'-S','127.0.0.1:8113','panel-router.php'],cwd=ROOT,
        env={**os.environ,'PHP_CLI_SERVER_WORKERS':'4'},stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True,preexec_fn=demote)
    for _ in range(40):
        try:
            with socket.create_connection(('127.0.0.1',8113),timeout=1): break
        except OSError: time.sleep(.2)
    check('unprivileged HTTP workers',Path('/proc/'+str(server.pid)).stat().st_uid==uid)
    class Forms(HTMLParser):
        def __init__(self, html):
            super().__init__(); self.forms=[]; self.current=None; self.feed(html)
        def handle_starttag(self,tag,attrs):
            a=dict(attrs)
            if tag=='form': self.current={'action':a.get('action',''),'fields':{}}
            if tag=='input' and self.current is not None and 'name' in a:
                self.current['fields'][a['name']]=a.get('value','')
        def handle_endtag(self,tag):
            if tag=='form' and self.current is not None:
                self.forms.append(self.current); self.current=None
    def panel(who='admin',view='texts',data=None,path=None):
        url='http://127.0.0.1:8113'+(path or '/wp-admin/admin.php?page=faluss-fans-moderation&view='+view)
        headers={'Host':'text.local.test'}
        if who in sessions:
            a=sessions[who]; headers['Cookie']=a['cookie_name']+'='+a['cookie']+'; '+a['admin_cookie_name']+'='+a['admin_cookie']
        body=None
        if data is not None: body=urllib.parse.urlencode(data).encode(); headers['Content-Type']='application/x-www-form-urlencoded'
        req=urllib.request.Request(url,body,headers)
        try: res=urllib.request.urlopen(req,timeout=40)
        except urllib.error.HTTPError as e: res=e
        return res.status,res.read(),res.headers
    def forms(result): return Forms(result[1].decode()).forms
    def all_forms(view='texts'):
        result=panel(view=view)
        while True:
            yield from forms(result)
            links=re.findall(r'href="([^"]+)"',result[1].decode())
            following=next((html.unescape(x) for x in links if 'cursor=' in x),None)
            if not following: break
            parsed=urllib.parse.urlsplit(following)
            result=panel(path=parsed.path+'?'+parsed.query)
    def decision(row,reason,kind='text-publications'):
        f=next(f for f in all_forms('images' if kind=='images' else 'texts') if f['fields'].get('item_id')==row['image_id' if kind=='images' else 'publication_id'])
        f['fields']['reason']=reason
        return f['fields']
    def preview_form(row,kind='texts'):
        return next(f['fields'] for f in all_forms(kind) if f['fields'].get('image_id')==row['image_id'])
    def preview(data,who='admin'): return panel(who,data=data,path='/wp-admin/admin-post.php')
    def success(res): return res[0]==200 and 'Décision confirmée par le serveur'.encode() in res[1]
    def private_headers(res): return 'private, no-store' in res[2].get('Cache-Control','') and res[2].get('CDN-Cache-Control')=='no-store' and res[2].get('Surrogate-Control')=='no-store'

    a=image(approve=False); b=image('other',approve=False)
    rows=[text('owner' if i<15 else 'other') for i in range(26)]
    result=panel(); check('admin panel private no-store',result[0]==200 and private_headers(result))
    first=[f['fields']['item_id'] for f in forms(result) if 'item_id' in f['fields']]
    import re, html
    link=next(html.unescape(x) for x in re.findall(r'href="([^"]+)"',result[1].decode()) if 'cursor=' in x)
    parsed=urllib.parse.urlsplit(link)
    second=[f['fields']['item_id'] for f in forms(panel(path=parsed.path+'?'+parsed.query)) if 'item_id' in f['fields']]
    check('26 texts in successive pages no duplicate or omission',len(first)==20 and len(second)==6 and set(first+second)==set(x['publication_id'] for x in rows))
    check('creator denied panel',panel('owner')[0]==403)
    check('anonymous denied panel',panel('anonymous')[0] in [302,403])
    forged=decision(rows[0],'allowed_text'); forged['_wpnonce']='invalid'
    check('invalid nonce refuses mutation',panel(data=forged)[0]==403)
    good=decision(rows[0],'allowed_text')
    check('member with copied admin nonce denied',panel('owner',data=good)[0]==403)
    check('text remains pending after denied attempts',private(rows[0])['state']=='pending')
    img_form=preview_form(a,'images')
    check('private preview real PNG and no shared cache',preview(img_form)[1].startswith(b'\x89PNG') and private_headers(preview(img_form)))
    check('GET preview never serves PNG',not panel(path='/wp-admin/admin-post.php?action=faluss_fans_preview')[1].startswith(b'\x89PNG'))
    check('creator cannot preview copied form',preview(img_form,'owner')[0]==403)
    bad=img_form.copy(); bad['_wpnonce']='invalid'
    check('preview invalid nonce refused',preview(bad)[0]==403)
    approve_image=decision(a,'allowed_image','images')
    results=race(lambda:panel(view='images',data=approve_image),lambda:panel(view='images',data=approve_image))
    check('two concurrent image decisions exactly one success one 409',sorted(r[0] for r in results)==[200,409] and sum(success(r) for r in results)==1)
    a['revision']=2
    check('single image approval audit',len([x for x in call('admin','images/'+a['image_id']+'/decisions')[1] if x['action']=='approve'])==1)
    status,row=associate(rows[0],a); check('associate approved private image',status==200)
    preview_associated=preview_form(a)
    check('associated image examined through authenticated POST',preview(preview_associated)[0]==200)
    stale=decision(row,'allowed_text')
    status,edited=call('owner','text-publications/'+row['publication_id']+'/edit',{'revision':int(row['revision']),'text':'Révision concurrente autorisée.'})
    check('stale text decision shows conflict without success',panel(data=stale)[0]==409 and not success(panel(data=stale)))
    check('stale association preview refuses old text revision',preview(preview_associated)[0]==409)
    current=decision(edited,'allowed_text')
    results=race(lambda:panel(data=current),lambda:panel(data=current))
    check('two concurrent text decisions exactly one journalized success',sorted(r[0] for r in results)==[200,409] and sum(success(r) for r in results)==1)
    check('single text approval audit',len([x for x in call('admin','text-publications/'+row['publication_id']+'/decisions')[1] if x['action']=='approve'])==1)
    detail='/wp-admin/admin.php?page=faluss-fans-moderation&item='+row['publication_id']
    check('approved text and its journal remain reachable',panel(path=detail)[0]==200 and b'allowed_text' in panel(path=detail)[1])
    check('private detail denied to creator',panel('owner',path=detail)[0]==403)
    rejection=decision(rows[1],'prohibited_content')
    check('reject text confirmed',success(panel(data=rejection)))
    check('rejection purges current text with trace',private(rows[1])['body']=='' and call('admin','text-publications/'+rows[1]['publication_id']+'/decisions')[1][0]['reason']=='prohibited_content')
    withdrawn=decision(rows[2],'allowed_text')
    check('creator withdraw',call('owner','text-publications/'+rows[2]['publication_id']+'/withdraw',{'revision':1})[0]==200)
    check('withdrawn text cannot be approved from stale page',panel(data=withdrawn)[0]==409)
    suspended=decision(rows[3],'allowed_text')
    profile=sessions['owner']['creator_id']
    check('suspend creator through API',call('admin','creators/'+profile+'/status',{'status':'suspended'})[0]==200)
    check('suspended creator approval blocked',panel(data=suspended)[0]==403)
    check('suspended image preview blocked',preview(img_form)[0]==404)
    check('suspended panel context has no approval choice for creator', 'Profil non public'.encode() in panel()[1])
    check('suspended text rejection remains possible',success(panel(data=decision(rows[3],'needs_revision'))))
    check('reactivate fixture creator',call('admin','creators/'+profile+'/status',{'status':'active'})[0]==200)
    check('image rejection through panel',success(panel(view='images',data=decision(b,'needs_revision','images'))))
    check('rejected image bytes unavailable',preview(preview_form(a,'images'))[0]==200 and call('admin','images/'+b['image_id']+'/bytes')[0]==404)
    # Keep only synthetic UI screenshots; credentials stay in the private local fixture file.
    if os.environ.get('FANS_PANEL_VISUAL')=='1':
        script=subprocess.check_output(['wslpath','-w',str(REPO/'tests/Fans/Publications/recipe/panel-browser.cjs')],text=True).strip()
        subprocess.run([os.environ['FANS_PANEL_NODE'],script,os.environ['FANS_PANEL_MODULES']],check=True)
    check('withdraw approved image',call('owner','images/'+a['image_id']+'/withdraw',{'revision':2})[0]==200)
    check('withdrawn preview closed',preview(img_form)[0]==404)
    check('quarantine files cleaned',list(STORE.iterdir())==[])
    config('FALUSS_PLATFORM_FANS_TEXT_PUBLICATIONS','false',True)
    check('panel disappears after local flag rollback',panel()[0]==403)
    print(str(checks)+' real HTTP moderation panel controls passed.',flush=True)
finally:
    if server is not None and sessions:
        for line in sql("SELECT image_id,revision FROM wp_faluss_fans_images WHERE state IN ('pending','approved')").splitlines():
            item_id,rev=line.split('\t')
            call('admin','images/'+item_id+'/moderate',{'revision':int(rev),'decision':'reject','reason':'needs_revision'})
    config('FALUSS_PLATFORM_FANS_IMAGE_DELIVERY','false',True)
    config('FALUSS_PLATFORM_FANS_IMAGES','false',True)
    config('FALUSS_PLATFORM_FANS_TEXT_PUBLICATIONS','false',True)
    config('DB_USER',old_user); config('FALUSS_FANS_IMAGE_PRIVATE_ROOT',old_root)
    config('FALUSS_FANS_IMAGE_STORAGE_ATTESTED','true' if old_attestation in ['1','true'] else 'false',True)
    if server is not None:
        os.killpg(server.pid,signal.SIGTERM); server.wait(timeout=10)
    if db_created: sql("DROP USER 'www-data'@'localhost'")
    subprocess.run(WP+['eval',"foreach (get_users(['search'=>'recipe_*','search_columns'=>['user_login']]) as $user) { WP_Session_Tokens::get_instance($user->ID)->destroy_all(); }"],check=True)
    for directory in [STORE,TMP]:
        if not list(directory.iterdir()): directory.rmdir()
    router.unlink(missing_ok=True)
    print('Three local flags false; original config restored; HTTP workers stopped.',flush=True)
