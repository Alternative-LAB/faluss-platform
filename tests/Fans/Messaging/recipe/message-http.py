"""Actual HTTP, PHP services and isolated InnoDB; WP sessions/nonces are adapters."""
import urllib.request, urllib.error, json, uuid, subprocess, os, concurrent.futures
BASE='http://127.0.0.1:8768/wp-json/faluss-fans/v1/'
CREATOR='11111111-1111-4111-8111-111111111111'
def sql(statement):
    return subprocess.check_output(['mariadb','--no-defaults','--socket='+os.environ['FANS_EDITORIAL_FIXTURE']+'/sql.sock','-uroot','fans_editorial_test','-N','-e',statement],text=True).strip()
def check(label,ok):
    assert ok,label
    print('PASS '+label,flush=True)
def call(user,path,data=None,nonce=True):
    headers={'Cookie':'fixture_user='+str(user),'Content-Type':'application/json'}
    if nonce: headers['X-WP-Nonce']='fixture-wp_rest'
    r=urllib.request.Request(BASE+path,None if data is None else json.dumps(data).encode(),headers)
    try: response=urllib.request.urlopen(r,timeout=20)
    except urllib.error.HTTPError as e: response=e
    body=response.read()
    return response.status,json.loads(body),dict(response.headers)
def payload(body='Demande HTTP'): return dict(creator_id=CREATOR,body=body,key=str(uuid.uuid4()))
# The image recipe ends with a suspended creator: restore only this disposable fixture.
sql("UPDATE test_faluss_fans_creator_profiles SET status='active' WHERE creator_id='"+CREATOR+"'")
for user in [0,19,1]: check('HTTP request denied '+str(user),call(user,'messages/requests',payload())[0]==403)
check('HTTP CSRF denied',call(18,'messages/requests',payload(),False)[0]==403)
for extra in [{'max':True},{'state':'open'},{'image_id':str(uuid.uuid4())}]: check('forged fields refused '+str(extra),call(18,'messages/requests',dict(payload(),**extra))[0]==400)
for body in ['', 'x'*1001,'<img src=x>','x\u0000y']:
    result=call(18,'messages/requests',payload(body))
    check('request text validation '+str(result[0]),result[0]==400)
check('query cannot override body',call(18,'messages/requests?body=changed',payload())[0]==400)
check('self request refused',call(17,'messages/requests',payload())[0]==403)
p=payload()
with concurrent.futures.ThreadPoolExecutor(2) as pool: results=list(pool.map(lambda _:call(18,'messages/requests',p),range(2)))
check('concurrent exact replay one insertion',all(r[0]==200 for r in results) and results[0][1]['message_id']==results[1][1]['message_id'] and sql('SELECT COUNT(*) FROM test_faluss_fans_dm_messages')=='1')
thread=results[0][1]['thread_id'];route='messages/'+thread
check('private cache headers','no-store' in results[0][2]['Cache-Control'])
check('inbox contains only own conversation',len(call(18,'messages')[1]['items'])==1 and call(20,'messages/'+thread)[0]==404)
check('no user IDs or idempotency material',not any(k in json.dumps(call(18,route)[1]) for k in ['sender_id','fan_user','creator_user','key_hash','request_hash']))
check('fan cannot accept',call(18,route+'/decision',dict(revision=1,action='accept'))[0]==403)
check('pending creator reply refused',call(17,route+'/send',dict(body='Réponse',key=str(uuid.uuid4())))[0]==403)
with concurrent.futures.ThreadPoolExecutor(2) as pool: results=list(pool.map(lambda _:call(17,route+'/decision',dict(revision=1,action='accept')),range(2)))
check('concurrent acceptance single CAS winner',sorted(r[0] for r in results)==[200,409])
p=dict(body='Réponse acceptée',key=str(uuid.uuid4()))
check('bilateral creator sends',call(17,route+'/send',p)[0]==200)
check('linked fan sends',call(18,route+'/send',dict(body='Merci',key=str(uuid.uuid4())))[0]==200)
check('cursor excludes already read messages',len(call(18,route+'?after=2')[1]['messages'])==1)
sql("UPDATE test_faluss_fans_creator_profiles SET status='suspended' WHERE creator_id='"+CREATOR+"'")
check('suspension prevents send',call(18,route+'/send',dict(body='No',key=str(uuid.uuid4())))[0]==403)
check('suspended conversation remains privately readable for reporting',call(18,route)[0]==200 and not call(18,route)[1]['can_send'])
sql("UPDATE test_faluss_fans_creator_profiles SET status='active' WHERE creator_id='"+CREATOR+"'")
for i in range(28): last=call(18,route+'/send',dict(body='Quota '+str(i),key=str(uuid.uuid4())))
check('hourly sender quota enforced',last[0]==200 and call(18,route+'/send',dict(body='Too many',key=str(uuid.uuid4())))[0]==429)
sql("UPDATE test_faluss_fans_dm_threads SET last_sent_at=UTC_TIMESTAMP()-INTERVAL 13 MONTH")
check('expired content inaccessible',call(18,route)[0]==410 and sql('SELECT COUNT(*) FROM test_faluss_fans_dm_messages')=='0')
print('PASS messaging HTTP admission, concurrency, privacy, block, quota and retention',flush=True)
