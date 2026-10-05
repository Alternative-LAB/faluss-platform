<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

/** Owner-only corrective entries, paired atomically; never legacy compensation. */
final class ClosedCorrectionLedger
{
    public function __construct(private readonly ClosedReservationDatabase $connection)
    {
        ClosedCorrectionEnvironment::assertIsolated($connection->database);
    }

    /** @return array{restore_entry:?string,cancel_entry:?string} */
    public function apply(string $member, string $plan, string $fragment, string $restore, string $cancel, string $policy): array
    {
        $this->connection->assertHeldSubject($member);
        ModelValues::uuid($plan);
        ModelValues::integer($fragment);
        $restored = (int) ModelValues::integer($restore);
        $cancelled = (int) ModelValues::integer($cancel);
        $balance = (new ClosedLedgerWriter($this->connection))->balance($member);
        if ($restored > $cancelled || $balance > ModelValues::MAX_INTEGER - $restored || $balance + $restored < $cancelled) {
            throw new ModelViolation('h4_balance_mismatch');
        }
        return ['restore_entry' => $restored === 0 ? null : $this->append($member, $plan, $fragment, $restore, $policy, 'credit'),
            'cancel_entry' => $cancelled === 0 ? null : $this->append($member, $plan, $fragment, $cancel, $policy, 'debit')];
    }

    private function append(string $member, string $plan, string $fragment, string $quantity, string $policy, string $direction): string
    {
        ModelValues::version($policy);
        $entry = wp_generate_uuid4();
        $now = substr($this->connection->now(), 0, 19);
        $reference = $plan . '.' . $fragment;
        $this->connection->insert(\Token_Engine_Schema::pf_ledger_table(), [
            'entry_uuid' => $entry, 'faluss_id' => $member, 'amount_pf' => $quantity,
            'direction' => $direction, 'economic_class' => 'funded',
            'category' => $direction === 'credit' ? 'pf_allocation_restore' : 'pf_pack_cancel',
            'category_version' => '1.0.0', 'source_owner' => 'faluss-hub',
            'source_event_reference' => 'fixture.h4.' . $direction . '.' . $reference,
            'idempotency_key' => 'pf.h4.' . $direction . '.' . hash('sha256', $reference),
            'policy_version' => $policy, 'occurred_at' => $now, 'created_at' => $now,
            'compensates_entry_uuid' => null, 'administrative_reason' => null,
            'metadata' => ModelValues::encode(['closed_h4_recipe' => true]),
        ]);
        return $entry;
    }
}
