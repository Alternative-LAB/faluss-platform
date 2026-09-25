"""Real loopback-only recipe; synthetic dedicated DB, never production."""
import concurrent.futures, json, os, struct, subprocess, urllib.request, urllib.error, zlib
from pathlib import Path
ROOT = Path('/var/tmp/faluss-text-publications-recipe')
STORE = Path('/var/tmp/faluss-image-quarantine')
PROOF = Path('/var/tmp/faluss-image-proof')
DB = 'faluss_text_publications_recipe'
WP = ['php', '/var/tmp/faluss-v3-wp/wp-cli.phar', '--allow-root', '--path=' + str(ROOT)]
assert subprocess.check_output(WP + ['config', 'get', 'DB_NAME'], text=True).strip() == DB
SESSIONS = json.loads((PROOF / 'sessions.json').read_text())
BASE = 'http://127.0.0.1:8113/wp-json/faluss-fans/v1/'
checks = 0
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl): return None
urllib.request.install_opener(urllib.request.build_opener(NoRedirect()))

def check(label, condition):
    global checks
    if not condition: raise AssertionError(label)
    checks += 1
    print('PASS ' + label, flush=True)

def sql(statement):
    return subprocess.check_output(['mariadb', '-N', DB, '-e', statement], text=True).strip()

def call(who, route, data=None, upload=None, filename='image.png', mime='image/png', nonce=True, extra=False):
    headers = {'Host': 'text.local.test'}
    if who in SESSIONS:
        item = SESSIONS[who]
        headers['Cookie'] = item['cookie_name'] + '=' + item['cookie']
        if nonce: headers['X-WP-Nonce'] = item['nonce']
    body = None
    if upload is not None:
        boundary = 'FansRecipeBoundary'
        body = ('--' + boundary + '\r\nContent-Disposition: form-data; name="image"; filename="' + filename + '"\r\nContent-Type: ' + mime + '\r\n\r\n').encode() + upload + b'\r\n'
        if extra: body += ('--' + boundary + '\r\nContent-Disposition: form-data; name="tmp_name"\r\n\r\n/etc/passwd\r\n').encode()
        body += ('--' + boundary + '--\r\n').encode()
        headers['Content-Type'] = 'multipart/form-data; boundary=' + boundary
    elif data is not None:
        body = json.dumps(data).encode(); headers['Content-Type'] = 'application/json'
    request = urllib.request.Request(BASE + route, body, headers)
    try: result = urllib.request.urlopen(request, timeout=30)
    except urllib.error.HTTPError as e: result = e
    raw = result.read()
    return result.status, (raw if result.headers.get_content_type() == 'image/png' else json.loads(raw)), result.headers

def png(w=2, h=2):
    def chunk(kind, payload): return struct.pack('!I', len(payload)) + kind + payload + struct.pack('!I', zlib.crc32(kind + payload))
    return b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('!IIBBBBB', w, h, 8, 2, 0, 0, 0)) + chunk(b'IDAT', zlib.compress((b'\0' + b'\0\x80\0' * w) * h)) + chunk(b'IEND', b'')
PNG = png()
def submit(who='owner', **kwargs): return call(who, 'images', upload=PNG, **kwargs)
def decide(item, action='approve', reason='allowed_image'):
    return call('admin', 'images/' + item['image_id'] + '/moderate', {'revision': int(item['revision']), 'decision': action, 'reason': reason})
def withdraw(item, who='owner'):
    return call(who, 'images/' + item['image_id'] + '/withdraw', {'revision': int(item['revision'])})
def file_for(item): return STORE / (item['image_id'] + '.bin')
def audit(): return int(sql('SELECT COUNT(*) FROM wp_faluss_fans_image_decisions'))

for who in ['anon', 'unlinked', 'pending', 'admin']:
    check('upload refused for ' + who, submit(who)[0] in [401,403])
check('nonce mandatory on upload', submit(nonce=False)[0] in [401,403])
for payload in [b'<svg/>', b'GIF89a', PNG[:20], b'<?php echo 1; ?>', png(4097,1)]:
    check('actual malformed or unsupported bytes denied', call('owner','images',upload=payload)[0] == 415)
check('actual byte size enforced', call('owner','images',upload=PNG + b'x' * 2097152)[0] == 413)
check('forged tmp path field denied', submit(extra=True)[0] == 400)
check('JSON local file upload denied', call('owner','images',{'image':{'tmp_name':'/etc/passwd'}})[0] == 400)
check('invalid uploads leave no stored files', list(STORE.iterdir()) == [])
status,item,_ = submit(filename='../../payload.php', mime='application/x-php')
check('actual PNG safely accepted despite forged name and MIME', status == 201 and item['state'] == 'pending' and set(item) == {'image_id','state','revision'})
check('private file exists with strict permissions', file_for(item).is_file() and file_for(item).stat().st_mode & 0o777 == 0o600)
for who in ['anon','owner','other']:
    check('bytes reserved to admin: ' + who, call(who,'images/'+item['image_id']+'/bytes')[0] in [401,403])
check('admin bytes nonce mandatory', call('admin','images/'+item['image_id']+'/bytes',nonce=False)[0] in [401,403])
status,data,headers = call('admin','images/'+item['image_id']+'/bytes')
check('admin receives real PNG bytes without public cache', status == 200 and data.startswith(b'\x89PNG') and 'no-store' in headers['Cache-Control'] and headers['X-Content-Type-Options'] == 'nosniff')
check('foreign owner cannot withdraw', withdraw(item,'other')[0] == 403)
check('creator cannot moderate', call('owner','images/'+item['image_id']+'/moderate',{'revision':1,'decision':'approve','reason':'allowed_image'})[0] == 403)
check('forged route path refused', call('admin','images/..%2f..%2fetc%2fpasswd/bytes')[0] == 404)
for path in ['/faluss-image-quarantine/'+item['image_id']+'.bin','/wp-content/uploads/'+item['image_id']+'.bin']:
    try: result=urllib.request.urlopen('http://127.0.0.1:8113'+path)
    except urllib.error.HTTPError as e: result=e
    check('no direct HTTP file access: '+path.split('/')[1], result.headers.get_content_type() == 'text/html' and file_for(item).read_bytes() not in result.read() and not (ROOT / path.lstrip('/')).exists())
status,approved,_ = decide(item)
check('approval traced without public distribution', status == 200 and call('anon','images/'+item['image_id']+'/bytes')[0] in [401,403])
check('public image route absent', call('anon','images/'+item['image_id'])[0] == 404)
check('stale moderation refused', decide(item)[0] == 409)
trace = call('admin','images/'+item['image_id']+'/decisions')[1]
check('decision trail contains actor and reason without path', len(trace)==2 and trace[1]['action']=='approve' and 'file_hash' not in trace[1])
check('creator has metadata only', call('owner','images')[1]['items'][0]['image_id'] == item['image_id'])
cid = SESSIONS['owner']['creator_id']
check('profile suspension succeeds', call('admin','creators/'+cid+'/status',{'status':'suspended'})[0] == 200)
check('suspension revokes admin bytes', call('admin','images/'+item['image_id']+'/bytes')[0] == 404)
check('suspended creator cannot upload', submit()[0] == 403)
check('suspended creator can withdraw and delete bytes', withdraw(approved)[0] == 200 and not file_for(item).exists())
call('admin','creators/'+cid+'/status',{'status':'active'})
check('reactivation cannot restore withdrawn image', call('admin','images/'+item['image_id']+'/bytes')[0] == 404)

item=submit()[1]
check('rejection revokes and removes image', decide(item,'reject','prohibited_content')[0] == 200 and not file_for(item).exists() and call('admin','images/'+item['image_id']+'/bytes')[0] == 404)
# JPEG is decoded and returned only as normalized PNG.
jpeg=subprocess.check_output(['php','-r','$i=imagecreatetruecolor(2,2); imagejpeg($i);'])
status,item,_=call('owner','images',upload=jpeg,filename='x.jpg',mime='image/jpeg')
check('JPEG reencoded to PNG', status==201 and file_for(item).read_bytes().startswith(b'\x89PNG'))
withdraw(item)

before_files=set(STORE.iterdir()); before_audit=audit()
sql("CREATE TRIGGER recipe_fail_image_audit BEFORE INSERT ON wp_faluss_fans_image_decisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='recipe audit failure'")
try:
    check('failed SQL audit removes newly stored file', submit()[0]==503 and set(STORE.iterdir())==before_files and audit()==before_audit)
finally: sql('DROP TRIGGER recipe_fail_image_audit')
STORE.chmod(0o755)
try: check('nonprivate storage fails closed', submit()[0]==503)
finally: STORE.chmod(0o700)
# Failure after durable revocation: unsafe file mode prevents unlink; explicit cleanup retries.
item=submit()[1]; file_for(item).chmod(0o400)
check('failed unlink reports cleanup while access is revoked', withdraw(item)[0]==503 and call('admin','images/'+item['image_id']+'/bytes')[0]==404)
file_for(item).chmod(0o600)
check('cleanup retry deletes revoked file', call('admin','images/cleanup',{})[0]==200 and not file_for(item).exists())
check('non-admin cleanup refused', call('owner','images/cleanup',{})[0]==403)
# Symlink replacement never serves its target or deletes it.
item=submit()[1]; content=file_for(item).read_bytes(); file_for(item).unlink(); file_for(item).symlink_to('/etc/passwd')
try: check('symlink bytes and admission fail closed', call('admin','images/'+item['image_id']+'/bytes')[0]==503 and submit()[0]==503)
finally: file_for(item).unlink(); file_for(item).write_bytes(content); file_for(item).chmod(0o600)
withdraw(item)

# A journal failure during rejection must retain both the previous state and bytes.
item=submit('other')[1]
sql("CREATE TRIGGER recipe_fail_image_audit BEFORE INSERT ON wp_faluss_fans_image_decisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='recipe audit failure'")
try:
    check('failed rejection rolls back without deleting live bytes', decide(item,'reject','needs_revision')[0]==503 and file_for(item).exists() and call('admin','images/'+item['image_id']+'/bytes')[0]==200)
finally: sql('DROP TRIGGER recipe_fail_image_audit')
withdraw(item,'other')
# No hosting attestation or a web-root directory must never admit bytes.
subprocess.run(WP+['config','set','FALUSS_FANS_IMAGE_STORAGE_ATTESTED','false','--raw','--quiet'],check=True)
try: check('missing hosting privacy attestation fails closed', submit('other')[0]==503)
finally: subprocess.run(WP+['config','set','FALUSS_FANS_IMAGE_STORAGE_ATTESTED','true','--raw','--quiet'],check=True)
subprocess.run(WP+['config','set','FALUSS_FANS_IMAGE_PRIVATE_ROOT',str(ROOT),'--quiet'],check=True)
try: check('web-root storage denied', submit('other')[0]==503)
finally: subprocess.run(WP+['config','set','FALUSS_FANS_IMAGE_PRIVATE_ROOT',str(STORE),'--quiet'],check=True)

rows=[submit('quota')[1] for _ in range(4)]
with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
    results=list(pool.map(lambda _:submit('quota'),range(4)))
check('concurrent final creator slot admits exactly one image', sorted(r[0] for r in results)==[201,429,429,429])
rows += [r[1] for r in results if r[0]==201]
check('quota cannot block withdrawal', withdraw(rows[0],'quota')[0]==200)
check('quota cannot block rejection', decide(rows[1],'reject','needs_revision')[0]==200)
for r in rows[2:]: withdraw(r,'quota')
# Competing approval and withdrawal use one revision, with no reopened bytes.
item=submit('racer')[1]
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
    a=pool.submit(decide,item); b=pool.submit(withdraw,item,'racer'); results=[a.result(),b.result()]
check('concurrent decisions serialize revisions', sorted(r[0] for r in results)==[200,409])
current=next(r[1] for r in results if r[0]==200)
if current['state']=='approved': withdraw(current,'racer')
check('revoked bytes unavailable after competing decisions', call('admin','images/'+item['image_id']+'/bytes')[0]==404 and not file_for(item).exists())
# Rate quotas cannot be bypassed by deleting every image. Shift only synthetic audit time.
for i in range(60):
    if i in [20,40]:
        sql("UPDATE wp_faluss_fans_image_decisions SET occurred_at=UTC_TIMESTAMP()-INTERVAL 2 HOUR WHERE action='submit' AND image_id IN (SELECT image_id FROM wp_faluss_fans_images WHERE creator_id='"+SESSIONS['daily']['creator_id']+"')")
    status,item,_=submit('daily')
    if status!=201 or withdraw(item,'daily')[0]!=200: raise AssertionError('rate fixture admission/removal failed')
    if i==19: check('hourly quota survives withdrawals', submit('daily')[0]==429)
sql("UPDATE wp_faluss_fans_image_decisions SET occurred_at=UTC_TIMESTAMP()-INTERVAL 2 HOUR WHERE action='submit' AND image_id IN (SELECT image_id FROM wp_faluss_fans_images WHERE creator_id='"+SESSIONS['daily']['creator_id']+"')")
check('daily quota survives withdrawals', submit('daily')[0]==429)
# Physical capacity includes files left by a hypothetical process crash.
import uuid
orphans=[]
for _ in range(100):
    f=STORE/(str(uuid.uuid4())+'.bin'); f.write_bytes(PNG); f.chmod(0o600); orphans.append(f)
try:
    check('site physical quota counts orphan files', submit('other')[0]==429)
    status,cleaned,_=call('admin','images/cleanup',{})
    check('explicit orphan reconciliation removes only unreferenced files', status==200 and cleaned['removed']==100)
finally:
    for f in orphans:
        if f.exists(): f.unlink()
check('no public WordPress media created', sql("SELECT COUNT(*) FROM wp_posts WHERE post_type='attachment'")=='0')
check('all images cleaned after test withdrawals', list(STORE.iterdir())==[])
subprocess.run(WP+['config','set','FALUSS_PLATFORM_FANS_IMAGES','false','--raw','--quiet'],check=True)
import time
for _ in range(10):
    if call('admin','images')[0]==404: break
    time.sleep(.5)
check('flag rollback closes image routes', call('admin','images')[0]==404)
print(str(checks)+' real WordPress/MariaDB image controls passed; flag disabled.',flush=True)
