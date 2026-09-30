"""Actual multipart/PHP uploads + real Images services and InnoDB, WP adapters only."""
import concurrent.futures, json, os, pathlib, struct, subprocess, urllib.request, urllib.error, urllib.parse, zlib

ROOT = pathlib.Path(os.environ['FANS_EDITORIAL_FIXTURE'])
assert ROOT.parent == pathlib.Path('/var/tmp') and ROOT.name.startswith('fans-editorial-') and (ROOT/'isolated-fixture').is_file()
BASE = 'http://127.0.0.1:8768'
OWNER = '11111111-1111-4111-8111-111111111111'
count = 0
def check(label, condition):
    global count
    if not condition: raise AssertionError(label)
    count += 1; print('PASS '+label, flush=True)
def png(seed=0):
    def chunk(kind,data): return struct.pack('!I',len(data))+kind+data+struct.pack('!I',zlib.crc32(kind+data))
    return b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('!IIBBBBB',32,32,8,2,0,0,0))+chunk(b'IDAT',zlib.compress((b'\0'+bytes([seed,120,90])*32)*32))+chunk(b'IEND',b'')
def call(user,route,data=None,upload=None,nonce=True,native=False):
    headers = {'Cookie':'fixture_user='+str(user)}
    if nonce: headers['X-WP-Nonce']='fixture-wp_rest'
    body = None
    if upload is not None:
        boundary='IsolatedImagesBoundary'
        body=b'--'+boundary.encode()+b'\r\nContent-Disposition: form-data; name="image"; filename="ignored.png"\r\nContent-Type: image/png\r\n\r\n'+upload+b'\r\n'
        if native:
            for name,value in {'image_action':'upload','fans_images_nonce':'fixture-fans_images'}.items():
                body+=('--'+boundary+'\r\nContent-Disposition: form-data; name="'+name+'"\r\n\r\n'+value+'\r\n').encode()
        body+=b'--'+boundary.encode()+b'--\r\n'; headers['Content-Type']='multipart/form-data; boundary='+boundary
    elif data is not None:
        body=json.dumps(data).encode(); headers['Content-Type']='application/json'
    request=urllib.request.Request(BASE+(route if native else '/wp-json/faluss-fans/v1/'+route),body,headers)
    try: response=urllib.request.urlopen(request,timeout=20)
    except urllib.error.HTTPError as error: response=error
    raw=response.read()
    return response.status, raw if native or response.headers.get_content_type()=='image/jpeg' else json.loads(raw), response.headers
def sql(statement):
    return subprocess.check_output(['mariadb','--no-defaults','--socket='+str(ROOT/'sql.sock'),'-uroot','fans_editorial_test','-N','-e',statement],text=True).strip()
def withdraw(row): return call(17,'images/'+row['image_id']+'/withdraw',{'revision':int(row['revision'])})

for user in [0,18,19,1]: check('upload permission '+str(user),call(user,'images',upload=png())[0]==403)
check('upload nonce mandatory',call(17,'images',upload=png(),nonce=False)[0]==403)
check('SVG denied by real decoder',call(17,'images',upload=b'<svg></svg>')[0]==415)
check('truncated image denied',call(17,'images',upload=png()[:40])[0]==415)
check('actual byte cap',call(17,'images',upload=png()+b'x'*2097152)[0]==413)
before=sql('SELECT COUNT(*) FROM test_faluss_fans_image_decisions')
status,row,headers=call(17,'images',upload=png())
check('actual multipart admitted pending',status==201 and row['state']=='pending')
again=call(17,'images',upload=png())
check('same live raster returns same row',again[0]==200 and again[1]['reused'] is True and again[1]['image_id']==row['image_id'])
check('retry consumes no audit quota',int(sql('SELECT COUNT(*) FROM test_faluss_fans_image_decisions'))==int(before)+1)
check('private listing has date without source filename',bool(call(17,'images')[1]['items'][0]['created_at']) and 'filename' not in call(17,'images')[1]['items'][0])
check('live scope excludes closed records',all(x['state'] in ['pending','approved'] for x in call(17,'images?scope=live')[1]['items']))
check('invalid scope denied',call(17,'images?scope=unknown')[0]==400)
check('owner preview JPEG',call(17,'images/'+row['image_id']+'/preview/1')[1].startswith(b'\xff\xd8'))
other='55555555-5555-4555-8555-555555555555'
sql("INSERT INTO test_faluss_fans_identity_links(wp_user_id,faluss_id,created_at,last_proved_at) VALUES(20,'"+other+"',UTC_TIMESTAMP(),UTC_TIMESTAMP())")
sql("INSERT INTO test_faluss_fans_creator_profiles(creator_id,wp_user_id,category,status,created_at,updated_at) VALUES('"+other+"',20,'arts','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())")
check('other creator cannot preview',call(20,'images/'+row['image_id']+'/preview/1')[0]==404)
check('other creator cannot withdraw',call(20,'images/'+row['image_id']+'/withdraw',{'revision':1})[0]==403)
other_image=call(20,'images',upload=png())
check('dedup does not cross owners',other_image[0]==201 and other_image[1]['image_id']!=row['image_id'])
check('creator listing isolated',len(call(20,'images')[1]['items'])==1 and call(20,'images')[1]['items'][0]['image_id']==other_image[1]['image_id'])
call(20,'images/'+other_image[1]['image_id']+'/withdraw',{'revision':1})
check('preview nonce mandatory',call(17,'images/'+row['image_id']+'/preview/1',nonce=False)[0]==403)
check('Fan cannot withdraw',call(18,'images/'+row['image_id']+'/withdraw',{'revision':1})[0]==403)
check('stale withdraw conflicts',call(17,'images/'+row['image_id']+'/withdraw',{'revision':2})[0]==409)
check('owner withdraw',withdraw(row)[0]==200)
check('withdraw revokes old preview',call(17,'images/'+row['image_id']+'/preview/1')[0]==404)
check('closed scope excludes live records',all(x['state'] in ['withdrawn','rejected'] for x in call(17,'images?scope=closed')[1]['items']))
new=call(17,'images',upload=png())
check('reupload withdrawn raster creates pending new id',new[0]==201 and new[1]['image_id']!=row['image_id'] and new[1]['state']=='pending')
withdraw(new[1])
with concurrent.futures.ThreadPoolExecutor(2) as pool:
    responses=list(pool.map(lambda _:call(17,'images',upload=png(1)),range(2)))
check('concurrent retries one creation',sorted(r[0] for r in responses)==[200,201] and responses[0][1]['image_id']==responses[1][1]['image_id'])
withdraw(responses[0][1])
responses=[call(17,'images',upload=png(i)) for i in range(10,15)]
check('five distinct images admitted',all(r[0]==201 for r in responses))
rows=[r[1] for r in responses]
check('five live images quota holds',call(17,'images',upload=png(15))[0]==429)
check('retry at capacity still returns existing image',call(17,'images',upload=png(10))[0]==200)
for current in rows: check('quota does not block withdrawal',withdraw(current)[0]==200)
before=sql('SELECT COUNT(*) FROM test_faluss_fans_images')
files=set((ROOT/'images').iterdir())
sql("CREATE TRIGGER fail_image_audit BEFORE INSERT ON test_faluss_fans_image_decisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated audit failure'")
try: check('failed audit rolls back and removes file',call(17,'images',upload=png(2))[0]==503 and sql('SELECT COUNT(*) FROM test_faluss_fans_images')==before and set((ROOT/'images').iterdir())==files)
finally: sql('DROP TRIGGER fail_image_audit')
sql("UPDATE test_faluss_fans_creator_profiles SET status='suspended' WHERE creator_id='"+OWNER+"'")
check('suspended upload denied',call(17,'images',upload=png(3))[0]==403)
sql("UPDATE test_faluss_fans_creator_profiles SET status='active' WHERE creator_id='"+OWNER+"'")
status,html,_=call(17,'/faluss-fans/creator/creer',upload=png(4),native=True)
check('native UI multipart reaches real service',status==200 and b'Action confirm' in html)
check('native gallery masks UUID and original filename',b'ignored.png' not in html and b'Mes images priv' in html)
# A single native request must never dispatch both services.
form=urllib.parse.urlencode({'image_action':'upload','author_action':'create','fans_images_nonce':'fixture-fans_images'}).encode()
try: response=urllib.request.urlopen(urllib.request.Request(BASE+'/faluss-fans/creator/creer',form,{'Cookie':'fixture_user=17'}))
except urllib.error.HTTPError as response: check('mixed native forms rejected before mutation',response.code==400)
else: raise AssertionError('ambiguous form accepted')
current=call(17,'images?scope=live')[1]['items'][0]
sql("UPDATE test_faluss_fans_creator_profiles SET status='suspended' WHERE creator_id='"+OWNER+"'")
check('suspended creator retains own metadata',call(17,'images?scope=live')[0]==200)
check('suspension revokes private preview',call(17,'images/'+current['image_id']+'/preview/'+str(current['revision']))[0]==404)
sql("UPDATE test_faluss_fans_creator_profiles SET status='active' WHERE creator_id='"+OWNER+"'")
(ROOT/'images').rename(ROOT/'images-paused')
try:
    check('unavailable private storage blocks upload',call(17,'images',upload=png(5))[0]==503)
    result=withdraw(current)
    check('failed unlink reports durable revocation',result[0]==503 and result[1]['code']=='image_cleanup_required')
finally: (ROOT/'images-paused').rename(ROOT/'images')
check('failed unlink never reopens preview',call(17,'images/'+current['image_id']+'/preview/'+str(current['revision']))[0]==404)
check('ordinary member cannot reconcile files',call(17,'images/cleanup',{})[0]==403)
result=call(1,'images/cleanup',{})
check('admin cleanup removes revoked file',result[0]==200 and result[1]['removed']>=1 and not (ROOT/'images'/(current['image_id']+'.bin')).exists())
print(json.dumps({'checks':count,'scope':'real multipart, services, SQL; WordPress primitives are adapters'}),flush=True)
