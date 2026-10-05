<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\DelegatedContext;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SignedEnvelope;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\TransportMessage;

/** Closed H3 owner adapter. Only a disposable test loader can register HTTP. */
final class ClosedGateway
{
    public function __construct(private readonly \wpdb $database, private readonly PeerPolicy $peer,
        private readonly string $keyId)
    {
        ClosedEnvironment::assertIsolated($database, 'hub');
        if ($peer->node !== 'fixture.fans' || $peer->audience !== 'fixture.hub') {
            throw new ModelViolation('pf_fixture_peer_required');
        }
    }

    /** Throws before admission on an untrusted request; returns only a bound signed response after admission. */
    public function handle(string $wire): string
    {
        ClosedEnvironment::assertIsolated($this->database, 'hub');
        $outer = CanonicalJson::object($wire, 65536);
        $payload = SignedEnvelope::open(SignedEnvelope::REQUEST, $outer, $this->peer, time());
        $request = TransportMessage::request($payload, $this->peer, time());
        $protocol = new ClosedProtocolStore($this->database, 'fixture.hub', $this->keyId);
        $protocol->acceptNonce($this->peer->node, $request['nonce'], hash('sha256', $wire), $request['intent']->values['member_faluss_id']);
        $outcome = 'ok';
        try {
            $reservations = new ClosedReservationStore($this->database, ['fixture.fans'], ['fixture.purchase']);
            $consumptions = new ClosedConsumptionStore($this->database, ['fixture.fans'], ['fixture.purchase'], $protocol->record(...));
            $answer = match ($request['operation']) {
                'reserve' => $reservations->reserve($request['intent'], $request['key']),
                'release' => $reservations->release($request['intent'], $request['key']),
                'confirm' => $consumptions->confirm($request['intent'], $request['key']),
                'lookup' => $consumptions->lookup($request['intent'], $request['lookup'], $request['key']),
                default => throw new ModelViolation('pf_invalid_operation'),
            };
            $result = ['state' => $answer['state']];
            if ($answer['state'] === 'confirmed') {
                $result['receipt'] = $protocol->receipt($request['intent'], $answer['consumption']);
            }
        } catch (ModelViolation $error) {
            $outcome = str_ends_with($error->reason, '_unknown') ? 'unknown' : 'refused';
            $result = ['reason' => $error->reason];
        }
        $now = time();
        $response = ['contract' => DelegatedContext::CONTRACT, 'kind' => SignedEnvelope::RESPONSE,
            'issuer' => 'fixture.hub', 'audience' => 'fixture.fans', 'operation' => $request['operation'],
            'nonce' => $request['nonce'], 'request_sha256' => hash('sha256', $wire),
            'issued_at' => gmdate('Y-m-d\TH:i:s\Z', $now), 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $now + 60),
            'outcome' => $outcome, 'result' => $result];
        return CanonicalJson::encode(SignedEnvelope::seal(SignedEnvelope::RESPONSE, CanonicalJson::encode($response), $this->keyId));
    }
}
