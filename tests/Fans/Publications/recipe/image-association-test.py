"""Real HTTP/InnoDB recipe, destructive ONLY inside the named disposable local DB.
Four unprivileged PHP workers, synthetic sessions, no outgoing HTTP or redirects.
"""
import concurrent.futures, json, os, pwd, signal, socket, subprocess, threading, time, uuid
import urllib.request, urllib.error
from pathlib import Path

ROOT = Path('/var/tmp/faluss-text-publications-recipe')
REPO = Path(__file__).resolve().parents[4]
DB = 'faluss_text_publications_recipe'
STORE = Path('/var/tmp/faluss-association-images')
TMP = Path('/var/tmp/faluss-association-upload')
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
try:
    config('FALUSS_PLATFORM_FANS_IMAGES','true',True)
    config('FALUSS_PLATFORM_FANS_TEXT_PUBLICATIONS','true',True)
    config('FALUSS_FANS_IMAGE_PRIVATE_ROOT',str(STORE))
    config('FALUSS_FANS_IMAGE_STORAGE_ATTESTED','true',True)
    before=sql('SELECT COUNT(*),COALESCE(SUM(revision),0) FROM wp_faluss_fans_text_publications')
    subprocess.run(WP+['eval',r'Faluss\Platform\Fans\Publications\TextPublicationsModule::activate();'],check=True)
    check('v2 to v3 preserves existing text rows and revisions',sql('SELECT COUNT(*),COALESCE(SUM(revision),0) FROM wp_faluss_fans_text_publications')==before)
    check('private association schema InnoDB',sql("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='wp_faluss_fans_text_images'")=='InnoDB')
    # All contents of these tables are synthetic from this explicitly disposable DB.
    for suffix in ['text_images','text_requests','text_decisions','text_publications','image_decisions','images']:
        sql('TRUNCATE TABLE wp_faluss_fans_'+suffix)
    subprocess.run(WP+['eval-file',str(REPO/'tests/Fans/Images/recipe/fixture.php')],check=True)
    sessions=json.loads(Path('/var/tmp/faluss-image-proof/sessions.json').read_text())
    png=subprocess.check_output(['php','-r','$i=imagecreatetruecolor(2,2); imagepng($i);'])
    sql("CREATE USER 'www-data'@'localhost' IDENTIFIED VIA unix_socket"); db_created=True
    sql("GRANT ALL ON faluss_text_publications_recipe.* TO 'www-data'@'localhost'")
    config('DB_USER','www-data')
    def demote(): os.setgroups([]); os.setgid(gid); os.setuid(uid)
    server=subprocess.Popen(['php','-d','opcache.enable=0','-d','upload_tmp_dir='+str(TMP),'-S','127.0.0.1:8113','router.php'],cwd=ROOT,
        env={**os.environ,'PHP_CLI_SERVER_WORKERS':'4'},stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True,preexec_fn=demote)
    for _ in range(40):
        try:
            with socket.create_connection(('127.0.0.1',8113),timeout=1): break
        except OSError: time.sleep(.2)
    check('unprivileged HTTP workers',Path('/proc/'+str(server.pid)).stat().st_uid==uid)
    a=image(); b=image(); foreign=image('other'); pending=image(approve=False); row=text()
    for who in ['anon','unlinked','pending','admin','other']:
        check('association permission '+who,associate(row,a,who)[0] in [401,403])
    check('association nonce required',associate(row,a,nonce=False)[0] in [401,403])
    check('image ownership required',associate(row,foreign)[0]==409)
    check('image approval required',associate(row,pending)[0]==409)
    check('image revision required',associate(row,{**a,'revision':1})[0]==409)
    check('unknown image denied',associate(row,{**a,'image_id':str(uuid.uuid4())})[0]==409)
    for payload in [{'revision':1,'image_id':'../x','image_revision':2}, {'revision':1,'image_id':None,'image_revision':2},
                    {'revision':1,'image_id':a['image_id'],'image_revision':2,'creator_id':sessions['owner']['creator_id']}]:
        check('forged association input refused',call('owner','text-publications/'+row['publication_id']+'/image',payload)[0]==400)
    status,row=associate(row,a); check('owned approved image associated pending',status==200 and row['state']=='pending' and private(row)['image_id']==a['image_id'])
    check('association hidden from public before moderation',call('anon','text-publications/'+row['publication_id'])[0]==404)
    status,row=moderate(row); check('text separately approved',status==200)
    public=call('anon','text-publications/'+row['publication_id'])
    allowed={'publication_id','creator_id','revision','body','updated_at'}
    check('public text whitelist excludes all image data',public[0]==200 and set(public[1])==allowed and a['image_id'] not in json.dumps(public[1]))
    check('public list excludes all image data',all(set(r)==allowed for r in call('anon','text-publications')[1]['items']))
    for who in ['anon','owner','other']:
        check('approved text never grants image bytes '+who,call(who,'images/'+a['image_id']+'/bytes')[0] in [401,403])
    status,row=associate(row,b); check('replacement removes public text until review',status==200 and row['state']=='pending' and private(row)['image_id']==b['image_id'] and call('anon','text-publications/'+row['publication_id'])[0]==404)
    results=race(lambda:associate(row,a),lambda:associate(row,None))
    check('concurrent association edits one winner one stale',sorted(r[0] for r in results)==[200,409])
    row=private(row)
    results=race(lambda:associate(row,a),lambda:call('owner','text-publications/'+row['publication_id']+'/edit',{'revision':int(row['revision']),'text':'Texte corrigé.'}))
    check('text edit and association share revision lock',sorted(r[0] for r in results)==[200,409])
    row=private(row)
    results=race(lambda:associate(row,a),lambda:moderate(row))
    check('moderation and association share revision lock',sorted(r[0] for r in results)==[200,409])
    row=private(row); before=sql('SELECT COUNT(*) FROM wp_faluss_fans_text_images'); revision=row['revision']
    sql("CREATE TRIGGER recipe_association_fail BEFORE INSERT ON wp_faluss_fans_text_decisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic failure'")
    try: check('journal failure rolls back association and text',associate(row,a)[0]==503 and private(row)['revision']==revision and sql('SELECT COUNT(*) FROM wp_faluss_fans_text_images')==before)
    finally: sql('DROP TRIGGER recipe_association_fail')
    status,row=associate(row,b); check('retry after rollback succeeds',status==200)
    results=race(lambda:associate(row,a),lambda:call('owner','images/'+a['image_id']+'/withdraw',{'revision':int(a['revision'])}))
    check('image withdrawal races safely with attachment',results[0][0] in [200,409] and results[1][0]==200)
    row=private(row)
    check('withdrawn image is never usable',row['image_id']!=a['image_id'] and associate(row,a)[0]==409 and call('admin','images/'+a['image_id']+'/bytes')[0]==404)
    status,row=associate(row,b); check('valid replacement after revocation',status==200)
    sql("UPDATE wp_faluss_fans_creator_profiles SET status='suspended' WHERE creator_id='"+sessions['owner']['creator_id']+"'")
    check('profile suspension immediately hides reference',private(row)['image_id'] is None and call('admin','images/'+b['image_id']+'/bytes')[0]==404)
    check('suspended owner cannot attach',associate(row,b)[0]==403)
    check('suspended owner can withdraw publication',call('owner','text-publications/'+row['publication_id']+'/withdraw',{'revision':int(row['revision'])})[0]==200)
    sql("UPDATE wp_faluss_fans_creator_profiles SET status='active' WHERE creator_id='"+sessions['owner']['creator_id']+"'")
    check('withdrawn publication reference stays unusable after reactivation',private(row)['image_id'] is None)
    row=text(); status,row=associate(row,b)
    results=race(lambda:associate(row,None),lambda:call('owner','text-publications/'+row['publication_id']+'/withdraw',{'revision':int(row['revision'])}))
    check('publication withdrawal and association share revision',sorted(r[0] for r in results)==[200,409])
    # Close any remaining publication and image fixtures through normal HTTP paths.
    row=private(row)
    if row['state']!='withdrawn': check('withdraw remaining publication',call('owner','text-publications/'+row['publication_id']+'/withdraw',{'revision':int(row['revision'])})[0]==200)
    for img,who in [(b,'owner'),(pending,'owner'),(foreign,'other')]:
        check('withdraw private image fixture',call(who,'images/'+img['image_id']+'/withdraw',{'revision':int(img['revision'])})[0]==200)
    check('private files cleaned',list(STORE.iterdir())==[])
    config('FALUSS_PLATFORM_FANS_IMAGES','false',True)
    check('image rollback closes bytes',call('admin','images/'+b['image_id']+'/bytes')[0]==404)
    config('FALUSS_PLATFORM_FANS_TEXT_PUBLICATIONS','false',True)
    check('text rollback closes association route',associate(row,None)[0]==404)
    print(str(checks)+' real HTTP association controls passed.',flush=True)
finally:
    config('FALUSS_PLATFORM_FANS_IMAGES','false',True)
    config('FALUSS_PLATFORM_FANS_TEXT_PUBLICATIONS','false',True)
    config('DB_USER',old_user); config('FALUSS_FANS_IMAGE_PRIVATE_ROOT',old_root)
    config('FALUSS_FANS_IMAGE_STORAGE_ATTESTED','true' if old_attestation in ['1','true'] else 'false',True)
    if server is not None:
        os.killpg(server.pid,signal.SIGTERM); server.wait(timeout=10)
    if db_created: sql("DROP USER 'www-data'@'localhost'")
    sql('DROP TRIGGER IF EXISTS recipe_association_fail')
    for directory in [STORE,TMP]:
        if not list(directory.iterdir()): directory.rmdir()
    print('Both local flags false; original config restored; HTTP workers stopped.',flush=True)
