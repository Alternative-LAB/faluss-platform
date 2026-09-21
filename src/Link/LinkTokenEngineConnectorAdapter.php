<?php

declare(strict_types=1);

namespace Faluss\Platform\Link;

use WP_Error;

final class LinkTokenEngineConnectorAdapter
{
    public static function available(): bool
    {
        return self::hasMethod('daily_reward_offer')
            && self::hasMethod('daily_reward_status_for_current_subject')
            && self::hasMethod('claim_daily_reward_for_current_subject')
            && self::hasMethod('subject_has_entitlement')
            && self::hasMethod('entitlement_definitions');
    }

    /** @return array<string, mixed>|WP_Error */
    public static function dailyRewardOffer(): array|WP_Error
    {
        if (!self::available()) {
            return new WP_Error('connector_unavailable');
        }

        $result = self::call('daily_reward_offer');

        return is_array($result) || $result instanceof WP_Error
            ? $result
            : new WP_Error('connector_response_invalid');
    }

    /** @return array<string, mixed>|WP_Error */
    public static function dailyRewardStatusForCurrentSubject(): array|WP_Error
    {
        if (!self::available()) {
            return new WP_Error('connector_unavailable');
        }

        $result = self::call('daily_reward_status_for_current_subject');

        return is_array($result) || $result instanceof WP_Error
            ? $result
            : new WP_Error('connector_response_invalid');
    }

    /** @return array<string, mixed>|WP_Error */
    public static function claimDailyRewardForCurrentSubject(): array|WP_Error
    {
        if (!self::available()) {
            return new WP_Error('connector_unavailable');
        }

        $result = self::call('claim_daily_reward_for_current_subject');

        return is_array($result) || $result instanceof WP_Error
            ? $result
            : new WP_Error('connector_response_invalid');
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
