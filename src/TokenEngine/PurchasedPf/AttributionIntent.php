<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

/** An immutable model intention, not an instruction to debit PF. */
final class AttributionIntent
{
    /** @param array<string, string> $values */
    private function __construct(public readonly array $values)
    {
    }

    /** @param array<array-key, mixed> $input */
    public static function fromArray(array $input): self
    {
        ModelValues::exactKeys($input, ['attribution_id', 'client_authority', 'member_faluss_id',
            'creator_faluss_id', 'purchased_pf', 'policy_version']);
        $values = [
            'attribution_id' => ModelValues::uuid($input['attribution_id']),
            'client_authority' => ModelValues::authority($input['client_authority']),
            'member_faluss_id' => ModelValues::uuid($input['member_faluss_id']),
            'creator_faluss_id' => ModelValues::uuid($input['creator_faluss_id']),
            'purchased_pf' => ModelValues::integer($input['purchased_pf'], true),
            'policy_version' => ModelValues::version($input['policy_version']),
        ];
        if ($values['member_faluss_id'] === $values['creator_faluss_id']) {
            throw new ModelViolation('self_attribution');
        }

        return new self($values);
    }

    public function fingerprint(): string
    {
        return ModelValues::fingerprint($this->values);
    }
}
