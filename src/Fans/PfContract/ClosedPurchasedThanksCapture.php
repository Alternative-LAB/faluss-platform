<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\PfContract;

use Faluss\Platform\Fans\Hof\RankingIdentity;
use Faluss\Platform\Fans\Messaging\MessageAutomaticEvents;
use Faluss\Platform\Fans\Messaging\MessageAutomationSchema;
use Faluss\Platform\Fans\Messaging\MessageStore;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;

/** First validated receipt only. This is a delivery checkpoint, never another economic consumption. */
final class ClosedPurchasedThanksCapture
{
    /** The caller has authenticated the envelope, matched the immutable intention and inserted its receipt.
     * Never called on replay, corpus reconstruction or correction.
     * @param array<string,mixed> $receipt */
    public static function inTransaction(\wpdb $db, array $receipt): void
    {
        ClosedEnvironment::assertIsolated($db,'fans');
        if ($db !== ($GLOBALS['wpdb'] ?? null) || (string) $db->get_var('SELECT @@in_transaction') !== '1') { throw new ModelViolation('pf_thanks_primary_required'); }
        if (!ClosedPurchasedThanksSchema::installed($db)) { return; }
        // A closed/unprepared messaging policy grants no event and no later retroactive retry.
        if (!MessageAutomaticEvents::installed()) { return; }
        if (!MessageAutomationSchema::ready()) { throw new ModelViolation('pf_thanks_messages_unavailable'); }
        // No profile/Identity/configuration row lock before the ordinary messaging mutex.
        $result = MessageStore::composeInTransaction(static fn (): bool => self::capture($db,$receipt));
        if ($result !== true) { throw new ModelViolation('pf_thanks_messages_unavailable'); }
    }

    /** @param array<string,mixed> $receipt */
    private static function capture(\wpdb $db, array $receipt): bool
    {
        $event = hash('sha256','thanks' . "\0" . $receipt['attribution_id']);
        $identity = new RankingIdentity($db); $creator = $identity->creatorInTransaction($receipt['creator_faluss_id']);
        $fan = $identity->memberInTransaction($receipt['member_faluss_id']);
        $capture = $creator !== null && $fan !== null;
        $values = ['event_key' => $event,'attribution_id' => $receipt['attribution_id'],'origin_id' => $receipt['ranking_context']['origin_id'],
            'member_faluss_id' => $receipt['member_faluss_id'],'creator_faluss_id' => $receipt['creator_faluss_id'],
            'receipt_id' => $receipt['receipt_id'],'receipt_sha256' => hash('sha256',CanonicalJson::encode($receipt)),
            'context_sha256' => $receipt['context_sha256'],'consumption_order' => $receipt['consumption_order'],
            'ordering_epoch' => $receipt['ordering_epoch'],'state' => $capture ? 'captured' : 'ignored'];
        $now = $db->get_var('SELECT UTC_TIMESTAMP(6)');
        if (!is_string($now) || self::failed() || $db->insert(ClosedPurchasedThanksSchema::tables($db)['bindings'],$values + ['created_at' => $now]) !== 1 || self::failed()) {
            throw new ModelViolation('pf_thanks_storage_unavailable');
        }
        return !$capture || MessageAutomaticEvents::purchasedInTransaction($creator,$receipt['member_faluss_id'],$event);
    }

    /** @phpstan-impure */
    private static function failed(): bool { global $wpdb; return $wpdb->last_error !== ''; }
}
