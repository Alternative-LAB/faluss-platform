<?php
declare(strict_types=1);
// Same physical fixture, real services/locks; never production or an environment preparer.
use Faluss\Platform\Fans\Messaging\MessageContact as Contact;
use Faluss\Platform\Fans\Messaging\MessageService as ContactMessages;
use Faluss\Platform\Fans\Messaging\MessageStore as ContactStore;

$fixtureUser=17;
check('creator contact defaults open without stored row',Contact::own()===['mode'=>'open','revision'=>0]);
foreach(['open ',false,[],null] as $mode) {check('invalid contact mode rejected',code(Contact::save($mode,0))===400);}
foreach([-1,'0',true,null] as $revision) {check('invalid contact revision rejected',code(Contact::save('request',$revision))===400);}
foreach([0,1,18,19] as $fixtureUser) {check('contact preference owner only '.$fixtureUser,code(Contact::save('request',0))===403);}
$fixtureUser=18;$openKey=wp_generate_uuid4();$opened=ContactMessages::request(CREATOR,'Contact ouvert de recette',$openKey);
check('admissible fan starts direct conversation by default',is_array($opened)&&$opened['state']==='open'&&$opened['direct_opening_available']);
$contactThread=$opened['thread_id'];$fixtureUser=17;
check('creator explicitly restricts new conversations',Contact::save('request',0)===['mode'=>'request','revision'=>1]);
check('restriction never closes accepted conversation',code(ContactMessages::send($contactThread,'Toujours accessible',wp_generate_uuid4()))===200);
check('stale contact edit conflicts',code(Contact::save('open',0))===409);
$wpdb->query("UPDATE test_faluss_fans_dm_threads SET last_sent_at=UTC_TIMESTAMP()-INTERVAL 13 MONTH");
check('clean only disposable direct conversation',ContactStore::purge()['purged']===1);
$fixtureUser=18;$pending=ContactMessages::request(CREATOR,'Demande explicite de recette',wp_generate_uuid4());$contactThread=$pending['thread_id'];
check('restricted admission remains pending',$pending['state']==='pending');
$fixtureUser=17;check('switch back to open',Contact::save('open',1)===['mode'=>'open','revision'=>2]);
check('open mode does not accept old pending',code(ContactMessages::send($contactThread,'Ne doit pas partir',wp_generate_uuid4()))===403);
check('old pending still explicitly rejectable',code(ContactMessages::decide($contactThread,1,'refuse'))===200);
$fixtureUser=18;check('open mode never reopens refusal',code(ContactMessages::request(CREATOR,'Ne doit pas rouvrir',wp_generate_uuid4()))===409);
$fixtureUser=17;check('block remains possible',code(ContactMessages::decide($contactThread,2,'block'))===200);
$fixtureUser=18;check('open mode cannot bypass creator block',code(ContactMessages::request(CREATOR,'Ne doit pas livrer',wp_generate_uuid4()))===403);
$fixtureUser=17;
$wpdb->query("CREATE TRIGGER fail_contact BEFORE UPDATE ON test_faluss_fans_dm_contact FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated contact failure'");
check('failed edit returns error',code(Contact::save('request',2))===503);
$wpdb->query('DROP TRIGGER fail_contact');
check('failed edit rolls back mode and revision',Contact::own()===['mode'=>'open','revision'=>2]);
$wpdb->query("UPDATE test_faluss_fans_creator_profiles SET status='suspended' WHERE creator_id='".CREATOR."'");
check('suspended creator cannot change mode',code(Contact::save('request',2))===403);
$wpdb->query("UPDATE test_faluss_fans_creator_profiles SET status='active' WHERE creator_id='".CREATOR."'");
$wpdb->query("UPDATE test_faluss_fans_dm_threads SET last_sent_at=UTC_TIMESTAMP()-INTERVAL 13 MONTH");
check('contact fixture expiry erases content',ContactStore::purge()['purged']===1);
$wpdb->query('DELETE FROM test_faluss_fans_dm_blocks');
check('historical pending scenarios explicitly opt into requests',Contact::save('request',2)===['mode'=>'request','revision'=>3]);
