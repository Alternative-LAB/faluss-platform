"""Recipient-only report decision events on the disposable WordPress fixture."""
from pathlib import Path
exec((Path(__file__).with_name('admission-http.py')).read_text().split("panel = ")[0])
thread=session['notification_thread'];history=api('messages/'+thread,'fan')[1]
message=next(row for row in history['messages'] if not row['mine'])
code,case,_=api('messages/'+thread+'/report','fan',{'message_id':message['message_id'],'reason':'other'})
check('report uses actual received message',code==200)
case_id=case['case_id'];route='message-reports/'+case_id
decision={'revision':1,'action':'no_action','reason':'INTERNAL_CANARY_MODERATOR_ONLY','recourse_complete':False,'days':0}
check('moderator decision committed',api(route+'/decision','admin-two',decision)[0]==200)
check('exactly two recipient events',cli("global $wpdb;echo $wpdb->get_var(\"SELECT COUNT(*) FROM wp_faluss_fans_notifications WHERE object_id='"+case_id+"'\");")=='2')
check('stale report decision no extra event',api(route+'/decision','admin-two',decision)[0]==409 and cli("global $wpdb;echo $wpdb->get_var(\"SELECT COUNT(*) FROM wp_faluss_fans_notifications WHERE object_id='"+case_id+"'\");")=='2')
for who,role in [('member','creator'),('fan','fan')]:
    body=http('/app/'+role+'/notifications',who)[1]
    check('report event without internal reason '+who,'Une décision est disponible' in body and 'INTERNAL_CANARY' not in body and ('case='+case_id) in body)
    detail=http('/app/'+role+'/messages?section=reports&case='+case_id,who)[1]
    check('participant opens authorized status only '+who,'absence de mesure' in detail and 'INTERNAL_CANARY' not in detail and message['body'] not in detail)
check('other member cannot resolve case',cli('wp_set_current_user('+str(session['sessions']['other']['id'])+');echo is_wp_error(\\Faluss\\Platform\\Fans\\Messaging\\MessageReports::ownItem("'+case_id+'"))?"denied":"leak";')=='denied')
check('internal review emits no notification',api(route+'/decision','admin-two',{**decision,'revision':2,'action':'review'})[0]==200 and cli("global $wpdb;echo $wpdb->get_var(\"SELECT COUNT(*) FROM wp_faluss_fans_notifications WHERE object_id='"+case_id+"'\");")=='2')
check('finality requires real explicit parameter',api(route+'/decision','admin-two',{**decision,'revision':3,'action':'finalize'})[0]==409)
check('fixture explicit finality produces both events',api(route+'/decision','admin-two',{**decision,'revision':3,'action':'finalize','recourse_complete':True})[0]==200 and cli("global $wpdb;echo $wpdb->get_var(\"SELECT COUNT(*) FROM wp_faluss_fans_notifications WHERE object_id='"+case_id+"'\");")=='4')
session['notification_case']=case_id;(root/'session.json').write_text(json.dumps(session))
(root/'notifications-report-checks.json').write_text(json.dumps({'passed':len(passed),'checks':passed},indent=2))
print(json.dumps({'passed':len(passed),'checks':passed},indent=2))
