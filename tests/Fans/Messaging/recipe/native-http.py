"""Native forms reach actual message/report SQL services; no browser API simulation."""
import pathlib,urllib.request,urllib.parse,urllib.error,uuid,subprocess,os,json
ns={};exec(compile(pathlib.Path(__file__).with_name('message-http.py').read_text().split('# The image recipe')[0],'helpers','exec'),ns)
call,check,sql,CREATOR=[ns[k] for k in ['call','check','sql','CREATOR']]
BASE='http://127.0.0.1:8768'
def native(user,path,data=None):
    req=urllib.request.Request(BASE+path,None if data is None else urllib.parse.urlencode(data).encode(),{'Cookie':'fixture_user='+str(user)})
    try: r=urllib.request.urlopen(req,timeout=20)
    except urllib.error.HTTPError as error: r=error
    return r.status,r.read().decode(),dict(r.headers)
def form(action,**fields): return dict(message_action=action,fans_messages_nonce='fixture-fans_messages',**fields)
# The report HTTP fixture leaves one unexpired thread; preserve it and navigate through the UI.
thread=call(18,'messages')[1]['items'][0]['thread_id'];path='/faluss-fans/fan/messages?thread='+thread
for user in [0,1,19]: check('native message access denied '+str(user),native(user,path)[0]==403)
check('native outsider cannot read private thread',native(20,path)[0]==404)
check('private page no store','no-store' in native(18,path)[2]['Cache-Control'])
check('native no identity UUID visible',thread not in native(18,path)[1].split('<h2>')[1].split('</h2>')[0])
decision=form('decision',thread_id=thread,revision=str(call(17,'messages/'+thread)[1]['revision']),decision='accept')
check('native nonce denied',native(17,'/faluss-fans/creator/messages?thread='+thread,dict(decision,fans_messages_nonce='wrong'))[0]==403)
check('native extra privilege field denied',native(17,'/faluss-fans/creator/messages?thread='+thread,dict(decision,max='true'))[0]==400)
check('native creator acceptance',native(17,'/faluss-fans/creator/messages?thread='+thread,decision)[0]==200)
key=str(uuid.uuid4());send=form('send',thread_id=thread,body='Message via formulaire natif',key=key)
check('native send',native(18,path,send)[0]==200)
check('native exact replay no duplicate',native(18,path,send)[0]==200 and sql("SELECT COUNT(*) FROM test_faluss_fans_dm_messages WHERE body='Message via formulaire natif'")=='1')
msg=call(17,'messages/'+thread)[1]['messages'][-1]['message_id']
check('native report reaches actual service',native(17,'/faluss-fans/creator/messages?thread='+thread,form('report',thread_id=thread,message_id=msg,reason='spam'))[0]==200)
case=call(17,'message-reports/mine')[1]['items'][0]['case_id'];panel='/admin.php?page=faluss-fans-moderation&view=messages&item='+case
for user in [0,1,17,18]: check('native evidence denied '+str(user),native(user,panel)[0]==403)
check('native moderator sees selected proof','Message via formulaire natif' in native(42,panel)[1])
mod=dict(_wpnonce='fixture-fans_moderation',kind='message-report',item_id=case,revision='1',action='no_action',reason='Examen natif réel',days='0')
check('native moderation nonce required',native(42,panel,dict(mod,_wpnonce='wrong'))[0]==403)
check('native moderator provisional decision',native(42,panel,mod)[0]==200)
check('native participant appeal',native(18,'/faluss-fans/fan/messages?section=reports',form('appeal',case_id=case,revision='2',reason='Recours transmis par formulaire natif'))[0]==200)
subprocess.run(['php',str(pathlib.Path(__file__).with_name('retention.php')),thread,case],env=dict(os.environ,FANS_MESSAGE_READONLY='1'),check=True)
print('PASS native message forms, report panel, closed admission and retention',flush=True)
