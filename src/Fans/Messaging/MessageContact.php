<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Messaging;

use Faluss\Platform\Fans\Profiles\CreatorProfileService;

/** Creator-owned admission preference. Existing conversation decisions are immutable here. */
final class MessageContact
{
    public const OPTION = 'faluss_fans_message_contact_schema_version';

    public static function ready(): bool
    { return get_option(self::OPTION) === '1' && MessageSchema::verify('contact'); }

    /** Explicit operator/plugin activation only, never a side effect of reading or sending. */
    public static function installOrVerify(): bool
    { return MessageSchema::installGroup(['contact'], self::OPTION); }

    /**
     * Caller owns the existing messaging transaction and mutex.
     * @return array{mode:string,revision:int}|\WP_Error
     */
    public static function inTransaction(string $creator): array|\WP_Error
    {
        if (!MessagePolicy::uuid($creator) || !self::ready()) { return MessagePolicy::error('message_contact_unavailable'); }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT mode,revision FROM `'.MessageSchema::table('contact').'` WHERE creator_id=%s FOR UPDATE', $creator), 'ARRAY_A');
        if (MessageStore::failed()) { return MessagePolicy::error('messages_read_failed'); }
        if ($row === null) { return ['mode'=>'open', 'revision'=>0]; }
        if (!in_array($row['mode'] ?? null, ['open','request'], true) || !is_numeric($row['revision'] ?? null) || (int)$row['revision'] < 1) {
            return MessagePolicy::error('message_contact_unavailable');
        }
        return ['mode'=>$row['mode'], 'revision'=>(int)$row['revision']];
    }

    public static function own(): mixed
    {
        return MessageService::run(static function (): mixed {
            $creator = CreatorProfileService::activeIdForOwnerInTransaction(get_current_user_id());
            return $creator === null ? MessagePolicy::error('message_contact_forbidden',403) : self::inTransaction($creator);
        });
    }

    public static function save(mixed $mode, mixed $revision): mixed
    {
        if (!in_array($mode, ['open','request'], true) || !is_int($revision) || $revision < 0 || $revision > 999999999) {
            return MessagePolicy::error('invalid_message_contact',400);
        }
        return MessageService::run(static function () use ($mode,$revision): mixed {
            $creator = CreatorProfileService::activeIdForOwnerInTransaction(get_current_user_id());
            if ($creator === null) { return MessagePolicy::error('message_contact_forbidden',403); }
            $old = self::inTransaction($creator);
            if ($old instanceof \WP_Error) { return $old; }
            if ($old['revision'] !== $revision) { return MessagePolicy::error('message_contact_conflict',409); }
            global $wpdb;
            $sql = $revision === 0
                ? $wpdb->prepare('INSERT INTO `'.MessageSchema::table('contact').'` (mode,revision,updated_at,creator_id) VALUES(%s,1,UTC_TIMESTAMP(),%s)', $mode,$creator)
                : $wpdb->prepare('UPDATE `'.MessageSchema::table('contact').'` SET mode=%s,revision=revision+1,updated_at=UTC_TIMESTAMP() WHERE creator_id=%s AND revision=%d', $mode,$creator,$revision);
            if ($wpdb->query($sql) !== 1) { return MessagePolicy::error('messages_write_failed'); }
            return ['mode'=>$mode,'revision'=>$revision+1];
        });
    }
}
