<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\PfContract;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\BarrierTransport;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;

/** One lookup per advance, no cron, implicit refresh or fallback registration. */
final class ClosedBarrierPrimaryClient
{
    public function __construct(private readonly \wpdb $db, private readonly ClosedBarrierInbox $inbox,
        private readonly ClosedBarrierPrimaryRead $reader, private readonly string $keyId, private readonly string $endpoint)
    {
        ClosedEnvironment::assertIsolated($db,'fans');
        if (!$inbox->usesConnection($db) || !$reader->usesInbox($inbox)) { throw new ModelViolation('pf_local_barrier_primary_connection'); }
        if (preg_match('#^http://127\.0\.0\.1:([1-9][0-9]{3,4})/index\.php\?rest_route=/faluss-b3-recipe/v1/barrier$#D',$endpoint,$port) !== 1
            || (int) $port[1] > 65535) { throw new ModelViolation('pf_fixture_peer_required'); }
    }

    /** Preparation is explicit and durable. This method resumes only that revision.
     * @return array<string,mixed> */
    public function advance(string $action): array
    {
        try {
            $progress = $this->reader->recover($action);
            if (in_array($progress['phase'],['current','refused'],true)) { return $progress; }
            $original = $this->inbox->recover($action);
            $sealed = BarrierTransport::sealRequest($progress['fields'],$original['operation_key'],$this->keyId,time()+60,$progress['contract']);
            $this->reader->request($action,$sealed);
            ClosedEnvironment::assertIsolated($this->db,'fans');
            $response = wp_remote_post($this->endpoint,['body' => $sealed['wire'],
                'headers' => ['Content-Type' => 'application/json','Accept' => 'application/json'], 'timeout' => 8,
                'redirection' => 0,'limit_response_size' => BarrierTransport::MAX_WIRE+1,'sslverify' => true,'cookies' => []]);
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200 || wp_remote_retrieve_body($response) === '') {
                throw new ModelViolation('pf_transport_unknown');
            }
            try { return $this->reader->accept($action,['wire' => wp_remote_retrieve_body($response),
                'nonce' => $sealed['nonce'],'request_sha256' => hash('sha256',$sealed['wire'])]); }
            catch (ModelViolation $error) {
                if (!in_array($error->reason,['pf_local_barrier_busy','pf_local_barrier_commit_unknown','pf_local_barrier_checkpoint_moved'],true)) {
                    throw new ModelViolation('pf_transport_unknown');
                }
                throw $error;
            }
        } catch (ModelViolation $error) {
            if (!in_array($error->reason,['pf_transport_unknown','pf_local_barrier_busy','pf_local_barrier_commit_unknown','pf_local_barrier_checkpoint_moved'],true)) { throw $error; }
            return ['action_id' => $action,'phase' => 'pending','reason' => $error->reason];
        }
    }
}
