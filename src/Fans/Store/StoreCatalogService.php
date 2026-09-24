<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Store;

use Faluss\Platform\Fans\Profiles\CreatorProfileService;

final class StoreCatalogService
{
    /** @return array{product_id:string,creator_id:string,category:string,category_label:string,visibility:string,created_at:string}|\WP_Error */
    public static function create(mixed $creatorId, mixed $category, mixed $requestKey): array|\WP_Error
    {
        if (!current_user_can('manage_options')) {
            return self::error('admin_required', 403);
        }
        if (!self::uuidValid($creatorId)
            || !self::uuidValid($requestKey)
            || !is_string($category)
            || !in_array($category, PurchaseGate::CATEGORIES, true)
            || CreatorProfileService::publicById($creatorId) === null
        ) {
            return self::error('invalid_product', 400);
        }
        $table = StoreCatalogSchema::table();
        if ($table === null || !StoreCatalogSchema::ready()) {
            return self::error('store_unavailable', 503);
        }
        $existing = self::byRequestKey($table, $requestKey);
        if ($existing !== null) {
            return $existing['creator_id'] === $creatorId && $existing['category'] === $category
                ? $existing
                : self::error('request_key_conflict', 409);
        }
        global $wpdb;
        try {
            $productId = self::uuid(random_bytes(16));
        } catch (\Throwable) {
            return self::error('product_id_unavailable', 503);
        }
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . self::quote($table)
                . ' (product_id, request_key, creator_id, category, visibility, created_at)'
                . ' VALUES (%s, %s, %s, %s, %s, %s)',
            $productId,
            $requestKey,
            $creatorId,
            $category,
            'visible',
            $now
        ));
        if ($inserted !== 1) {
            $existing = self::byRequestKey($table, $requestKey);

            return $existing !== null && $existing['creator_id'] === $creatorId && $existing['category'] === $category
                ? $existing
                : self::error('store_unavailable', 503);
        }

        return [
            'product_id' => $productId,
            'creator_id' => $creatorId,
            'category' => $category,
            'category_label' => (string) PurchaseGate::categoryLabel($category),
            'visibility' => 'visible',
            'created_at' => $now,
        ];
    }

    /** @return array{product_id:string,creator_id:string,category:string,category_label:string,visibility:string,created_at:string}|null */
    public static function publicById(mixed $productId): ?array
    {
        $table = StoreCatalogSchema::table();
        if (!self::uuidValid($productId) || $table === null || !StoreCatalogSchema::ready()) {
            return null;
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT product_id, creator_id, category, visibility, created_at FROM ' . self::quote($table)
                . ' WHERE product_id = %s AND visibility = %s LIMIT 1',
            $productId,
            'visible'
        ), 'ARRAY_A');
        $product = self::product($row);

        return $product !== null && $product['visibility'] === 'visible' ? $product : null;
    }

    /** @return list<array{product_id:string,creator_id:string,category:string,category_label:string,visibility:string,created_at:string}>|\WP_Error */
    public static function publicList(mixed $category): array|\WP_Error
    {
        if ($category !== null && (!is_string($category) || !in_array($category, PurchaseGate::CATEGORIES, true))) {
            return self::error('invalid_category', 400);
        }
        $table = StoreCatalogSchema::table();
        if ($table === null || !StoreCatalogSchema::ready()) {
            return self::error('store_unavailable', 503);
        }
        global $wpdb;
        $base = 'SELECT product_id, creator_id, category, visibility, created_at FROM ' . self::quote($table)
            . ' WHERE visibility = %s';
        $sql = $category === null
            ? $wpdb->prepare($base . ' ORDER BY product_id ASC LIMIT 20', 'visible')
            : $wpdb->prepare($base . ' AND category = %s ORDER BY product_id ASC LIMIT 20', 'visible', $category);
        $rows = $wpdb->get_results($sql, 'ARRAY_A');
        if (!is_array($rows)) {
            return self::error('store_unavailable', 503);
        }
        $products = [];
        foreach ($rows as $row) {
            $product = self::product($row);
            if ($product === null || $product['visibility'] !== 'visible') {
                return self::error('store_unavailable', 503);
            }
            $products[] = $product;
        }

        return $products;
    }

    public static function purchase(mixed $productId): \WP_Error
    {
        $product = self::publicById($productId);

        return $product === null
            ? self::error('product_not_found', 404)
            : PurchaseGate::refusePurchase($product['category']);
    }

    /** @return array{product_id:string,creator_id:string,category:string,category_label:string,visibility:string,created_at:string}|null */
    private static function byRequestKey(string $table, string $requestKey): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT product_id, creator_id, category, visibility, created_at FROM ' . self::quote($table)
                . ' WHERE request_key = %s LIMIT 1',
            $requestKey
        ), 'ARRAY_A');

        return self::product($row);
    }

    /** @return array{product_id:string,creator_id:string,category:string,category_label:string,visibility:string,created_at:string}|null */
    private static function product(mixed $row): ?array
    {
        if (!is_array($row)
            || !self::uuidValid($row['product_id'] ?? null)
            || !self::uuidValid($row['creator_id'] ?? null)
            || !is_string($row['category'] ?? null)
            || !in_array($row['category'], PurchaseGate::CATEGORIES, true)
            || !in_array($row['visibility'] ?? null, ['visible', 'hidden'], true)
            || !is_string($row['created_at'] ?? null)
        ) {
            return null;
        }

        return [
            'product_id' => $row['product_id'],
            'creator_id' => $row['creator_id'],
            'category' => $row['category'],
            'category_label' => (string) PurchaseGate::categoryLabel($row['category']),
            'visibility' => $row['visibility'],
            'created_at' => $row['created_at'],
        ];
    }

    private static function uuidValid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }

    private static function uuid(string $bytes): string
    {
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private static function error(string $code, int $status): \WP_Error
    {
        return new \WP_Error($code, 'Action indisponible.', ['status' => $status]);
    }

    private static function quote(string $value): string
    {
        return '`' . str_replace('`', '``', $value) . '`';
    }
}
