<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Messaging;

/** Minimal evidence extraction and participant recourse. Never returns the evidence copy to members. */
final class MessageReports
{
    /** Recipient projection only: never loads proof, another participant, reason or internal journal. */
    public static function ownItem(string $id): mixed
    {
        if(!MessagePolicy::uuid($id)||!ReportSchema::ready()){return MessagePolicy::error('report_not_found',404);}
        return MessageService::run(static function()use($id):mixed{
            global $wpdb;$user=get_current_user_id();
            $row=$wpdb->get_row($wpdb->prepare('SELECT case_id,thread_id,state,revision,decision,created_at,reviewed_at,final_at FROM `'.MessageSchema::table('reports').'` WHERE case_id=%s AND (reporter_user=%d OR subject_user=%d) AND (state<>%s OR DATE_ADD(final_at,INTERVAL 12 MONTH)>UTC_TIMESTAMP())',$id,$user,$user,'final'),'ARRAY_A');
            return MessageStore::failed()?MessagePolicy::error('reports_read_failed'):(is_array($row)?$row:MessagePolicy::error('report_not_found',404));
        });
    }
    public static function report(mixed $threadId,mixed $messageId,mixed $reason): mixed
    {
        if (!MessagePolicy::uuid($threadId)||!MessagePolicy::uuid($messageId)||!in_array($reason,['harassment','spam','prohibited_content','other'],true)) { return MessagePolicy::error('invalid_message_report',400); }
        if (!ReportSchema::ready()) { return MessagePolicy::error('reports_unavailable'); }
        return MessageService::run(static function () use($threadId,$messageId,$reason): mixed {
            global $wpdb; $thread=MessageService::owned($threadId);
            if($thread instanceof \WP_Error||isset($thread['_expired'])) { return $thread; }
            $user=get_current_user_id();$table=MessageSchema::table('reports');
            $old=$wpdb->get_row($wpdb->prepare('SELECT case_id FROM `'.$table.'` WHERE reporter_user=%d AND message_id=%s',$user,$messageId),'ARRAY_A');
            if(MessageStore::failed()) {return MessagePolicy::error('reports_read_failed');}
            if(is_array($old)) {return ['case_id'=>$old['case_id'],'reused'=>true];}
            $row=$wpdb->get_row($wpdb->prepare('SELECT sender_id,body,created_at FROM `'.MessageSchema::table('messages').'` WHERE thread_id=%s AND message_id=%s',$threadId,$messageId),'ARRAY_A');
            if(MessageStore::failed()) {return MessagePolicy::error('reports_read_failed');}
            if(!is_array($row)||(int)$row['sender_id']===$user||$row['body']==='') {return MessagePolicy::error('message_not_reportable',404);}
            foreach([
                [$wpdb->prepare('SELECT COUNT(*) FROM `'.$table.'` WHERE reporter_user=%d AND created_at>UTC_TIMESTAMP()-INTERVAL 24 HOUR',$user),3],
                [$wpdb->prepare('SELECT COUNT(*) FROM `'.$table.'` WHERE reporter_user=%d AND state<>%s',$user,'final'),20],
            ] as [$query,$limit]) {
                $count=$wpdb->get_var($query);
                if(MessageStore::failed()||!is_numeric($count)) {return MessagePolicy::error('reports_read_failed');}
                if((int)$count>=$limit) {return MessagePolicy::error('message_report_limit',429);}
            }
            $id=wp_generate_uuid4();
            $sql='INSERT INTO `'.$table.'` (case_id,thread_id,message_id,reporter_user,subject_user,body,sent_at,reason,state,revision,decision,restricted,created_at,reviewed_at,review_due_at,final_at,hold_until) VALUES(%s,%s,%s,%d,%d,%s,%s,%s,%s,1,%s,0,UTC_TIMESTAMP(),NULL,UTC_TIMESTAMP()+INTERVAL 30 DAY,NULL,NULL)';
            if($wpdb->query($wpdb->prepare($sql,$id,$threadId,$messageId,$user,(int)$row['sender_id'],$row['body'],$row['created_at'],$reason,'open','none'))!==1
                ||!self::event($id,1,'report',$reason)) {return MessagePolicy::error('reports_write_failed');}
            return ['case_id'=>$id,'state'=>'open','revision'=>1];
        });
    }
    public static function own(mixed $cursor=''): mixed
    {
        if($cursor!==''&&!MessagePolicy::uuid($cursor)) {return MessagePolicy::error('invalid_report_cursor',400);}
        if(!ReportSchema::ready()) {return MessagePolicy::error('reports_unavailable');}
        return MessageService::run(static function () use($cursor): mixed {
            global $wpdb;$user=get_current_user_id();
            $rows=$wpdb->get_results($wpdb->prepare('SELECT case_id,thread_id,state,revision,decision,created_at,reviewed_at,final_at FROM `'.MessageSchema::table('reports').'` WHERE (reporter_user=%d OR subject_user=%d) AND case_id>%s AND (state<>%s OR DATE_ADD(final_at,INTERVAL 12 MONTH)>UTC_TIMESTAMP()) ORDER BY case_id LIMIT 21',$user,$user,$cursor,'final'),'ARRAY_A');
            if(!is_array($rows)||MessageStore::failed()) {return MessagePolicy::error('reports_read_failed');}
            $more=count($rows)>20;$rows=array_slice($rows,0,20);
            return ['items'=>$rows,'next_cursor'=>$more?$rows[19]['case_id']:null];
        });
    }
    public static function appeal(mixed $id,mixed $revision,mixed $reason): mixed
    {
        if(!MessagePolicy::uuid($id)||!is_int($revision)||$revision<1||!MessagePolicy::text($reason,1000)) {return MessagePolicy::error('invalid_message_appeal',400);}
        if(!ReportSchema::ready()) {return MessagePolicy::error('reports_unavailable');}
        return MessageService::run(static function () use($id,$revision,$reason): mixed {
            global $wpdb;$row=self::row($id);
            if($row instanceof \WP_Error) {return $row;}
            if(!in_array(get_current_user_id(),[(int)$row['reporter_user'],(int)$row['subject_user']],true)) {return MessagePolicy::error('report_not_found',404);}
            if((int)$row['revision']!==$revision) {return MessagePolicy::error('report_conflict',409);}
            if(!in_array($row['state'],['decided','appealed'],true)) {return MessagePolicy::error('report_not_appealable',409);}
            $lastDecision=$wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(revision),0) FROM `'.MessageSchema::table('report_events').'` WHERE case_id=%s AND action IN (\'no_action\',\'remove\',\'restrict\',\'restore\')',$id));
            if(MessageStore::failed()||!is_numeric($lastDecision)) {return MessagePolicy::error('reports_read_failed');}
            $count=$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.MessageSchema::table('report_events').'` WHERE case_id=%s AND actor_user=%d AND action=%s AND revision>%d',$id,get_current_user_id(),'appeal',(int)$lastDecision));
            if(MessageStore::failed()||!is_numeric($count)) {return MessagePolicy::error('reports_read_failed');}
            if((int)$count>0) {return MessagePolicy::error('appeal_already_received',409);}
            if($wpdb->query($wpdb->prepare('UPDATE `'.MessageSchema::table('reports').'` SET state=%s,revision=revision+1,review_due_at=UTC_TIMESTAMP()+INTERVAL 30 DAY WHERE case_id=%s','appealed',$id))!==1
                ||!self::event($id,$revision+1,'appeal',$reason)) {return MessagePolicy::error('reports_write_failed');}
            return ['case_id'=>$id,'state'=>'appealed','revision'=>$revision+1];
        });
    }
    /** @return array<string,mixed>|\WP_Error */
    public static function row(string $id): array|\WP_Error
    {
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare('SELECT *, (state=%s AND DATE_ADD(final_at,INTERVAL 12 MONTH)<=UTC_TIMESTAMP() AND (hold_until IS NULL OR hold_until<=UTC_TIMESTAMP())) AS expired FROM `'.MessageSchema::table('reports').'` WHERE case_id=%s FOR UPDATE','final',$id),'ARRAY_A');
        return MessageStore::failed()?MessagePolicy::error('reports_read_failed'):(is_array($row)?$row:MessagePolicy::error('report_not_found',404));
    }
    public static function event(string $id,int $revision,string $action,string $reason): bool
    {
        global $wpdb;
        return $wpdb->query($wpdb->prepare('INSERT INTO `'.MessageSchema::table('report_events').'` (case_id,revision,actor_user,action,reason,created_at) VALUES(%s,%d,%d,%s,%s,UTC_TIMESTAMP())',$id,$revision,get_current_user_id(),$action,$reason))===1;
    }
}
