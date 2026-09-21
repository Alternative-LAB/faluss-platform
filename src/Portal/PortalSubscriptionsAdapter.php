<?php

declare(strict_types=1);

namespace Faluss\Platform\Portal;

use Faluss\Platform\Subscriptions\SubscriptionsContract;

final class PortalSubscriptionsAdapter
{
    /** @return array{available:bool,offer_name:string,level:string,state:string,expires_at:string,billing_interval:string,portal_available:bool} */
    public static function snapshot(string $falussId): array
    {
        return SubscriptionsContract::snapshot($falussId);
    }

    /** @return array<string, mixed> */
    public static function plans(): array
    {
        return SubscriptionsContract::plans();
    }

    public static function createCustomerPortal(string $falussId): mixed
    {
        return SubscriptionsContract::createCustomerPortal($falussId);
    }
}
