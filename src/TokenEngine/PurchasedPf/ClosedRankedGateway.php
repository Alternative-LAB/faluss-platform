<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\DelegatedContext;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedIntent;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedTransport;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SignedEnvelope;

/** Closed version-specific owner adapter. No global registration or downgrade to historical wire. */
final class ClosedRankedGateway
{
    public function __construct(private readonly \wpdb $db, private readonly PeerPolicy $peer, private readonly string $keyId)
    {
        ClosedEnvironment::assertIsolated($db,'hub');
        if ($peer->node !== 'fixture.fans' || $peer->audience !== 'fixture.hub') { throw new ModelViolation('pf_fixture_peer_required'); }
    }

    public function handle(string $wire): string
    {
        ClosedEnvironment::assertIsolated($this->db,'hub');
        $payload = SignedEnvelope::open(SignedEnvelope::RANKING_REQUEST,CanonicalJson::object($wire,RankedTransport::MAX_WIRE),$this->peer,time());
        $request = RankedTransport::request($payload,$this->peer,time());
        $fresh = static function () use ($payload): void { DelegatedContext::fresh($payload['issued_at'],$payload['expires_at'],time()); };
        (new ClosedProtocolStore($this->db,'fixture.hub',$this->keyId))->acceptNonce($this->peer->node,$request['nonce'],
            hash('sha256',$wire),$request['intent']->base->values['member_faluss_id']);
        $outcome = 'ok';
        try {
            $fresh();
            $answer = (new ClosedRankedStore($this->db,$this->keyId))->execute($this->peer,$request['intent'],
                $request['operation'],$request['key'],$request['lookup'],$fresh);
            $result = ['state' => $answer['state']];
            if ($answer['state'] === 'confirmed') { $result['receipt'] = $answer['receipt']; }
        } catch (ModelViolation $error) {
            $outcome = str_ends_with($error->reason,'_unknown') ? 'unknown' : 'refused'; $result = ['reason' => $error->reason];
        }
        $now = time();
        $reply = ['contract' => RankedIntent::CONTRACT,'kind' => SignedEnvelope::RANKING_RESPONSE,
            'issuer' => 'fixture.hub','audience' => 'fixture.fans','operation' => $request['operation'],
            'nonce' => $request['nonce'],'request_sha256' => hash('sha256',$wire),
            'issued_at' => gmdate('Y-m-d\TH:i:s\Z',$now),'expires_at' => gmdate('Y-m-d\TH:i:s\Z',$now+60),
            'outcome' => $outcome,'result' => $result];
        return CanonicalJson::encode(SignedEnvelope::seal(SignedEnvelope::RANKING_RESPONSE,CanonicalJson::encode($reply),$this->keyId));
    }
}
