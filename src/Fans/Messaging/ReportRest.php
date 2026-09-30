<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Messaging;
use Faluss\Platform\Fans\Profiles\CreatorProfileRest;
use Faluss\Platform\Fans\Profiles\EditorialRest;
final class ReportRest
{
    public static function routes(): void
    {
        foreach ([['/messages/(?P<thread_id>[0-9a-f-]{36})/report','POST','report','member'],
            ['/message-reports/mine','GET','own','member'],['/message-reports/(?P<case_id>[0-9a-f-]{36})/appeal','POST','appeal','member'],
            ['/message-reports','GET','listing','moderator'],['/message-reports/(?P<case_id>[0-9a-f-]{36})','GET','inspect','moderator'],
            ['/message-reports/(?P<case_id>[0-9a-f-]{36})/decision','POST','decide','moderator']] as [$path,$method,$callback,$guard]) {
            register_rest_route(CreatorProfileRest::NAMESPACE,$path,['methods'=>$method,'callback'=>[self::class,$callback],'permission_callback'=>[self::class,$guard]]);
        }
    }
    public static function member(\WP_REST_Request $r): bool {return ReportSchema::ready() && MessageRest::member($r);}
    public static function moderator(\WP_REST_Request $r): bool {return ReportModeration::allowed() && CreatorProfileRest::adminPermission($r);}
    public static function own(\WP_REST_Request $r): \WP_REST_Response {return EditorialRest::response(MessageReports::own($r->get_param('cursor')??''));}
    public static function listing(\WP_REST_Request $r): \WP_REST_Response {return EditorialRest::response(ReportModeration::listing($r->get_param('cursor')??''));}
    public static function inspect(\WP_REST_Request $r): \WP_REST_Response {return EditorialRest::response(ReportModeration::inspect($r->get_param('case_id')));}
    public static function report(\WP_REST_Request $r): \WP_REST_Response
    {
        $d=MessageRest::input($r,['message_id','reason']);
        return EditorialRest::response($d===null?MessagePolicy::error('invalid_message_report',400):MessageReports::report($r->get_param('thread_id'),$d['message_id'],$d['reason']));
    }
    public static function appeal(\WP_REST_Request $r): \WP_REST_Response
    {
        $d=MessageRest::input($r,['revision','reason']);
        return EditorialRest::response($d===null?MessagePolicy::error('invalid_message_appeal',400):MessageReports::appeal($r->get_param('case_id'),$d['revision'],$d['reason']));
    }
    public static function decide(\WP_REST_Request $r): \WP_REST_Response
    {
        $d=MessageRest::input($r,['revision','action','reason','recourse_complete','days']);
        return EditorialRest::response($d===null?MessagePolicy::error('invalid_report_decision',400):ReportModeration::decide($r->get_param('case_id'),$d['revision'],$d['action'],$d['reason'],$d['recourse_complete'],$d['days']));
    }
}
