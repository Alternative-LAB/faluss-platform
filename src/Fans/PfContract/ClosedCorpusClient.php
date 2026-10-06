<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\PfContract;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CorpusTransport;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SignedEnvelope;

/** One exchange only; its caller durably stores the read key before HTTP. No automatic retry or browser route. */
final class ClosedCorpusClient
{
    public function __construct(private readonly \wpdb $db, private readonly PeerPolicy $peer, private readonly string $keyId,
        private readonly string $endpoint, private readonly string $origin, private readonly string $policy)
    {
        ClosedEnvironment::assertIsolated($db,'fans'); $peer->allow('pf.ranking.corpus'); ModelValues::uuid($origin); ModelValues::version($policy);
        if ($peer->node !== 'fixture.hub' || $peer->audience !== 'fixture.fans'
            || preg_match('#^http://127\.0\.0\.1:([1-9][0-9]{3,4})/index\.php\?rest_route=/faluss-b3-recipe/v1/corpus$#D',$endpoint,$port) !== 1
            || (int) $port[1] > 65535) {
            throw new ModelViolation('pf_fixture_peer_required');
        }
    }

    /** Global owner context only, never a member's SSO delegation.
     * @param array<string,mixed> $fields
     * @return array{outcome:string,result:array<string,mixed>} */
    public function exchange(array $fields, string $key): array
    {
        ClosedEnvironment::assertIsolated($this->db,'fans'); CorpusTransport::fields($fields);
        if ($fields['origin_id'] !== $this->origin || $fields['policy_version'] !== $this->policy) { throw new ModelViolation('pf_corpus_context_mismatch'); }
        $request = CorpusTransport::sealRequest($fields,$key,$this->keyId,time()+60); $wire = $request['wire'];
        $response = wp_remote_post($this->endpoint,['body' => $wire,'headers' => ['Content-Type' => 'application/json','Accept' => 'application/json'],
            'timeout' => 8,'redirection' => 0,'limit_response_size' => CorpusTransport::MAX_RESPONSE_WIRE+1,'sslverify' => true,'cookies' => []]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { throw new ModelViolation('pf_transport_unknown'); }
        return $this->accept(wp_remote_retrieve_body($response),$fields,$request['nonce'],hash('sha256',$wire));
    }

    /** No private facts accepted until signature and exact response binding have both passed.
     * @param array<string,mixed> $fields
     * @return array{outcome:string,result:array<string,mixed>} */
    public function accept(string $wire, array $fields, string $nonce, string $digest): array
    {
        ClosedEnvironment::assertIsolated($this->db,'fans'); CorpusTransport::fields($fields);
        if ($fields['origin_id'] !== $this->origin || $fields['policy_version'] !== $this->policy) { throw new ModelViolation('pf_corpus_context_mismatch'); }
        try {
            $payload = SignedEnvelope::open(SignedEnvelope::CORPUS_RESPONSE,CanonicalJson::object($wire,CorpusTransport::MAX_RESPONSE_WIRE),$this->peer,time());
            return CorpusTransport::response($payload,$this->peer,$fields,$nonce,$digest,time());
        } catch (ModelViolation) { throw new ModelViolation('pf_transport_unknown'); }
    }
}
