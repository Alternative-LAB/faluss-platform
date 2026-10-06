<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\PfContract;

use Faluss\Platform\Fans\Sso\FansLocalSession;
use Faluss\Platform\Fans\Sso\FansSsoService;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SignedEnvelope;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SnapshotEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SnapshotTransport;

/** One bounded private read per explicit advance; no automatic background retry, ledger or public route. */
final class ClosedSnapshotClient
{
    public function __construct(private readonly \wpdb $database, private readonly PeerPolicy $peer,
        private readonly string $keyId, private readonly string $endpoint, private readonly string $epoch)
    {
        SnapshotEnvironment::assertIsolated($database, 'fans');
        if ($peer->node !== 'fixture.hub' || $peer->audience !== 'fixture.fans'
            || preg_match('#^http://127\.0\.0\.1:[1-9][0-9]{3,4}/index\.php\?rest_route=/faluss-h4-recipe/v1/snapshot$#D', $endpoint) !== 1) {
            throw new ModelViolation('pf_fixture_peer_required');
        }
    }

    /** Resolve the member only from the current linked local session.
     * @return array{state:string,pages:string} */
    public function advance(string $readId): array
    {
        SnapshotEnvironment::assertIsolated($this->database, 'fans');
        $subject = FansSsoService::currentLinkedSubject();
        $expires = FansLocalSession::expires();
        if ($subject === null || $expires === null || $expires <= time()) { throw new ModelViolation('pf_linked_session_required'); }
        $member = $subject['faluss_id'];
        $store = new ClosedSnapshotInbox($this->database, $this->epoch);
        $progress = $store->prepare($member, $readId);
        if (in_array($progress['phase'], ['current', 'refused'], true)) { return self::status($progress); }
        $fields = ['operation' => $progress['phase'], 'read_id' => $readId, 'member_faluss_id' => $member,
            'snapshot_id' => $progress['manifest']['snapshot_id'] ?? '', 'cursor' => $progress['next_cursor'] ?? ''];
        $request = SnapshotTransport::sealRequest($fields, $progress['read_key'], $this->keyId, $expires);
        $response = wp_remote_post($this->endpoint, ['body' => $request['wire'], 'headers' => ['Content-Type' => 'application/json',
            'Accept' => 'application/json'], 'timeout' => 8, 'redirection' => 0, 'limit_response_size' => 360001,
            'sslverify' => true, 'cookies' => []]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { throw new ModelViolation('pf_transport_unknown'); }
        try {
            return $this->accept(wp_remote_retrieve_body($response), $fields, $request['nonce'], hash('sha256', $request['wire']));
        } catch (ModelViolation $error) {
            if (str_starts_with($error->reason, 'pf_local_')
                || in_array($error->reason, ['pf_snapshot_incomplete', 'pf_snapshot_regression', 'pf_snapshot_context_mismatch'], true)) {
                throw $error;
            }
            // An invalid body does not prove that the stable Hub read did not commit.
            throw new ModelViolation('pf_transport_unknown');
        }
    }

    /** @param array<string,mixed> $fields
     * @return array{state:string,pages:string} */
    public function accept(string $wire, array $fields, string $nonce, string $digest): array
    {
        SnapshotEnvironment::assertIsolated($this->database, 'fans');
        SnapshotTransport::fields($fields);
        $payload = SignedEnvelope::open(SignedEnvelope::SNAPSHOT_RESPONSE, CanonicalJson::object($wire, 360000), $this->peer, time());
        $answer = SnapshotTransport::response($payload, $this->peer, $fields, $nonce, $digest, time());
        $store = new ClosedSnapshotInbox($this->database, $this->epoch);
        if ($answer['outcome'] === 'unknown') { throw new ModelViolation('pf_transport_unknown'); }
        if ($answer['outcome'] === 'refused') { return self::status($store->refuse($fields['member_faluss_id'], $fields['read_id'])); }
        $progress = $fields['operation'] === 'finish'
            ? $store->finish($fields['member_faluss_id'], $fields['read_id'], $answer['result'], $wire)
            : $store->page($fields['member_faluss_id'], $fields['read_id'], $answer['result'], $wire);
        return self::status($progress);
    }

    /** @param array<string,mixed> $progress
     * @return array{state:string,pages:string} */
    private static function status(array $progress): array
    {
        return ['state' => $progress['phase'], 'pages' => $progress['next_page']];
    }
}
