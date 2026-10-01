"""Run after browser captures; only the disposable recipe database is accepted."""
from pathlib import Path
exec((Path(__file__).with_name('admission-http.py')).read_text().split("panel = ")[0])
thread=session['notification_thread'];mod=session['sessions']['admin-two']['id']
profile=api('creators/'+member+'/private','admin')[1]
cli('global $wpdb;$wpdb->query("ALTER TABLE wp_faluss_fans_notifications ENGINE=MyISAM");')
try:
    check('nontransactional notifications refuse emitting decision',api('creators/'+member+'/status','admin',{'revision':profile['status_revision'],'status':'suspended'})[0]==503)
    check('invalid event store does not commit domain status',api('creators/'+member+'/private','admin')[1]==profile)
finally:cli('global $wpdb;$wpdb->query("ALTER TABLE wp_faluss_fans_notifications ENGINE=InnoDB");')
config=root/'wordpress/wp-config.php';config.write_text(config.read_text().replace('define("FALUSS_PLATFORM_FANS_MESSAGING",true);','define("FALUSS_PLATFORM_FANS_MESSAGING",false);'))
check('closed admission preserves notification center',http('/app/fan/notifications','fan')[0]==200)
check('closed admission preserves real conversation read',api('messages/'+thread,'fan')[0]==200)
cli("global $wpdb;$wpdb->query(\"UPDATE wp_faluss_fans_dm_threads SET last_sent_at=UTC_TIMESTAMP()-INTERVAL 13 MONTH WHERE thread_id='"+thread+"'\");")
check('expired link omitted without read side effect',('thread='+thread) not in http('/app/fan/notifications','fan')[1] and cli('global $wpdb;echo $wpdb->get_var("SELECT COUNT(*) FROM wp_faluss_fans_dm_threads");')=='1')
cli("global $wpdb;$wpdb->query(\"CREATE TRIGGER fail_notification_delete BEFORE DELETE ON wp_faluss_fans_notifications FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture delete failure'\");")
try:
    panel='/app/admin?view=operations';body=http(panel,'admin-two')[1];nonce=re.search(r'name="_wpnonce" value="([^"]+)"',body).group(1)
    check('backoffice purge failure is not shown as success',http(panel,'admin-two',{'_wpnonce':nonce,'operation':'messages_purge','confirm':'yes'})[0]==503)
    check('purge failure is explicit',cli('wp_set_current_user('+str(mod)+');$r=\\Faluss\\Platform\\Fans\\Messaging\\MessageOperations::purge();echo is_wp_error($r)||($r["error"]??"")!==""?"failed":"ok";')=='failed')
    check('failed purge rolls back both stores',cli('global $wpdb;echo $wpdb->get_var("SELECT COUNT(*) FROM wp_faluss_fans_dm_threads");')=='1')
finally:cli('global $wpdb;$wpdb->query("DROP TRIGGER fail_notification_delete");')
check('purge succeeds with admission closed',cli('wp_set_current_user('+str(mod)+');echo is_wp_error(\\Faluss\\Platform\\Fans\\Messaging\\MessageOperations::purge())?"failed":"ok";')=='ok')
check('ordinary messages purged',cli('global $wpdb;echo $wpdb->get_var("SELECT COUNT(*) FROM wp_faluss_fans_dm_messages");')=='0')
check('conversation notifications purged together',cli("global $wpdb;echo $wpdb->get_var(\"SELECT COUNT(*) FROM wp_faluss_fans_notifications WHERE object_id='"+thread+"'\");")=='0')
check('purged conversation links absent',thread not in http('/app/fan/notifications','fan')[1])
check('unrelated moderation notifications preserved','Votre présentation a été refusée' in http('/app/creator/notifications','member')[1])
case=session.get('notification_case')
if case:
    check('report events survive ordinary purge',cli("global $wpdb;echo $wpdb->get_var(\"SELECT COUNT(*) FROM wp_faluss_fans_notifications WHERE object_id='"+case+"'\");")=='4')
    cli("global $wpdb;$wpdb->query(\"UPDATE wp_faluss_fans_dm_reports SET final_at=UTC_TIMESTAMP()-INTERVAL 13 MONTH WHERE case_id='"+case+"'\");")
    check('expired report link absent',('case='+case) not in http('/app/fan/notifications','fan')[1])
    cli('wp_set_current_user('+str(mod)+');\\Faluss\\Platform\\Fans\\Messaging\\MessageOperations::purge();')
    check('report events purged with expired finalized proof',cli("global $wpdb;echo $wpdb->get_var(\"SELECT COUNT(*) FROM wp_faluss_fans_notifications WHERE object_id='"+case+"'\");")=='0' and cli('global $wpdb;echo $wpdb->get_var("SELECT COUNT(*) FROM wp_faluss_fans_dm_reports");')=='0')
check('no PHP fatal in runtime','PHP Fatal' not in (root/'debug.log').read_text())
(root/'notifications-retention-checks.json').write_text(json.dumps({'passed':len(passed),'checks':passed},indent=2))
print(json.dumps({'passed':len(passed),'checks':passed},indent=2))
