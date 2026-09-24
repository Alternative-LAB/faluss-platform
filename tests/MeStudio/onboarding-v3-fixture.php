<?php

declare(strict_types=1);

// Isolated visual fixture. It does not simulate WordPress persistence or publication.
function esc_html($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value): string { return esc_html($value); }
function esc_url($value): string { return esc_html($value); }
function home_url($path = ''): string { return 'https://faluss.example' . $path; }
function checked($actual, $expected = true): void { if ($actual === $expected) { echo ' checked'; } }
function selected($actual, $expected = true): void { if ($actual === $expected) { echo ' selected'; } }

final class Faluss_Link
{
    public static function studio_v2_social_catalog(): array
    {
        $items = [];
        foreach (['instagram' => 'Instagram', 'tiktok' => 'TikTok', 'telegram' => 'Telegram', 'youtube' => 'YouTube', 'x' => 'X'] as $key => $label) {
            $items[$key] = ['active' => true, 'label' => $label, 'full' => ['src' => ''], 'outline' => ['src' => '']];
        }
        return $items;
    }

    public static function studio_v3_name_options(): array
    {
        return [
            'fonts' => ['outfit' => ['label' => 'Outfit'], 'system' => ['label' => 'Sans native'], 'serif' => ['label' => 'Sérif native']],
            'colors' => ['#000000' => 'Noir', '#F54955' => 'Corail', '#BE79FF' => 'Rose', '#82206B' => 'Prune', '#FFFFFF' => 'Blanc'],
        ];
    }
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$state = [
    'profile' => ['display_name' => 'Dylan', 'public_slug' => 'dylan', 'avatar_attachment_id' => 0],
    'preferences' => [
        'structure' => 'atomic', 'page_background' => '#DED4E4', 'button_color' => '#080808',
        'link_style' => 'outline', 'button_texture' => 'smooth', 'avatar_shape' => 'round',
        'avatar_effect' => 'border', 'cover_attachment_id' => 0, 'wallpaper_size' => 'cover',
        'wallpaper_effect' => 'gradient', 'name_font' => 'system', 'name_weight' => '700',
        'name_color' => '#F54955', 'social_style' => 'brand-light', 'social_color' => '#080808',
        'social_links' => [['network' => 'instagram', 'url' => 'https://instagram.com/dylan']],
    ],
    'blocks' => [
        ['type' => 'link', 'block_id' => '00000000-0000-4000-8000-000000000001', 'label' => 'Mon travail', 'url' => 'https://faluss.example/work'],
        ['type' => 'link', 'block_id' => '00000000-0000-4000-8000-000000000002', 'label' => 'Ma boutique', 'url' => 'https://faluss.example/shop'],
        ['type' => 'link', 'block_id' => '00000000-0000-4000-8000-000000000003', 'label' => 'Mes vidéos', 'url' => 'https://faluss.example/videos'],
    ],
];

$method = new ReflectionMethod(\Faluss\Platform\MeStudio\OnboardingV3::class, 'controls');
$output = [];
foreach (['simple', 'atomic'] as $mode) {
    foreach (\Faluss\Platform\MeStudio\OnboardingV3::sequence($mode) as $step) {
        ob_start();
        $method->invoke(null, $step, $mode, $state, 'dylan');
        $output[$mode][$step] = (string) ob_get_clean();
    }
}
ob_start();
$method->invoke(null, 'v3_success', 'atomic', $state, 'dylan');
$output['atomic']['v3_success'] = (string) ob_get_clean();
echo json_encode($output, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
