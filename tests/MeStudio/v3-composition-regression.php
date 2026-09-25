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
// Native management uses precise mutations, never replaces the complete stream.
$mutate = static function ($mutation, $fields, $version = null) {
    return Faluss_Link::studio_v2_mutate(array_merge($fields, ['mutation' => $mutation, 'aggregate_version' => $version ?? Faluss_Link::studio_v2_state()['version']]));
};
$initialBlocks = Faluss_Link::studio_v2_state()['blocks'];
$textId = 'a0000000-0000-4000-8000-000000000001';
$mediaId = 'a0000000-0000-4000-8000-000000000002';
$collectionId = 'a0000000-0000-4000-8000-000000000003';
$linkId = 'a0000000-0000-4000-8000-000000000004';
$descriptionId = 'a0000000-0000-4000-8000-000000000005';
$mediaFields = ['block_id' => $mediaId, 'type' => 'media_teaser', 'attachment_id' => 77, 'title' => 'Média', 'text' => 'Description', 'format' => 'portrait', 'access_mode' => 'member'];
fl_hotfix_assert($mutate('create_content', ['block_id' => $textId, 'type' => 'text', 'value' => 'Texte initial'])['ok'], 'Create text');
fl_hotfix_assert($mutate('create_content', $mediaFields)['ok'], 'Create owned media');
$stale = Faluss_Link::studio_v2_state()['version'];
fl_hotfix_assert($mutate('update_content', ['block_id' => $textId, 'value' => 'Texte modifié'])['ok'], 'Update text preserves type');
$before = $wpdb->digest();
fl_hotfix_assert($mutate('update_content', ['block_id' => $textId, 'value' => 'Ancienne session'], $stale)['status'] === 409 && $wpdb->digest() === $before, 'Content conflict preserves all data');
foreach ([['attachment_id' => 999], ['access_mode' => 'invalid'], ['access_mode' => 'entitlement', 'entitlement_code' => ''], ['type' => 'link']] as $invalid) {
    fl_hotfix_assert(!$mutate('update_content', array_replace($mediaFields, $invalid))['ok'] && $wpdb->digest() === $before, 'Invalid media change rolls back');
}
fl_hotfix_assert(!$mutate('delete_content', ['block_id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff'])['ok'] && $wpdb->digest() === $before, 'Foreign or missing block is rejected');
fl_hotfix_assert($mutate('create_collection', ['block_id' => $collectionId, 'description_block_id' => $descriptionId, 'name' => 'Collection', 'description' => 'Présentation'])['ok'], 'Create collection');
fl_hotfix_assert($mutate('update_collection', ['block_id' => $collectionId, 'name' => 'Collection modifiée', 'description' => 'Présentation modifiée'])['ok'], 'Edit collection');
fl_hotfix_assert($mutate('create_link', ['block_id' => $linkId, 'label' => 'Lien', 'url' => 'https://example.org/', 'collection_id' => $collectionId, 'visibility' => 'members', 'attachment_id' => 77])['ok'], 'Add link in collection');
fl_hotfix_assert($mutate('update_link', ['block_id' => $linkId, 'label' => 'Lien modifié', 'url' => 'https://example.org/', 'visibility' => 'none', 'attachment_id' => 0])['ok'], 'Edit link visibility and image');
$ids = array_column(Faluss_Link::studio_v2_state()['blocks'], 'block_id');
fl_hotfix_assert($mutate('reorder_blocks', ['block_ids' => $ids])['ok'], 'Complete stream reorder accepted');
$before = $wpdb->digest();
fl_hotfix_assert(!$mutate('reorder_blocks', ['block_ids' => [$linkId]])['ok'] && $wpdb->digest() === $before, 'Partial reorder cannot drop blocks');
fl_hotfix_assert($mutate('dissolve_collection', ['block_id' => $collectionId])['ok'], 'Dissolve collection');
fl_hotfix_assert(in_array($linkId, array_column(Faluss_Link::studio_v2_state()['blocks'], 'block_id'), true), 'Dissolve retains its link');
fl_hotfix_assert($mutate('delete_link', ['block_id' => $linkId])['ok'], 'Delete link');
fl_hotfix_assert($mutate('delete_content', ['block_id' => $mediaId])['ok'], 'Delete media');
fl_hotfix_assert($mutate('delete_content', ['block_id' => $textId])['ok'], 'Delete text');
fl_hotfix_assert(Faluss_Link::studio_v2_state()['blocks'] === $initialBlocks, 'Every original block and order retained');
fl_hotfix_assert($mutate('save_profile', ['bio' => 'Bio modifiée', 'publication_status' => 'draft'])['ok'], 'Edit bio and unpublish');
fl_hotfix_assert($mutate('save_profile', ['publication_status' => 'published'])['ok'], 'Republish');
fl_hotfix_assert($mutate('save_header', ['available' => 1, 'avatar_visible' => 1, 'alignment' => 'left', 'social_layout' => 'bubbles'])['ok'], 'Restore header settings');
fl_hotfix_assert($mutate('save_atomic_design', ['links_mode' => 'image-grid', 'link_width' => 'compact'])['ok'], 'Restore link layout');
fl_hotfix_assert($mutate('save_appearance', ['hero_transition_color' => '#82206B', 'hero_transition_intensity' => 60, 'hero_transition_position' => 75])['ok'], 'Restore cover transition');

$legacyMode = \Faluss\Platform\MeStudio\OnboardingV3::mode('wizard_atomic_wallpaper', ['structure' => 'simple']);
fl_hotfix_assert($legacyMode === 'atomic', 'Historical Atomic cursor takes precedence over default preferences');
$studioViews = [];
foreach (['v3_mode', 'v3_identity', 'v3_socials', 'v3_links', 'v3_colors', 'v3_buttons', 'v3_avatar', 'v3_wallpaper', 'v3_name', 'v3_network_style', 'v3_collections', 'v3_contents', 'v3_settings'] as $section) {
    $_GET['v3_section'] = $section;
    $studioViews[$section] = \Faluss\Platform\MeStudio\StudioV3::render();
    fl_hotfix_assert(str_contains($studioViews[$section], 'data-studio="true"'), 'Native Studio renders ' . $section);
    fl_hotfix_assert(!str_contains($studioViews[$section], 'data-v3-section') && !str_contains($studioViews[$section], '__phone') && str_contains($studioViews[$section], '<dialog'), 'Independent Studio navigation and on-demand preview');
}
$confirmation = (new ReflectionMethod(\Faluss\Platform\MeStudio\OnboardingV3::class, 'confirmation'))->invoke(null, 'membre');
fl_hotfix_assert(!str_contains($confirmation, 'data-v3-panel') && !str_contains($confirmation, 'progressbar') && str_contains($confirmation, 'Ouvrir le Studio'), 'Confirmation is outside the onboarding shell');
fl_hotfix_assert(str_contains($studioViews['v3_network_style'], 'data-v3-tab-panel="color" hidden inert'), 'Color tab hidden initially');
fl_hotfix_assert(str_contains($studioViews['v3_avatar'], 'Remplacer la photo') && str_contains($studioViews['v3_avatar'], '/77.jpg'), 'Studio reuses retained avatar');
if (getenv('FALUSS_V3_PAGE')) {
    $state = Faluss_Link::studio_v2_state();
    $prefs = $prefs_method->invoke(null, $member, false);
    $prefs['page_background'] = '#DED4E4';
    $prefs['wallpaper_size'] = 'compact';
    $prefs['wallpaper_effect'] = 'gradient';
    $prefs['links_mode'] = 'neutral';
    $prefs['link_width'] = 'wide';
    $prefs['alignment'] = 'center';
    $render = new ReflectionMethod('Faluss_Link', 'card_markup');
    $profile = $state['profile'] + ['faluss_id' => $member];
    $blocks = [];
    for ($n = 0; $n < 20; ++$n) { $blocks[] = ['block_id' => sprintf('b0000000-0000-4000-8000-%012d', $n), 'type' => 'link', 'label' => 'Lien ' . ($n + 1), 'url' => 'https://example.org/', 'visibility' => 'all', 'attachment_id' => 0]; }
    echo json_encode(['short' => $render->invoke(null, $profile, $prefs, 'center', false, array_slice($blocks, 0, 2)), 'long' => $render->invoke(null, $profile, $prefs, 'center', false, $blocks)], JSON_THROW_ON_ERROR);
    exit;
}
if (getenv('FALUSS_V3_VIEWS')) { echo json_encode($studioViews + ['confirmation' => $confirmation], JSON_THROW_ON_ERROR); }
else if (getenv('FALUSS_V3_CARDS')) { echo json_encode($cards, JSON_THROW_ON_ERROR); }
else { echo "V3 composition, preview, persistence, media and concurrency: OK\n"; }
