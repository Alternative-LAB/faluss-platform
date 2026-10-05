<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\PfContract;

use Faluss\Platform\TokenEngine\PurchasedPf\AttributionIntent;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\DelegatedContext;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PrivateReceipt;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SignedEnvelope;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\TransportMessage;

/** No bootstrap or automatic retry. Fixture-only fixed loopback transport and private proof inbox. */
final class ClosedClient
{
    public function __construct(private readonly \wpdb $database, private readonly PeerPolicy $peer,
        private readonly string $keyId, private readonly string $endpoint)
    {
        ClosedEnvironment::assertIsolated($database, 'fans');
        if ($peer->node !== 'fixture.hub' || $peer->audience !== 'fixture.fans'
            || preg_match('#^http://127\.0\.0\.1:[1-9][0-9]{3,4}/index\.php\?rest_route=/faluss-h3-recipe/v1/pf$#D', $endpoint) !== 1) {
            throw new ModelViolation('pf_fixture_peer_required');
        }
    }

    /** @param array<string,mixed> $input
     *  @return array<string,mixed> */
    public function exchange(array $input, string $operation, string $lookup = ''): array
    {
        TransportMessage::operation($operation, $lookup);
        $intent = ClosedDelegation::resolve($this->database, $input);
        $store = new ClosedProtocolStore($this->database);
        // Commit the stable key before sending. Unknown results never cause a replacement key.
        $key = $store->prepare($intent, $operation === 'lookup' ? $lookup : $operation, $operation === 'lookup');
        $request = self::request($intent, $operation, $key, $lookup, $this->keyId);
        $wire = $request['wire'];
        $response = wp_remote_post($this->endpoint, ['body' => $wire, 'headers' => ['Content-Type' => 'application/json',
            'Accept' => 'application/json'], 'timeout' => 8, 'redirection' => 0, 'limit_response_size' => 65537,
            'sslverify' => true, 'cookies' => []]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            throw new ModelViolation('pf_transport_unknown');
        }
        try {
            return $this->accept(wp_remote_retrieve_body($response), $intent, $operation, $request['nonce'], hash('sha256', $wire));
        } catch (ModelViolation $error) {
            // A malformed/untrusted body cannot prove that the Hub transaction did not commit.
            if (str_starts_with($error->reason, 'pf_local_') || $error->reason === 'pf_receipt_conflict') { throw $error; }
            throw new ModelViolation('pf_transport_unknown');
        }
    }

    /** Public construction is still bound to closed fixture intent; not a user endpoint.
     *  @return array{wire:string,nonce:string} */
    public static function request(AttributionIntent $intent, string $operation, string $key, string $lookup, string $keyId): array
    {
        TransportMessage::operation($operation, $lookup);
        \Faluss\Platform\TokenEngine\PurchasedPf\ModelValues::keyHash($key);
        if ($intent->values['client_authority'] !== 'fixture.fans') { throw new ModelViolation('pf_fixture_peer_required'); }
        $now = time();
        $nonce = bin2hex(random_bytes(32));
        $context = ['contract' => DelegatedContext::CONTRACT, 'kind' => SignedEnvelope::CONTEXT,
            'issuer' => 'fixture.fans', 'audience' => 'fixture.hub', 'operation' => $operation, 'nonce' => $nonce,
            'key_sha256' => hash('sha256', $key), 'lookup_operation' => $lookup, 'intent' => $intent->values,
            'issued_at' => gmdate('Y-m-d\TH:i:s\Z', $now), 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $now + 60)];
        $payload = ['contract' => DelegatedContext::CONTRACT, 'kind' => SignedEnvelope::REQUEST,
            'issuer' => 'fixture.fans', 'audience' => 'fixture.hub', 'operation' => $operation, 'nonce' => $nonce,
            'operation_key' => $key, 'lookup_operation' => $lookup,
            'issued_at' => $context['issued_at'], 'expires_at' => $context['expires_at'],
            'context' => SignedEnvelope::seal(SignedEnvelope::CONTEXT, CanonicalJson::encode($context), $keyId)];
        return ['wire' => CanonicalJson::encode(SignedEnvelope::seal(SignedEnvelope::REQUEST, CanonicalJson::encode($payload), $keyId)), 'nonce' => $nonce];
    }

    /** No inbox write until both fresh response and private receipt have been verified.
     * @return array<string,mixed> */
    public function accept(string $wire, AttributionIntent $intent, string $operation, string $nonce, string $digest): array
    {
        ClosedEnvironment::assertIsolated($this->database, 'fans');
        $outer = CanonicalJson::object($wire, 65536);
        $payload = SignedEnvelope::open(SignedEnvelope::RESPONSE, $outer, $this->peer, time());
        $answer = TransportMessage::response($payload, $this->peer, $operation, $nonce, $digest, time());
        if ($answer['outcome'] !== 'ok') { return $answer; }
        if ($answer['result']['state'] === 'confirmed') {
            $receipt = SignedEnvelope::open(SignedEnvelope::RECEIPT, $answer['result']['receipt'], $this->peer, time());
            PrivateReceipt::validate($receipt, 'fixture.hub', 'fixture.fans', $intent);
            (new ClosedProtocolStore($this->database))->receive($intent, $receipt);
            // Private wire payload is not echoed to the browser, logs or fixture report.
            return ['outcome' => 'ok', 'result' => ['state' => 'confirmed', 'receipt_id' => $receipt['receipt_id']]];
        }
        return $answer;
    }
}
