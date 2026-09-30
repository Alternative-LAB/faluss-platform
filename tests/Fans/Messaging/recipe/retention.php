<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/Profiles/recipe/bootstrap.php';
use Faluss\Platform\Fans\Messaging\MessageModule;
use Faluss\Platform\Fans\Messaging\MessageService;
use Faluss\Platform\Fans\Messaging\MessageReading;
use Faluss\Platform\Fans\Messaging\MessageReports;
use Faluss\Platform\Fans\Messaging\ReportModeration;
use Faluss\Platform\Fans\Messaging\MessageRetention;
function wp_json_encode(mixed $v): string {return json_encode($v);}
function check(string $label,bool $ok): void {if(!$ok){throw new RuntimeException($label);}echo "PASS $label\n";}
$thread=$argv[1];$case=$argv[2];$fixtureUser=18;
check('admission explicitly closed in isolated fixture',!MessageModule::available()&&MessageModule::privateAccessAvailable());
check('closed admission preserves history but prevents send',is_array(MessageReading::conversation($thread))&&!MessageReading::conversation($thread)['can_send']&&MessageService::send($thread,'Denied',wp_generate_uuid4()) instanceof WP_Error);
check('closed admission preserves case statuses',count(MessageReports::own()['items'])===1);
$fixtureUser=42;
check('closed admission preserves moderator access',is_array(ReportModeration::inspect($case)));
check('moderator examines appeal with admission closed',is_array(ReportModeration::decide($case,3,'no_action','Recours réexaminé')));
check('moderator finalizes with admission closed',is_array(ReportModeration::decide($case,4,'finalize','Recours terminé et notifié',true)));
$wpdb->query('UPDATE test_faluss_fans_dm_threads SET last_sent_at=UTC_TIMESTAMP()-INTERVAL 13 MONTH');
$wpdb->query('UPDATE test_faluss_fans_dm_reports SET final_at=UTC_TIMESTAMP()-INTERVAL 13 MONTH');
$fixtureUser=0;MessageRetention::run();$status=json_decode(get_option(MessageRetention::STATUS),true);
check('cron purges without admission or session',$status['ordinary_purged']===1&&$status['reports_purged']===1&&$status['error']==='');
check('cron diagnostic contains no private content',array_keys($status)===['checked_at','ordinary_purged','reports_purged','more','error']);
MessageRetention::run();$status=json_decode(get_option(MessageRetention::STATUS),true);
check('cron retry idempotent',$status['ordinary_purged']===0&&$status['reports_purged']===0);
