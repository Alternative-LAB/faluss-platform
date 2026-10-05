<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

/** Hub-owned, fixture-only composing writer to the existing official PF ledger. */
final class ClosedLedgerWriter
{
    public function __construct(private readonly ClosedReservationDatabase $connection)
    {
    }

    public function balance(string $member): int
    {
        $this->connection->assertHeldSubject($member);
        $database = $this->connection->database;
        $value = $database->get_var($database->prepare(
            "SELECT COALESCE(SUM(CASE WHEN e.direction='credit' THEN e.amount_pf WHEN e.direction='debit' THEN -e.amount_pf WHEN e.direction='compensation' AND o.direction='credit' THEN -e.amount_pf WHEN e.direction='compensation' AND o.direction='debit' THEN e.amount_pf ELSE 0 END),0) FROM %i e LEFT JOIN %i o ON o.entry_uuid=e.compensates_entry_uuid WHERE e.faluss_id=%s AND e.economic_class='funded'",
            \Token_Engine_Schema::pf_ledger_table(), \Token_Engine_Schema::pf_ledger_table(), $member));
        if ($database->last_error !== '') {
            throw new ModelViolation('h2_storage_unavailable');
        }
        return (int) ModelValues::integer($value);
    }

    public function appendCredit(string $member, string $lot, string $quantity, string $policy): string
    {
        $amount = (int) ModelValues::integer($quantity, true);
        if ($this->balance($member) > ModelValues::MAX_INTEGER - $amount) {
            throw new ModelViolation('h2_balance_overflow');
        }
        return $this->append($member, $lot, $quantity, $policy, 'credit', 'pf_pack_purchase');
    }

    private function append(string $member, string $reference, string $quantity, string $policy, string $direction, string $category): string
    {
        $this->connection->assertHeldSubject($member);
        ModelValues::uuid($reference);
        ModelValues::integer($quantity, true);
        ModelValues::version($policy);
        $entry = wp_generate_uuid4();
        ModelValues::uuid($entry);
        $now = substr($this->connection->now(), 0, 19);
        $this->connection->insert(\Token_Engine_Schema::pf_ledger_table(), [
            'entry_uuid' => $entry, 'faluss_id' => $member, 'amount_pf' => $quantity,
            'direction' => $direction, 'economic_class' => 'funded', 'category' => $category,
            'category_version' => '1.0.0', 'source_owner' => 'faluss-hub',
            'source_event_reference' => 'fixture.h2.' . $direction . '.' . $reference,
            'idempotency_key' => 'pf.h2.' . $direction . '.' . hash('sha256', $reference),
            'policy_version' => $policy, 'occurred_at' => $now, 'created_at' => $now,
            'compensates_entry_uuid' => null, 'administrative_reason' => null,
            'metadata' => ModelValues::encode(['closed_h2_recipe' => true]),
        ]);
        return $entry;
    }

    public function verifyCredit(string $entry, string $member, string $lot, string $quantity, string $policy): void
    {
        $this->connection->assertHeldSubject($member);
        $table = \Token_Engine_Schema::pf_ledger_table();
        $row = $this->connection->row($table, 'entry_uuid=%s', [$entry]);
        $expected = ['faluss_id' => $member, 'amount_pf' => $quantity, 'direction' => 'credit',
            'economic_class' => 'funded', 'category' => 'pf_pack_purchase', 'category_version' => '1.0.0',
            'source_owner' => 'faluss-hub', 'policy_version' => $policy,
            'source_event_reference' => 'fixture.h2.credit.' . $lot,
            'idempotency_key' => 'pf.h2.credit.' . hash('sha256', $lot),
            'metadata' => ModelValues::encode(['closed_h2_recipe' => true])];
        foreach ($expected as $field => $value) {
            if ($row === null || (string) $row[$field] !== $value) {
                throw new ModelViolation('h2_ledger_filiation_failure');
            }
        }
        if ($row['compensates_entry_uuid'] !== null
            || $this->connection->row($table, 'compensates_entry_uuid=%s', [$entry]) !== null
        ) {
            // H4 must own corrections. An out-of-band historic reversal never recreates provenance.
            throw new ModelViolation('h2_ledger_filiation_failure');
        }
    }
}
