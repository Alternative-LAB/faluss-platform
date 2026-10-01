<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Messaging;

/** Internal serialized boundary, shared by conversation, reports and retention operations. */
final class MessageStore
{
    public static function transaction(callable $operation): mixed
    {
        if (!MessageSchema::ready()) { return MessagePolicy::error('messages_unavailable'); }
        global $wpdb;
        $lock='fans_dm_'.substr(hash('sha256',MessageSchema::table('threads')),0,40);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)',$lock))!==1) { return MessagePolicy::error('messages_busy'); }
        $previous=$wpdb->suppress_errors(true);
        try {
            if ($wpdb->query('START TRANSACTION')===false) { return MessagePolicy::error('messages_write_failed'); }
            $result=$operation();
            if ($result instanceof \WP_Error) { return $result; }
            return $wpdb->query('COMMIT')===false ? MessagePolicy::error('messages_write_failed') : $result;
        } catch (\Throwable) { return MessagePolicy::error('messages_write_failed'); }
        finally { $wpdb->query('ROLLBACK'); $wpdb->suppress_errors($previous); $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
    /** @phpstan-impure Reads the most recent wpdb operation. */
    public static function failed(): bool { global $wpdb; return $wpdb->last_error !== ''; }
    /** @return array<string,mixed>|null */
    public static function thread(string $id): ?array
    {
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare('SELECT *, DATE_ADD(last_sent_at, INTERVAL 12 MONTH)<=UTC_TIMESTAMP() AS expired FROM `'.MessageSchema::table('threads').'` WHERE thread_id=%s FOR UPDATE',$id),'ARRAY_A');
        return is_array($row)?$row:null;
    }
    /** Caller already owns transaction. */
    public static function eraseThread(string $id): bool
    {
        global $wpdb;
        return \Faluss\Platform\Fans\Notifications\NotificationEvents::forgetConversation($id)
            && $wpdb->query($wpdb->prepare('DELETE FROM `'.MessageSchema::table('messages').'` WHERE thread_id=%s',$id))!==false
            && $wpdb->query($wpdb->prepare('DELETE FROM `'.MessageSchema::table('threads').'` WHERE thread_id=%s',$id))!==false;
    }
    /** Deletes expired ordinary content only; legal proofs live in separate storage. */
    public static function purge(): mixed
    {
        return self::transaction(static function (): array|\WP_Error {
            global $wpdb;
            $rows=$wpdb->get_results('SELECT thread_id FROM `'.MessageSchema::table('threads').'` WHERE DATE_ADD(last_sent_at,INTERVAL 12 MONTH)<=UTC_TIMESTAMP() ORDER BY last_sent_at LIMIT 100 FOR UPDATE','ARRAY_A');
            if (!is_array($rows) || self::failed()) { return MessagePolicy::error('messages_read_failed'); }
            foreach ($rows as $row) { if (!self::eraseThread($row['thread_id'])) { return MessagePolicy::error('messages_purge_failed'); } }
            return ['purged'=>count($rows)];
        });
    }
}
