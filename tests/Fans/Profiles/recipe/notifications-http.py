"""Persisted notifications: real isolated WordPress/MariaDB, no remote sites."""
from pathlib import Path
exec((Path(__file__).with_name('admission-http.py')).read_text().split("panel = ")[0])

def center(who='member',data=None,query=''):
    return http('/app/'+('fan' if who=='fan' else 'creator')+'/notifications'+query,who,data)
def rows(who='member',cursor='',filter='all'):
    return json.loads(cli('wp_set_current_user('+str(session['sessions'][who]['id'])+');echo wp_json_encode(\\Faluss\\Platform\\Fans\\Notifications\\NotificationService::listing("'+cursor+'","'+filter+'"));'))
def count(who='member'):
    return int(cli('wp_set_current_user('+str(session['sessions'][who]['id'])+');echo \\Faluss\\Platform\\Fans\\Notifications\\NotificationService::count();'))
def nonce(who='member'):
    body=center(who)[1];return re.search(r'name="fans_notifications_nonce" value="([^"]+)"',body).group(1)
def table_count():return int(cli('global $wpdb;echo $wpdb->get_var("SELECT COUNT(*) FROM wp_faluss_fans_notifications");'))

for who in [None,'unlinked','admin','editor']:
    check('private notifications denied '+str(who),http('/app/fan/notifications',who)[0]==403)
check('additive schema prepared before real decisions',cli('echo \\Faluss\\Platform\\Fans\\Notifications\\NotificationSchema::ready()?"yes":"no";')=='yes')
check('real account decision delivered',any(r['kind']=='profile_active' for r in rows()['items']))
code,body,headers=center()
check('actual page private cache',code==200 and 'no-store' in headers.get('Cache-Control','') and 'no-store' in headers.get('CDN-Cache-Control',''))
check('bell exact unread count',('aria-label="'+str(count())+' non lues"') in body)
check('no private account identity in notifications',session['profiles']['member'] not in re.sub(r'href="[^"]+"','',body))
check('empty linked Fan center works',center('fan')[0]==200 and rows('fan')['items']==[])
item=rows()['items'][0]['id'];before=count()
form={'fans_notifications_nonce':nonce(),'notification':str(item),'unread':'0'}
check('read without nonce denied',center(data={**form,'fans_notifications_nonce':'bad'})[0]==403 and count()==before)
check('read forged recipient denied',center(data={**form,'recipient':str(session['sessions']['other']['id'])})[0]==400)
check('another linked member cannot read-mark event',center('other',{**form,'fans_notifications_nonce':nonce('other')})[0]==404)
check('read marks exact count',center(data=form)[0]==200 and count()==before-1)
check('read replay has no duplicate decrement',center(data=form)[0]==200 and count()==before-1)
check('unread restore exact count',center(data={**form,'unread':'1'})[0]==200 and count()==before)
check('invalid cursor refused',center(query='?cursor=-1')[0]==400)

editorial=api('creators/me/editorial','member')[1]
code,pending,_=api('creators/me/editorial','member',{'revision':int(editorial['revision']),'public_name':'Atelier de recette','bio':'Bio à revoir pour la recette.','portrait_id':'','portrait_revision':0})
check('real editorial revision submitted',code==200)
before=table_count();old=api('creators/'+member)[1]['editorial']
cli("global $wpdb;$wpdb->query(\"CREATE TRIGGER fail_notification BEFORE INSERT ON wp_faluss_fans_notifications FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture notification failure'\");")
decision={'revision':pending['revision'],'decision':'reject','reason':'needs_revision'}
try:
    check('notification write failure rejects domain commit',api('editorial/'+member+'/moderate','admin',decision)[0]==503)
    check('failed event rolls back editorial and approved snapshot',int(api('creators/me/editorial','member')[1]['revision'])==pending['revision'] and api('creators/'+member)[1]['editorial']==old and table_count()==before)
finally:cli('global $wpdb;$wpdb->query("DROP TRIGGER fail_notification");')
check('rejection and notification commit together',api('editorial/'+member+'/moderate','admin',decision)[0]==200 and table_count()==before+1)
check('stale rejection emits nothing',api('editorial/'+member+'/moderate','admin',decision)[0]==409 and table_count()==before+1)
check('communicable bio reason and no rejected bio', 'Des modifications sont nécessaires' in center()[1] and 'Bio à revoir' not in center()[1])
check('approved public snapshot still unchanged',api('creators/'+member)[1]['editorial']==old)
before=table_count()
check('unknown internal reason rejected',cli("echo \\Faluss\\Platform\\Fans\\Notifications\\NotificationEvents::record("+str(session['sessions']['member']['id'])+",'editorial_rejected','"+member+"',999,'internal_note')?'yes':'no';")=='no' and table_count()==before)

# Produce enough real profile decisions for keyset pagination, not invented events.
for i in range(24):
    profile=api('creators/'+other+'/private','admin')[1]
    check('real paginated status event '+str(i),api('creators/'+other+'/status','admin',{'revision':profile['status_revision'],'status':'suspended' if profile['status']=='active' else 'active'})[0]==200)
first=rows('other');second=rows('other',first['next_cursor'])
check('pagination exact and disjoint',len(first['items'])==20 and first['next_cursor'] is not None and len(second['items'])>=4 and not set(r['id'] for r in first['items'])&set(r['id'] for r in second['items']))
check('recipient scopes isolated',not set(r['id'] for r in rows()['items'])&set(r['id'] for r in first['items']))

# Open only this disposable fixture to test real messages. Never attests a target policy.
config=root/'wordpress/wp-config.php';original=config.read_text()
config.write_text(original.replace('<?php\n','<?php\ndefine("FALUSS_PLATFORM_FANS_MESSAGING",true);\ndefine("FALUSS_FANS_MESSAGING_POLICY_ATTESTED",true);\n',1))
cli('get_userdata('+str(session['sessions']['admin-two']['id'])+')->add_cap("moderate_faluss_fans_messages");wp_set_current_user('+str(session['sessions']['admin-two']['id'])+');\\Faluss\\Platform\\Fans\\Messaging\\MessageOperations::prepare();\\Faluss\\Platform\\Fans\\Messaging\\MessageOperations::purge();')
payload={'creator_id':member,'body':'Demande privée de recette.','key':cli('echo wp_generate_uuid4();')}
before=table_count();code,thread,_=api('messages/requests','fan',payload)
check('real request notification',code==200 and rows()['items'][0]['kind']=='message_request' and table_count()==before+1)
check('request replay deduplicated',api('messages/requests','fan',payload)[0]==200 and table_count()==before+1)
thread_id=thread['thread_id'];route='messages/'+thread_id
check('creator accepts real request',api(route+'/decision','member',{'revision':1,'action':'accept'})[0]==200 and rows('fan')['items'][0]['kind']=='message_accepted')
before=table_count();send={'body':'Réponse privée sans copie en notification.','key':cli('echo wp_generate_uuid4();')}
check('real new message delivered to Fan',api(route+'/send','member',send)[0]==200 and rows('fan')['items'][0]['kind']=='message_received' and table_count()==before+1)
check('message replay deduplicated',api(route+'/send','member',send)[0]==200 and table_count()==before+1)
check('notification carries no message body', 'Réponse privée' not in center('fan')[1] and 'Demande privée' not in center()[1])
check('conversation opens through a protected form without exposed object link','name="action" value="open"' in center('fan')[1] and ('thread='+thread_id) not in center('fan')[1])
before=table_count()
cli("global $wpdb;$wpdb->query(\"CREATE TRIGGER fail_notification BEFORE INSERT ON wp_faluss_fans_notifications FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture notification failure'\");")
try:
    history=api(route,'fan')[1]
    check('message and event roll back together',api(route+'/send','member',{**send,'key':cli('echo wp_generate_uuid4();')})[0]==503 and api(route,'fan')[1]==history and table_count()==before)
finally:cli('global $wpdb;$wpdb->query("DROP TRIGGER fail_notification");')
check('no external notification delivery claimed','e-mail envoyé' not in center('fan')[1])
view='/wp-json/faluss-fans/v1/notification-view?role=fan&part=center'
check('private refresh nonce required',http(view,'fan',rest=True,nonce=False)[0]==403)
check('private refresh never accepts recipient',http(view+'&recipient=1','fan',rest=True)[0]==400)
read=json.loads(http(view,'fan',rest=True)[1])
check('private refresh shares native forms and exact count',read['unread']==count('fan') and 'name="action" value="open"' in read['html'])
item=rows('fan')['items'][0]['id'];before=count('fan')
check('GET open never changes stored read state',center('fan',query='?action=open&notification='+str(item))[0]==200 and count('fan')==before)
code,body,headers=center('fan',{'fans_notifications_nonce':nonce('fan'),'notification':str(item),'action':'open'})
check('POST open marks once then 303 to authorized conversation',code==303 and count('fan')==before-1 and ('thread='+thread_id) in headers.get('Location',''))
session['notification_thread']=thread_id
(root/'session.json').write_text(json.dumps(session))
(root/'notifications-checks.json').write_text(json.dumps({'passed':len(passed),'checks':passed},indent=2))
print(json.dumps({'passed':len(passed),'checks':passed},indent=2))
