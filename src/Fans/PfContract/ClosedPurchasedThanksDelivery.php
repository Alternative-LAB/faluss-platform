<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\PfContract;

use Faluss\Platform\Fans\Hof\ClosedOriginOpening;
use Faluss\Platform\Fans\Hof\RankingIdentity;
use Faluss\Platform\Fans\Hof\SessionStore;
use Faluss\Platform\Fans\Messaging\MessageAutomaticDelivery;
use Faluss\Platform\Fans\Messaging\MessageAutomaticEvents;
use Faluss\Platform\Fans\Messaging\MessageAutomationSchema;
use Faluss\Platform\Fans\Messaging\MessageStore;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;

/** Historical receipt authorizes a candidate; only its current complete positive corpus authorizes delivery. */
final class ClosedPurchasedThanksDelivery
{
    public function __construct(private readonly \wpdb $db,private readonly ClosedOriginOpening $opening,
        private readonly ClosedBarrierPrimaryRead $primary,private readonly ClosedCorpusInbox $corpus,private readonly PeerPolicy $peer)
    {
        ClosedEnvironment::assertIsolated($db,'fans');
        if ($db !== ($GLOBALS['wpdb'] ?? null) || $peer->node !== 'fixture.hub' || $peer->audience !== 'fixture.fans') { throw new ModelViolation('pf_thanks_primary_required'); }
    }

    public function usesConnection(\wpdb $db): bool
    { ClosedEnvironment::assertIsolated($this->db,'fans'); return $this->db === $db; }

    public static function configured(): ?self
    {
        try {
            global $wpdb; if (!$wpdb instanceof \wpdb) { return null; }
            ClosedEnvironment::assertIsolated($wpdb,'fans');
            if (!ClosedPurchasedThanksSchema::ready($wpdb) || !MessageAutomationSchema::ready()) { return null; }
            $source=apply_filters('faluss_fans_closed_pf_thanks_delivery',null);
            return $source instanceof self && $source->usesConnection($wpdb) ? $source : null;
        } catch (\Throwable) { return null; }
    }

    /** Bounded DB-only worker. No HTTP, confirmation retry, reservation or economic transaction here. */
    public function run(int $limit=10): mixed
    {
        ClosedEnvironment::assertIsolated($this->db,'fans');
        if ($limit<1 || $limit>20) { throw new ModelViolation('pf_thanks_budget'); }
        $done=0;
        for ($i=0;$i<$limit;$i++) {
            $event=MessageAutomaticEvents::nextPurchased();
            if ($event instanceof \WP_Error) { return $event; }
            if ($event===null) { break; }
            if (!is_string($event) || preg_match('/^[0-9a-f]{64}$/D',$event)!==1) { throw new ModelViolation('pf_thanks_source_conflict'); }
            try { $this->deliver($event); }
            catch (\Throwable) { $result=MessageAutomaticDelivery::purchasedSourceFailed($event); if ($result instanceof \WP_Error) { return $result; } }
            $done++;
        }
        return ['processed'=>$done];
    }

    private function deliver(string $event): void
    {
        (new SessionStore($this->db))->withGovernanceMutex(fn (): mixed => $this->opening->withCurrentRead($this->primary,
            fn (array $origin): mixed => $this->corpus->withCurrentInTransaction(function (?array $verified) use ($event,$origin): mixed {
                if ($verified===null || $verified['manifest']['origin_id']!==$origin['origin_id']) { throw new ModelViolation('pf_thanks_source_unavailable'); }
                $binding=$this->db->get_row($this->db->prepare('SELECT * FROM %i WHERE event_key=%s FOR UPDATE',ClosedPurchasedThanksSchema::tables($this->db)['bindings'],$event),'ARRAY_A');
                if (!is_array($binding) || $this->db->last_error!=='' || $binding['state']!=='captured' || $binding['origin_id']!==$origin['origin_id']
                    || hash('sha256','thanks'."\0".$binding['attribution_id'])!==$event) { throw new ModelViolation('pf_thanks_source_conflict'); }
                $receipt=(new ClosedRankedProtocolStore($this->db))->confirmedInTransaction($binding['attribution_id'],$binding['member_faluss_id'],$this->peer);
                if ($binding['receipt_sha256']!==hash('sha256',CanonicalJson::encode($receipt))) { throw new ModelViolation('pf_thanks_source_conflict'); }
                foreach (['creator_faluss_id','receipt_id','context_sha256','consumption_order','ordering_epoch'] as $field) {
                    if ($binding[$field]!==$receipt[$field]) { throw new ModelViolation('pf_thanks_source_conflict'); }
                }
                $fact=null;
                foreach ($verified['facts'] as $candidate) { if ($candidate['attribution_id']===$binding['attribution_id']) { $fact=$candidate; break; } }
                if ($fact===null) { throw new ModelViolation('pf_thanks_source_unavailable'); }
                foreach (['attribution_id','member_faluss_id','creator_faluss_id','context_sha256','purchased_pf','ledger_entry_uuid','confirmed_at','consumption_order','ordering_epoch'] as $field) {
                    if ($fact[$field]!==$receipt[$field]) { throw new ModelViolation('pf_thanks_source_conflict'); }
                }
                if ($fact['consumption_id']!==$receipt['receipt_id']) { throw new ModelViolation('pf_thanks_source_conflict'); }
                $result=MessageStore::composeInTransaction(fn (): mixed => MessageAutomaticDelivery::purchasedInTransaction($event,(int)$fact['net_pf']>0,
                    (new RankingIdentity($this->db))->creatorInTransaction($receipt['creator_faluss_id']),$receipt['member_faluss_id']));
                if ($result instanceof \WP_Error) { throw new ModelViolation('pf_thanks_messages_unavailable'); }
                return $result;
            })));
    }
}
