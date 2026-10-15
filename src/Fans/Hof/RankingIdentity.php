<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\Fans\Sso\FansLinkedAccounts;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Resolve attested Hub subjects through module-owned local contracts, never a supplied mapping. */
final class RankingIdentity
{
    public function __construct(private readonly \wpdb $db) {}

    public function memberInTransaction(string $falussId): ?int
    {
        $this->transaction();
        return FansLinkedAccounts::memberInTransaction($falussId);
    }

    public function creatorInTransaction(string $falussId): ?string
    {
        $member = $this->memberInTransaction($falussId);
        return $member === null ? null : CreatorProfileService::activeIdForOwnerInTransaction($member);
    }

    /** A resolved account is not consent, editorial approval, or a proof of a current ranking corpus. */
    public function visibleNameInTransaction(string $family, string $falussId): ?string
    {
        $this->transaction();
        $visibility = new RankingVisibility($this->db);
        if ($family === 'fan') {
            $member = $this->memberInTransaction($falussId);
            return $member === null ? null : $visibility->visibleFan($member);
        }
        if ($family === 'creator') {
            $creator = $this->creatorInTransaction($falussId);
            return $creator === null ? null : $visibility->visibleCreator($creator);
        }
        throw new ModelViolation('hof_invalid_family');
    }

    private function transaction(): void
    {
        if ($this->db !== ($GLOBALS['wpdb'] ?? null)) { throw new ModelViolation('hof_primary_connection_required'); }
        $state = $this->db->get_row('SELECT @@in_transaction AS active,@@read_only AS replica', 'ARRAY_A');
        if (!is_array($state) || $this->db->last_error !== '' || (string) ($state['active'] ?? '') !== '1'
            || (string) ($state['replica'] ?? '') !== '0') { throw new ModelViolation('hof_primary_transaction_required'); }
    }
}
