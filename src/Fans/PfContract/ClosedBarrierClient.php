<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\PfContract;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\BarrierTransport;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;

/** Bounded lifecycle recovery. No cron, site route, fallback key, cookies or redirects. */
final class ClosedBarrierClient
{
    private const RETRYABLE = ['pf_transport_unknown','pf_local_barrier_commit_unknown','pf_local_barrier_busy','pf_local_barrier_checkpoint_moved'];

    public function __construct(private readonly \wpdb $db, private readonly ClosedBarrierInbox $inbox,
        private readonly string $keyId, private readonly string $endpoint)
    {
        ClosedEnvironment::assertIsolated($db,'fans');
        if (preg_match('#^http://127\.0\.0\.1:([1-9][0-9]{3,4})/index\.php\?rest_route=/faluss-b3-recipe/v1/barrier$#D',$endpoint,$port) !== 1
            || (int) $port[1] > 65535) { throw new ModelViolation('pf_fixture_peer_required'); }
    }

    /** The original action remains durable even when its registration predecessor must be resumed.
     * @return array<string,string> */
    public function advance(string $actionId, int $steps = 4): array
    {
        ModelValues::uuid($actionId);
        if ($steps < 1 || $steps > 16) { throw new ModelViolation('pf_local_barrier_budget'); }
        $completed = 0;
        try {
            $target = $this->inbox->recover($actionId);
            while (!in_array($target['phase'],['acknowledged','refused'],true) && $completed < $steps) {
                $progress = $target['blocking_action_id'] === '' ? $target : $this->inbox->recover($target['blocking_action_id']);
                if ($progress['phase'] === 'refused') {
                    return ['state' => 'pending','action_id' => $actionId,'local_state' => $target['state'],
                        'completed_steps' => (string) $completed,'reason' => 'pf_local_barrier_opening_refused'];
                }
                $fields = $this->inbox->fields($progress);
                $sealed = BarrierTransport::sealRequest($fields,$progress['operation_key'],$this->keyId,time()+60,
                    $progress['contract'] ?? BarrierTransport::CONTRACT);
                $this->inbox->request($fields,$sealed);
                $wire = $this->post($sealed['wire']);
                // Invalid or unauthenticated delivery remains uncertain, never a trusted owner refusal.
                try {
                    $this->inbox->accept(['wire' => $wire,'fields' => $fields,'nonce' => $sealed['nonce'],
                        'request_sha256' => hash('sha256',$sealed['wire'])]);
                } catch (ModelViolation $error) {
                    if (!in_array($error->reason,self::RETRYABLE,true)) { throw new ModelViolation('pf_transport_unknown'); }
                    throw $error;
                }
                $completed++; $target = $this->inbox->recover($actionId);
            }
            return ['state' => match ($target['phase']) {'acknowledged' => 'acknowledged','refused' => 'unavailable',default => 'pending'},
                'action_id' => $actionId,'local_state' => $target['state'],'completed_steps' => (string) $completed];
        } catch (ModelViolation $error) {
            if (!in_array($error->reason,self::RETRYABLE,true)) { throw $error; }
            return ['state' => 'pending','action_id' => $actionId,'completed_steps' => (string) $completed,'reason' => $error->reason];
        }
    }

    private function post(string $wire): string
    {
        ClosedEnvironment::assertIsolated($this->db,'fans');
        $response = wp_remote_post($this->endpoint,['body' => $wire,'headers' => ['Content-Type' => 'application/json','Accept' => 'application/json'],
            'timeout' => 8,'redirection' => 0,'limit_response_size' => BarrierTransport::MAX_WIRE+1,'sslverify' => true,'cookies' => []]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200 || wp_remote_retrieve_body($response) === '') {
            throw new ModelViolation('pf_transport_unknown');
        }
        return wp_remote_retrieve_body($response);
    }
}
