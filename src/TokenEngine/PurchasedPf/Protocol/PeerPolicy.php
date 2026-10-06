<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf\Protocol;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Explicit PF policy, never inherited from historical Federation wallet/event permissions. */
final class PeerPolicy
{
    /** @param list<string> $permissions
     *  @param array<string,array{public_key:string,state:string,from:string,until:string}> $keys
     */
    public function __construct(public readonly string $node, public readonly string $audience,
        private readonly array $permissions, private readonly array $keys)
    {
        foreach ([$node, $audience] as $identity) {
            ModelValues::authority($identity);
            if (!str_starts_with($identity, 'fixture.')) {
                throw new ModelViolation('pf_fixture_peer_required');
            }
        }
        if ($node === $audience) {
            throw new ModelViolation('pf_invalid_peer');
        }
    }

    public function allow(string $permission): void
    {
        if (!in_array($permission, ['pf.reserve', 'pf.confirm', 'pf.release', 'pf.lookup', 'pf.context.delegate', 'pf.snapshot',
            'pf.ranking.context.register','pf.ranking.context.close'], true)
            || !in_array($permission, $this->permissions, true)
        ) {
            throw new ModelViolation('pf_permission_denied');
        }
    }

    public function publicKey(string $id, int $now): string
    {
        self::keyId($id);
        $key = $this->keys[$id] ?? null;
        if ($key === null || $key['state'] !== 'active'
            || strtotime(ModelValues::utc($key['from'])) > $now || strtotime(ModelValues::utc($key['until'])) <= $now
        ) {
            throw new ModelViolation('pf_key_not_trusted');
        }
        return $key['public_key'];
    }

    public static function keyId(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $value) !== 1) {
            throw new ModelViolation('pf_invalid_key');
        }
        return $value;
    }
}
