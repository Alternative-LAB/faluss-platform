<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Messaging;

use Faluss\Platform\Fans\Profiles\CreatorProfileRest;
use Faluss\Platform\Fans\Profiles\EditorialRest;

/** Registered only when the complete moderation and retention integration is available. */
final class MessageRest
{
    public static function routes(): void
    {
        $id='/messages/(?P<thread_id>[0-9a-f-]{36})';
        foreach ([['/messages','GET','inbox'],['/messages/requests','POST','request'],[$id,'GET','conversation'],
            [$id.'/send','POST','send'],[$id.'/decision','POST','decide'],['/messages/blocks','GET','blocked'],
            ['/messages/blocks/(?P<block_id>[0-9a-f-]{36})/unblock','POST','unblock']] as [$route,$method,$callback]) {
            register_rest_route(CreatorProfileRest::NAMESPACE,$route,['methods'=>$method,'callback'=>[self::class,$callback],'permission_callback'=>[self::class,'member']]);
        }
    }
    public static function member(\WP_REST_Request $r): bool { return MessageModule::available() && CreatorProfileRest::memberPermission($r); }
    public static function inbox(\WP_REST_Request $r): \WP_REST_Response { return EditorialRest::response(MessageReading::inbox($r->get_param('cursor')??'')); }
    public static function blocked(\WP_REST_Request $r): \WP_REST_Response { return EditorialRest::response(MessageReading::blocked($r->get_param('cursor')??'')); }
    public static function conversation(\WP_REST_Request $r): \WP_REST_Response
    {
        $after=$r->get_param('after')??'0';
        return EditorialRest::response(!is_string($after)||preg_match('/^(0|[1-9][0-9]{0,8})$/D',$after)!==1?MessagePolicy::error('invalid_message_cursor',400):MessageReading::conversation($r->get_param('thread_id'),(int)$after));
    }
    public static function request(\WP_REST_Request $r): \WP_REST_Response
    { $d=self::input($r,['creator_id','body','key']); return EditorialRest::response($d===null?MessagePolicy::error('invalid_message_request',400):MessageService::request($d['creator_id'],$d['body'],$d['key'])); }
    public static function send(\WP_REST_Request $r): \WP_REST_Response
    { $d=self::input($r,['body','key']); return EditorialRest::response($d===null?MessagePolicy::error('invalid_message',400):MessageService::send($r->get_param('thread_id'),$d['body'],$d['key'])); }
    public static function decide(\WP_REST_Request $r): \WP_REST_Response
    { $d=self::input($r,['revision','action']); return EditorialRest::response($d===null?MessagePolicy::error('invalid_message_decision',400):MessageService::decide($r->get_param('thread_id'),$d['revision'],$d['action'])); }
    public static function unblock(\WP_REST_Request $r): \WP_REST_Response
    { $d=self::input($r,['confirm']); return EditorialRest::response($d===null||$d['confirm']!==true?MessagePolicy::error('invalid_message_block',400):MessageReading::unblock($r->get_param('block_id'))); }
    /**
     * @param list<string> $keys
     * @return array<string,mixed>|null
     */
    private static function input(\WP_REST_Request $r,array $keys): ?array
    {
        /** @var mixed $data */
        $data=$r->get_json_params();
        return is_array($data)&&count($data)===count($keys)&&array_diff($keys,array_keys($data))===[]
            &&$r->get_query_params()===[]&&$r->get_file_params()===[]&&$r->get_body_params()===[]?$data:null;
    }
}
