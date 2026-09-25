<?php
// In-memory transaction double, real Link renderer. No real WordPress claim.
ob_start();
require dirname(__DIR__) . '/Link/link-hotfix-behavior-test.php';
ob_end_clean();
$GLOBALS['fl_test_composition_ready'] = true;
$wpdb->identity['publication_status'] = 'published';
$wpdb->identity['avatar_attachment_id'] = 77;
$wpdb->card['cover_attachment_id'] = 77;
$compose = new ReflectionMethod('Faluss_Link', 'composition_from_preferences');
$wpdb->card['composition'] = wp_json_encode($compose->invoke(null, $prefs_method->invoke(null, $member, false)));
$save = static function ($section, $fields, $version = null) {
    $state = Faluss_Link::studio_v2_state();
    return Faluss_Link::studio_v2_save_onboarding_step($section, '', $fields, $version ?? $state['version'], true);
};
$cards = [];
foreach (['simple', 'atomic'] as $structure) {
    fl_hotfix_assert($save('v3_mode', ['structure' => $structure])['ok'], 'Studio changes structure without onboarding cursor');
    foreach ([['compact','none'], ['compact','gradient'], ['compact','gradient'], ['cover','gradient'], ['compact','gradient'], ['cover','none']] as [$size,$effect]) {
        $fields = ['cover_attachment_id' => '77', 'wallpaper_size' => $size, 'wallpaper_effect' => $effect];
        $before = $wpdb->digest();
        $preview = Faluss_Link::studio_v3_preview($fields);
        fl_hotfix_assert(!is_wp_error($preview) && $wpdb->digest() === $before, 'Preview does not write');
        $result = $save('v3_wallpaper', $fields);
        fl_hotfix_assert($result['ok'], 'Wallpaper save succeeds');
        $state = Faluss_Link::studio_v2_state();
        fl_hotfix_assert(str_replace('onboarding-preview', 'studio-preview', $preview['preview_html']) === $state['preview_html'], 'Wallpaper preview equals reloaded saved card');
        fl_hotfix_assert($state['profile']['avatar_attachment_id'] === 77, 'Avatar retained');
        $cards[$structure . '-' . $size . '-' . $effect] = $state['preview_html'];
    }
}
foreach ([
    ['v3_colors', ['page_background' => '#DED4E4', 'button_color' => '#FFFFFF'], '--fl-page-background:#DED4E4'],
    ['v3_buttons', ['link_style' => 'light', 'button_texture' => 'grain'], 'faluss-link-card--links-light'],
    ['v3_name', ['name_font' => 'serif', 'name_weight' => '400', 'name_color' => '#F54955'], '--fl-name-weight:400'],
    ['v3_avatar', ['avatar_attachment_id' => '77', 'avatar_shape' => 'square', 'avatar_effect' => 'shadow'], 'faluss-link-card--avatar-shape-square'],
    ['v3_network_style', ['social_style' => 'outline-dark', 'social_color' => '#DED4E4'], '--fl-social-color:#DED4E4'],
] as [$section,$fields,$needle]) {
    $before = $wpdb->digest();
    $preview = Faluss_Link::studio_v3_preview($fields);
    fl_hotfix_assert(!is_wp_error($preview) && str_contains($preview['preview_html'], $needle), 'Immediate preview: ' . $section);
    fl_hotfix_assert($wpdb->digest() === $before, 'Preview read only: ' . $section);
    $stale = Faluss_Link::studio_v2_state()['version'];
    $saved = $save($section,$fields);
    fl_hotfix_assert($saved['ok'], 'Save: ' . $section);
    $state = Faluss_Link::studio_v2_state();
    fl_hotfix_assert(str_replace('onboarding-preview','studio-preview',$preview['preview_html']) === $state['preview_html'], 'Preview persistence parity: ' . $section);
    $digest = $wpdb->digest();
    $conflict = $save($section,$fields,$stale);
    fl_hotfix_assert($conflict['status'] === 409 && $wpdb->digest() === $digest, 'Concurrent save rejected: ' . $section);
}
fl_hotfix_assert(str_contains($state['preview_html'], '--fl-action-text:#000000'), 'White buttons have black text before JavaScript');
$before = $wpdb->digest();
$rejected = $save('v3_identity', ['display_name' => '', 'avatar_attachment_id' => '77']);
fl_hotfix_assert(!$rejected['ok'] && $wpdb->digest() === $before, 'Empty identity cannot erase a published profile');
$rejected = $save('v3_wallpaper', ['cover_attachment_id' => '999', 'wallpaper_size' => 'cover', 'wallpaper_effect' => 'none']);
fl_hotfix_assert(!$rejected['ok'] && $wpdb->digest() === $before, 'Foreign media cannot alter the composition');
$rejected = $save('v3_mode', ['structure' => 'invalid']);
fl_hotfix_assert(!$rejected['ok'] && $wpdb->digest() === $before, 'Invalid structure rolls back');
$studioViews = [];
foreach (['v3_mode', 'v3_identity', 'v3_socials', 'v3_links', 'v3_colors', 'v3_buttons', 'v3_avatar', 'v3_wallpaper', 'v3_name', 'v3_network_style'] as $section) {
    $_GET['v3_section'] = $section;
    $studioViews[$section] = \Faluss\Platform\MeStudio\OnboardingV3::render(true);
    fl_hotfix_assert(str_contains($studioViews[$section], 'data-studio="true"') && str_contains($studioViews[$section], 'Enregistrer'), 'Native Studio renders ' . $section);
}
$confirmation = (new ReflectionMethod(\Faluss\Platform\MeStudio\OnboardingV3::class, 'confirmation'))->invoke(null, 'membre');
fl_hotfix_assert(!str_contains($confirmation, 'data-v3-panel') && !str_contains($confirmation, 'progressbar') && str_contains($confirmation, 'Ouvrir le Studio'), 'Confirmation is outside the onboarding shell');
fl_hotfix_assert(str_contains($studioViews['v3_network_style'], 'data-v3-tab-panel="color" hidden inert'), 'Color tab hidden initially');
fl_hotfix_assert(str_contains($studioViews['v3_avatar'], 'Remplacer la photo') && str_contains($studioViews['v3_avatar'], '/77.jpg'), 'Studio reuses retained avatar');
if (getenv('FALUSS_V3_VIEWS')) { echo json_encode($studioViews + ['confirmation' => $confirmation], JSON_THROW_ON_ERROR); }
else if (getenv('FALUSS_V3_CARDS')) { echo json_encode($cards, JSON_THROW_ON_ERROR); }
else { echo "V3 composition, preview, persistence, media and concurrency: OK\n"; }
