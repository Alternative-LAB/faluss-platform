<?php

declare(strict_types=1);

namespace Faluss\Platform\Link;

use WP_Error;

final class LinkTokenEngineConnectorAdapter
{
    public static function available(): bool
    {
        return self::hasMethod('subject_has_entitlement')
            && self::hasMethod('entitlement_definitions');
    }

    /** Retired Link-only compatibility entry point; never calls the Connector. */
    public static function dailyRewardOffer(): WP_Error
    {
        return new WP_Error('faluss_link_daily_reward_retired');
    }

    /** Retired Link-only compatibility entry point; never calls the Connector. */
    public static function dailyRewardStatusForCurrentSubject(): WP_Error
    {
        return new WP_Error('faluss_link_daily_reward_retired');
    }

    /** Retired Link-only compatibility entry point; never calls the Connector. */
    public static function claimDailyRewardForCurrentSubject(): WP_Error
    {
        return new WP_Error('faluss_link_daily_reward_retired');
    }

    public static function subjectHasEntitlement(string $falussId, string $code): bool
    {
        if ($falussId === '' || $code === '' || !self::available()) {
            return false;
        }

        return self::call('subject_has_entitlement', [$falussId, $code]) === true;
    }

    /** @return array<string, array<string, mixed>> */
    public static function entitlementDefinitions(): array
    {
        if (!self::available()) {
            return [];
        }

        $definitions = self::call('entitlement_definitions');

        return is_array($definitions) ? $definitions : [];
    }

    private static function hasMethod(string $method): bool
    {
        return class_exists('Token_Engine_Connector_Service')
            && method_exists('Token_Engine_Connector_Service', $method);
    }

    /** @param list<mixed> $arguments */
    private static function call(string $method, array $arguments = []): mixed
    {
        $class = 'Token_Engine_Connector_Service';
        if (!self::hasMethod($method)) {
            return null;
        }

        return $class::$method(...$arguments);
    }
}
