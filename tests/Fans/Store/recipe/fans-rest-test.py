from pathlib import Path
exec((Path(__file__).resolve().parents[2] / 'Sso/recipe/fans-http-test.py').read_text().split('callback,jar=prepare(\'success\'')[0])
fixtures=json.loads((root/'rest-fixtures.json').read_text())
import uuid
def call(who,route,data=None,nonce=True):
    jar=root/('rest-'+who+'.cookies'); f=fixtures.get(who)
    jar.write_text('# Netscape HTTP Cookie File\n'+('fans.local.test\tFALSE\t/\tTRUE\t0\t'+f['cookie_name']+'\t'+f['cookie']+'\n' if f else ''))
    if data is not None and nonce and f: data={**data,'_wpnonce':f['nonce']}
    headers,body=request('https://fans.local.test/wp-json/faluss-fans/v1/'+route,jar,data,['X-WP-Nonce: '+f['nonce']] if nonce and f else [])
    return int(headers.splitlines()[0].split()[1]),json.loads(body)
status,error=call('member','creators/me',{'category':'arts'},False); print('Missing nonce response:',status,error.get('code')); check('HTTP member create without nonce denied',status in [401,403])
status,profile=call('member','creators/me',{'category':'arts'}); check('HTTP member creator creation',status==201)
cid=profile['creator_id']
status,_=call('member','creators/'+cid+'/status',{'status':'active'}); check('HTTP member cannot approve creator',status==403)
status,profile=call('admin','creators/'+cid+'/status',{'status':'active'}); check('HTTP admin approval is not identity verification',status==200 and profile['identity_verified'] is False)
# Create through the owner service via CLI fixture to provide idempotency headers separately below.
def create(category):
    f=fixtures['admin']; jar=root/'rest-admin.cookies'; token=uuid.uuid4().hex
    head=root/(token+'.h'); body=root/(token+'.b')
    subprocess.run(['curl','--silent','--noproxy','*','--resolve','fans.local.test:443:127.0.0.1','--cacert',str(root/'cert.pem'),'-b',str(jar),'-D',str(head),'-o',str(body),'-H','X-WP-Nonce: '+f['nonce'],'-H','Idempotency-Key: '+str(uuid.uuid4()),'--data',urllib.parse.urlencode({'creator_id':cid,'category':category,'_wpnonce':f['nonce']}),'https://fans.local.test/wp-json/faluss-fans/v1/store/products'],check=True)
    result=json.loads(body.read_text()); status=int(head.read_text().splitlines()[0].split()[1]); head.unlink(); body.unlink(); check('HTTP admin catalogue creation '+category,status==201); return result
adult=create('external_adult_delivery_right'); hosted=create('hosted_allowed_content')
check('nominative adult listing initially hidden',adult['visibility']=='hidden')
status,categories=call('anon','store/categories'); check('both categories visible over HTTP',status==200 and len(categories)==2)
status,_=call('anon','store/products/'+adult['product_id']); check('adult public detail denied',status==404)
status,products=call('anon','store/products?category=external_adult_delivery_right'); check('adult public list excludes nominative records',status==200 and products==[])
for item,expected in [(adult,403),(hosted,503)]:
 status,_=call('anon','store/products/'+item['product_id']+'/purchase',{}); check('direct HTTP purchase denied '+str(expected),status==expected)
subprocess.run(['mariadb','faluss_fans_recipe','-e',"UPDATE wp_faluss_fans_store_catalog SET category='external_adult_delivery_right' WHERE product_id='"+hosted['product_id']+"'"],check=True)
status,_=call('anon','store/products/'+hosted['product_id']); check('changed category stays hidden even with visible database field',status==404)
status,_=call('anon','store/products/'+hosted['product_id']+'/purchase',{}); check('changed category purchase denied 403',status==403)
