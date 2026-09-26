"""Image delivery HTTP/InnoDB recipe, destructive ONLY inside the named disposable local DB.
Four unprivileged PHP workers, synthetic sessions, no outgoing HTTP or redirects.
"""
import concurrent.futures, json, os, pwd, signal, socket, subprocess, threading, time, uuid
import urllib.request, urllib.error
from pathlib import Path

ROOT = Path('/var/tmp/faluss-text-publications-recipe')
REPO = Path(__file__).resolve().parents[4]
DB = 'faluss_text_publications_recipe'
STORE = Path('/var/tmp/faluss-display-images')
TMP = Path('/var/tmp/faluss-display-upload')
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
    config('FALUSS_PLATFORM_FANS_IMAGE_DELIVERY','false',True)
    config('FALUSS_PLATFORM_FANS_IMAGES','true',True)
    config('FALUSS_PLATFORM_FANS_TEXT_PUBLICATIONS','true',True)
    config('FALUSS_FANS_IMAGE_PRIVATE_ROOT',str(STORE))
    config('FALUSS_FANS_IMAGE_STORAGE_ATTESTED','true',True)
    check('text v3 already installed', sql("SELECT option_value FROM wp_options WHERE option_name='faluss_fans_text_publications_schema_version'")=='3')
    # All contents of these tables are synthetic from this explicitly disposable DB.
    for suffix in ['text_images','text_requests','text_decisions','text_publications','image_decisions','images']:
        sql('TRUNCATE TABLE wp_faluss_fans_'+suffix)
    subprocess.run(WP+['eval-file',str(REPO/'tests/Fans/Images/recipe/fixture.php')],check=True)
    sessions=json.loads(Path('/var/tmp/faluss-image-proof/sessions.json').read_text())
    png=subprocess.check_output(['php','-r','$i=imagecreatetruecolor(1800,900); imagefill($i,0,0,imagecolorallocate($i,20,160,60)); imagepng($i);'])
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
    def display(row,extra=None,method='GET',suffix=''):
        url='http://127.0.0.1:8113/wp-json/faluss-fans/v1/text-publications/'+row['publication_id']+'/image/'+str(row['revision'])+suffix
        req=urllib.request.Request(url,headers={'Host':'text.local.test',**(extra or {})},method=method)
        try: response=urllib.request.urlopen(req,timeout=40)
        except urllib.error.HTTPError as e: response=e
        return response.status,response.read(),response.headers

    def no_cache(result):
        return all(result[2].get(h)=='no-store' for h in ['CDN-Cache-Control','Surrogate-Control']) and 'private, no-store' in result[2].get('Cache-Control','')

    def publish(row):
        status,row=moderate(row); check('approve text',status==200); return row

    def image_path(item): return STORE/(item['image_id']+'.bin')

    def hold(statement,seconds=2):
        # Separate SQL connection holds a real lock while HTTP workers run.
        process=subprocess.Popen(['mariadb','-N','--unbuffered',DB,'-e',statement+'; SELECT "locked"; DO SLEEP('+str(seconds)+'); COMMIT;'],stdout=subprocess.PIPE,text=True)
        assert process.stdout.readline().strip()=='locked'
        return process

    a=image(); row=text(); status,row=associate(row,a)
    check('separate delivery flag initially closed',display(row)[0]==404)
    config('FALUSS_PLATFORM_FANS_IMAGE_DELIVERY','true',True)
    check('pending text never delivers despite approved image',display(row)[0]==404)
    row=publish(row); approved=row.copy(); source=image_path(a).read_bytes()
    initial_files=sorted(p.name for p in STORE.iterdir())
    attachments=sql("SELECT COUNT(*) FROM wp_posts WHERE post_type='attachment'")
    result=display(row,{'Origin':'https://faluss.me'})
    check('anonymous public display returns JPEG, never PNG quarantine',result[0]==200 and result[2].get_content_type()=='image/jpeg' and result[1].startswith(b'\xff\xd8') and result[1]!=source)
    jpeg=result[1]
    size=json.loads(subprocess.check_output(['php','-r','echo json_encode(getimagesizefromstring(stream_get_contents(STDIN)));'],input=jpeg))
    check('display resized to 1280x640',size['0']==1280 and size['1']==640)
    check('no shared cache or cross-origin browser embedding',no_cache(result) and result[2].get('Cross-Origin-Resource-Policy')=='same-origin' and result[2].get('Access-Control-Allow-Origin') is None)
    check('no storage path or image UUID in response headers',str(STORE) not in str(result[2]) and a['image_id'] not in str(result[2]))
    check('HEAD reauthorizes and sends no bytes',display(row,method='HEAD')[0:2]==(200,b''))
    check('conditional request does not bypass authorization via 304',display(row,{'If-None-Match':'*','If-Modified-Since':'Wed, 01 Jan 2031 00:00:00 GMT'})[0]==200)
    check('range is refused without partial original',display(row,{'Range':'bytes=0-20'})[0]==400)
    check('forged source query rejected',display(row,suffix='?image_id='+a['image_id'])[0]==400)
    check('no persistent derivative or WordPress attachment',initial_files==sorted(p.name for p in STORE.iterdir()) and attachments==sql("SELECT COUNT(*) FROM wp_posts WHERE post_type='attachment'"))
    for who in ['anon','owner','other']:
        check('original bytes remain private '+who,call(who,'images/'+a['image_id']+'/bytes')[0] in [401,403])
    for path in ['/faluss-display-images/'+a['image_id']+'.bin','/wp-content/uploads/'+a['image_id']+'.bin','/wp-json/faluss-fans/v1/text-publications/..%2fetc%2fpasswd/image/1']:
        req=urllib.request.Request('http://127.0.0.1:8113'+path,headers={'Host':'text.local.test'})
        try: response=urllib.request.urlopen(req,timeout=20)
        except urllib.error.HTTPError as e: response=e
        data=response.read()
        check('direct path never returns source or derivative',source not in data and jpeg not in data and response.headers.get_content_type() not in ['image/jpeg','image/png'])
    orphan=publish(text())
    check('approved text without association denied',display(orphan)[0]==404)
    foreign=image('other'); pending=image(approve=False)
    ref_where=" WHERE publication_id='"+row['publication_id']+"'"
    # Corrupt synthetic links bypass the write API to exercise delivery's independent guards.
    for item in [foreign,pending]:
        sql("UPDATE wp_faluss_fans_text_images SET image_id='"+item['image_id']+"',image_revision="+str(item['revision'])+ref_where)
        check('delivery independently denies foreign or unapproved image',display(row)[0]==404)
    sql("UPDATE wp_faluss_fans_text_images SET image_id='"+a['image_id']+"',image_revision=999"+ref_where)
    check('stale image revision denied',display(row)[0]==404)
    sql('UPDATE wp_faluss_fans_text_images SET image_revision=2'+ref_where)
    sql("UPDATE wp_faluss_fans_text_publications SET category='external_adult_delivery_right'"+ref_where)
    check('forged adult publication denied',display(row)[0]==404)
    sql("UPDATE wp_faluss_fans_text_publications SET category='hosted_allowed_content'"+ref_where)
    path=image_path(a); saved=path.read_bytes(); original_hash=sql("SELECT file_hash FROM wp_faluss_fans_images WHERE image_id='"+a['image_id']+"'")
    try:
        path.write_bytes(b'corrupt')
        failed=display(row); check('hash mismatch fails closed with no-store',failed[0]==503 and not failed[1].startswith(b'\xff\xd8') and no_cache(failed))
        sql("UPDATE wp_faluss_fans_images SET file_hash=SHA2('corrupt',256) WHERE image_id='"+a['image_id']+"'")
        check('matching hash but malformed raster fails decoding closed',display(row)[0]==503)
    finally:
        path.write_bytes(saved)
        sql("UPDATE wp_faluss_fans_images SET file_hash='"+original_hash+"' WHERE image_id='"+a['image_id']+"'")
    try:
        path.chmod(0o644); check('nonprivate file denied',display(row)[0]==503)
    finally: path.chmod(0o600)
    moved=TMP/'source-held.bin'; path.rename(moved)
    try:
        check('missing quarantine file denied',display(row)[0]==503)
        path.symlink_to(moved); check('forged symlink denied',display(row)[0]==503)
    finally:
        if path.is_symlink(): path.unlink()
        moved.rename(path)
    config('FALUSS_FANS_IMAGE_STORAGE_ATTESTED','false',True)
    check('missing hosting attestation fails closed',display(row)[0]==503)
    config('FALUSS_FANS_IMAGE_STORAGE_ATTESTED','true',True)
    sql('RENAME TABLE wp_faluss_fans_text_images TO recipe_display_saved_links')
    try: check('missing association schema closes route',display(row)[0] in [403,404,503])
    finally: sql('RENAME TABLE recipe_display_saved_links TO wp_faluss_fans_text_images')
    check('recovery revalidates and renders',display(row)[0]==200)
    import hashlib
    lock='fans_display_'+hashlib.sha256(b'wp_faluss_fans_text_publications').hexdigest()[:40]
    blocker=hold("DO GET_LOCK('"+lock+"',0)",3)
    try: check('occupied generation slot returns no bytes',display(row)[0]==503)
    finally: blocker.wait(timeout=5)
    # An uncommitted edit locks the publication before the HTTP authorization read.
    blocker=hold("START TRANSACTION; UPDATE wp_faluss_fans_text_publications SET state='pending',revision=revision+1"+ref_where)
    try: check('waiting request reads committed edit, not old approval',display(row)[0]==404)
    finally: blocker.wait(timeout=5)
    row=private(row); check('new pending revision denied',display(row)[0]==404)
    row=publish(row); check('old URL stays closed after reapproval',display(approved)[0]==404 and display(row)[0]==200)
    old=row.copy(); status,row=call('owner','text-publications/'+row['publication_id']+'/edit',{'revision':int(row['revision']),'text':'Nouvelle revue.'})
    check('normal text edit immediately revokes display',status==200 and display(old)[0]==404 and display(row)[0]==404)
    row=publish(row)
    # A second distinct image proves replacement is not a stale derivative cache.
    png=subprocess.check_output(['php','-r','$i=imagecreatetruecolor(1800,900); imagefill($i,0,0,imagecolorallocate($i,50,50,220)); imagepng($i);'])
    b=image(); old=row.copy(); status,row=associate(row,b)
    check('replacement closes old and pending URLs',status==200 and display(old)[0]==404 and display(row)[0]==404)
    row=publish(row); replacement=display(row)
    check('reapproved replacement renders fresh pixels',replacement[0]==200 and replacement[1]!=jpeg and display(old)[0]==404)
    results=race(lambda:display(row),lambda:call('owner','images/'+b['image_id']+'/withdraw',{'revision':int(b['revision'])}))
    check('image withdrawal versus byte request is ordered',results[0][0] in [200,404] and results[1][0]==200)
    check('next request after image withdrawal denied',display(row)[0]==404)
    status,row=associate(row,a); row=publish(row)
    results=race(lambda:display(row),lambda:call('admin','images/'+a['image_id']+'/moderate',{'revision':int(a['revision']),'decision':'reject','reason':'needs_revision'}))
    check('image rejection versus byte request is ordered',results[0][0] in [200,404] and results[1][0]==200)
    check('next request after image rejection denied',display(row)[0]==404)
    c=image(); status,row=associate(row,c); row=publish(row)
    profile=sessions['owner']['creator_id']
    # Use SQL for the synthetic status race: the same InnoDB UPDATE as the profile service.
    results=race(lambda:display(row),lambda:sql("UPDATE wp_faluss_fans_creator_profiles SET status='suspended' WHERE creator_id='"+profile+"'"))
    check('profile suspension race closes next request',results[0][0] in [200,404] and display(row)[0]==404)
    sql("UPDATE wp_faluss_fans_creator_profiles SET status='active' WHERE creator_id='"+profile+"'")
    check('active profile can regain unchanged approvals',display(row)[0]==200)
    results=race(lambda:display(row),lambda:call('admin','text-publications/'+row['publication_id']+'/moderate',{'revision':int(row['revision']),'decision':'reject','reason':'needs_revision'}))
    check('text rejection race closes next request',results[0][0] in [200,404] and results[1][0]==200 and display(row)[0]==404)
    row=private(row); status,row=call('owner','text-publications/'+row['publication_id']+'/edit',{'revision':int(row['revision']),'text':'Texte revu.'}); row=publish(row)
    old=row.copy(); status,row=associate(row,None); row=publish(row)
    check('detachment denies even after text approval',display(old)[0]==404 and display(row)[0]==404)
    status,row=associate(row,c); row=publish(row)
    results=race(lambda:display(row),lambda:call('owner','text-publications/'+row['publication_id']+'/withdraw',{'revision':int(row['revision'])}))
    check('text withdrawal race closes next request',results[0][0] in [200,404] and results[1][0]==200 and display(row)[0]==404)
    fresh=text(); status,fresh=associate(fresh,c); fresh=publish(fresh)
    config('FALUSS_PLATFORM_FANS_IMAGE_DELIVERY','false',True)
    check('delivery rollback closes public route but preserves admin quarantine',display(fresh)[0]==404 and call('admin','images/'+c['image_id']+'/bytes')[0]==200)
    config('FALUSS_PLATFORM_FANS_IMAGE_DELIVERY','true',True); config('FALUSS_PLATFORM_FANS_IMAGES','false',True)
    check('quarantine flag closure also closes delivery',display(fresh)[0]==404)
    config('FALUSS_PLATFORM_FANS_IMAGES','true',True)
    for item,who in [(pending,'owner'),(c,'owner'),(foreign,'other')]:
        check('cleanup live quarantine image',call(who,'images/'+item['image_id']+'/withdraw',{'revision':int(item['revision'])})[0]==200)
    check('no derived or quarantine files retained after cleanup',list(STORE.iterdir())==[])
    print(str(checks)+' real HTTP image delivery controls passed.',flush=True)
finally:
    config('FALUSS_PLATFORM_FANS_IMAGE_DELIVERY','false',True)
    config('FALUSS_PLATFORM_FANS_IMAGES','false',True)
    config('FALUSS_PLATFORM_FANS_TEXT_PUBLICATIONS','false',True)
    config('DB_USER',old_user); config('FALUSS_FANS_IMAGE_PRIVATE_ROOT',old_root)
    config('FALUSS_FANS_IMAGE_STORAGE_ATTESTED','true' if old_attestation in ['1','true'] else 'false',True)
    if server is not None:
        os.killpg(server.pid,signal.SIGTERM); server.wait(timeout=10)
    if db_created: sql("DROP USER 'www-data'@'localhost'")
    for directory in [STORE,TMP]:
        if not list(directory.iterdir()): directory.rmdir()
    print('Three local flags false; original config restored; HTTP workers stopped.',flush=True)
