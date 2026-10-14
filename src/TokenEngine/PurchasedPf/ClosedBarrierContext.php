<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\BarrierTransport;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\DelegatedContext;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingBarrier;

/** Owner composition after transport authentication; never a signature verifier or network admission. */
final class ClosedBarrierContext
{
    /** @param array<string,mixed> $fields */
    public function __construct(private readonly array $fields, private readonly string $issuedAt, private readonly string $expiresAt,
        private readonly string $contract = BarrierTransport::CONTRACT)
    { BarrierTransport::fields($fields,$contract); $this->assertFresh(); }

    public function assertContract(string $expected): void
    { if ($this->contract !== $expected) { throw new ModelViolation('pf_barrier_context_mismatch'); } }

    public function assertFresh(): void
    { DelegatedContext::fresh($this->issuedAt,$this->expiresAt,time()); }

    /** Full immutable action binding supplements the historical object's digest only for this transport.
     * @param array<string,mixed> $object */
    public function digest(string $operation, array $object): string
    {
        $target = $this->fields['operation'] === 'lookup' ? $this->fields['lookup_operation'] : $this->fields['operation'];
        if ($target !== $operation || CanonicalJson::encode($object) !== CanonicalJson::encode($this->fields['object'])) {
            throw new ModelViolation('pf_barrier_context_mismatch');
        }
        return hash('sha256',CanonicalJson::encode(['contract' => $this->contract,'action_id' => $this->fields['action_id'],
            'origin_id' => $this->fields['origin_id'],'policy_version' => $this->fields['policy_version'],'operation' => $operation,'object' => $object]));
    }

    /** The sparse close reference cannot authorize its caller-supplied origin on its own.
     * @param array<string,string>|null $row */
    public function assertOwner(?array $row): void
    {
        if ($row === null) { throw new ModelViolation('pf_barrier_origin_unavailable'); }
        $descriptor = RankingBarrier::descriptor(CanonicalJson::object($row['descriptor_json']));
        $reference = RankingBarrier::reference($descriptor['content']);
        if ($row['owner'] !== 'fixture.fans' || $row['descriptor_sha256'] !== hash('sha256',CanonicalJson::encode($descriptor))
            || $reference['barrier_key'] !== $row['barrier_key'] || $reference['version'] !== $row['version']
            || $reference['content_sha256'] !== $row['content_sha256']
            || $descriptor['content']['origin_id'] !== $this->fields['origin_id']
            || $descriptor['content']['policy_version'] !== $this->fields['policy_version']) {
            throw new ModelViolation('pf_barrier_context_mismatch');
        }
        $this->assertFresh();
    }
}
