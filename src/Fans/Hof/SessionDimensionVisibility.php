<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Read-only visibility of a session dimension, never a rewrite of admitted contributions. */
final class SessionDimensionVisibility
{
    public function __construct(private readonly \wpdb $db) {}

    /** The delivery already owns the primary governance transaction. A storage failure is not an exclusion.
     * @param array<string,string> $session */
    public function creatorInTransaction(string $creator, array $session, ?string $policy): bool
    {
        if ($session['scope'] === 'international') { return true; }
        if ($policy === null) { throw new ModelViolation('hof_reviewed_territory_required'); }
        try { (new TerritoryService($this->db))->eligible($creator,$policy,$session); }
        catch (ModelViolation $error) {
            if ($error->reason !== 'hof_reviewed_territory_required') { throw $error; }
            return false;
        }
        return true;
    }

    /** Fans are not subject to the Creator's principal-activity territory rule.
     * @param array<string,string> $session */
    public function visibleNameInTransaction(string $family, string $subject, array $session, ?string $policy): ?string
    {
        $identity = new RankingIdentity($this->db);
        $name = $identity->visibleNameInTransaction($family,$subject);
        if ($name === null || $family === 'fan') { return $name; }
        $creator = $identity->creatorInTransaction($subject);
        return $creator !== null && $this->creatorInTransaction($creator,$session,$policy) ? $name : null;
    }
}
