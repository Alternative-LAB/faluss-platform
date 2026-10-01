<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Messaging;
use Faluss\Platform\Core\SiteRole;

/** Retention survives closure of admission; no message, identity or evidence is logged here. */
final class MessageRetention
{
    public const HOOK='faluss_fans_messages_retention';
    public const STATUS='faluss_fans_messages_retention_status';
    private static function ownsData(): bool
    {return SiteRole::fromValue(defined('FALUSS_PLATFORM_ROLE')?constant('FALUSS_PLATFORM_ROLE'):null)===SiteRole::Fans && MessageSchema::ready();}
    public static function register(): void
    {
        if(!self::ownsData()) {return;}
        add_action(self::HOOK,[self::class,'run']);
        if(ReportSchema::ready()) {
            add_action('rest_api_init',[ReportRest::class,'routes']);
            \Faluss\Platform\Fans\Moderation\ModerationPanel::register();
        }
        if(!wp_next_scheduled(self::HOOK)) {wp_schedule_event(time()+60,'hourly',self::HOOK);}
    }
    /** @return array<string,mixed>|\WP_Error */
    public static function run(): array|\WP_Error
    {
        if(!self::ownsData()) {return MessagePolicy::error('messages_unavailable');}
        $threads=0;$reports=0;$error='';$more=false;
        for($i=0;$i<10;$i++) {
            $ordinary=MessageStore::purge();
            $proof=ReportSchema::ready()?ReportRetention::purge():(get_option(ReportSchema::OPTION,false)===false?['purged'=>0]:MessagePolicy::error('reports_schema_unavailable'));
            if($ordinary instanceof \WP_Error||$proof instanceof \WP_Error) {
                $error='retention_failed';break;
            }
            $threads+=(int)$ordinary['purged'];$reports+=(int)$proof['purged'];
            $more=$ordinary['purged']===100||$proof['purged']===100;
            if(!$more) {break;}
        }
        $status=['checked_at'=>time(),'ordinary_purged'=>$threads,'reports_purged'=>$reports,'more'=>$more,'error'=>$error];
        $json=(string)wp_json_encode($status);
        update_option(self::STATUS,$json,false);
        return get_option(self::STATUS) === $json ? $status : MessagePolicy::error('message_retention_status_failed');
    }
}
