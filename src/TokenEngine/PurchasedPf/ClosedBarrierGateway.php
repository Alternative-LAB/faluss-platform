<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\BarrierTransport;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SignedEnvelope;

/** Authenticated closed owner composition. No route, pair registration, economic mutation or site migration. */
final class ClosedBarrierGateway
{
    public function __construct(private readonly \wpdb $db, private readonly PeerPolicy $peer, private readonly string $keyId)
    {
        ClosedEnvironment::assertIsolated($db,'hub');
        if ($peer->node !== 'fixture.fans' || $peer->audience !== 'fixture.hub') { throw new ModelViolation('pf_fixture_peer_required'); }
    }

    public function handle(string $wire): string
    {
        ClosedEnvironment::assertIsolated($this->db,'hub');
        $payload = SignedEnvelope::open(SignedEnvelope::BARRIER_REQUEST,CanonicalJson::object($wire,BarrierTransport::MAX_WIRE),$this->peer,time());
        $contract = $payload['contract'] ?? null;
        if (!is_string($contract) || !in_array($contract,[BarrierTransport::CONTRACT,BarrierTransport::COMPLETION_CONTRACT],true)) {
            throw new ModelViolation('pf_barrier_contract_mismatch');
        }
        $request = BarrierTransport::request($payload,$this->peer,time(),$contract); $digest = hash('sha256',$wire);
        (new ClosedBarrierAdmission($this->db))->accept($this->peer,$request,$digest,$contract);
        $fields = array_diff_key($request,array_flip(['operation_key','nonce','issued_at','expires_at']));
        $outcome = 'ok';
        try {
            $context = new ClosedBarrierContext($fields,$request['issued_at'],$request['expires_at'],$contract);
            $owner = new ClosedBarrierStore($this->db);
            $completion = $contract === BarrierTransport::COMPLETION_CONTRACT && ($request['object']['reason'] ?? '') === 'session_completed';
            $result = match ($request['operation']) {
                'register' => $owner->register($this->peer,$request['object'],$request['operation_key'],$context),
                'close' => $completion ? $owner->completeSession($this->peer,$request['object'],$request['operation_key'],$context)
                    : $owner->close($this->peer,$request['object'],$request['operation_key'],$context),
                'lookup' => $completion ? $owner->lookupCompletion($this->peer,$request['object'],$request['operation_key'],$context)
                    : $owner->lookup($this->peer,$request['lookup_operation'],$request['object'],$request['operation_key'],$context),
                default => throw new ModelViolation('pf_barrier_request_mismatch'),
            };
        } catch (ModelViolation $error) {
            $outcome = str_ends_with($error->reason,'_unknown') ? 'unknown' : 'refused'; $result = ['reason' => $error->reason];
        }
        $now = time();
        $reply = ['contract' => $contract,'kind' => SignedEnvelope::BARRIER_RESPONSE,
            'issuer' => 'fixture.hub','audience' => 'fixture.fans','nonce' => $request['nonce'],'request_sha256' => $digest,
            'issued_at' => gmdate('Y-m-d\TH:i:s\Z',$now),'expires_at' => gmdate('Y-m-d\TH:i:s\Z',$now+60),
            'outcome' => $outcome,'result' => $result] + BarrierTransport::responseFields($fields,$contract);
        BarrierTransport::response($reply,new PeerPolicy('fixture.hub','fixture.fans',['pf.ranking.context.register','pf.ranking.context.close','pf.lookup'],[]),$fields,$request['nonce'],$digest,$now,$contract);
        $answer = CanonicalJson::encode(SignedEnvelope::seal(SignedEnvelope::BARRIER_RESPONSE,CanonicalJson::encode($reply),$this->keyId));
        if (strlen($answer) > BarrierTransport::MAX_WIRE) { throw new ModelViolation('pf_payload_size'); }
        return $answer;
    }
}
