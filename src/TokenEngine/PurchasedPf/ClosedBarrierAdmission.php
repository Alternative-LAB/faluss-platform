<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\BarrierTransport;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\DelegatedContext;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingValues;
use Throwable;

/** One-use nonce admission after signature verification. No barrier decision or economic write. */
final class ClosedBarrierAdmission
{
    private readonly ClosedReservationDatabase $connection;

    public function __construct(private readonly \wpdb $db)
    { ClosedEnvironment::assertIsolated($db,'hub'); $this->connection = new ClosedReservationDatabase($db); }

    /** The gateway must authenticate both signature domains before calling.
     * @param array<string,mixed> $request */
    public function accept(PeerPolicy $peer, array $request, string $digest): void
    {
        ClosedEnvironment::assertIsolated($this->db,'hub');
        ModelValues::exactKeys($request,['operation','action_id','origin_id','policy_version','lookup_operation','object',
            'operation_key','nonce','issued_at','expires_at']);
        BarrierTransport::fields(array_diff_key($request,array_flip(['operation_key','nonce','issued_at','expires_at'])));
        if ($peer->node !== 'fixture.fans' || $peer->audience !== 'fixture.hub') { throw new ModelViolation('pf_invalid_peer'); }
        $target = $request['operation'] === 'lookup' ? $request['lookup_operation'] : $request['operation'];
        $peer->allow('pf.ranking.context.' . $target);
        if ($request['operation'] === 'lookup') { $peer->allow('pf.lookup'); }
        DelegatedContext::nonce($request['nonce']); RankingValues::digest($digest); ModelValues::keyHash($request['operation_key']);
        DelegatedContext::fresh($request['issued_at'],$request['expires_at'],time());
        $hash = hash('sha256',$request['nonce']);
        $lock = 'pf_b3bh_' . substr(hash('sha256',$this->db->prefix . ':' . $peer->node . ':' . $hash),0,40);
        $held = $started = false; $suppressed = $this->db->suppress_errors(true);
        try {
            if ((string) $this->connection->scalar('SELECT @@in_transaction') !== '0') { throw new ModelViolation('nested_transaction_refused'); }
            if ((string) $this->connection->scalar($this->db->prepare('SELECT GET_LOCK(%s,10)',$lock)) !== '1') {
                throw new ModelViolation('pf_barrier_admission_unavailable');
            }
            $held = true;
            if (!ClosedBarrierTransportSchema::ready($this->db)) { throw new ModelViolation('pf_barrier_transport_unavailable'); }
            DelegatedContext::fresh($request['issued_at'],$request['expires_at'],time());
            $this->connection->query('START TRANSACTION'); $started = true;
            $table = ClosedBarrierTransportSchema::tables($this->db)['nonces'];
            if ($this->connection->row($table,'peer=%s AND nonce_sha256=%s',[$peer->node,$hash]) !== null) {
                throw new ModelViolation('pf_nonce_replayed');
            }
            $this->connection->insert($table,['peer' => $peer->node,'nonce_sha256' => $hash,'request_sha256' => $digest,
                'action_id' => $request['action_id'],'origin_id' => $request['origin_id'],'policy_version' => $request['policy_version'],
                'issued_at' => $request['issued_at'],'expires_at' => $request['expires_at'],'admitted_at' => $this->connection->now()]);
            DelegatedContext::fresh($request['issued_at'],$request['expires_at'],time());
            if ($this->db->query('COMMIT') === false || $this->db->last_error !== '') { throw new ModelViolation('pf_barrier_admission_unknown'); }
            $started = false;
        } catch (Throwable $error) {
            if ($started) { $this->db->query('ROLLBACK'); } throw $error;
        } finally {
            $released = !$held || (string) $this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)',$lock)) === '1';
            $this->db->suppress_errors($suppressed);
            if (!$released) { throw new ModelViolation('pf_barrier_admission_unknown'); }
        }
    }
}
