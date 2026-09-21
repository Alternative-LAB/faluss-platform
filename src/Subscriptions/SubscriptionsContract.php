<?php

declare(strict_types=1);

namespace Faluss\Platform\Subscriptions;

use Throwable;

/** Narrow read/billing boundary for platform consumers. */
final class SubscriptionsContract
{
    /** @return array{available:bool,offer_name:string,level:string,state:string,expires_at:string,billing_interval:string,portal_available:bool} */
    public static function snapshot(string $falussId): array
    {
        $empty = [
            'available' => false,
            'offer_name' => 'Offre indisponible',
            'level' => 'free',
            'state' => 'unavailable',
            'expires_at' => '',
            'billing_interval' => '',
            'portal_available' => false,
        ];
        if (!self::uuid($falussId)
            || !class_exists('Faluss_Subscriptions_Resolver')
            || !class_exists('Faluss_Subscriptions_Catalog')
            || !class_exists('Faluss_Subscriptions_Repository')
        ) {
            return $empty;
        }

        try {
            $decision = \Faluss_Subscriptions_Resolver::resolve_for_faluss_id($falussId);
            if (!is_array($decision)) {
                return $empty;
            }
            $level = ($decision['level'] ?? null) === 'pro' ? 'pro' : 'free';
            $states = ['free', 'trialing', 'active', 'canceling', 'past_due', 'suspended', 'expired', 'comped', 'revoked'];
            $state = in_array($decision['state'] ?? null, $states, true) ? (string) $decision['state'] : 'free';
            $plans = \Faluss_Subscriptions_Catalog::plans();
            $plan = $plans[$level] ?? $plans['free'] ?? [];
            $reference = '';
            foreach ((array) ($decision['effective_sources'] ?? []) as $source) {
                if (is_array($source) && ($source['source'] ?? null) === 'subscription') {
                    $reference = (string) ($source['reference'] ?? '');
                    break;
                }
            }
            $interval = '';
            if ($reference !== '') {
                foreach ((array) \Faluss_Subscriptions_Repository::subscriptions_for_faluss_id($falussId) as $subscription) {
                    if (($subscription['subscription_uuid'] ?? null) === $reference
                        && in_array($subscription['billing_interval'] ?? null, ['monthly', 'annual'], true)
                    ) {
                        $interval = (string) $subscription['billing_interval'];
                        break;
                    }
                }
            }
            $portalAvailable = class_exists('Faluss_Subscriptions_Stripe_Config')
                && !is_wp_error(\Faluss_Subscriptions_Stripe_Config::portal_configuration_id())
                && is_array(\Faluss_Subscriptions_Repository::customer_for_faluss_id($falussId, 'stripe'));

            return [
                'available' => true,
                'offer_name' => is_string($plan['public_name'] ?? null) ? $plan['public_name'] : 'Faluss Gratuit',
                'level' => $level,
                'state' => $state,
                'expires_at' => self::date($decision['expires_at'] ?? null) ? (string) $decision['expires_at'] : '',
                'billing_interval' => $interval,
                'portal_available' => $portalAvailable,
            ];
        } catch (Throwable) {
            return $empty;
        }
    }

    /** @return array<string, mixed> */
    public static function plans(): array
    {
        if (!class_exists('Faluss_Subscriptions_Catalog')) {
            return [];
        }

        try {
            $plans = \Faluss_Subscriptions_Catalog::plans();

            return $plans;
        } catch (Throwable) {
            return [];
        }
    }

    public static function createCustomerPortal(string $falussId): mixed
    {
        if (!self::uuid($falussId) || !class_exists('Faluss_Subscriptions_Billing')) {
            return null;
        }

        try {
            return \Faluss_Subscriptions_Billing::create_portal($falussId);
        } catch (Throwable) {
            return null;
        }
    }

    private static function uuid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value) === 1;
    }

    private static function date(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) === 1;
    }
}
