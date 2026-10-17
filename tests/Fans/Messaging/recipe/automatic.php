<?php
declare(strict_types=1);
// Included by the existing messaging SQL recipe; actual InnoDB, not a WordPress installation.
use Faluss\Platform\Fans\Messaging\{MessageAutomationSchema,MessageAutomaticSettings as AutoSettings,MessageAutomaticDelivery as AutoDelivery,MessageReading as AutoReading,MessageService as AutoMessages,MessageStore as AutoStore};
use Faluss\Platform\Fans\Followers\{FollowersSchema,FollowersService};

function autoFan(int $id): void {
    global $wpdb;
    check('automatic real local member '.$id,$wpdb->query($wpdb->prepare('INSERT INTO test_users(ID) VALUES(%d)',$id))===1
        &&$wpdb->query($wpdb->prepare('INSERT INTO test_usermeta(user_id,meta_key,meta_value) VALUES(%d,%s,%s)',$id,'test_capabilities',serialize(['subscriber'=>true])))===1
        &&$wpdb->query($wpdb->prepare('INSERT INTO test_faluss_fans_identity_links(wp_user_id,faluss_id,created_at,last_proved_at) VALUES(%d,%s,UTC_TIMESTAMP(),UTC_TIMESTAMP())',$id,wp_generate_uuid4()))===1);
}
function autoEvent(int $fan): array {
    global $wpdb;
    $identity=$wpdb->get_var($wpdb->prepare('SELECT faluss_id FROM test_faluss_fans_identity_links WHERE wp_user_id=%d',$fan));
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM test_faluss_fans_dm_automatic_events WHERE event_key=%s',hash('sha256','welcome'."\0".CREATOR."\0".$identity)),'ARRAY_A')??[];
}
function autoRun(): mixed {$GLOBALS['fixtureUser']=0;return AutoDelivery::run();}

check('automatic follower schema explicit',FollowersSchema::installOrVerify());
$fixtureUser=18;check('preexisting follow before automation installation',code(FollowersService::follow(CREATOR))===200);
check('automatic metadata explicit and idempotent',MessageAutomationSchema::installOrVerify()&&MessageAutomationSchema::installOrVerify());
$fixtureUser=17;$defaults=AutoSettings::own();
check('two independent disabled configurations',$defaults['welcome']['enabled']===false&&$defaults['thanks']['enabled']===false);
check('attachments unavailable in both configurations',!$defaults['welcome_attachment_available']&&!$defaults['thanks_attachment_available']);
foreach([0,1,18,19] as $fixtureUser){check('automatic text owner only '.$fixtureUser,code(AutoSettings::save('welcome','Texte',true,0))===403);}
$fixtureUser=17;
foreach([['unknown','Texte',true,0],['welcome','',true,0],['welcome','<img>',true,0],['welcome',str_repeat('x',2001),true,0],['welcome','Texte',1,0],['welcome','Texte',true,'0']] as $args){check('automatic strict input rejected',code(AutoSettings::save(...$args))===400);}
check('welcome explicitly enabled',code(AutoSettings::save('welcome','Bienvenue A de recette.',true,0))===200);
check('thanks independently prepared',code(AutoSettings::save('thanks','Merci de recette.',false,0))===200);
check('unconnected economic source cannot be enabled',code(AutoSettings::save('thanks','Merci de recette.',true,1))===503&&!AutoSettings::own()['thanks_delivery_available']);
check('preview no mutation',AutoSettings::preview('welcome','Aperçu non enregistré.')['preview']&&AutoSettings::own()['welcome']['revision']===1);
foreach(['welcome','thanks'] as $kind){
    $r=new WP_REST_Request('POST','/faluss-fans/v1/messages/automatic/'.$kind);
    $r->set_header('X-WP-Nonce','fixture-wp_rest');$r->set_body(json_encode(['body'=>'Texte','enabled'=>true,'revision'=>1,'image_id'=>wp_generate_uuid4()]));
    check('attachment field refused for '.$kind,rest_do_request($r)->get_status()===400);
    $r->set_body(json_encode(['body'=>'Texte','enabled'=>true,'revision'=>1]));$r->set_file_params(['image'=>['tmp_name'=>'unused']]);
    check('file payload refused for '.$kind,rest_do_request($r)->get_status()===400);
}
$fixtureUser=18;check('existing follower retry sends no retroactive welcome',code(FollowersService::follow(CREATOR))===200&&autoEvent(18)===[]);
check('existing follower can leave without receiving a welcome',code(FollowersService::unfollow(CREATOR))===200);
check('existing follower rejoin cannot turn into retroactive welcome',code(FollowersService::follow(CREATOR))===200&&autoEvent(18)['state']==='suppressed');
check('no retroactive job',autoRun()['processed']===0);

foreach(range(60,67) as $id){autoFan($id);}
$fixtureUser=60;check('actual follow queues welcome',code(FollowersService::follow(CREATOR))===200&&autoEvent(60)['state']==='pending');
$first=autoEvent(60);check('follow retry preserves one selected version',code(FollowersService::follow(CREATOR))===200&&autoEvent(60)===$first);
$fixtureUser=17;check('creator can edit future welcome',code(AutoSettings::save('welcome','Bienvenue B de recette.',true,1))===200);
check('actual dispatcher sends one welcome',autoRun()['processed']===1);
$sent=autoEvent(60);check('sent snapshot wiped but business outcome retained',$sent['state']==='sent'&&$sent['body']===''&&(int)$sent['config_revision']===1);
check('terminal welcome does not retain the recipient identity or delivery key',$sent['fan_identity']===''&&$sent['delivery_key']==='');
$fixtureUser=60;$conversation=AutoReading::conversation($sent['thread_id']);
check('frozen welcome delivered in the existing private inbox',$conversation['messages'][0]['body']==='Bienvenue A de recette.'&&$conversation['state']==='automatic'&&!$conversation['can_send']&&$conversation['can_start']);
check('automatic welcome does not grant general send permission',code(AutoMessages::send($sent['thread_id'],'Non autorisé',wp_generate_uuid4()))===403);
$fixtureUser=17;check('creator cannot manually solicit through automatic-only thread',code(AutoMessages::send($sent['thread_id'],'Non autorisé',wp_generate_uuid4()))===403);
$fixtureUser=60;$request=AutoMessages::request(CREATOR,'Vraie demande après bienvenue.',wp_generate_uuid4());
check('fan can explicitly request exchange on same thread',is_array($request)&&$request['thread_id']===$sent['thread_id']&&$request['state']==='pending');
$fixtureUser=17;check('restricted reply still needs explicit acceptance',code(AutoMessages::send($sent['thread_id'],'Trop tôt',wp_generate_uuid4()))===403);
check('normal creator acceptance opens the same existing thread',code(AutoMessages::decide($sent['thread_id'],2,'accept'))===200);

$fixtureUser=61;check('second fan welcome queued',code(FollowersService::follow(CREATOR))===200);
$fixtureUser=17;check('disable cancels pending without changing thanks',code(AutoSettings::save('welcome','Bienvenue B de recette.',false,2))===200&&autoEvent(61)['state']==='cancelled'&&autoEvent(61)['body']===''&&AutoSettings::own()['thanks']['revision']===1);
check('reenable affects future events only',code(AutoSettings::save('welcome','Bienvenue B de recette.',true,3))===200);
check('cancelled work never resumes',autoRun()['processed']===0);
$fixtureUser=61;check('leave and rejoin cannot repeat welcome',code(FollowersService::unfollow(CREATOR))===200&&code(FollowersService::follow(CREATOR))===200&&autoEvent(61)['state']==='cancelled');

$fixtureUser=62;check('durable event for failure recovery',code(FollowersService::follow(CREATOR))===200);$pending=autoEvent(62);
$count=(int)$wpdb->get_var('SELECT COUNT(*) FROM test_faluss_fans_dm_messages');
$wpdb->query("CREATE TRIGGER fail_auto_outcome BEFORE UPDATE ON test_faluss_fans_dm_automatic_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated automatic outcome failure'");
check('failure after message insert rolls back entire delivery',code(autoRun())===503&&(int)$wpdb->get_var('SELECT COUNT(*) FROM test_faluss_fans_dm_messages')===$count&&autoEvent(62)===$pending);
$wpdb->query('DROP TRIGGER fail_auto_outcome');
AutoDelivery::run(); // Deliberately discard the successful response, then retry the job.
check('lost response retry cannot duplicate sent message',autoRun()['processed']===0&&autoEvent(62)['state']==='sent'&&(int)$wpdb->get_var('SELECT COUNT(*) FROM test_faluss_fans_dm_messages')===$count+1);

$fixtureUser=63;$blocked=AutoMessages::request(CREATOR,'Demande à bloquer.',wp_generate_uuid4());
$fixtureUser=17;check('real block before an automatic event',code(AutoMessages::decide($blocked['thread_id'],1,'block'))===200);
$fixtureUser=63;check('follow itself remains free after messaging block',code(FollowersService::follow(CREATOR))===200);
check('existing block forbids automatic delivery',autoRun()['processed']===1&&autoEvent(63)['state']==='cancelled');

$fixtureUser=64;check('welcome queued before unfollow',code(FollowersService::follow(CREATOR))===200);
check('unfollow revokes pending text immediately',code(FollowersService::unfollow(CREATOR))===200&&autoEvent(64)['state']==='cancelled'&&autoEvent(64)['body']==='');
$fixtureUser=65;check('one event for concurrent dispatchers',code(FollowersService::follow(CREATOR))===200);
$processes=[];$channels=[];
foreach([0,1] as $n){$processes[$n]=proc_open([PHP_BINARY,__DIR__.'/automatic-worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$channels[$n]);fclose($channels[$n][0]);}
$processed=0;
foreach([0,1] as $n){$out=stream_get_contents($channels[$n][1]);$err=stream_get_contents($channels[$n][2]);fclose($channels[$n][1]);fclose($channels[$n][2]);check('concurrent dispatcher succeeds '.$n,proc_close($processes[$n])===0&&$err==='');$processed+=(int)json_decode($out,true)['processed'];}
check('two workers commit one ordinary message',$processed===1&&autoEvent(65)['state']==='sent');

$fixtureUser=66;check('welcome waits for current creator permission',code(FollowersService::follow(CREATOR))===200);
$fixtureUser=42;check('real creator suspension before delivery',code(\Faluss\Platform\Fans\Profiles\CreatorProfileService::setStatus(CREATOR,'suspended'))===200);
check('suspension revokes queued automatic delivery',autoRun()['processed']===1&&autoEvent(66)['state']==='cancelled');
$fixtureUser=66;check('suspended creator does not prevent free unfollow',code(FollowersService::unfollow(CREATOR))===200);
$fixtureUser=42;check('fixture creator readmitted explicitly',code(\Faluss\Platform\Fans\Profiles\CreatorProfileService::setStatus(CREATOR,'active'))===200);
$fixtureUser=17;
$hourly=(int)$wpdb->get_var('SELECT COUNT(*) FROM test_faluss_fans_dm_messages WHERE sender_id=17 AND created_at>UTC_TIMESTAMP()-INTERVAL 1 HOUR');
for($n=$hourly;$n<30;$n++){check('actual creator send fills shared quota '.$n,code(AutoMessages::send($sent['thread_id'],'Message de quota de recette.',wp_generate_uuid4()))===200);}
$fixtureUser=67;check('send quota never charges or refuses the free follow',code(FollowersService::follow(CREATOR))===200);
check('automatic send respects shared creator quota',autoRun()['processed']===1&&autoEvent(67)['state']==='retry'&&autoEvent(67)['body']!=='');
check('backoff prevents immediate duplicate retry',autoRun()['processed']===0);
$wpdb->query('UPDATE test_faluss_fans_dm_messages SET created_at=UTC_TIMESTAMP()-INTERVAL 2 HOUR WHERE sender_id=17');
$wpdb->query($wpdb->prepare('UPDATE test_faluss_fans_dm_automatic_events SET next_attempt_at=UTC_TIMESTAMP() WHERE event_key=%s',autoEvent(67)['event_key']));
check('quota recovery sends the same pending welcome',autoRun()['processed']===1&&autoEvent(67)['state']==='sent');

// Retention owns ordinary content and contact timestamps; minimal event tombstones remain.
$wpdb->query('UPDATE test_faluss_fans_dm_threads SET last_sent_at=UTC_TIMESTAMP()-INTERVAL 13 MONTH');
check('ordinary purge covers automated messages and manual replies',is_array(AutoStore::purge())&&(int)$wpdb->get_var('SELECT COUNT(*) FROM test_faluss_fans_dm_messages')===0&&(int)$wpdb->get_var('SELECT COUNT(*) FROM test_faluss_fans_dm_automatic_contacts')===0);
$fixtureUser=60;check('ordinary purge never resets lifetime welcome uniqueness',code(FollowersService::unfollow(CREATOR))===200&&code(FollowersService::follow(CREATOR))===200&&autoEvent(60)['state']==='sent');
$wpdb->query('DELETE FROM test_faluss_fans_dm_blocks');
echo "PASS automatic text snapshot, once-only follow, permissions, cancellation, rollback and concurrent ordinary delivery\n";
