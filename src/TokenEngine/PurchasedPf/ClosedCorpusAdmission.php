<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\DelegatedContext;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingValues;

/** One-use transport admission separate from every historical member nonce or economic primitive. */
final class ClosedCorpusAdmission
{
    private readonly ClosedReservationDatabase $connection;

    public function __construct(private readonly \wpdb $db)
    { ClosedEnvironment::assertIsolated($db,'hub'); $this->connection = new ClosedReservationDatabase($db); }

    /** Only the gateway calls this after authenticating both signatures and the exact context.
     * @param array<string,string> $request */
    public function accept(PeerPolicy $peer, array $request, string $digest): void
    {
        $peer->allow('pf.ranking.corpus');
        if ($peer->node !== 'fixture.fans' || $peer->audience !== 'fixture.hub') { throw new ModelViolation('pf_invalid_peer'); }
        DelegatedContext::nonce($request['nonce']); RankingValues::digest($digest);
        ModelValues::uuid($request['origin_id']); ModelValues::version($request['policy_version']);
        (new ClosedCorpusTransaction($this->db))->run(function () use ($peer,$request,$digest): array {
            if (!ClosedCorpusTransportSchema::ready($this->db)) { throw new ModelViolation('pf_corpus_transport_unavailable'); }
            // A request may expire while waiting for the owner mutex. Never admit it after that wait.
            DelegatedContext::fresh($request['issued_at'],$request['expires_at'],time());
            $table = ClosedCorpusTransportSchema::tables($this->db)['nonces']; $hash = hash('sha256',$request['nonce']);
            $known = $this->connection->row($table,'peer=%s AND nonce_sha256=%s',[$peer->node,$hash]);
            if ($known !== null) { throw new ModelViolation('pf_nonce_replayed'); }
            $this->connection->insert($table,['peer' => $peer->node,'nonce_sha256' => $hash,'request_sha256' => $digest,
                'origin_id' => $request['origin_id'],'policy_version' => $request['policy_version'],
                'issued_at' => $request['issued_at'],'expires_at' => $request['expires_at'],'admitted_at' => $this->connection->now()]);
            return [];
        });
    }
}
