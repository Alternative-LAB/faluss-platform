<?php

declare(strict_types=1);

namespace Faluss\Platform\Portal;

use Throwable;

final class PortalTokenEngineAdapter
{
    private const OWNER = 'faluss-hub';
    private const REWARD = 'hub.daily_accrual';

    /** @return array<string, mixed> */
    public static function dailyStatus(string $falussId): array
    {
        if (!self::uuid($falussId) || !self::available()) {
            return ['status' => 'unavailable'];
        }
        try {
            return self::document(\Token_Engine_Points_Service::daily_status(
                $falussId,
                self::OWNER,
                self::REWARD,
                self::serverProof()
            ));
        } catch (Throwable) {
            return ['status' => 'unavailable'];
        }
    }

    /** @return array<string, mixed> */
    public static function claim(string $falussId): array
    {
        if (!self::uuid($falussId) || !self::available()) {
            return ['status' => 'unavailable'];
        }
        try {
            return self::document(\Token_Engine_Points_Service::claim_hub_daily($falussId, self::serverProof()));
        } catch (Throwable) {
            return ['status' => 'unavailable'];
        }
    }

    private static function available(): bool
    {
        $schemaClass = 'Token_Engine_Schema';
        if (!class_exists('Token_Engine_Points_Service')
            || !defined('TOKEN_ENGINE_VERSION')
        ) {
            return false;
        }
        if (constant('TOKEN_ENGINE_VERSION') !== '0.4.1' || !class_exists($schemaClass)) {
            return false;
        }

        return (string) (new \ReflectionClass($schemaClass))->getConstant('VERSION') === '5';
    }

    /** @return array{owner:string,identity_active:true} */
    private static function serverProof(): array
    {
        return ['owner' => self::OWNER, 'identity_active' => true];
    }

    private static function uuid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value) === 1;
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
