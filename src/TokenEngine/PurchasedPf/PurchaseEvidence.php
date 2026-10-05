<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

/** Validated model data does not prove that a purchase occurred. */
final class PurchaseEvidence
{
    public const CONTRACT = 'hub.purchased-pf.h1-model/1.0.0';
    private const FIELDS = ['contract', 'authority_id', 'purchase_id', 'source_revision', 'evidence_id',
        'member_faluss_id', 'purchased_pf', 'bonus_pf', 'cancelled_purchased_pf_cumulative',
        'state', 'confirmed_at', 'observed_at', 'policy_version'];

    /** @param array<string, string|null> $values */
    private function __construct(public readonly array $values)
    {
    }

    /** @param array<array-key, mixed> $input */
    public static function fromArray(array $input): self
    {
        ModelValues::exactKeys($input, self::FIELDS);
        if ($input['contract'] !== self::CONTRACT
            || !in_array($input['state'], ['pending', 'confirmed', 'disputed', 'partially_cancelled', 'cancelled'], true)
        ) {
            throw new ModelViolation('invalid_contract_or_state');
        }
        $values = [
            'contract' => self::CONTRACT,
            'authority_id' => ModelValues::authority($input['authority_id']),
            'purchase_id' => ModelValues::reference($input['purchase_id']),
            'source_revision' => ModelValues::integer($input['source_revision'], true),
            'evidence_id' => ModelValues::uuid($input['evidence_id']),
            'member_faluss_id' => ModelValues::uuid($input['member_faluss_id']),
            'purchased_pf' => ModelValues::integer($input['purchased_pf'], true),
            'bonus_pf' => ModelValues::integer($input['bonus_pf']),
            'cancelled_purchased_pf_cumulative' => ModelValues::integer($input['cancelled_purchased_pf_cumulative']),
            'state' => $input['state'],
            'confirmed_at' => $input['confirmed_at'] === null ? null : ModelValues::utc($input['confirmed_at']),
            'observed_at' => ModelValues::utc($input['observed_at']),
            'policy_version' => ModelValues::version($input['policy_version']),
        ];
        $cancelled = (int) $values['cancelled_purchased_pf_cumulative'];
        $purchased = (int) $values['purchased_pf'];
        if ($cancelled > $purchased
            || (in_array($values['state'], ['pending', 'confirmed'], true) && $cancelled !== 0)
            || ($values['state'] === 'pending' && $values['confirmed_at'] !== null)
            || ($values['state'] !== 'pending' && $values['confirmed_at'] === null)
            || ($values['confirmed_at'] !== null && $values['confirmed_at'] > $values['observed_at'])
            || ($values['state'] === 'partially_cancelled' && ($cancelled === 0 || $cancelled === $purchased))
            || ($values['state'] === 'cancelled' && $cancelled !== $purchased)
        ) {
            throw new ModelViolation('inconsistent_evidence');
        }

        return new self($values);
    }

    public function fingerprint(): string
    {
        return ModelValues::fingerprint($this->values);
    }

    public function immutableFingerprint(): string
    {
        return ModelValues::fingerprint(array_intersect_key($this->values, array_flip([
            'contract', 'authority_id', 'purchase_id', 'member_faluss_id', 'purchased_pf', 'bonus_pf', 'policy_version',
        ])));
    }

    public function assertSuccessorOf(self $previous): void
    {
        if (!hash_equals($previous->immutableFingerprint(), $this->immutableFingerprint())
            || ($previous->values['confirmed_at'] !== null && $this->values['confirmed_at'] !== $previous->values['confirmed_at'])
            || (int) $this->values['cancelled_purchased_pf_cumulative'] < (int) $previous->values['cancelled_purchased_pf_cumulative']
            || ($previous->values['state'] !== 'pending' && $this->values['state'] === 'pending')
            || ($previous->values['state'] === 'cancelled' && $this->values['state'] !== 'cancelled')
            || $this->values['observed_at'] < $previous->values['observed_at']
        ) {
            throw new ModelViolation('evidence_conflict');
        }
        if ((int) $this->values['source_revision'] <= (int) $previous->values['source_revision']) {
            throw new ModelViolation('stale_revision');
        }
    }
}
