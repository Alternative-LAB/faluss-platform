<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SignedEnvelope;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SnapshotDocument;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SnapshotEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SnapshotTransport;

/** Closed read-only owner adapter. Never registered by the plugin bootstrap. */
final class ClosedSnapshotGateway
{
    public function __construct(private readonly \wpdb $database, private readonly PeerPolicy $peer, private readonly string $keyId)
    {
        SnapshotEnvironment::assertIsolated($database, 'hub');
        ClosedCorrectionEnvironment::assertIsolated($database);
    }

    public function handle(string $wire): string
    {
        SnapshotEnvironment::assertIsolated($this->database, 'hub');
        $outer = CanonicalJson::object($wire, 65536);
        $request = SnapshotTransport::request(SignedEnvelope::open(SignedEnvelope::SNAPSHOT_REQUEST, $outer, $this->peer, time()), $this->peer, time());
        (new ClosedProtocolStore($this->database, 'fixture.hub', $this->keyId))->acceptNonce(
            $this->peer->node, $request['nonce'], hash('sha256', $wire), $request['member_faluss_id']);
        $outcome = 'ok';
        try {
            $store = new ClosedSnapshotStore($this->database, ['fixture.purchase']);
            $result = match ($request['operation']) {
                'start' => $store->create($request['member_faluss_id'], 'fixture.fans', $request['read_key']),
                'page' => $store->page($request['member_faluss_id'], 'fixture.fans', $request['snapshot_id'], $request['cursor']),
                'finish' => $store->finish($request['member_faluss_id'], 'fixture.fans', $request['snapshot_id']),
                default => throw new ModelViolation('pf_snapshot_request_mismatch'),
            };
        } catch (ModelViolation $error) {
            $outcome = str_ends_with($error->reason, '_unknown') ? 'unknown' : 'refused';
            $result = ['reason' => $error->reason];
        }
        $now = time();
        $response = ['contract' => SnapshotDocument::CONTRACT, 'kind' => SignedEnvelope::SNAPSHOT_RESPONSE,
            'issuer' => 'fixture.hub', 'audience' => 'fixture.fans', 'read_id' => $request['read_id'],
            'operation' => $request['operation'], 'member_faluss_id' => $request['member_faluss_id'],
            'nonce' => $request['nonce'], 'request_sha256' => hash('sha256', $wire),
            'issued_at' => gmdate('Y-m-d\TH:i:s\Z', $now), 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $now + 60),
            'outcome' => $outcome, 'result' => $result];
        return CanonicalJson::encode(SignedEnvelope::seal(SignedEnvelope::SNAPSHOT_RESPONSE, CanonicalJson::encode($response), $this->keyId));
    }
}
