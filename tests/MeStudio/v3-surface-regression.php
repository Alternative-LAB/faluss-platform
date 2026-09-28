<?php
// Real Link transactions/renderer with in-memory WordPress doubles; never a site recipe.
ob_start();
require __DIR__ . '/v3-composition-regression.php';
ob_end_clean();
$GLOBALS['fl_test_owned_images'] = [77, 78];
// Test-only catalogue: exercise the configured-media path without shipping fallback icons.
if (!class_exists('Faluss_Link_Admin')) {
class Faluss_Link_Admin {
    public static function catalog() { return ['instagram' => ['label' => 'Instagram', 'active' => 1, 'outline_icon' => 77, 'full_logo' => 77]]; }
    public static function active_catalog() { return self::catalog(); }
}
}

$out = [];
foreach (['round', 'rounded', 'square'] as $shape) {
    foreach (['none', 'border', 'shadow', 'both'] as $effect) {
        $fields = ['avatar_attachment_id' => '78', 'avatar_shape' => $shape, 'avatar_effect' => $effect, 'avatar_visible' => '1'];
        $preview = Faluss_Link::studio_v3_preview($fields);
        fl_hotfix_assert(!is_wp_error($preview), 'Avatar draft accepted');
        fl_hotfix_assert($save('v3_avatar', $fields)['ok'], 'Avatar replacement/shape/effect saved');
        $state = Faluss_Link::studio_v2_state();
        fl_hotfix_assert($state['profile']['avatar_attachment_id'] === 78, 'Replacement attachment survives canonical reload');
        fl_hotfix_assert(str_replace('onboarding-preview', 'studio-preview', $preview['preview_html']) === $state['preview_html'], 'Avatar preview equals saved rendering');
        $out['avatar-' . $shape . '-' . $effect] = $state['preview_html'];
    }
}
$fields['avatar_visible'] = '0';
$preview = Faluss_Link::studio_v3_preview($fields);
fl_hotfix_assert($save('v3_avatar', $fields)['ok'], 'Hide retained avatar');
$state = Faluss_Link::studio_v2_state();
fl_hotfix_assert($state['profile']['avatar_attachment_id'] === 78 && str_contains($state['preview_html'], 'faluss-link-card--avatar-no'), 'Hide preserves attachment');
fl_hotfix_assert(str_replace('onboarding-preview', 'studio-preview', $preview['preview_html']) === $state['preview_html'], 'Hidden avatar parity');
$out['avatar-hidden'] = $state['preview_html'];
$fields['avatar_visible'] = '1';
fl_hotfix_assert($save('v3_avatar', $fields)['ok'], 'Retained avatar restored');
// An explicit transition overrides the selected page background.
fl_hotfix_assert($save('v3_colors', ['page_background' => '#000000', 'button_color' => '#FFD3BD'])['ok'], 'Exact black saved');
$state = Faluss_Link::studio_v2_state();
fl_hotfix_assert($state['preferences']['page_background'] === '#000000', 'Black persists unchanged');
fl_hotfix_assert($state['preferences']['hero_transition_color'] === '#82206B', 'Explicit transition preserved');
$out['explicit-fade'] = $state['preview_html'];
// Reset the theme through the existing user mutation, then select black without a separate fade.
fl_hotfix_assert($mutate('save_appearance', ['selected_theme' => 'faluss-default'])['ok'], 'Theme reset');
$preview = Faluss_Link::studio_v3_preview(['page_background' => '#000000', 'button_color' => '#FFD3BD']);
fl_hotfix_assert($save('v3_colors', ['page_background' => '#000000', 'button_color' => '#FFD3BD'])['ok'], 'Black overrides theme');
$state = Faluss_Link::studio_v2_state();
fl_hotfix_assert($state['preferences']['hero_transition_color'] === '#000000', 'Implicit fade follows exact black');
fl_hotfix_assert(str_replace('onboarding-preview', 'studio-preview', $preview['preview_html']) === $state['preview_html'], 'Black draft/saved parity');
$render = new ReflectionMethod('Faluss_Link', 'card_markup');
$profile = $state['profile'] + ['faluss_id' => $member];
$blocks = [];
for ($n = 0; $n < 7; ++$n) {
    $blocks[] = ['block_id' => sprintf('b0000000-0000-4000-8000-%012d', $n), 'type' => 'link', 'label' => 'Lien ' . ($n + 1), 'url' => 'https://instagram.com/demo', 'visibility' => $n === 5 ? 'none' : 'all', 'attachment_id' => $n % 2 ? 0 : 78];
}
$prefs = $state['preferences'];
$prefs['links_mode'] = 'image-grid';
$prefs['social_links'] = [['network' => 'instagram', 'url' => 'https://instagram.com/demo']];
$prefs['name_color'] = '#FFFFFF';
$prefs['button_texture'] = 'smooth';
$prefs['theme_overrides'][] = 'name_color';
foreach (['compact','cover'] as $size) {
    $prefs['wallpaper_size'] = $size;
    $prefs['wallpaper_effect'] = 'gradient';
    foreach ([0,1] as $avatarVisible) {
        $prefs['avatar_visible'] = $avatarVisible;
        $out[$size . '-' . $avatarVisible] = $render->invoke(null, $profile, $prefs, 'center', false, $blocks);
    }
}
fl_hotfix_assert(!str_contains($out['compact-1'], '>Lien 6<') && str_contains($out['compact-1'], '>Lien 7<'), 'Hidden links stay absent without changing later order');
$prefs['links_mode'] = 'neutral';
$out['list'] = $render->invoke(null, $profile, $prefs, 'center', false, $blocks);
fl_hotfix_assert(!str_contains($out['list'], 'faluss-link-card__link-image'), 'List default has no illustrated tiles');
// Image mutations reuse the existing block ID and ownership checks.
fl_hotfix_assert($mutate('create_link', ['block_id' => $linkId, 'label' => 'Image', 'url' => 'https://example.org/', 'collection_id' => '', 'visibility' => 'all', 'attachment_id' => 0])['ok'], 'Image link created');
foreach ([77,78,0] as $attachment) {
    fl_hotfix_assert($mutate('update_link', ['block_id' => $linkId, 'label' => 'Image', 'url' => 'https://example.org/', 'visibility' => 'all', 'attachment_id' => $attachment])['ok'], 'Link image add/replace/remove');
    $found = array_values(array_filter(Faluss_Link::studio_v2_state()['blocks'], static fn($b) => $b['block_id'] === $linkId));
    fl_hotfix_assert($found[0]['attachment_id'] === $attachment, 'Link attachment survives reload');
}
if (getenv('FALUSS_V3_SURFACES')) { echo json_encode($out, JSON_THROW_ON_ERROR); }
else { echo "V3 surface corrections: attachment lifecycle, avatar visibility, exact black, explicit fade and link images OK\n"; }
