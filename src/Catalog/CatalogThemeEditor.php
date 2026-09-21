<?php

declare(strict_types=1);

namespace Faluss\Platform\Catalog;

final class CatalogThemeEditor
{
    /** @var array<string, array<string, mixed>> */
    private array $themes;

    /** @param array<mixed> $stored
     *  @param array<string, string>|false $availableEntitlements
     */
    public function __construct(array $stored, private readonly array|false $availableEntitlements)
    {
        $this->themes = (new CatalogThemeReader($stored))->storedThemes();
    }

    /** @param array<string, mixed> $input
     *  @return array<string, array<string, mixed>>|false
     */
    public function create(array $input): array|false
    {
        $name = self::text($input['name'] ?? '', 80);
        if ($name === '') {
            return false;
        }

        $theme = $this->validated($input, $this->uniqueSlug($name), '');
        if ($theme === false) {
            return false;
        }

        $this->themes[$theme['slug']] = $theme;

        return $this->themes;
    }

    /** @param array<string, mixed> $input
     *  @return array<string, array<string, mixed>>|false
     */
    public function update(string $slug, array $input): array|false
    {
        $slug = sanitize_title($slug);
        if ($slug === CatalogThemeReader::SYSTEM_SLUG || !isset($this->themes[$slug])) {
            return false;
        }

        $existing = $this->themes[$slug]['entitlement_code'];
        $theme = $this->validated($input, $slug, is_string($existing) ? $existing : '');
        if ($theme === false) {
            return false;
        }

        $this->themes[$slug] = $theme;

        return $this->themes;
    }

    /** @return array<string, array<string, mixed>>|false */
    public function delete(string $slug): array|false
    {
        $slug = sanitize_title($slug);
        if ($slug === CatalogThemeReader::SYSTEM_SLUG || !isset($this->themes[$slug])) {
            return false;
        }

        unset($this->themes[$slug]);

        return $this->themes;
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>|false
     */
    private function validated(array $input, string $slug, string $existingEntitlement): array|false
    {
        $name = self::text($input['name'] ?? '', 80);
        $background = self::hex($input['page_background'] ?? '');
        $hero = self::hex($input['hero_transition_color'] ?? '');
        $nameColor = self::hex($input['name_color'] ?? '');
        $alignment = self::choice($input['alignment'] ?? '', ['left', 'center']);
        $social = self::choice($input['social_variant'] ?? '', ['outline', 'full']);
        $link = self::choice($input['link_style'] ?? '', ['dark', 'light', 'outline']);
        $entitlement = $this->validatedEntitlement($input['entitlement_code'] ?? '', $existingEntitlement);

        if ($name === '' || $background === '' || $hero === ''
            || !in_array($nameColor, ['#BE79FF', '#FFFFFF', '#000000', '#82206B'], true)
            || $alignment === '' || $social === '' || $link === '' || $entitlement === false
        ) {
            return false;
        }

        $imageId = absint($input['preview_attachment_id'] ?? 0);

        return [
            'name' => $name,
            'slug' => $slug,
            'active' => (string) ($input['active'] ?? '') === '1' ? 1 : 0,
            'sort_order' => min(9999, max(1, (int) ($input['sort_order'] ?? 10))),
            'preview_attachment_id' => $imageId && wp_attachment_is_image($imageId) ? $imageId : 0,
            'scope' => CatalogThemeReader::LINK_SCOPE,
            'page_background' => $background,
            'hero_transition_color' => $hero,
            'name_color' => $nameColor,
            'alignment' => $alignment,
            'social_variant' => $social,
            'link_style' => $link,
            'entitlement_code' => $entitlement,
            'system' => false,
        ];
    }

    private function validatedEntitlement(mixed $requested, string $existing): string|false
    {
        $code = self::entitlement($requested);
        if ($code === '') {
            return '';
        }

        if ($this->availableEntitlements === false) {
            return $code === self::entitlement($existing) ? $code : false;
        }

        return isset($this->availableEntitlements[$code]) ? $code : false;
    }

    private function uniqueSlug(string $name): string
    {
        $base = sanitize_title($name);
        $base = $base === '' ? 'theme-faluss' : $base;
        $slug = $base;
        $number = 2;

        while ($slug === CatalogThemeReader::SYSTEM_SLUG || isset($this->themes[$slug])) {
            $slug = $base . '-' . $number;
            ++$number;
        }

        return $slug;
    }

    private static function hex(mixed $value): string
    {
        $color = is_string($value) ? strtoupper((string) sanitize_hex_color($value)) : '';

        return preg_match('/^#[0-9A-F]{6}$/D', $color) ? $color : '';
    }

    /** @param list<string> $allowed */
    private static function choice(mixed $value, array $allowed): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : '';
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
