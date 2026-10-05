<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

/** Re-read immutable H2 facts on the primary; no inferred or foreign provenance. */
final class ClosedCorrectionFacts
{
    /** @param list<string> $sources */
    public function __construct(private readonly ClosedReservationDatabase $connection, private readonly array $sources)
    {
    }

    /** @return array<string,mixed> */
    public function lot(string $lotId, string $member): array
    {
        $store = new ClosedReservationStore($this->connection->database, ['fixture.fans'], $this->sources);
        $credit = $this->connection->row(ClosedReservationSchema::tables($this->connection->database)['credits'], 'lot_id=%s', [$lotId]);
        if ($credit === null) {
            throw new ModelViolation('h4_filiation_failure');
        }
        return $store->verifyCredit($credit, $member);
    }

    /** @return list<array{attribution_id:string,lot_id:string,purchased_pf:string,confirmed_at:string,cancelled_pf:string}> */
    public function allocations(string $lotId, string $member): array
    {
        $database = $this->connection->database;
        $h2 = ClosedReservationSchema::tables($database);
        $h4 = ClosedCorrectionSchema::tables($database);
        $rows = $this->connection->rows($database->prepare(
            'SELECT a.*,r.state,r.payload_json,r.member_faluss_id FROM %i a LEFT JOIN %i r ON r.attribution_id=a.attribution_id WHERE a.lot_id=%s ORDER BY a.attribution_id FOR UPDATE',
            $h2['allocations'], $h2['reservations'], $lotId));
        $result = [];
        foreach ($rows as $row) {
            if ($row['state'] === null || $row['member_faluss_id'] !== $member) {
                throw new ModelViolation('h4_filiation_failure');
            }
            if ($row['state'] !== 'confirmed') {
                continue;
            }
            $intent = AttributionIntent::fromArray(ClosedReservationDatabase::decode((string) $row['payload_json']));
            $fact = (new ClosedConsumptionStore($database, [$intent->values['client_authority']], $this->sources))->confirmedFact($intent);
            $matching = array_values(array_filter($fact['allocations'], static fn (array $a): bool => $a['lot_id'] === $lotId));
            if (count($matching) !== 1 || $matching[0]['purchased_pf'] !== $row['purchased_pf']) {
                throw new ModelViolation('h4_filiation_failure');
            }
            $state = $this->connection->row($h4['states'], 'attribution_id=%s AND lot_id=%s', [$row['attribution_id'], $lotId]);
            if ($state !== null) {
                self::verifyState($state);
                if ($state['original_pf'] !== $row['purchased_pf']) {
                    throw new ModelViolation('h4_filiation_failure');
                }
            }
            $result[] = ['attribution_id' => (string) $row['attribution_id'], 'lot_id' => $lotId,
                'purchased_pf' => (string) $row['purchased_pf'], 'confirmed_at' => (string) $fact['confirmed_at'],
                'cancelled_pf' => (string) ($state['cancelled_pf'] ?? '0')];
        }
        return $result;
    }

    /** @param array<string,mixed> $row */
    public static function verifyState(array $row): void
    {
        $values = array_intersect_key($row, array_flip(['attribution_id', 'lot_id', 'plan_id', 'source_revision',
            'original_pf', 'cancelled_pf', 'suspended_pf', 'net_pf']));
        if ($row['payload_sha256'] !== ModelValues::fingerprint($values)
            || (int) $row['original_pf'] !== (int) $row['cancelled_pf'] + (int) $row['suspended_pf'] + (int) $row['net_pf']
        ) {
            throw new ModelViolation('h4_filiation_failure');
        }
    }
}
