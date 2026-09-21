<?php

declare(strict_types=1);

namespace Faluss\Platform\Theme;

final class ThemeTokenSet
{
    public const OPTION = 'faluss_theme_tokens';

    /** @return array<string, string> */
    public static function defaults(): array
    {
        return [
            'canvas' => '#FFFDF5',
            'surface' => '#FFFFFF',
            'ink' => '#080808',
            'muted' => '#6F6A63',
            'accent' => '#FF3D16',
            'action' => '#080808',
            'action_text' => '#FFFFFF',
            'action_hover' => '#28231F',
            'action_active' => '#000000',
            'border_color' => '#080808',
            'border_opacity' => '12',
            'shadow' => 'soft',
            'card_radius' => '24px',
            'control_radius' => '8px',
            'pill_radius' => '999px',
        ];
    }

    /** @return array<string, string> */
    public static function shadows(): array
    {
        return [
            'none' => 'none',
            'soft' => '0 12px 30px rgba(8, 8, 8, 0.06)',
            'lifted' => '0 18px 38px rgba(8, 8, 8, 0.09)',
        ];
    }

    /** @param mixed $stored
     *  @return array<string, string>
     */
    public static function fromStorage(mixed $stored): array
    {
        $stored = is_array($stored) ? $stored : [];

        if (isset($stored['border']) && !isset($stored['border_color'])) {
            $stored['border_color'] = '#080808';
            $stored['border_opacity'] = '12';
        }

        return self::sanitize(array_merge(self::defaults(), $stored));
    }

    /** @param mixed $input
     *  @return array<string, string>
     */
    public static function sanitize(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $values = self::defaults();

        foreach (self::colorKeys() as $key) {
            $candidate = $input[$key] ?? null;
            if (is_string($candidate) && preg_match('/^#[A-Fa-f0-9]{6}$/D', $candidate)) {
                $values[$key] = strtoupper($candidate);
            }
        }

        $opacity = $input['border_opacity'] ?? null;
        if (is_scalar($opacity) && is_numeric($opacity)) {
            $values['border_opacity'] = (string) max(0, min(100, (int) $opacity));
        }

        $shadow = $input['shadow'] ?? null;
        if (is_string($shadow) && isset(self::shadows()[$shadow])) {
            $values['shadow'] = $shadow;
        }

        foreach (self::radiusKeys() as $key) {
            $candidate = $input[$key] ?? null;
            if (is_string($candidate) && preg_match('/^[0-9]{1,3}px$/D', $candidate)) {
                $values[$key] = $candidate;
            }
        }

        return $values;
    }

    /** @param array<string, string> $values */
    public static function css(array $values): string
    {
        $values = self::sanitize($values);
        $css = ':root{';

        foreach (array_merge(array_slice(self::colorKeys(), 0, 9), self::radiusKeys()) as $key) {
            $css .= '--faluss-' . $key . ':' . $values[$key] . ';';
        }

        $css .= '--faluss-border:rgba(' . self::rgb($values['border_color']) . ',';
        $css .= ((int) $values['border_opacity'] / 100) . ');';
        $css .= '--faluss-shadow:' . self::shadows()[$values['shadow']] . ';}';

        return $css;
    }

    /** @param array<string, string> $values */
    public static function previewCss(array $values): string
    {
        $values = self::sanitize($values);
        $preview = [
            '--ft-surface' => $values['surface'],
            '--ft-ink' => $values['ink'],
            '--ft-muted' => $values['muted'],
            '--ft-action' => $values['action'],
            '--ft-action-text' => $values['action_text'],
            '--ft-action-hover' => $values['action_hover'],
            '--ft-border' => 'rgba(' . self::rgb($values['border_color']) . ',' . ((int) $values['border_opacity'] / 100) . ')',
            '--ft-card-radius' => $values['card_radius'],
            '--ft-shadow' => self::shadows()[$values['shadow']],
            'background' => $values['canvas'],
        ];
        $css = '';

        foreach ($preview as $name => $value) {
            $css .= $name . ':' . $value . ';';
        }

        return $css;
    }

    /** @return list<string> */
    public static function colorKeys(): array
    {
        return ['canvas', 'surface', 'ink', 'muted', 'accent', 'action', 'action_text', 'action_hover', 'action_active', 'border_color'];
    }

    /** @return list<string> */
    public static function radiusKeys(): array
    {
        return ['card_radius', 'control_radius', 'pill_radius'];
    }

    private static function rgb(string $hex): string
    {
        return implode(',', array_map('hexdec', str_split(substr($hex, 1), 2)));
    }
}
