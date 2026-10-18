<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\Fans\PfContract\ClosedBarrierPrimaryRead;
use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\Fans\Profiles\EditorialService;
use Faluss\Platform\Fans\Sso\FansLinkedAccounts;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingBarrier;

/** Explicit server configuration of existing signed category/country registrations, never browser claims. */
final class ClosedAttributionPolicy
{
    public function __construct(private readonly \wpdb $db,private readonly ClosedBarrierPrimaryRead $primary,
        private readonly string $creatorAction,private readonly string $countryAction)
    {
        ClosedEnvironment::assertIsolated($db,'fans'); ModelValues::uuid($creatorAction); ModelValues::uuid($countryAction);
        if ($db !== ($GLOBALS['wpdb'] ?? null)) { throw new ModelViolation('hof_primary_connection_required'); }
    }

    public function usesConnection(\wpdb $db): bool { return $this->db === $db; }

    /** Caller already owns the sessions/origin transaction. No network or registration occurs here.
     * @return array{creator_faluss_id:string,creator_category:string,category_revision:string,country_policy_revision:string} */
    public function contextInTransaction(string $profile,string $origin): array
    {
        ModelValues::uuid($profile); ModelValues::uuid($origin);
        $public = CreatorProfileService::publicById($profile,true);
        $owner = CreatorProfileService::activeOwner($profile,true);
        $subject = $owner === null ? null : FansLinkedAccounts::identityInTransaction($owner);
        if ($public === null || $subject === null || EditorialService::publicNameInTransaction($profile) === null) { throw new ModelViolation('pf_active_creator_required'); }
        $creator = $this->registration($this->creatorAction,'creator',$origin);
        $country = $this->registration($this->countryAction,'country',$origin);
        if ($creator['object_id'] !== $subject || $creator['creator_category'] !== $public['category'] || $country['version'] !== '1') {
            throw new ModelViolation('hof_attribution_policy_conflict');
        }
        return ['creator_faluss_id' => $subject,'creator_category' => $creator['creator_category'],
            'category_revision' => $creator['version'],'country_policy_revision' => $country['object_id']];
    }

    /** @return array<string,string> */
    private function registration(string $action,string $kind,string $origin): array
    {
        return $this->primary->withCurrentAcknowledgementInTransaction($action,function (array $ack) use ($kind,$origin): array {
            if ($ack['fields']['operation'] !== 'register' || $ack['local_state'] !== 'active' || $ack['result']['state'] !== 'active') {
                throw new ModelViolation('hof_attribution_policy_unavailable');
            }
            $descriptor = RankingBarrier::descriptor($ack['fields']['object']); $content = $descriptor['content'];
            $now = $this->db->get_var('SELECT UTC_TIMESTAMP(6)');
            if ($this->db->last_error !== '' || !is_string($now)) { throw new ModelViolation('hof_storage_unavailable'); }
            if ($content['kind'] !== $kind || $content['origin_id'] !== $origin || $content['subject_id'] !== ''
                || $content['policy_version'] !== RankingPolicy::VERSION || $now < $descriptor['valid_from'] || $now >= $descriptor['valid_until']) {
                throw new ModelViolation('hof_attribution_policy_unavailable');
            }
            $reference = RankingBarrier::reference($content);
            foreach (['barrier_key','version','content_sha256'] as $field) {
                if ($ack['result'][$field] !== $reference[$field]) { throw new ModelViolation('hof_attribution_policy_conflict'); }
            }
            return $content;
        });
    }
}
