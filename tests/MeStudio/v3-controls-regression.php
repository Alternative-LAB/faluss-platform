<?php
// Real rendering/transactions over in-memory doubles. No WordPress installation.
ob_start(); require __DIR__ . '/v3-surface-regression.php'; ob_end_clean();
$exact = new ReflectionMethod('Faluss_Link', 'composition_is_exact');
$oldComposition = json_decode($wpdb->card['composition'], true);
foreach (['secondary_color', 'image_border', 'image_border_color'] as $key) { unset($oldComposition['atomic'][$key]); }
$wpdb->card['composition'] = wp_json_encode($oldComposition);
$before = $wpdb->digest();
fl_hotfix_assert($exact->invoke(null, $wpdb->card['composition']), '0.6.2 composition remains valid');
foreach ([null, 'invalid', 1] as $invalidAtomic) {
    $malformed = $oldComposition; $malformed['atomic'] = $invalidAtomic;
    fl_hotfix_assert(!$exact->invoke(null, wp_json_encode($malformed)), 'Malformed atomic section rejected without a fatal');
}
$legacy = Faluss_Link::studio_v2_state();
fl_hotfix_assert($wpdb->digest() === $before && $legacy['preferences']['secondary_color'] === '', 'Read keeps legacy colour and never migrates storage');
fl_hotfix_assert($save('v3_colors', ['page_background'=>'#000000','button_color'=>$legacy['preferences']['button_color']])['ok'], 'Unrelated legacy save accepted');
$stored = json_decode($wpdb->card['composition'], true);
foreach (['secondary_color','image_border','image_border_color'] as $key) { fl_hotfix_assert(!array_key_exists($key,$stored['atomic']), 'Defaults do not expand unrelated legacy saves'); }
$before=$wpdb->digest();
$cards = ['legacy' => $legacy['preview_html']];
function get_query_var($key) { return $GLOBALS['public_test_slug'] ?? ''; }
ob_start(); Faluss_Link::public_profile_canvas(); $privateHead=ob_get_clean();
fl_hotfix_assert($privateHead === '', 'Public canvas hook leaves other pages alone');
$GLOBALS['public_test_slug']=$legacy['profile']['public_slug'];
ob_start(); Faluss_Link::public_profile_canvas(); $cards['head']=ob_get_clean();
fl_hotfix_assert(str_contains($cards['head'], '--fl-public-canvas:#000000') && str_contains($cards['head'], 'theme-color'), 'Public canvas colour emitted before first paint');
$GLOBALS['public_test_slug']='absent';
ob_start(); Faluss_Link::public_profile_canvas(); $missingHead=ob_get_clean();
fl_hotfix_assert($missingHead === '' && $wpdb->digest() === $before, 'Missing public profile is inert and head rendering never writes');

foreach (['#FFFFFF', '#000000', '#C94B73', ''] as $color) {
    $fields = ['name_font' => $legacy['preferences']['name_font'], 'name_weight' => '700', 'name_color' => $legacy['preferences']['name_color'], 'secondary_color' => $color];
    $preview = Faluss_Link::studio_v3_preview($fields);
    fl_hotfix_assert(!is_wp_error($preview) && $save('v3_name', $fields)['ok'], 'Shared colour draft/save accepted');
    $state = Faluss_Link::studio_v2_state();
    fl_hotfix_assert($state['preferences']['secondary_color'] === $color, 'Shared colour survives reload');
    fl_hotfix_assert(str_replace('onboarding-preview','studio-preview',$preview['preview_html']) === $state['preview_html'], 'Shared colour preview/saved parity');
    $cards['color-' . ($color ?: 'legacy')] = $state['preview_html'];
}
foreach ([['secondary_color'=>'red'], ['secondary_color'=>'#000;display:none'], ['image_border'=>'invalid'], ['image_border_color'=>''], ['image_border_color'=>'url(x)']] as $fields) {
    $before = $wpdb->digest();
    fl_hotfix_assert(is_wp_error(Faluss_Link::studio_v3_preview($fields)), 'Invalid style preview denied');
    fl_hotfix_assert(!$mutate('save_atomic_design', $fields)['ok'] && $before === $wpdb->digest(), 'Invalid style rejected atomically');
}
$base = Faluss_Link::studio_v2_state();
foreach (['none','solid'] as $border) {
    $fields = ['links_mode'=>'image-grid','image_border'=>$border,'image_border_color'=>'#C94B73'];
    $preview = Faluss_Link::studio_v3_preview($fields);
    fl_hotfix_assert(!is_wp_error($preview) && $mutate('save_atomic_design',$fields)['ok'], 'Border draft/save accepted');
    $state = Faluss_Link::studio_v2_state();
    fl_hotfix_assert($state['blocks'] === $base['blocks'], 'Presentation save preserves links and collections');
    fl_hotfix_assert($state['preferences']['image_border'] === $border && $state['preferences']['image_border_color'] === '#C94B73', 'Border survives reload');
    fl_hotfix_assert(str_replace('onboarding-preview','studio-preview',$preview['preview_html']) === $state['preview_html'], 'Border preview/saved parity');
    $prefs = $state['preferences']; $prefs['secondary_color']='#FFFFFF'; $prefs['name_color']='#FFFFFF'; $prefs['theme_overrides'][]='name_color';
    foreach (['compact','cover'] as $size) {
        $prefs['wallpaper_size']=$size; $prefs['wallpaper_effect']='none';
        $cards[$size.'-'.$border]=$render->invoke(null,$profile,$prefs,'center',false,$blocks);
        $cards[$size.'-short-'.$border]=$render->invoke(null,$profile,$prefs,'center',false,[]);
    }
    $prefs['links_mode']='neutral'; $cards['list-'.$border]=$render->invoke(null,$profile,$prefs,'center',false,$blocks);
}
$stale = $mutate('save_atomic_design', ['image_border'=>'none'], $base['version']);
fl_hotfix_assert(!$stale['ok'] && $stale['status']===409, 'Concurrent style edit conflicts');
if(getenv('FALUSS_V3_CONTROLS')) { echo json_encode($cards,JSON_THROW_ON_ERROR); }
else { echo "V3 controls: legacy compatibility, shared colour, illustrated borders, parity, invalid styles and conflict OK\n"; }
