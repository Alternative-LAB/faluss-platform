<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Token_Engine_Connector_Service
{
    public const OPTION = \Faluss\Platform\TokenEngineConnector\ConnectorService::OPTION;
    public const PROTOCOL_VERSION = \Faluss\Platform\TokenEngineConnector\ConnectorService::PROTOCOL_VERSION;
    public const CORE_ENGINE = \Faluss\Platform\TokenEngineConnector\ConnectorService::CORE_ENGINE;
    public const REST_MODE_REWRITE = \Faluss\Platform\TokenEngineConnector\ConnectorService::REST_MODE_REWRITE;
    public const REST_MODE_QUERY = \Faluss\Platform\TokenEngineConnector\ConnectorService::REST_MODE_QUERY;
    public const REST_NAMESPACE = \Faluss\Platform\TokenEngineConnector\ConnectorService::REST_NAMESPACE;
    public const PERMISSION_WALLET_READ = \Faluss\Platform\TokenEngineConnector\ConnectorService::PERMISSION_WALLET_READ;
    public const PERMISSION_REWARD_CLAIM = \Faluss\Platform\TokenEngineConnector\ConnectorService::PERMISSION_REWARD_CLAIM;
    public const PERMISSION_ENTITLEMENTS_READ = \Faluss\Platform\TokenEngineConnector\ConnectorService::PERMISSION_ENTITLEMENTS_READ;

    /** @return array<string, mixed> */
    public static function configuration(): array
    {
        return \Faluss\Platform\TokenEngineConnector\ConnectorService::configuration();
    }

    public static function is_configured(): bool
    {
        return \Faluss\Platform\TokenEngineConnector\ConnectorService::isConfigured();
    }

    /** @return array<string, mixed>|\WP_Error */
    public static function save_configuration(mixed $values): array|\WP_Error
    {
        return \Faluss\Platform\TokenEngineConnector\ConnectorService::saveConfiguration($values);
    }

    public static function migrate_legacy_site_url(mixed $value): string
    {
        return \Faluss\Platform\TokenEngineConnector\ConnectorService::migrateLegacySiteUrl($value);
    }

    public static function current_subject_id(): string
    {
        return \Faluss\Platform\TokenEngineConnector\ConnectorService::currentSubjectId();
    }

    /** @return array<string, mixed> */
    public static function subject_diagnostic(): array
    {
        return \Faluss\Platform\TokenEngineConnector\ConnectorService::subjectDiagnostic();
    }

    /** @return array<string, mixed> */
    public static function faluss_subject_diagnostic(): array
    {
        return self::subject_diagnostic();
    }

    /** @return array<string, mixed>|\WP_Error */
    public static function core_connection_test(): array|\WP_Error
    {
        return \Faluss\Platform\TokenEngineConnector\ConnectorService::coreConnectionTest();
    }

    /** @return array<string, mixed>|\WP_Error */
    public static function test_connection(): array|\WP_Error
    {
        return self::core_connection_test();
    }

    /** @return array<string, mixed>|\WP_Error */
    public static function balance_for_current_subject(): array|\WP_Error
    {
        return \Faluss\Platform\TokenEngineConnector\ConnectorService::balanceForCurrentSubject();
    }

    /** The historical optional-provider boundary deliberately remains untyped. */
    public static function entitlement_definitions(): mixed
    {
        return \Faluss\Platform\TokenEngineConnector\ConnectorService::entitlementDefinitions();
    }

    public static function subject_has_entitlement(mixed $subjectId, mixed $entitlementCode): bool|\WP_Error
    {
        return \Faluss\Platform\TokenEngineConnector\ConnectorService::subjectHasEntitlement(
            $subjectId,
            $entitlementCode
        );
    }

    public static function current_subject_has_entitlement(mixed $entitlementCode): bool|\WP_Error
    {
        return \Faluss\Platform\TokenEngineConnector\ConnectorService::currentSubjectHasEntitlement($entitlementCode);
    }

    /** @return array<string, mixed> */
    public static function entitlements_diagnostic(): array
    {
        return \Faluss\Platform\TokenEngineConnector\ConnectorService::entitlementsDiagnostic();
    }

    /** @return array<string, mixed> */
    public static function daily_reward_status_for_current_subject(): array
    {
        return \Faluss\Platform\TokenEngineConnector\ConnectorService::dailyRewardStatusForCurrentSubject();
    }

    /** @return array<string, mixed> */
    public static function daily_reward_offer(): array
    {
        return \Faluss\Platform\TokenEngineConnector\ConnectorService::dailyRewardOffer();
    }

    /** @return array<string, mixed> */
    public static function claim_daily_reward_for_current_subject(): array
    {
        return \Faluss\Platform\TokenEngineConnector\ConnectorService::claimDailyRewardForCurrentSubject();
    }

    /** @return array<string, mixed> */
    public static function daily_reward_diagnostic(): array
    {
        return \Faluss\Platform\TokenEngineConnector\ConnectorService::dailyRewardDiagnostic();
    }
}
