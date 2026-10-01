<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Messaging;

/** Human review of isolated proof. No ordinary administrator or participant gets implicit access. */
final class ReportModeration
{
    public static function allowed(): bool
    { return \Faluss\Platform\Core\SiteRole::fromValue(defined('FALUSS_PLATFORM_ROLE')?constant('FALUSS_PLATFORM_ROLE'):null)===\Faluss\Platform\Core\SiteRole::Fans
        && MessageSchema::ready() && ReportSchema::ready() && current_user_can('manage_options') && current_user_can('moderate_faluss_fans_messages'); }
    public static function run(callable $operation): mixed
    {
        if(!self::allowed()) {return MessagePolicy::error('report_moderation_forbidden',403);}
        $result=MessageStore::transaction($operation);
        return is_array($result)&&isset($result['_report_expired'])?MessagePolicy::error('report_expired',410):$result;
    }
    public static function listing(mixed $cursor=''): mixed
    {
        if($cursor!==''&&!MessagePolicy::uuid($cursor)) {return MessagePolicy::error('invalid_report_cursor',400);}
        return self::run(static function () use($cursor): mixed {
            global $wpdb;
            $rows=$wpdb->get_results($wpdb->prepare('SELECT case_id,reporter_user,subject_user,state,revision,decision,reason,created_at,review_due_at,final_at,hold_until,((state<>%s OR hold_until>UTC_TIMESTAMP()) AND review_due_at<=UTC_TIMESTAMP()) AS overdue FROM `'.MessageSchema::table('reports').'` WHERE case_id>%s AND (state<>%s OR DATE_ADD(final_at,INTERVAL 12 MONTH)>UTC_TIMESTAMP() OR hold_until>UTC_TIMESTAMP()) ORDER BY case_id LIMIT 21','final',$cursor,'final'),'ARRAY_A');
            if(!is_array($rows)||MessageStore::failed()) {return MessagePolicy::error('reports_read_failed');}
            $more=count($rows)>20;$rows=array_slice($rows,0,20);
            $overdue=$wpdb->get_var('SELECT COUNT(*) FROM `'.MessageSchema::table('reports').'` WHERE (state<>\'final\' OR hold_until>UTC_TIMESTAMP()) AND review_due_at<=UTC_TIMESTAMP()');
            if(!is_numeric($overdue)||MessageStore::failed()) {return MessagePolicy::error('reports_read_failed');}
            return ['items'=>$rows,'next_cursor'=>$more?$rows[19]['case_id']:null,'overdue'=>(int)$overdue];
        });
    }
    public static function inspect(mixed $id): mixed
    {
        if(!MessagePolicy::uuid($id)) {return MessagePolicy::error('invalid_report',400);}
        return self::run(static function () use($id): mixed {
            global $wpdb;$row=self::case($id);
            if($row instanceof \WP_Error||isset($row['_report_expired'])) {return $row;}
            $events=$wpdb->get_results($wpdb->prepare('SELECT revision,actor_user,action,reason,created_at FROM `'.MessageSchema::table('report_events').'` WHERE case_id=%s ORDER BY revision DESC LIMIT 201',$id),'ARRAY_A');
            if(!is_array($events)||MessageStore::failed()) {return MessagePolicy::error('reports_read_failed');}
            $holds=$wpdb->get_results($wpdb->prepare('SELECT actor_user,action,reason,until_at,created_at FROM `'.MessageSchema::table('legal_holds').'` WHERE case_id=%s ORDER BY created_at DESC,hold_id DESC LIMIT 201',$id),'ARRAY_A');
            if(!is_array($holds)||MessageStore::failed()) {return MessagePolicy::error('reports_read_failed');}
            unset($row['expired']);
            return $row+['events'=>array_slice($events,0,200),'holds'=>array_slice($holds,0,200),'history_truncated'=>count($events)>200||count($holds)>200];
        });
    }
    /** Explicit operations; finality is a human attestation, never inferred from elapsed time. */
    public static function decide(mixed $id,mixed $revision,mixed $action,mixed $reason,mixed $recourseComplete=false,mixed $days=0): mixed
    {
        if(!MessagePolicy::uuid($id)||!is_int($revision)||$revision<1||!MessagePolicy::text($reason,1000)
            ||!in_array($action,['review','no_action','remove','restrict','restore','finalize','hold','release_hold'],true)
            ||!is_bool($recourseComplete)||!is_int($days)||($action==='hold'?($days<1||$days>90):$days!==0)) {return MessagePolicy::error('invalid_report_decision',400);}
        return self::run(static function () use($id,$revision,$action,$reason,$recourseComplete,$days): mixed {
            global $wpdb;$row=self::case($id);
            if($row instanceof \WP_Error||isset($row['_report_expired'])) {return $row;}
            if((int)$row['revision']!==$revision) {return MessagePolicy::error('report_conflict',409);}
            $table=MessageSchema::table('reports');$state=$row['state'];
            if($action==='finalize') {
                if($state!=='decided'||!$recourseComplete) {return MessagePolicy::error('recourse_not_complete',409);}
                $state='final';
                $update=$wpdb->prepare('UPDATE `'.$table.'` SET state=%s,final_at=UTC_TIMESTAMP(),reviewed_at=UTC_TIMESTAMP(),review_due_at=UTC_TIMESTAMP()+INTERVAL 30 DAY,revision=revision+1 WHERE case_id=%s',$state,$id);
            } elseif(in_array($action,['hold','release_hold'],true)) {
                $until=$action==='hold'?'UTC_TIMESTAMP()+INTERVAL '.$days.' DAY':'NULL';
                $audit='INSERT INTO `'.MessageSchema::table('legal_holds').'` (hold_id,case_id,actor_user,action,reason,until_at,created_at) VALUES(%s,%s,%d,%s,%s,'.($action==='hold'?$until:'UTC_TIMESTAMP()').',UTC_TIMESTAMP())';
                if($wpdb->query($wpdb->prepare($audit,wp_generate_uuid4(),$id,get_current_user_id(),$action,$reason))!==1) {return MessagePolicy::error('reports_write_failed');}
                $update=$wpdb->prepare('UPDATE `'.$table.'` SET hold_until='.$until.',revision=revision+1 WHERE case_id=%s',$id);
            } elseif($action==='review') {
                $update=$wpdb->prepare('UPDATE `'.$table.'` SET reviewed_at=UTC_TIMESTAMP(),review_due_at=UTC_TIMESTAMP()+INTERVAL 30 DAY,revision=revision+1 WHERE case_id=%s',$id);
            } else {
                if($state==='final') {return MessagePolicy::error('report_final',409);}
                $effect=self::effect($row,$action);if($effect instanceof \WP_Error) {return $effect;}
                $state='decided';
                $restriction=$action==='restrict'?',restricted=1':($action==='restore'?',restricted=0':'');
                $update=$wpdb->prepare('UPDATE `'.$table.'` SET state=%s,decision=%s'.$restriction.',reviewed_at=UTC_TIMESTAMP(),review_due_at=UTC_TIMESTAMP()+INTERVAL 30 DAY,revision=revision+1 WHERE case_id=%s',$state,$action,$id);
            }
            if($wpdb->query($update)!==1||!MessageReports::event($id,$revision+1,$action,$reason)) {return MessagePolicy::error('reports_write_failed');}
            if(in_array($action,['no_action','remove','restrict','restore','finalize'],true)){
                foreach([(int)$row['reporter_user'],(int)$row['subject_user']] as $recipient){
                    if(!\Faluss\Platform\Fans\Notifications\NotificationEvents::record($recipient,$action==='finalize'?'report_final':'report_decision',$id,$revision+1)){return MessagePolicy::error('report_notification_failed');}
                }
            }
            return ['case_id'=>$id,'state'=>$state,'revision'=>$revision+1];
        });
    }
    /** @return array<string,mixed>|\WP_Error */
    private static function case(string $id): array|\WP_Error
    {
        $row=MessageReports::row($id);if($row instanceof \WP_Error) {return $row;}
        if(in_array(get_current_user_id(),[(int)$row['reporter_user'],(int)$row['subject_user']],true)) {return MessagePolicy::error('report_review_conflict',403);}
        if((bool)$row['expired']) {return ReportRetention::erase($id)?['_report_expired'=>true]:MessagePolicy::error('reports_purge_failed');}
        return $row;
    }
    /** @param array<string,mixed> $row */
    private static function effect(array $row,string $action): ?\WP_Error
    {
        global $wpdb;
        $thread=MessageStore::thread($row['thread_id']);
        if(MessageStore::failed()) {return MessagePolicy::error('reports_read_failed');}
        if($thread===null) {return null;} // The evidence never recreates expired ordinary content.
        if((bool)$thread['expired']) {return MessageStore::eraseThread($row['thread_id'])?null:MessagePolicy::error('messages_purge_failed');}
        if($action==='remove'||$action==='restore') {
            if($wpdb->query($wpdb->prepare('UPDATE `'.MessageSchema::table('messages').'` SET body=%s WHERE message_id=%s AND thread_id=%s',$action==='remove'?'':$row['body'],$row['message_id'],$row['thread_id']))===false) {return MessagePolicy::error('reports_write_failed');}
        }
        $state=$thread['state'];
        if($action==='restrict'&&in_array($state,['pending','open','refused'],true)) {$state='paused_'.$state;}
        if($action==='restore'&&str_starts_with($state,'paused_')) {
            $others=$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.MessageSchema::table('reports').'` WHERE thread_id=%s AND case_id<>%s AND restricted=1',$row['thread_id'],$row['case_id']));
            if(!is_numeric($others)||MessageStore::failed()) {return MessagePolicy::error('reports_read_failed');}
            if((int)$others===0) {$state=substr($state,7);}
        }
        return $wpdb->query($wpdb->prepare('UPDATE `'.MessageSchema::table('threads').'` SET state=%s,revision=revision+1 WHERE thread_id=%s',$state,$row['thread_id']))===false?MessagePolicy::error('reports_write_failed'):null;
    }
}
