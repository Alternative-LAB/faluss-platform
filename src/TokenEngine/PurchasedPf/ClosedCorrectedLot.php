<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

/** Additive eligibility: absent H4 schema retains exact H2 behavior. */
final class ClosedCorrectedLot
{
    /**
     * @param array<string,mixed> $lot
     * @return array{eligible:bool,available_cancelled_pf:int}
     */
    public static function view(ClosedReservationDatabase $connection, array $lot): array
    {
        $database = $connection->database;
        $tables = ClosedCorrectionSchema::tables($database);
        $exists = $connection->scalar($database->prepare('SHOW TABLES LIKE %s', $database->esc_like($tables['plans'])));
        if ($exists === null) {
            return ['eligible' => $lot['source_state'] === 'confirmed', 'available_cancelled_pf' => 0];
        }
        if (!ClosedCorrectionSchema::ready($database)) {
            throw new ModelViolation('h4_schema_unavailable');
        }
        $rows = $connection->rows($database->prepare('SELECT * FROM %i WHERE lot_id=%s ORDER BY source_revision DESC LIMIT 1 FOR UPDATE',
            $tables['plans'], $lot['lot_id']));
        $plan = $rows[0] ?? null;
        if ($plan === null) {
            // A source revised via H1 without a matching corrective transaction remains closed.
            $credit = $connection->row(ClosedReservationSchema::tables($database)['credits'], 'lot_id=%s', [$lot['lot_id']]);
            return ['eligible' => $lot['source_state'] === 'confirmed' && $credit !== null
                && $credit['source_revision'] === $lot['source_revision'], 'available_cancelled_pf' => 0];
        }
        return ['eligible' => $plan['state'] === 'complete' && $plan['source_revision'] === $lot['source_revision']
            && in_array($lot['source_state'], ['confirmed', 'partially_cancelled'], true),
            'available_cancelled_pf' => (int) ModelValues::integer((string) $plan['available_cancelled_pf'])];
    }
}
