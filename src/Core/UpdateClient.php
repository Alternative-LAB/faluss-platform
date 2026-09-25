<?php

declare(strict_types=1);

namespace Faluss\Platform\Core;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
use YahnisElsts\PluginUpdateChecker\v5p7\Plugin\UpdateChecker;

final class UpdateClient
{
    public const LICENSE_OPTION = 'faluss_platform_license_key';
    public const METADATA_URL = 'https://updates.faluss.com/?action=get_metadata&slug=faluss-platform';
    public const SLUG = 'faluss-platform';
    public const SUPPORTED_WORDPRESS = '7.1';
    public const TESTED_WORDPRESS = '7.1.2';
    public const SUPPORTED_PHP = '8.2';

    public static function boot(string $pluginFile): void
    {
        if (!self::isAvailable()) {
            return;
        }

        $checker = PucFactory::buildUpdateChecker(
            self::METADATA_URL,
            $pluginFile,
            self::SLUG
        );

        if (!$checker instanceof UpdateChecker) {
            return;
        }

        $checker->addQueryArgFilter([self::class, 'addLicenseToQuery']);
        $checker->addFilter('pre_inject_update', [self::class, 'addLicenseToUpdate']);
        $checker->addFilter('retain_fields', [self::class, 'retainMetadataFields']);
    }

    public static function isAvailable(): bool
    {
        return class_exists(PucFactory::class);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public static function addLicenseToQuery(array $query): array
    {
        $license = self::license();
        if ($license !== '') {
            $query['license_key'] = $license;
        }

        return $query;
    }

    public static function addLicenseToUpdate(mixed $update): mixed
    {
        $license = self::license();
        if ($license === '' || !is_object($update) || empty($update->download_url)) {
            return $update;
        }

        $update->download_url = add_query_arg('license_key', $license, (string) $update->download_url);

        // Older immutable packages did not include readme metadata. Keep the
        // WordPress update screen accurate while those packages are retired.
        self::setMetadataFallback($update, 'requires', self::SUPPORTED_WORDPRESS);
        self::setMetadataFallback($update, 'tested', self::TESTED_WORDPRESS);
        self::setMetadataFallback($update, 'requires_php', self::SUPPORTED_PHP);

        return $update;
    }

    /**
     * Keep WordPress compatibility fields when Plugin Update Checker converts metadata.
     *
     * @param array<int, string> $fields
     * @return array<int, string>
     */
    public static function retainMetadataFields(array $fields): array
    {
        return array_values(array_unique(array_merge($fields, ['requires'])));
    }

    private static function setMetadataFallback(object $update, string $property, string $fallback): void
    {
        if (!property_exists($update, $property) || empty($update->{$property})) {
            $update->{$property} = $fallback;
        }
    }

    public static function license(): string
    {
        if (defined('FALUSS_PLATFORM_LICENSE_KEY')) {
            return self::sanitize((string) constant('FALUSS_PLATFORM_LICENSE_KEY'));
        }

        if (!function_exists('get_option')) {
            return '';
        }

        return self::sanitize((string) \get_option(self::LICENSE_OPTION, ''));
    }

    public static function hasLicense(): bool
    {
        return self::license() !== '';
    }

    public static function usesConstant(): bool
    {
        return defined('FALUSS_PLATFORM_LICENSE_KEY');
    }

    public static function sanitize(string $license): string
    {
        $license = trim($license);

        return preg_match('/^[A-Za-z0-9_-]{20,128}$/D', $license) === 1 ? $license : '';
    }
}
