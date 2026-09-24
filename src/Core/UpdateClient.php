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

    public static function boot(string $pluginFile): void
    {
        if (!class_exists(PucFactory::class)) {
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

        return $update;
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
