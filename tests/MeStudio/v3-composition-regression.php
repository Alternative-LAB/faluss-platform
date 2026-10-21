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
$initialVersion = Faluss_Link::studio_v2_state()['version'];
$before = $wpdb->digest();
fl_hotfix_assert(!is_wp_error(Faluss_Link::studio_v3_preview(['activity_category' => 'video'])) && $wpdb->digest() === $before, 'Category preview is read only');
fl_hotfix_assert($save('v3_activity', ['activity_category' => 'video'])['ok'], 'Category saves through the V3 aggregate');
$activityState = Faluss_Link::studio_v2_state();
fl_hotfix_assert($activityState['preferences']['activity_category'] === 'video' && $activityState['version'] !== $initialVersion, 'Category is canonical and versioned');
$storedSocials = json_decode($wpdb->card['social_links'], true);
fl_hotfix_assert($storedSocials['activity_category'] === 'video' && is_array($storedSocials['networks']), 'Category and networks share the Link envelope');
$before = $wpdb->digest();
fl_hotfix_assert($save('v3_activity', ['activity_category' => 'music'], $initialVersion)['status'] === 409 && $wpdb->digest() === $before, 'Stale category save cannot overwrite the current choice');
fl_hotfix_assert(!$save('v3_activity', ['activity_category' => 'unknown'])['ok'] && $wpdb->digest() === $before, 'Unknown category is rejected without a write');
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
fl_hotfix_assert($save('v3_socials', ['social_networks' => wp_json_encode([['network' => 'instagram', 'url' => 'https://instagram.com/recette']])])['ok'], 'V3 networks save after activity choice');
fl_hotfix_assert(Faluss_Link::studio_v2_state()['preferences']['activity_category'] === 'video', 'V3 networks save preserves the category');
$legacySave = new ReflectionMethod('Faluss_Link', 'save_preferences');
fl_hotfix_assert($legacySave->invoke(null, $member, ['social_networks' => [['network' => 'instagram', 'url' => 'https://instagram.com/recette']]]), 'Legacy preferences save after activity choice');
fl_hotfix_assert(Faluss_Link::studio_v2_state()['preferences']['activity_category'] === 'video', 'Legacy preferences save preserves the category');
fl_hotfix_assert($save('v3_socials', ['social_networks' => '[]'])['ok'], 'Fixture restores its initial networks');
fl_hotfix_assert(Faluss_Link::studio_v2_state()['preferences']['activity_category'] === 'video', 'Clearing networks preserves the category');
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
fl_hotfix_assert($state['preferences']['activity_category'] === 'video', 'Unrelated V3 design saves preserve the category');
fl_hotfix_assert($save('v3_activity', ['activity_category' => 'none'])['ok'], 'Member can clear the category without removing other card data');
fl_hotfix_assert(Faluss_Link::studio_v2_state()['preferences']['activity_category'] === 'none', 'Cleared category persists as the default');
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
$contextCases = [
    'video' => ['youtube', 'https://youtu.be/dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
    'music' => ['music_link', 'https://open.spotify.com/track/example', 'https://open.spotify.com/track/example'],
    'streaming' => ['twitch', 'https://twitch.tv/example', 'https://www.twitch.tv/example'],
    'visual_arts' => ['dribbble', 'https://dribbble.com/example', 'https://www.dribbble.com/example'],
];
$contextIds = [];
foreach ($contextCases as $category => [$provider, $url, $canonical]) {
    $id = wp_generate_uuid4(); $contextIds[$category] = $id;
    $fields = ['block_id' => $id, 'category' => $category, 'provider' => $provider, 'url' => $url, 'title' => 'À la une ' . $category, 'subtitle' => 'Description', 'cover_attachment_id' => '0', 'enabled' => '1'];
    fl_hotfix_assert($mutate('create_contextual', $fields)['ok'], 'Optional contextual block saves: ' . $category);
    $saved = current(array_filter(Faluss_Link::studio_v2_state()['blocks'], static fn ($block): bool => $block['block_id'] === $id));
    fl_hotfix_assert($saved['url'] === $canonical && $saved['enabled'], 'Provider URL canonicalized: ' . $category);
}
$before = $wpdb->digest();
fl_hotfix_assert(!$mutate('create_contextual', ['block_id' => wp_generate_uuid4(), 'category' => 'video', 'provider' => 'youtube', 'url' => 'https://youtu.be/dQw4w9WgXcQ', 'enabled' => '1'])['ok'] && $wpdb->digest() === $before, 'One contextual block per category');
foreach (['http://youtu.be/dQw4w9WgXcQ', 'https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ', 'https://user@youtube.com/watch?v=dQw4w9WgXcQ', 'https://youtube.com:8443/watch?v=dQw4w9WgXcQ', 'https://youtu.be/dQw4w9WgXcQ#x', 'https://127.0.0.1/watch?v=dQw4w9WgXcQ'] as $hostile) {
    fl_hotfix_assert(!$mutate('update_contextual', ['block_id' => $contextIds['video'], 'category' => 'video', 'provider' => 'youtube', 'url' => $hostile])['ok'] && $wpdb->digest() === $before, 'Unsafe provider URL rejected without write');
}
fl_hotfix_assert(!$mutate('update_contextual', ['block_id' => $contextIds['video'], 'category' => 'music', 'provider' => 'music_link', 'url' => 'https://open.spotify.com/track/example'])['ok'] && $wpdb->digest() === $before, 'Category cannot move an existing block');
fl_hotfix_assert(!$mutate('update_contextual', ['block_id' => $contextIds['video'], 'category' => 'video', 'provider' => 'youtube', 'url' => 'https://youtu.be/dQw4w9WgXcQ', 'cover_attachment_id' => '999'])['ok'] && $wpdb->digest() === $before, 'Foreign cover rejected');
fl_hotfix_assert($save('v3_activity', ['activity_category' => 'video'])['ok'], 'Activate video category');
$videoState = Faluss_Link::studio_v2_state();
fl_hotfix_assert(str_contains($videoState['preview_html'], 'À la une video') && strpos($videoState['preview_html'], 'À la une video') < strpos($videoState['preview_html'], 'Premier onglet') && !str_contains($videoState['preview_html'], 'À la une music'), 'Only active contextual block renders first');
$draft = ['block_id' => $contextIds['video'], 'category' => 'video', 'provider' => 'youtube', 'url' => 'https://youtube.com/shorts/dQw4w9WgXcQ', 'title' => 'Brouillon vidéo', 'subtitle' => 'Modifié', 'cover_attachment_id' => '0', 'enabled' => '1'];
$before = $wpdb->digest();
$preview = Faluss_Link::studio_v3_preview(['contextual_draft' => wp_json_encode($draft)]);
fl_hotfix_assert(is_array($preview) && str_contains($preview['preview_html'], 'Brouillon vidéo') && $wpdb->digest() === $before, 'Contextual draft previews without writing');
$stale = $videoState['version'];
fl_hotfix_assert($mutate('update_contextual', ['block_id' => $contextIds['video'], 'category' => 'video', 'provider' => 'youtube', 'url' => $draft['url'], 'title' => 'Vidéo modifiée', 'enabled' => '1'])['ok'], 'Contextual edit persists');
$before = $wpdb->digest();
fl_hotfix_assert($mutate('update_contextual', ['block_id' => $contextIds['video'], 'category' => 'video', 'provider' => 'youtube', 'url' => $draft['url'], 'enabled' => '0'], $stale)['status'] === 409 && $wpdb->digest() === $before, 'Stale contextual edit rejects without write');
fl_hotfix_assert($save('v3_activity', ['activity_category' => 'music'])['ok'], 'Category switch retains contextual content');
$musicState = Faluss_Link::studio_v2_state();
fl_hotfix_assert(str_contains($musicState['preview_html'], 'À la une music') && !str_contains($musicState['preview_html'], 'Vidéo modifiée') && count(array_filter($musicState['blocks'], static fn ($block): bool => $block['type'] === 'contextual_feature')) === 4, 'Inactive blocks stay private but remain editable');
$publicMusic = (new ReflectionMethod('Faluss_Link', 'card_markup'))->invoke(null, $musicState['profile'] + ['faluss_id' => $member], $prefs_method->invoke(null, $member, false), 'center', false, $musicState['blocks']);
fl_hotfix_assert(str_contains($publicMusic, 'À la une music') && !str_contains($publicMusic, 'Vidéo modifiée') && !str_contains($publicMusic, 'data-v3-preview-block-id'), 'Public and preview use the same active block projection');
fl_hotfix_assert($mutate('update_contextual', ['block_id' => $contextIds['visual_arts'], 'category' => 'visual_arts', 'provider' => 'pinterest', 'url' => 'https://pinterest.com/example', 'enabled' => '1'])['ok'], 'Pinterest portfolio is a valid visual-arts alternative');
fl_hotfix_assert($mutate('update_contextual', ['block_id' => $contextIds['music'], 'category' => 'music', 'provider' => 'music_link', 'url' => 'https://open.spotify.com/track/example', 'enabled' => '0'])['ok'], 'Member can hide a contextual block');
fl_hotfix_assert(!str_contains(Faluss_Link::studio_v2_state()['preview_html'], 'À la une music'), 'Hidden block has no public projection');
fl_hotfix_assert($mutate('update_contextual', ['block_id' => $contextIds['music'], 'category' => 'music', 'provider' => 'music_link', 'url' => 'https://open.spotify.com/track/example', 'enabled' => '1'])['ok'], 'Member can reactivate a contextual block');
foreach ($contextIds as $id) { fl_hotfix_assert($mutate('delete_contextual', ['block_id' => $id])['ok'], 'Explicit contextual deletion succeeds'); }
fl_hotfix_assert($save('v3_activity', ['activity_category' => 'none'])['ok'] && Faluss_Link::studio_v2_state()['blocks'] === $initialBlocks, 'Contextual scenario restores the original card');
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
$linkForPreview = current(array_filter($initialBlocks, static fn ($block): bool => ($block['type'] ?? '') === 'link'));
$linkDraft = ['block_id' => $linkForPreview['block_id'], 'label' => 'Lien temporaire', 'url' => 'https://example.test/temporaire'];
$temporaryPreview = Faluss_Link::studio_v3_preview(['link_draft' => wp_json_encode($linkDraft)]);
fl_hotfix_assert(is_array($temporaryPreview) && str_contains($temporaryPreview['preview_html'], 'Lien temporaire') && str_contains($temporaryPreview['preview_html'], 'data-v3-preview-block-id="' . $linkForPreview['block_id'] . '"'), 'Existing link draft uses canonical preview and stable block identity');
fl_hotfix_assert(Faluss_Link::studio_v3_preview(['link_draft' => wp_json_encode($linkDraft + ['unexpected' => 'x'])]) instanceof \WP_Error, 'Unexpected preview field rejected');
fl_hotfix_assert(Faluss_Link::studio_v3_preview(['link_draft' => wp_json_encode(array_replace($linkDraft, ['url' => 'http://example.test/no']))]) instanceof \WP_Error, 'Non-HTTPS draft rejected');
fl_hotfix_assert(Faluss_Link::studio_v3_preview(['link_draft' => wp_json_encode(array_replace($linkDraft, ['block_id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff']))]) instanceof \WP_Error, 'Unknown link draft rejected');
$publicCard = (new ReflectionMethod('Faluss_Link', 'card_markup'))->invoke(null, Faluss_Link::studio_v2_state()['profile'], $prefs_method->invoke(null, $member, false), 'center', false, $initialBlocks);
fl_hotfix_assert(!str_contains($publicCard, 'data-v3-preview-block-id'), 'Editor identifiers stay out of public markup');
fl_hotfix_assert(Faluss_Link::studio_v2_state()['blocks'] === $initialBlocks, 'Preview never persists a link draft');

$legacyMode = \Faluss\Platform\MeStudio\OnboardingV3::mode('wizard_atomic_wallpaper', ['structure' => 'simple']);
fl_hotfix_assert($legacyMode === 'atomic', 'Historical Atomic cursor takes precedence over default preferences');
$studioViews = [];
$contextFixtureId = wp_generate_uuid4();
fl_hotfix_assert($save('v3_activity', ['activity_category' => 'video'])['ok'], 'Context browser fixture selects video');
fl_hotfix_assert($mutate('create_contextual', ['block_id' => $contextFixtureId, 'category' => 'video', 'provider' => 'youtube', 'url' => 'https://youtu.be/dQw4w9WgXcQ', 'title' => 'Vidéo à la une', 'subtitle' => 'Démonstration', 'cover_attachment_id' => '0', 'enabled' => '1'])['ok'], 'Context browser fixture stores a video');
$_GET['v3_section'] = 'v3_context';
$studioViews['v3_context'] = \Faluss\Platform\MeStudio\StudioV3::render();
fl_hotfix_assert(str_contains($studioViews['v3_context'], 'data-mutation="update_contextual"') && str_contains($studioViews['v3_context'], 'data-faluss-contextual-embed'), 'Context browser fixture includes editor and public projection');
fl_hotfix_assert($mutate('delete_contextual', ['block_id' => $contextFixtureId])['ok'] && $save('v3_activity', ['activity_category' => 'none'])['ok'], 'Context browser fixture restores card');
foreach (['v3_mode', 'v3_identity', 'v3_activity', 'v3_socials', 'v3_links', 'v3_colors', 'v3_buttons', 'v3_avatar', 'v3_wallpaper', 'v3_name', 'v3_network_style', 'v3_collections', 'v3_contents', 'v3_settings'] as $section) {
    $_GET['v3_section'] = $section;
    $studioViews[$section] = \Faluss\Platform\MeStudio\StudioV3::render();
    fl_hotfix_assert(str_contains($studioViews[$section], 'data-studio="true"'), 'Native Studio renders ' . $section);
    fl_hotfix_assert(!str_contains($studioViews[$section], 'data-v3-section') && !str_contains($studioViews[$section], '__phone') && str_contains($studioViews[$section], '<dialog'), 'Independent Studio navigation and on-demand preview');
}
$confirmation = (new ReflectionMethod(\Faluss\Platform\MeStudio\OnboardingV3::class, 'confirmation'))->invoke(null, 'membre');
fl_hotfix_assert(!str_contains($confirmation, 'data-v3-panel') && !str_contains($confirmation, 'progressbar') && str_contains($confirmation, 'Ouvrir le Studio'), 'Confirmation is outside the onboarding shell');
fl_hotfix_assert(str_contains($studioViews['v3_network_style'], 'data-v3-tab-panel="color" hidden inert'), 'Color tab hidden initially');
fl_hotfix_assert(str_contains($studioViews['v3_avatar'], 'Remplacer la photo') && str_contains($studioViews['v3_avatar'], '/77.jpg'), 'Studio reuses retained avatar');
fl_hotfix_assert(str_contains($studioViews['v3_activity'], 'name="activity_category"') && str_contains($studioViews['v3_activity'], 'Plus tard'), 'Activity is editable in the Studio profile group');
if (getenv('FALUSS_V3_ONBOARDING_VIEWS')) {
    // Actual PHP onboarding shell on the same in-memory Link aggregate.
    function get_user_meta(): string { return ''; }
    final class Faluss_Identity_Onboarding {
        public static function v3_context(): array { return ['available' => true, 'step' => $GLOBALS['v3_fixture_step']]; }
    }
    $wpdb->identity['publication_status'] = 'draft';
    $onboardingViews = [];
    foreach (['v3_identity', 'v3_activity', 'v3_links', 'v3_colors', 'v3_review'] as $fixtureStep) {
        $GLOBALS['v3_fixture_step'] = $fixtureStep;
        $onboardingViews[$fixtureStep] = \Faluss\Platform\MeStudio\OnboardingV3::render();
        fl_hotfix_assert(str_contains($onboardingViews[$fixtureStep], 'data-v3-web-editors') && str_contains($onboardingViews[$fixtureStep], 'data-v3-step-controls'), 'Web editor and sequential step share shell: ' . $fixtureStep);
        fl_hotfix_assert(str_contains($onboardingViews[$fixtureStep], 'data-v3-editor') && str_contains($onboardingViews[$fixtureStep], 'data-v3-preview-block-id'), 'Existing links keep editor and preview identity: ' . $fixtureStep);
    }
    $wpdb->identity['public_slug'] = '';
    $GLOBALS['v3_fixture_step'] = 'v3_identity';
    fl_hotfix_assert(!str_contains(\Faluss\Platform\MeStudio\OnboardingV3::render(), 'data-v3-web-editors'), 'No link editor before slug reservation');
    echo json_encode($onboardingViews, JSON_THROW_ON_ERROR);
    exit;
}
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
