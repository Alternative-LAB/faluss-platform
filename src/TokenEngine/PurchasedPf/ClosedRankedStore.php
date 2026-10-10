<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedIntent;

/** Explicit 0.3 owner composition. No HTTP route, bootstrap, producer or external admission. */
final class ClosedRankedStore
{
    public function __construct(private readonly \wpdb $db, private readonly string $keyId)
    { ClosedEnvironment::assertIsolated($db,'hub'); }

    /** @return array<string,mixed> */
    public function execute(PeerPolicy $peer, RankedIntent $intent, string $operation, string $key, ?string $lookupOperation = null,
        ?\Closure $fresh = null): array
    {
        if ($peer->node !== 'fixture.fans' || $peer->audience !== 'fixture.hub' || $intent->base->values['client_authority'] !== $peer->node) { throw new ModelViolation('pf_invalid_peer'); }
        if (!in_array($operation,['reserve','confirm','release','lookup'],true)) { throw new ModelViolation('invalid_h2_operation'); }
        $peer->allow('pf.' . $operation);
        if ($operation === 'lookup' && !in_array($lookupOperation,['reserve','confirm','release'],true)) { throw new ModelViolation('invalid_h2_operation'); }
        $context = new ClosedRankingContext($this->db,$intent,$this->keyId,$fresh);
        $reservations = new ClosedReservationStore($this->db,['fixture.fans'],['fixture.purchase'],$context);
        $consumptions = new ClosedConsumptionStore($this->db,['fixture.fans'],['fixture.purchase'],null,$context);
        $result = match ($operation) {
            'reserve' => $reservations->reserve($intent->base,$key),
            'release' => $reservations->release($intent->base,$key),
            'confirm' => $consumptions->confirm($intent->base,$key),
            'lookup' => $consumptions->lookup($intent->base,(string) $lookupOperation,$key),
        };
        if ($result['state'] === 'confirmed') {
            try { $result['receipt'] = (new ClosedRankedReceiptStore($this->db,$this->keyId))->receipt($intent,$result['consumption']); }
            catch (ModelViolation) {
                // The debit is already committed; unavailable proof retrieval cannot assert a refusal/rollback.
                throw new ModelViolation('pf_ranked_receipt_unknown');
            }
        }
        return $result;
    }
}
