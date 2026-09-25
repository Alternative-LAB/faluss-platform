<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Store;

/** The only purchase policy exposed by the catalog until a separately reviewed order engine exists. */
final class PurchaseGate
{
    public const HOSTED = 'hosted_allowed_content';
    public const EXTERNAL_ADULT = 'external_adult_delivery_right';
    public const CATEGORIES = [self::HOSTED, self::EXTERNAL_ADULT];

    public static function categoryLabel(string $category): ?string
    {
        return match ($category) {
            self::HOSTED => 'Contenu autorisé hébergé par Fans',
            self::EXTERNAL_ADULT => 'Droit à une livraison adulte externe',
            default => null,
        };
    }

    public static function refusePurchase(mixed $category): \WP_Error
    {
        if ($category === self::EXTERNAL_ADULT) {
            return new \WP_Error('external_adult_purchase_blocked', 'Achat indisponible.', ['status' => 403]);
        }
        if ($category === self::HOSTED) {
            return new \WP_Error('hosted_purchase_not_open', 'Achat indisponible.', ['status' => 503]);
        }

        return new \WP_Error('invalid_category', 'Catégorie invalide.', ['status' => 400]);
    }
}
