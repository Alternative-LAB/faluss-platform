<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Ui;

use Faluss\Platform\Fans\Messaging\MessageReading;
use Faluss\Platform\Fans\Messaging\MessageRest;
use Faluss\Platform\Fans\Messaging\MessagePolicy;
use Faluss\Platform\Fans\Profiles\EditorialRest;
use Faluss\Platform\Fans\Profiles\CreatorProfileService;

/** Private, bounded rendering adapter. Reads and forms retain their existing service gates. */
final class FansUiMessageRefresh
{
    public static function routes(): void
    {
        register_rest_route('faluss-fans/v1','/message-view',[
            'methods'=>'GET','callback'=>[self::class,'read'],
            'permission_callback'=>static fn(\WP_REST_Request $r):bool=>FansUiModule::available()&&MessageRest::member($r),
        ]);
    }
    public static function read(\WP_REST_Request $r): \WP_REST_Response
    {
        $role=$r->get_param('role');$part=$r->get_param('part');
        $after=$r->get_param('after')??'0';
        if(!in_array($role,['fan','creator'],true)||!in_array($part,['list','chat'],true)
            ||!is_string($after)||preg_match('/^(0|[1-9][0-9]{0,8})$/D',$after)!==1) {
            return EditorialRest::response(MessagePolicy::error('invalid_message_cursor',400));
        }
        if($role==='creator'&&CreatorProfileService::own()===null) {return EditorialRest::response(MessagePolicy::error('creator_not_found',404));}
        $data=$part==='list'?MessageReading::inbox($r->get_param('cursor')??''):MessageReading::conversation($r->get_param('thread'),(int)$after);
        if($data instanceof \WP_Error) {return EditorialRest::response($data);}
        $v=new FansUiMessages();$v->available=true;$v->role=$role;$v->key=wp_generate_uuid4();
        $response=new \WP_REST_Response($data);
        ob_start();
        if($part==='list') {$v->listing=$response;FansUiMessageView::listing($v);}
        else {$v->conversation=$response;$v->threadId=$data['thread_id'];FansUiMessageView::conversation($v);}
        $html=(string)ob_get_clean();
        return EditorialRest::response($part==='list'
            ?['html'=>$html,'next_cursor'=>$data['next_cursor']]
            :['html'=>$html,'next_after'=>$data['next_after'],'revision'=>$data['revision'],
                'history_revision'=>$data['revision']-$data['last_sequence'],'can_send'=>$data['can_send']]);
    }
}
