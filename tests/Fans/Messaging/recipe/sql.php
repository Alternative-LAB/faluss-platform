<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/Profiles/recipe/bootstrap.php';
use Faluss\Platform\Fans\Messaging\MessageSchema as Schema;
use Faluss\Platform\Fans\Messaging\MessageService as Service;
use Faluss\Platform\Fans\Messaging\MessageReading as Reading;
use Faluss\Platform\Fans\Messaging\MessageStore as Store;
function check(string $label,bool $ok): void { if(!$ok){throw new RuntimeException($label.' SQL='.$GLOBALS['wpdb']->last_error);} echo "PASS $label\n"; }
function code(mixed $r): int { return $r instanceof WP_Error?(int)$r->data['status']:200; }
const CREATOR='11111111-1111-4111-8111-111111111111';
check('message schema explicit and idempotent',Schema::installOrVerify()&&Schema::installOrVerify());
$fixtureUser=18; $key=wp_generate_uuid4();
$r=Service::request(CREATOR,'Demande de test SQL',$key);
check('linked fan real pending request',is_array($r)&&$r['state']==='pending'&&!$r['direct_opening_available']);
$id=$r['thread_id'];
check('request replay does not duplicate',Service::request(CREATOR,'Demande de test SQL',$key)['message_id']===$r['message_id']);
check('request replay changed text conflicts',code(Service::request(CREATOR,'Autre',$key))===409);
check('fan cannot accept own request',code(Service::decide($id,1,'accept'))===403);
check('pending cannot send',code(Service::send($id,'Suite',wp_generate_uuid4()))===403);
foreach([0,19,1] as $fixtureUser) {check('unlinked or privileged member denied '.$fixtureUser,code(Reading::conversation($id))===403);}
$fixtureUser=17;
check('creator reads initial text',Reading::conversation($id)['messages'][0]['body']==='Demande de test SQL');
check('creator accepts',code(Service::decide($id,1,'accept'))===200);
check('stale decision denied',code(Service::decide($id,1,'refuse'))===409);
$key=wp_generate_uuid4();$r=Service::send($id,'Réponse du créateur',$key);
check('accepted bilateral send',code($r)===200);
$before=$wpdb->get_row("SELECT revision,last_seq,last_sent_at FROM test_faluss_fans_dm_threads WHERE thread_id='$id'");
check('send replay same ID',Service::send($id,'Réponse du créateur',$key)['message_id']===$r['message_id']);
check('replay does not extend retention or revision',$before===$wpdb->get_row("SELECT revision,last_seq,last_sent_at FROM test_faluss_fans_dm_threads WHERE thread_id='$id'"));
$wpdb->query("CREATE TRIGGER fail_message BEFORE INSERT ON test_faluss_fans_dm_messages FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated message failure'");
check('SQL failure explicit',code(Service::send($id,'Must rollback',wp_generate_uuid4()))===503);
$wpdb->query('DROP TRIGGER fail_message');
check('SQL rollback preserves thread',$before===$wpdb->get_row("SELECT revision,last_seq,last_sent_at FROM test_faluss_fans_dm_threads WHERE thread_id='$id'"));
check('block creator',code(Service::decide($id,3,'block'))===200);
$fixtureUser=18;
check('block denies peer send',code(Service::send($id,'Denied',wp_generate_uuid4()))===403);
check('peer unblock cannot clear creator block',code(Service::decide($id,4,'unblock'))===200&&!Reading::conversation($id)['can_send']);
$fixtureUser=17;$block=Reading::blocked()['items'][0]['block_id'];
$wpdb->query("UPDATE test_faluss_fans_dm_threads SET last_sent_at=UTC_TIMESTAMP()-INTERVAL 12 MONTH WHERE thread_id='$id'");
check('expiry returns 410',code(Reading::conversation($id))===410);
check('expiry committed erasure',(int)$wpdb->get_var('SELECT COUNT(*) FROM test_faluss_fans_dm_messages')===0&&(int)$wpdb->get_var('SELECT COUNT(*) FROM test_faluss_fans_dm_threads')===0);
$fixtureUser=18;check('block survives content purge',code(Service::request(CREATOR,'New',wp_generate_uuid4()))===403);
$fixtureUser=17;check('block independently reversible after purge',code(Reading::unblock($block))===200&&Reading::blocked()['items']===[]);
check('calendar twelve months clamps leap day',$wpdb->get_var("SELECT DATE_ADD('2024-02-29 10:11:12',INTERVAL 12 MONTH)")==='2025-02-28 10:11:12');
$fixtureUser=18;$r=Service::request(CREATOR,'À refuser',wp_generate_uuid4());$id=$r['thread_id'];$fixtureUser=17;
check('creator refusal',code(Service::decide($id,1,'refuse'))===200);
check('refusal cannot become acceptance',code(Service::decide($id,2,'accept'))===403);
$fixtureUser=18;check('refusal prevents repeated requests',code(Service::request(CREATOR,'Encore',wp_generate_uuid4()))===409);
$wpdb->query("UPDATE test_faluss_fans_dm_threads SET last_sent_at=UTC_TIMESTAMP()-INTERVAL 13 MONTH");
check('batch retention erases ordinary content',Store::purge()['purged']===1);
// Leave clean tables for HTTP tests; stored blocking preferences intentionally remain reversible.
check('empty inbox after purge',Reading::inbox()['items']===[]);
// Independent recipients exercise request throttling, without inventing subscription authority.
for($i=20;$i<=22;$i++) {
    $uuid=sprintf('%08d-1111-4111-8111-111111111111',$i);
    $wpdb->query($wpdb->prepare('INSERT INTO test_faluss_fans_identity_links (wp_user_id,faluss_id,created_at,last_proved_at) VALUES(%d,%s,UTC_TIMESTAMP(),UTC_TIMESTAMP())',$i,$uuid));
    $wpdb->query($wpdb->prepare('INSERT INTO test_faluss_fans_creator_profiles(creator_id,wp_user_id,category,status,created_at,updated_at) VALUES(%s,%d,%s,%s,UTC_TIMESTAMP(),UTC_TIMESTAMP())',$uuid,$i,'arts','active'));
    check('hourly request admission '.$i,code(Service::request($uuid,'Request quota',wp_generate_uuid4()))===($i===22?429:200));
}
$wpdb->query('UPDATE test_faluss_fans_dm_threads SET created_at=UTC_TIMESTAMP()-INTERVAL 2 HOUR');
check('hour quota expires',code(Service::request('00000022-1111-4111-8111-111111111111','After hour',wp_generate_uuid4()))===200);
$wpdb->query('UPDATE test_faluss_fans_dm_threads SET last_sent_at=UTC_TIMESTAMP()-INTERVAL 13 MONTH');
$wpdb->query("CREATE TRIGGER fail_purge BEFORE DELETE ON test_faluss_fans_dm_threads FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated purge failure'");
check('failed purge returns failure',code(Store::purge())===503);
$wpdb->query('DROP TRIGGER fail_purge');
check('failed purge rolls back message deletion',(int)$wpdb->get_var('SELECT COUNT(*) FROM test_faluss_fans_dm_messages')===3);
check('purge retry succeeds',Store::purge()['purged']===3);
// Remove only these disposable accounts; the image recipe owns its own account 20.
$wpdb->query('DELETE FROM test_faluss_fans_creator_profiles WHERE wp_user_id BETWEEN 20 AND 22');
$wpdb->query('DELETE FROM test_faluss_fans_identity_links WHERE wp_user_id BETWEEN 20 AND 22');
