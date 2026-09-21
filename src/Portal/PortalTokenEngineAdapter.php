<?php

declare(strict_types=1);

namespace Faluss\Platform\Portal;

use Faluss\Platform\TokenEngine\TokenEngineContract;

final class PortalTokenEngineAdapter
{
    private const OWNER = 'faluss-hub';
    private const REWARD = 'hub.daily_accrual';

    /** @return array<string, mixed> */
    public static function dailyStatus(string $falussId): array
    {
        return self::document(TokenEngineContract::hubDailyStatus($falussId));
    }

    /** @return array<string, mixed> */
    public static function claim(string $falussId): array
    {
        return self::document(TokenEngineContract::claimHubDaily($falussId));
    }

    /** @return array<string, mixed> */
    private static function document(mixed $core): array
    {
        $state = is_array($core) && is_string($core['state'] ?? null) ? $core['state'] : '';
        if (in_array($state, ['ineligible', 'unavailable', 'not_supported'], true)) {
            return ['status' => $state];
        }
        $amount = $core['amount_pf'] ?? null;
        if (!in_array($state, ['claimable', 'claimed'], true)
            || ($core['owner'] ?? null) !== self::OWNER
            || ($core['reward_key'] ?? null) !== self::REWARD
            || !is_int($amount)
            || $amount < 1
            || !is_string($core['economic_class'] ?? null)
            || !is_string($core['logical_date'] ?? null)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $core['logical_date']) !== 1
        ) {
            return ['status' => 'unavailable'];
        }
        $claimable = $state === 'claimable';

        return [
            'app_key' => 'hub',
            'reward_key' => self::REWARD,
            'owner' => self::OWNER,
            'status' => $state,
            'reward' => [
                'amount_pf' => $amount,
                'economic_class' => $core['economic_class'],
                'label' => $amount . ' PF',
            ],
            'period' => ['type' => 'daily', 'timezone' => 'Europe/Paris', 'logical_date' => $core['logical_date']],
            'delegation' => [
                'type' => $claimable ? 'owner_claim' : 'none',
                'action_key' => $claimable ? 'claim-hub-daily' : null,
                'target' => $claimable ? self::REWARD : null,
            ],
            'freshness' => [
                'generated_at' => gmdate('c'),
                'max_age_seconds' => 60,
                'stale_behavior' => 'refresh_from_owner',
            ],
            'source' => [
                'type' => 'owner_daily_reward_read_model',
                'engine' => self::OWNER,
                'read_model' => 'hub-daily-reward',
                'source_version' => '1.0.0',
            ],
            'compatibility' => [
                'minimum_consumer_version' => '1.0.0',
                'backward_compatible_with' => ['1.0.0'],
                'deprecated' => false,
                'sunset_at' => null,
                'replacement_reward_key' => null,
            ],
        ];
    }
}
