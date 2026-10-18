<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\PfContract;

use Faluss\Platform\Fans\Sso\FansLocalSession;
use Faluss\Platform\Fans\Sso\FansSsoService;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedIntent;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedTransport;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SignedEnvelope;

/** Existing 0.3 transport with the current local member and durable keys. No route or real peer admission. */
final class ClosedRankedClient
{
    public function __construct(private readonly \wpdb $db,private readonly PeerPolicy $peer,private readonly string $keyId,private readonly string $endpoint)
    {
        ClosedEnvironment::assertIsolated($db,'fans'); PeerPolicy::keyId($keyId);
        if ($db !== ($GLOBALS['wpdb'] ?? null) || $peer->node !== 'fixture.hub' || $peer->audience !== 'fixture.fans'
            || preg_match('#^http://127\.0\.0\.1:[1-9][0-9]{3,4}/index\.php\?rest_route=/faluss-b3-recipe/v1/ranked$#D',$endpoint)!==1) {
            throw new ModelViolation('pf_fixture_peer_required');
        }
    }

    public function usesConnection(\wpdb $db): bool { return $db === $this->db; }

    /** Only the server selection service supplies this complete immutable intention.
     * @return array<string,mixed> */
    public function reserve(RankedIntent $intent): array
    { return $this->exchange($intent,'reserve'); }

    /** Confirmation never rebuilds category/sessions from today's UI or requires a still-active Creator.
     * @return array<string,mixed> */
    public function confirm(string $attribution): array
    {
        $intent=$this->recover($attribution);
        (new ClosedRankedProtocolStore($this->db))->prepare($intent,'reserve',true);
        return $this->exchange($intent,'confirm');
    }

    /** @return array<string,mixed> */
    public function release(string $attribution): array
    {
        $intent=$this->recover($attribution);
        (new ClosedRankedProtocolStore($this->db))->prepare($intent,'reserve',true);
        return $this->exchange($intent,'release');
    }

    /** Primary lookup uses the already persisted operation key, never a replacement after uncertainty.
     * @return array<string,mixed> */
    public function lookup(string $attribution,string $operation): array
    {
        if (!in_array($operation,['reserve','confirm','release'],true)) { throw new ModelViolation('pf_invalid_operation'); }
        return $this->exchange($this->recover($attribution),'lookup',$operation);
    }

    private function recover(string $id): RankedIntent
    { return (new ClosedRankedProtocolStore($this->db))->recover($id,$this->member()); }

    private function member(): string
    {
        ClosedEnvironment::assertIsolated($this->db,'fans');
        $subject=FansSsoService::currentLinkedSubject();$expires=FansLocalSession::expires();
        if ($subject===null || $expires===null || $expires<=time()) { throw new ModelViolation('pf_linked_session_required'); }
        return $subject['faluss_id'];
    }

    /** @return array<string,mixed> */
    private function exchange(RankedIntent $intent,string $operation,string $lookup=''): array
    {
        if ($intent->values['member_faluss_id']!==$this->member()) { throw new ModelViolation('pf_local_intent_absent'); }
        $this->peer->allow('pf.'.$operation);
        $store=new ClosedRankedProtocolStore($this->db);
        $key=$store->prepare($intent,$operation==='lookup'?$lookup:$operation,$operation==='lookup');
        $request=RankedTransport::sealRequest($intent,$operation,$key,$lookup,$this->keyId,FansLocalSession::expires()??time());
        $response=wp_remote_post($this->endpoint,['body'=>$request['wire'],'headers'=>['Content-Type'=>'application/json','Accept'=>'application/json'],
            'timeout'=>8,'redirection'=>0,'limit_response_size'=>RankedTransport::MAX_WIRE+1,'sslverify'=>true,'cookies'=>[]]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response)!==200) { throw new ModelViolation('pf_transport_unknown'); }
        try {
            $wire=wp_remote_retrieve_body($response);
            $payload=SignedEnvelope::open(SignedEnvelope::RANKING_RESPONSE,CanonicalJson::object($wire,RankedTransport::MAX_WIRE),$this->peer,time());
            $answer=RankedTransport::response($payload,$this->peer,$intent,$operation,$request['nonce'],hash('sha256',$request['wire']),time());
        } catch (ModelViolation) { throw new ModelViolation('pf_transport_unknown'); }
        if ($answer['outcome']==='ok' && $answer['result']['state']==='confirmed') {
            // A local inbox failure cannot turn an already committed Hub debit into an economic rejection.
            $store->receive($intent,$answer['result']['receipt'],$this->peer);
            return ['outcome'=>'ok','result'=>['state'=>'confirmed']];
        }
        return $answer;
    }
}
