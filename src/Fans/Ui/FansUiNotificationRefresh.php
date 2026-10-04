<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Ui;

use Faluss\Platform\Fans\Notifications\NotificationService;
use Faluss\Platform\Fans\Profiles\CreatorProfileRest;
use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\Fans\Profiles\EditorialRest;

/** Private presentation adapter: no recipient, object identifier or destination supplied by the caller. */
final class FansUiNotificationRefresh
{
    public static function routes(): void
    {
        register_rest_route('faluss-fans/v1','/notification-view',[
            'methods'=>'GET','callback'=>[self::class,'read'],
            // Authorize inside read so denied responses also carry the private no-store headers.
            'permission_callback'=>'__return_true',
        ]);
    }
    public static function read(\WP_REST_Request $r): \WP_REST_Response
    {
        if(!FansUiModule::available()||!CreatorProfileRest::memberPermission($r)){return EditorialRest::response(NotificationService::error(403));}
        $role=$r->get_param('role');$part=$r->get_param('part');$cursor=$r->get_param('cursor')??'';$filter=$r->get_param('filter')??'all';
        if(array_diff(array_keys($r->get_params()),['role','part','cursor','filter','rest_route'])!==[]
            ||!in_array($role,['fan','creator'],true)||!in_array($part,['count','center'],true)
            ||!is_string($cursor)||!is_string($filter)) {return EditorialRest::response(NotificationService::error(400));}
        if($role==='creator'&&CreatorProfileService::own()===null){return EditorialRest::response(NotificationService::error(404));}
        if($part==='count'){$n=NotificationService::count();return EditorialRest::response($n===null?NotificationService::error():['unread'=>$n]);}
        $data=NotificationService::listing($cursor,$filter);if($data instanceof \WP_Error){return EditorialRest::response($data);}
        ob_start();FansUiNotifications::rows($data['items'],$role);$html=(string)ob_get_clean();
        ob_start();FansUiNotifications::pagination($filter,$data['next_cursor'],$role);$pagination=(string)ob_get_clean();
        return EditorialRest::response(['unread'=>$data['unread'],'html'=>$html,'pagination'=>$pagination]);
    }
}
