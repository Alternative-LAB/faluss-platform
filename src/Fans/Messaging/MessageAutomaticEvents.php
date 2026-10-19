<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Messaging;

use Faluss\Platform\Fans\Sso\FansLinkedAccounts;
use Faluss\Platform\Fans\Profiles\CreatorProfileService;

/** Transaction participant of actual follower transitions. No HTTP route or economic authority. */
final class MessageAutomaticEvents
{
    /** Ordinary-content transaction only; keep business deduplication keys after erasure. */
    public static function forgetContactInTransaction(string $thread): bool
    {
        global $wpdb;$table=MessageSchema::table('automatic_contacts');
        $found=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table));
        if(MessageStore::failed()){return false;}if($found===null){return true;}
        return $found===$table&&MessageSchema::verify('automatic_contacts')&&MessageSchema::verify('automatic_events')
            &&$wpdb->query($wpdb->prepare('DELETE FROM `'.$table.'` WHERE thread_id=%s',$thread))!==false
            &&$wpdb->query($wpdb->prepare('UPDATE `'.MessageSchema::table('automatic_events').'` SET thread_id=%s,message_id=%s WHERE thread_id=%s','','',$thread))!==false;
    }
    public static function installed(): bool
    {
        return defined('FALUSS_FANS_MESSAGING_POLICY_ATTESTED')&&constant('FALUSS_FANS_MESSAGING_POLICY_ATTESTED')===true
            &&get_option(MessageAutomationSchema::OPTION)==='1';
    }

    /** Called only after an actual INSERT, or before removal of a stored follow (rememberOnly). */
    public static function followedInTransaction(int $fan,string $creator,bool $rememberOnly=false): bool
    {
        if(!self::installed()||!MessageStore::ownsTransaction()||get_current_user_id()!==$fan){return false;}
        $identity=FansLinkedAccounts::identityInTransaction($fan);
        $owner=$rememberOnly?null:CreatorProfileService::activeOwner($creator,true);
        if($identity===null||!MessagePolicy::uuid($creator)||(!$rememberOnly&&($owner===null||$owner===$fan))){return false;}
        return self::recordInTransaction('welcome',$creator,$identity,hash('sha256','welcome'."\0".$creator."\0".$identity),$rememberOnly);
    }

    /** Only the guarded PF inbox calls this, after validation and inside its actual receipt transaction. */
    public static function purchasedInTransaction(string $creator,string $identity,string $event): bool
    {
        global $wpdb;
        \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment::assertIsolated($wpdb,'fans');
        return self::installed() && MessageStore::ownsTransaction() && MessageAutomationSchema::ready()
            && MessagePolicy::uuid($creator) && MessagePolicy::uuid($identity) && preg_match('/^[0-9a-f]{64}$/D',$event)===1
            && self::recordInTransaction('thanks',$creator,$identity,$event,false);
    }

    /** Private bounded worker contract, not a member REST read or recipient selector. */
    public static function nextPurchased(): mixed
    {
        global $wpdb;
        \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment::assertIsolated($wpdb,'fans');
        return MessageStore::transaction(static function (): mixed {
            if (!MessageAutomationSchema::ready()) { return MessagePolicy::error('message_automations_unavailable'); }
            global $wpdb;
            $key=$wpdb->get_var('SELECT event_key FROM `'.MessageSchema::table('automatic_events').'` WHERE kind=\'thanks\' AND state IN (\'pending\',\'retry\') AND next_attempt_at<=UTC_TIMESTAMP() ORDER BY next_attempt_at,event_key LIMIT 1');
            return MessageStore::failed()?MessagePolicy::error('messages_read_failed'):$key;
        });
    }

    /**
     * Only module-owned authoritative callers; never expose arbitrary events through REST.
     * Body and revision are selected once, under the same mutex as configuration and delivery.
     */
    private static function recordInTransaction(string $kind,string $creator,string $identity,string $event,bool $rememberOnly): bool
    {
        global $wpdb;
        $table=MessageSchema::table('automatic_events');
        $seen=$wpdb->get_var($wpdb->prepare('SELECT event_key FROM `'.$table.'` WHERE event_key=%s FOR UPDATE',$event));
        if(MessageStore::failed()){return false;}
        if($seen!==null){
            return $seen===$event&&(!$rememberOnly||$wpdb->query($wpdb->prepare('UPDATE `'.$table.'` SET state=%s,body=%s,fan_identity=%s,delivery_key=%s,error=%s,updated_at=UTC_TIMESTAMP() WHERE event_key=%s AND state IN (%s,%s)',
                'cancelled','','','','unfollowed',$event,'pending','retry'))!==false);
        }
        $config=$rememberOnly?['enabled'=>false,'revision'=>0,'epoch'=>0,'body'=>'']:MessageAutomaticSettings::inTransaction($creator,$kind);
        if($config instanceof \WP_Error){return false;}
        $pending=!$rememberOnly&&$config['enabled']&&MessageModule::available();
        return $wpdb->query($wpdb->prepare('INSERT INTO `'.$table.'` (event_key,kind,creator_id,fan_identity,config_revision,config_epoch,state,body,delivery_key,thread_id,message_id,attempts,next_attempt_at,error,created_at,updated_at) VALUES(%s,%s,%s,%s,%d,%d,%s,%s,%s,%s,%s,0,UTC_TIMESTAMP(),%s,UTC_TIMESTAMP(),UTC_TIMESTAMP())',
            $event,$kind,$creator,$pending?$identity:'',$config['revision'],$config['epoch'],$pending?'pending':'suppressed',$pending?$config['body']:'',$pending?wp_generate_uuid4():'','','',$pending?'':'not_enabled'))===1;
    }
}
