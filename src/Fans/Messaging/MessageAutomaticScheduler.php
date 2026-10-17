<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Messaging;

use Faluss\Platform\Core\SiteRole;

/** Separate bounded WordPress job. The existing hourly retention event is unchanged. */
final class MessageAutomaticScheduler
{
    public const HOOK='faluss_fans_message_automations';
    public const SCHEDULE='faluss_fans_messages_minute';
    public const STATUS='faluss_fans_message_automation_status';

    public static function register(): void
    {
        if(SiteRole::fromValue(defined('FALUSS_PLATFORM_ROLE')?constant('FALUSS_PLATFORM_ROLE'):null)!==SiteRole::Fans||!MessageAutomationSchema::ready()){return;}
        add_filter('cron_schedules',[self::class,'schedules']);
        add_action(self::HOOK,[self::class,'run']);
    }
    /** @param array<string,array{interval:int,display:string}> $schedules
     * @return array<string,array{interval:int,display:string}> */
    public static function schedules(array $schedules): array
    { $schedules[self::SCHEDULE]=['interval'=>60,'display'=>'Messages automatiques Fans'];return $schedules; }

    public static function prepared(): bool
    {
        $event=wp_get_scheduled_event(self::HOOK);
        return $event!==false&&$event->schedule===self::SCHEDULE&&$event->interval===60&&$event->args===[];
    }
    public static function prepare(): bool
    {
        self::register();$event=wp_get_scheduled_event(self::HOOK);
        if($event===false&&wp_schedule_event(time()+60,self::SCHEDULE,self::HOOK,[],true)!==true){return false;}
        return self::prepared();
    }
    public static function run(): mixed
    {
        $result=MessageAutomaticDelivery::run();
        $status=['checked_at'=>time(),'processed'=>is_array($result)?(int)($result['processed']??0):0,
            'error'=>$result instanceof \WP_Error?'automatic_delivery_failed':''];
        $json=(string)wp_json_encode($status);update_option(self::STATUS,$json,false);
        return get_option(self::STATUS)===$json?$result:MessagePolicy::error('message_automation_status_failed');
    }
}
