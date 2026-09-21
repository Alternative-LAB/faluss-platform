<?php

declare(strict_types=1);

namespace Faluss\Platform\Catalog;

final class CatalogThemeReader
{
    public const OPTION = 'faluss_catalog_card_themes';
    public const SYSTEM_SLUG = 'faluss-default';
    public const LINK_SCOPE = 'faluss-link';

    /** @param array<mixed> $stored */
    public function __construct(private readonly array $stored)
    {
    }

    /** @return array<string, mixed> */
    public static function systemTheme(): array
    {
        return [
            'name' => 'Faluss par défaut',
            'slug' => self::SYSTEM_SLUG,
            'active' => 1,
            'sort_order' => 0,
            'preview_attachment_id' => 0,
            'scope' => self::LINK_SCOPE,
            'page_background' => '#FFFDF5',
            'hero_transition_color' => '#FFFDF5',
            'name_color' => '#000000',
            'alignment' => 'left',
            'social_variant' => 'outline',
            'link_style' => 'dark',
            'entitlement_code' => '',
            'system' => true,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function allForScope(string $scope = self::LINK_SCOPE): array
    {
        $themes = [self::SYSTEM_SLUG => self::systemTheme()];

        foreach ($this->stored as $slug => $stored) {
            $theme = self::normalise($stored, $slug);
            if ($theme === null || $scope !== $theme['scope']) {
                continue;
            }

            $themes[$theme['slug']] = $theme;
        }

        uasort($themes, static function (array $first, array $second): int {
            $order = $first['sort_order'] <=> $second['sort_order'];

            return $order !== 0 ? $order : strcasecmp($first['name'], $second['name']);
        });

        return $themes;
    }

    /** @return array<string, array<string, mixed>> */
    public function activeForScope(string $scope = self::LINK_SCOPE): array
    {
        return array_filter(
            $this->allForScope($scope),
            static fn (array $theme): bool => $theme['active'] !== 0
        );
    }

    /** @return array<string, mixed>|false */
    public function getTheme(string $slug, string $scope = self::LINK_SCOPE): array|false
    {
        $slug = sanitize_title($slug);

        return $this->allForScope($scope)[$slug] ?? false;
    }

    /** @return array<string, mixed>|false */
    public function getActiveTheme(string $slug, string $scope = self::LINK_SCOPE): array|false
    {
        $theme = $this->getTheme($slug, $scope);

        return is_array($theme) && $theme['active'] !== 0 ? $theme : false;
    }

    /** @return array<string, array<string, mixed>> */
    public function storedThemes(): array
    {
        $themes = [];

        foreach ($this->stored as $slug => $stored) {
            $theme = self::normalise($stored, $slug);
            if ($theme !== null) {
                $themes[$theme['slug']] = $theme;
            }
        }

        return $themes;
    }

    /** @return array<string, mixed>|null */
    private static function normalise(mixed $stored, mixed $fallbackSlug): ?array
    {
        if (!is_array($stored)) {
            return null;
        }

        $slug = sanitize_title((string) ($stored['slug'] ?? $fallbackSlug));
        $name = self::text($stored['name'] ?? '', 80);
        if ($slug === '' || $name === '' || $slug === self::SYSTEM_SLUG) {
            return null;
        }

        $fallback = self::systemTheme();
        $imageId = absint($stored['preview_attachment_id'] ?? 0);

        return [
            'name' => $name,
            'slug' => $slug,
            'active' => empty($stored['active']) ? 0 : 1,
            'sort_order' => min(9999, max(1, (int) ($stored['sort_order'] ?? 10))),
            'preview_attachment_id' => $imageId && wp_attachment_is_image($imageId) ? $imageId : 0,
            'scope' => self::LINK_SCOPE,
            'page_background' => self::hex($stored['page_background'] ?? '') ?: $fallback['page_background'],
            'hero_transition_color' => self::hex($stored['hero_transition_color'] ?? '') ?: $fallback['hero_transition_color'],
            'name_color' => self::choice(self::hex($stored['name_color'] ?? ''), ['#BE79FF', '#FFFFFF', '#000000', '#82206B'], $fallback['name_color']),
            'alignment' => self::choice($stored['alignment'] ?? '', ['left', 'center'], $fallback['alignment']),
            'social_variant' => self::choice($stored['social_variant'] ?? '', ['outline', 'full'], $fallback['social_variant']),
            'link_style' => self::choice($stored['link_style'] ?? '', ['dark', 'light', 'outline'], $fallback['link_style']),
            'entitlement_code' => self::entitlement($stored['entitlement_code'] ?? ''),
            'system' => false,
        ];
    }

    private static function hex(mixed $value): string
    {
        $color = is_string($value) ? strtoupper((string) sanitize_hex_color($value)) : '';

        return preg_match('/^#[0-9A-F]{6}$/D', $color) ? $color : '';
    }

    /** @param list<string> $allowed */
    private static function choice(mixed $value, array $allowed, string $fallback): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $fallback;
    }

    private static function entitlement(mixed $value): string
    {
        $code = is_string($value) ? strtolower(trim($value)) : '';

        return preg_match('/^[a-z][a-z0-9_.-]{1,118}$/D', $code) ? $code : '';
    }

    private static function text(mixed $value, int $length): string
    {
        $text = is_string($value) ? sanitize_text_field($value) : '';

        return function_exists('mb_substr') ? mb_substr($text, 0, $length) : substr($text, 0, $length);
    }
}
