<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Messaging;
final class ReportRetention
{
    /** Caller owns the shared message transaction. Erases evidence and its sensitive case history. */
    public static function erase(string $id): bool
    {
        global $wpdb;
        foreach(['report_events','legal_holds','reports'] as $kind) {
            if($wpdb->query($wpdb->prepare('DELETE FROM `'.MessageSchema::table($kind).'` WHERE case_id=%s',$id))===false) {return false;}
        }
        return true;
    }
    /** Retention is independent of admission flags and moderator availability. */
    public static function purge(): mixed
    {
        if(!ReportSchema::ready()) {return MessagePolicy::error('reports_unavailable');}
        return MessageStore::transaction(static function (): mixed {
            global $wpdb;
            $rows=$wpdb->get_results('SELECT case_id FROM `'.MessageSchema::table('reports').'` WHERE state=\'final\' AND DATE_ADD(final_at,INTERVAL 12 MONTH)<=UTC_TIMESTAMP() AND (hold_until IS NULL OR hold_until<=UTC_TIMESTAMP()) ORDER BY final_at LIMIT 100 FOR UPDATE','ARRAY_A');
            if(!is_array($rows)||MessageStore::failed()) {return MessagePolicy::error('reports_read_failed');}
            foreach($rows as $row) {if(!self::erase($row['case_id'])) {return MessagePolicy::error('reports_purge_failed');}}
            return ['purged'=>count($rows)];
        });
    }
}
