<?php

declare(strict_types=1);

final class Faluss_Subscriptions_Resolver
{
    /** @return array<string, mixed>|null */
    public static function resolve_for_faluss_id(string $falussId): ?array
    {
        return null;
    }
}

final class Faluss_Subscriptions_Catalog
{
    /** @return array<string, array<string, mixed>> */
    public static function plans(): array
    {
        return [];
    }
}

final class Faluss_Subscriptions_Repository
{
    /** @return list<array<string, mixed>> */
    public static function subscriptions_for_faluss_id(string $falussId): array
    {
        return [];
    }

    /** @return array<string, mixed>|null */
    public static function customer_for_faluss_id(string $falussId, string $provider): ?array
    {
        return null;
    }
}

final class Faluss_Subscriptions_Stripe_Config
{
    public static function portal_configuration_id(): string|WP_Error
    {
        return '';
    }
}

final class Faluss_Subscriptions_Billing
{
    public static function create_portal(string $falussId): mixed
    {
        return null;
    }
}

final class Token_Engine_Schema
{
    public const VERSION = 5;
}

final class Token_Engine_Points_Service
{
    /** @return array<string, mixed> */
    public static function daily_status(string $falussId, string $owner, string $rewardKey, array $proof): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public static function claim_hub_daily(string $falussId, array $proof): array
    {
        return [];
    }
}

final class Faluss_Analytics
{
    public static function register_runtime(): bool
    {
        return false;
    }

    /** @return array<string, bool> */
    public static function runtime_state(): array
    {
        return [];
    }
}
