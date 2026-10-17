<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\Fans\PfContract\ClosedBarrierInbox;
use Faluss\Platform\Fans\PfContract\ClosedBarrierPrimaryClient;
use Faluss\Platform\Fans\PfContract\ClosedBarrierPrimaryRead;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;

/** Explicit current-primary read after a lifecycle ACK, never inferred from the historical ACK. */
final class ClosedSessionFreshness
{
    public function __construct(private readonly \wpdb $db, ClosedBarrierInbox $inbox,
        private readonly ClosedBarrierPrimaryRead $reader, private readonly ClosedBarrierPrimaryClient $client)
    {
        ClosedEnvironment::assertIsolated($db, 'fans');
        if (!$inbox->usesConnection($db) || !$reader->usesInbox($inbox) || !$client->usesReader($reader)) {
            throw new ModelViolation('hof_lifecycle_primary_connection');
        }
    }

    public function usesConnection(\wpdb $db): bool { return $this->db === $db; }

    /** @return array<string,mixed> */
    public function refresh(string $action): array
    {
        ClosedEnvironment::assertIsolated($this->db, 'fans');
        $this->reader->prepare($action);
        return $this->client->advance($action);
    }
}
