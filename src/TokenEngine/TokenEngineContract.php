<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine;

use ReflectionClass;
use Throwable;

/** Narrow server-only PF boundary for platform consumers. */
final class TokenEngineContract
{
    private const OWNER = 'faluss-hub';
    private const REWARD = 'hub.daily_accrual';

    /** @return array<string, mixed> */
    public static function hubDailyStatus(string $falussId): array
    {
        if (!self::uuid($falussId) || !self::available()) {
            return ['state' => 'unavailable'];
        }

        try {
            $result = \Token_Engine_Points_Service::daily_status(
                $falussId,
                self::OWNER,
                self::REWARD,
                self::serverProof()
            );

            return $result;
        } catch (Throwable) {
            return ['state' => 'unavailable'];
        }
    }

    /** @return array<string, mixed> */
    public static function claimHubDaily(string $falussId): array
    {
        if (!self::uuid($falussId) || !self::available()) {
            return ['state' => 'unavailable'];
        }

        try {
            $result = \Token_Engine_Points_Service::claim_hub_daily($falussId, self::serverProof());

            return $result;
        } catch (Throwable) {
            return ['state' => 'unavailable'];
        }
    }

    private static function available(): bool
    {
        $schemaClass = 'Token_Engine_Schema';
        if (!class_exists('Token_Engine_Points_Service')
            || !defined('TOKEN_ENGINE_VERSION')
            || constant('TOKEN_ENGINE_VERSION') !== TokenEngineModule::VERSION
            || !class_exists($schemaClass)
        ) {
            return false;
        }

        return (string) (new ReflectionClass($schemaClass))->getConstant('VERSION') === '5';
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
}
