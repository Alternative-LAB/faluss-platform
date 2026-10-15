<?php
/** Configure les expériences Elementor sans toucher aux parcours Identity existants. */
declare(strict_types=1);

namespace Faluss\Platform\Deployment;

$options = getopt('', ['wordpress:', 'profiles:']);
if (!isset($options['wordpress'], $options['profiles'])) {
    fwrite(STDERR, "Usage: php configure-signinforge.php --wordpress=/path --profiles=/path/profiles.json\n");
    exit(1);
}
require rtrim($options['wordpress'], '/') . '/wp-load.php';

try {
    $profiles = json_decode(file_get_contents($options['profiles']), true, 512, JSON_THROW_ON_ERROR);
    $host = wp_parse_url(home_url(), PHP_URL_HOST);
    $profile = $profiles[$host] ?? throw new \RuntimeException('Site non prévu dans les profils.');
    if (!class_exists(\SigninForge\Integrations\Elementor\ElementorAdapter::class)) {
        throw new \RuntimeException('SignInForge doit être actif.');
    }
    $adapter = new \SigninForge\Integrations\Elementor\ElementorAdapter();
    if (!$adapter->isAvailable()) {
        throw new \RuntimeException('Ce déploiement exige Elementor, présent sur les trois sites audités.');
    }
    if (!\Elementor\Plugin::$instance->experiments->is_feature_active('container')) {
        throw new \RuntimeException('Les containers Elementor doivent être actifs avant ce déploiement.');
    }
    $admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
    if (!$admin) {
        throw new \RuntimeException('Aucun administrateur disponible pour enregistrer le document.');
    }
    wp_set_current_user((int) $admin[0]);

    $box = static fn (int $top, int $right, int $bottom, int $left): array => [
        'unit' => 'px', 'top' => $top, 'right' => $right, 'bottom' => $bottom, 'left' => $left, 'isLinked' => false,
    ];
    $widget = static fn (string $type, array $settings): array => [
        'elType' => 'widget', 'widgetType' => $type, 'settings' => $settings, 'elements' => [],
    ];
    $container = static fn (array $settings, array $elements): array => [
        'elType' => 'container', 'settings' => $settings, 'elements' => $elements,
    ];
    $heading = static fn (string $text, string $tag, int $size, string $color): array => $widget('heading', [
        'title' => $text, 'header_size' => $tag, 'title_color' => $color,
        'typography_typography' => 'custom', 'typography_font_family' => 'Outfit',
        'typography_font_size' => ['unit' => 'px', 'size' => $size],
        'typography_font_size_mobile' => ['unit' => 'px', 'size' => min($size, 28)],
        'typography_font_weight' => '600', 'typography_line_height' => ['unit' => 'em', 'size' => 1.08],
        'typography_letter_spacing' => ['unit' => 'px', 'size' => -0.8],
    ]);
    $text = static fn (string $content, string $color, int $size = 16): array => $widget('text-editor', [
        'editor' => '<p>' . esc_html($content) . '</p>', 'text_color' => $color,
        'typography_typography' => 'custom', 'typography_font_family' => 'Outfit',
        'typography_font_size' => ['unit' => 'px', 'size' => $size],
        'typography_line_height' => ['unit' => 'em', 'size' => 1.6],
    ]);
    $brand = [];
    if ($profile['logo'] !== '') {
        $brand[] = $widget('image', [
            'image' => ['url' => $profile['logo']], 'align' => 'left',
            'width' => ['unit' => 'px', 'size' => $host === 'fans.faluss.me' ? 48 : 150],
            'link_to' => 'none',
        ]);
    } else {
        $brand[] = $heading($profile['name'], 'h2', 30, $profile['hero_ink']);
    }
    $brand[] = $text($profile['eyebrow'], $profile['hero_ink'], 12);
    $brand[] = $heading($profile['headline'], 'h2', $host === 'faluss.com' ? 72 : 64, $profile['hero_ink']);
    $brand[] = $text($profile['intro'], $profile['hero_ink'], 19);
    if ($host === 'faluss.me' && $profile['image'] !== '') {
        $brand[] = $widget('image', [
            'image' => ['url' => $profile['image']], 'align' => 'left',
            'width' => ['unit' => 'px', 'size' => 340],
            'width_mobile' => ['unit' => 'px', 'size' => 180],
            'hide_mobile' => 'hidden-mobile',
        ]);
    }
    $brand[] = $text($profile['footer'], $profile['hero_ink'], 13);
    $brandSettings = [
        'content_width' => 'full', 'width' => ['unit' => '%', 'size' => 52],
        'hide_tablet' => 'hidden-tablet', 'hide_mobile' => 'hidden-mobile',
        'width_mobile' => ['unit' => '%', 'size' => 100], 'flex_direction' => 'column',
        'flex_justify_content' => 'center', 'flex_gap' => ['unit' => 'px', 'size' => 24, 'column' => 24, 'row' => 24],
        'padding' => $box(48, 56, 48, 56), 'padding_mobile' => $box(30, 24, 30, 24),
        'background_background' => 'classic', 'background_color' => $profile['hero_background'],
        'border_radius' => $box(28, 28, 28, 28),
    ];
    if ($host === 'fans.faluss.me') {
        $brandSettings += [
            'background_image' => ['url' => $profile['image']], 'background_position' => 'center center',
            'background_size' => 'cover', 'background_overlay_background' => 'classic',
            'background_overlay_color' => '#030806', 'background_overlay_opacity' => ['size' => 0.65],
        ];
    }
    $form = $widget('signinforge-auth-form', [
        'form' => 'auto', 'show_remember_me' => 'yes', 'show_links' => 'yes',
        'form_max_width' => ['unit' => '%', 'size' => 100],
        'form_gap' => ['unit' => 'px', 'size' => 20],
        'form_color' => $profile['ink'], 'form_accent' => $profile['button'],
        'form_typography_typography' => 'custom', 'form_typography_font_family' => 'Outfit',
        'form_typography_font_size' => ['unit' => 'px', 'size' => 16],
        'label_color' => $profile['ink'], 'label_typography_font_weight' => '500',
        'field_color' => $profile['ink'], 'field_background' => $profile['surface'],
        'field_border_border' => 'solid', 'field_border_color' => $profile['border'],
        'field_border_width' => $box(1, 1, 1, 1), 'field_radius' => $box(12, 12, 12, 12),
        'field_padding' => $box(15, 16, 15, 16),
        'button_color' => $profile['button_ink'], 'button_background' => $profile['button'],
        'button_hover_color' => $profile['button_ink'], 'button_hover_background' => $profile['button_hover'],
        'button_typography_typography' => 'custom', 'button_typography_font_family' => 'Outfit',
        'button_typography_font_size' => ['unit' => 'px', 'size' => 16], 'button_typography_font_weight' => '600',
        'button_radius' => $box(999, 999, 999, 999), 'button_padding' => $box(16, 24, 16, 24),
        'link_color' => $profile['ink'], 'link_hover_color' => $profile['button'],
    ]);
    $fields = [
        $text($profile['name'] . ' / Votre compte', $profile['muted'], 12),
        $heading($profile['form_title'], 'h1', 36, $profile['ink']),
        $text($profile['form_intro'], $profile['muted']), $form,
        $widget('button', [
            'text' => $profile['alternate_label'], 'link' => ['url' => home_url($profile['alternate_url'])],
            'align' => 'justify', 'button_text_color' => $profile['ink'],
            'background_color' => $profile['surface'], 'border_border' => 'solid',
            'border_width' => $box(1, 1, 1, 1), 'border_color' => $profile['border'],
            'border_radius' => $box(999, 999, 999, 999), 'text_padding' => $box(14, 20, 14, 20),
            'typography_typography' => 'custom', 'typography_font_family' => 'Outfit',
            'typography_font_size' => ['unit' => 'px', 'size' => 14],
        ]),
        $widget('button', [
            'text' => '← Retour au site', 'link' => ['url' => home_url('/')], 'align' => 'center',
            'background_color' => '#00000000', 'button_text_color' => $profile['muted'],
            'typography_typography' => 'custom', 'typography_font_family' => 'Outfit',
            'typography_font_size' => ['unit' => 'px', 'size' => 14],
        ]),
    ];
    $card = $container([
        'content_width' => 'full', 'width' => ['unit' => '%', 'size' => 100],
        'flex_direction' => 'column', 'flex_gap' => ['unit' => 'px', 'size' => 20, 'column' => 20, 'row' => 20],
        'padding' => $box(44, 40, 36, 40), 'padding_mobile' => $box(30, 22, 26, 22),
        'background_background' => 'classic', 'background_color' => $profile['surface'],
        'border_border' => 'solid', 'border_width' => $box(1, 1, 1, 1), 'border_color' => $profile['border'],
        'border_radius' => $box(24, 24, 24, 24),
    ], $fields);
    $right = $container([
        'content_width' => 'full', 'width' => ['unit' => '%', 'size' => 48],
        'width_mobile' => ['unit' => '%', 'size' => 100], 'width_tablet' => ['unit' => '%', 'size' => 100],
        'flex_direction' => 'column', 'flex_justify_content' => 'center', 'flex_align_items' => 'center',
        'padding' => $box(32, 32, 32, 32), 'padding_mobile' => $box(0, 0, 0, 0),
    ], [$container(['content_width' => 'full', 'width' => ['unit' => 'px', 'size' => 480],
        'width_mobile' => ['unit' => '%', 'size' => 100]], [$card])]);
    $elements = [$container([
        'content_width' => 'full', 'min_height' => ['unit' => 'vh', 'size' => 100],
        'flex_direction' => 'row', 'flex_direction_mobile' => 'column', 'flex_direction_tablet' => 'column',
        'flex_justify_content_mobile' => 'center', 'flex_justify_content_tablet' => 'center',
        'flex_align_items' => 'stretch', 'flex_gap' => ['unit' => 'px', 'size' => 24, 'column' => 24, 'row' => 24],
        'padding' => $box(24, 24, 24, 24), 'padding_mobile' => $box(12, 12, 12, 12),
        'background_background' => 'classic', 'background_color' => $profile['background'],
    ], [$container($brandSettings, $brand), $right])];

    $validated = $adapter->sanitizeData(['elements' => $elements, 'pageSettings' => ['template' => 'elementor_canvas']]);
    if ($validated['errors']) {
        throw new \RuntimeException('Échec de la validation Elementor avant modification.');
    }
    $existing = get_posts(['post_type' => 'signinforge_exp', 'post_status' => 'any',
        'meta_key' => '_faluss_signinforge_rollout', 'meta_value' => '219', 'numberposts' => 2]);
    if (count($existing) > 1) {
        throw new \RuntimeException('Plusieurs expériences correspondent : inspection requise.');
    }
    $snapshot = ['post' => $existing ? get_post($existing[0]->ID, ARRAY_A) : null,
        'meta' => $existing ? get_post_meta($existing[0]->ID) : [],
        'settings' => get_option('signinforge_settings', false)];
    $snapshotPath = tempnam(sys_get_temp_dir(), 'signinforge-219-snapshot-');
    if ($snapshotPath === false || file_put_contents($snapshotPath, wp_json_encode($snapshot)) === false) {
        throw new \RuntimeException('Impossible de sauvegarder l’expérience avant modification.');
    }
    chmod($snapshotPath, 0600);
    $id = $existing ? $existing[0]->ID : wp_insert_post(['post_type' => 'signinforge_exp',
        'post_status' => 'draft', 'post_title' => 'Connexion — ' . $profile['name']], true);
    if (is_wp_error($id)) {
        throw new \RuntimeException($id->get_error_message());
    }
    update_post_meta($id, '_faluss_signinforge_rollout', '219');
    $palette = [];
    foreach (['background', 'surface', 'text' => 'ink', 'muted', 'accent' => 'button',
        'accent-text' => 'button_ink', 'border', 'field' => 'surface'] as $token => $value) {
        $palette[is_int($token) ? $value : $token] = ['light' => $profile[$value], 'dark' => $profile[$value]];
    }
    update_post_meta($id, '_signinforge_palette', $palette);
    update_post_meta($id, '_signinforge_color_scheme', $profile['scheme']);
    if (!$adapter->importData((int) $id, $validated['data'])) {
        throw new \RuntimeException('Échec de la validation ou de l’enregistrement Elementor.');
    }
    $published = wp_update_post(['ID' => $id, 'post_status' => 'publish'], true);
    if (is_wp_error($published)) {
        throw new \RuntimeException($published->get_error_message());
    }
    $settings = new \SigninForge\Core\Settings(home_url());
    $settings->update(['screens' => ['login' => $id, 'lostpassword' => 0, 'resetpass' => 0, 'register' => 0],
        'redirects' => ['after_login' => '', 'after_logout' => '/wp-login.php?loggedout=true']]);
    echo wp_json_encode(['site' => home_url(), 'experience' => $id, 'builder' => 'elementor',
        'login' => wp_login_url(), 'snapshot' => $snapshotPath, 'settings' => ['redirects' => $settings->all()['redirects'] ?? []]], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (\Throwable $error) {
    if (isset($snapshot, $id) && is_int($id) && $id > 0) {
        if ($snapshot['settings'] === false) {
            delete_option('signinforge_settings');
        } else {
            update_option('signinforge_settings', $snapshot['settings']);
        }
        if ($snapshot['post'] !== null) {
            wp_update_post(wp_slash($snapshot['post']));
            foreach (array_keys(get_post_meta($id)) as $key) {
                delete_post_meta($id, $key);
            }
            foreach ($snapshot['meta'] as $key => $values) {
                foreach ($values as $value) {
                    add_post_meta($id, $key, wp_slash(maybe_unserialize($value)));
                }
            }
        } else {
            wp_delete_post($id, true);
        }
        \Elementor\Plugin::$instance->files_manager->clear_cache();
    }
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
