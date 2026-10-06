<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CorpusTransport;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingCorpusDocument;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SignedEnvelope;

/** Closed owner adapter; no route or production peer is registered by this class. */
final class ClosedCorpusGateway
{
    public function __construct(private readonly \wpdb $db, private readonly PeerPolicy $peer, private readonly string $keyId)
    {
        ClosedEnvironment::assertIsolated($db,'hub'); $peer->allow('pf.ranking.corpus');
        if ($peer->node !== 'fixture.fans' || $peer->audience !== 'fixture.hub') { throw new ModelViolation('pf_fixture_peer_required'); }
    }

    public function handle(string $wire): string
    {
        ClosedEnvironment::assertIsolated($this->db,'hub'); $now = time();
        $payload = SignedEnvelope::open(SignedEnvelope::CORPUS_REQUEST,CanonicalJson::object($wire,CorpusTransport::MAX_REQUEST_WIRE),$this->peer,$now);
        $request = CorpusTransport::request($payload,$this->peer,$now); $digest = hash('sha256',$wire);
        // Replays are rejected before dispatch, rather than signed as a successful repeated admission.
        (new ClosedCorpusAdmission($this->db))->accept($this->peer,$request,$digest);
        $outcome = 'ok';
        try {
            $owner = new ClosedRankingCorpusStore($this->db,['fixture.purchase'],$this->keyId);
            $origin = $request['origin_id']; $policy = $request['policy_version'];
            $result = match ($request['operation']) {
                'start' => ['state' => 'materialized','page' => $owner->create($this->peer,$origin,$policy,$request['read_key'])],
                'lookup' => $owner->lookup($this->peer,$origin,$policy,$request['read_key']),
                'page' => ['state' => 'page','page' => $owner->page($this->peer,$origin,$policy,$request['corpus_id'],$request['cursor'])],
                'finish' => $owner->finish($this->peer,$origin,$policy,$request['corpus_id']),
                default => throw new ModelViolation('pf_corpus_request_mismatch'),
            };
        } catch (ModelViolation $error) {
            $outcome = str_ends_with($error->reason,'_unknown') ? 'unknown' : 'refused'; $result = ['reason' => $error->reason];
        }
        $now = time(); $fields = array_intersect_key($request,array_flip(['operation','read_id','origin_id','policy_version','corpus_id','cursor']));
        $response = ['contract' => RankingCorpusDocument::CONTRACT,'kind' => SignedEnvelope::CORPUS_RESPONSE,
            'issuer' => 'fixture.hub','audience' => 'fixture.fans','nonce' => $request['nonce'],'request_sha256' => $digest,
            'issued_at' => gmdate('Y-m-d\TH:i:s\Z',$now),'expires_at' => gmdate('Y-m-d\TH:i:s\Z',$now+60),
            'outcome' => $outcome,'result' => $result] + $fields;
        CorpusTransport::response($response,new PeerPolicy('fixture.hub','fixture.fans',['pf.ranking.corpus'],[]),$fields,$request['nonce'],$digest,$now);
        $answer = CanonicalJson::encode(SignedEnvelope::seal(SignedEnvelope::CORPUS_RESPONSE,CanonicalJson::encode($response),$this->keyId));
        if (strlen($answer) > CorpusTransport::MAX_RESPONSE_WIRE) { throw new ModelViolation('pf_payload_size'); }
        return $answer;
    }
}
