<?php

declare(strict_types=1);

namespace Faluss\Platform\MeStudio;

use Faluss\Platform\Link\LinkStudioContract;

/** Native controls over the existing member aggregate, without a second block store. */
final class StudioV3Management
{
    public static function handles(string $section): bool
    {
        return in_array($section, ['v3_links', 'v3_collections', 'v3_contents', 'v3_settings'], true);
    }

    /** @param array<string, mixed> $state */
    public static function render(string $section, array $state): void
    {
        $titles = ['v3_links' => 'Tes liens', 'v3_collections' => 'Tes collections', 'v3_contents' => 'Contenus et ordre', 'v3_settings' => 'Réglages de ta carte'];
        echo '<div data-v3-management><h1 tabindex="-1">' . esc_html($titles[$section]) . '</h1><p>Enregistre chaque élément avant de passer au suivant.</p>';
        $blocks = (array) ($state['blocks'] ?? []);
        $collections = (array) ($state['collections'] ?? []);
        if ($section === 'v3_links') {
            foreach ($blocks as $block) {
                if ($block['type'] === 'link') { self::link($block); }
            }
            self::start('create_link', 'Ajouter un lien');
            self::linkFields([]);
            $choices = ['' => 'Hors collection'];
            foreach ($collections as $collection) { $choices[$collection['block_id']] = $collection['name']; }
            self::select('collection_id', 'Collection', $choices, '');
            self::end('Ajouter');
            echo '<p>Pour déplacer un lien entre collections, utilise « Contenus et ordre ».</p>';
        } elseif ($section === 'v3_collections') {
            foreach ($collections as $collection) {
                self::start('update_collection', (string) $collection['name'], (string) $collection['block_id']);
                self::input('name', 'Nom', $collection['name'], 80);
                self::input('description', 'Description', $collection['description'], 480, 'textarea');
                self::end('Enregistrer', 'dissolve_collection', 'Dissoudre la collection');
            }
            self::start('create_collection', 'Ajouter une collection');
            self::input('name', 'Nom', '', 80);
            self::input('description', 'Description', '', 480, 'textarea');
            self::end('Ajouter');
            echo '<p>Dissoudre retire le titre et sa description, en conservant les liens et médias.</p>';
        } elseif ($section === 'v3_contents') {
            $options = LinkStudioContract::managementOptions();
            foreach ($blocks as $block) {
                if (in_array($block['type'], ['text', 'media_teaser'], true)) {
                    self::content($block, (array) $options['rights']);
                }
            }
            self::content(['type' => 'text'], (array) $options['rights']);
            self::content(['type' => 'media_teaser'], (array) $options['rights']);
            self::start('reorder_blocks', 'Ordre de tous les contenus');
            echo '<p>Un titre ouvre une collection jusqu’au titre suivant. Déplace un lien sous le titre voulu, ou avant le premier titre pour le sortir des collections.</p><ol data-v3-block-order>';
            foreach ($blocks as $block) {
                echo '<li data-block-id="' . esc_attr($block['block_id']) . '"><span>' . esc_html($block['value'] ?? $block['label'] ?? $block['title'] ?? $block['type']) . '</span><button type="button" data-v3-order="up" aria-label="Monter">↑</button><button type="button" data-v3-order="down" aria-label="Descendre">↓</button></li>';
            }
            echo '</ol>';
            self::end('Enregistrer l’ordre');
        } else {
            self::settings($state);
        }
        echo '</div>';
    }

    /** @param array<string, mixed> $block */
    private static function link(array $block): void
    {
        self::start('update_link', (string) $block['label'], (string) $block['block_id']);
        self::linkFields($block);
        self::end('Enregistrer', 'delete_link', 'Supprimer le lien');
    }

    /** @param array<string, mixed> $block */
    private static function linkFields(array $block): void
    {
        self::input('label', 'Libellé', $block['label'] ?? '', 80);
        self::input('url', 'URL HTTPS', $block['url'] ?? '', 2048, 'url');
        self::select('visibility', 'Visibilité', ['all' => 'Tout le monde', 'members' => 'Membres', 'none' => 'Masqué'], $block['visibility'] ?? 'all');
        self::media($block);
    }

    /**
     * @param array<string, mixed> $block
     * @param array<mixed> $rights
     */
    private static function content(array $block, array $rights): void
    {
        $existing = isset($block['block_id']);
        self::start($existing ? 'update_content' : 'create_content', ($existing ? 'Modifier : ' : 'Ajouter : ') . ($block['type'] === 'text' ? 'texte' : 'teaser média'), (string) ($block['block_id'] ?? ''));
        self::input('type', '', $block['type'], 20, 'hidden');
        if ($block['type'] === 'text') {
            self::input('value', 'Texte', $block['value'] ?? '', 480, 'textarea');
        } else {
            self::media($block);
            self::input('title', 'Titre', $block['title'] ?? '', 80);
            self::input('text', 'Description', $block['text'] ?? '', 240, 'textarea');
            self::select('format', 'Format', ['landscape' => 'Paysage', 'portrait' => 'Portrait', 'square' => 'Carré'], $block['format'] ?? 'landscape');
            self::select('access_mode', 'Accès', ['public' => 'Public', 'member' => 'Membre Faluss', 'entitlement' => 'Droit requis'], $block['access_mode'] ?? 'public');
            $choices = ['' => 'Choisir un droit'];
            foreach ($rights as $right) { $choices[$right['code']] = $right['label']; }
            $current = (string) ($block['entitlement_code'] ?? '');
            if ($current !== '' && !isset($choices[$current])) { $choices[$current] = $current . ' (droit enregistré)'; }
            self::select('entitlement_code', 'Droit requis', $choices, $current);
            if (!$rights) { echo '<p>Aucun droit disponible dans le catalogue connecté. Un droit déjà enregistré reste conservé.</p>'; }
        }
        self::end($existing ? 'Enregistrer' : 'Ajouter', $existing ? 'delete_content' : '', 'Supprimer le contenu');
    }

    /** @param array<string, mixed> $state */
    private static function settings(array $state): void
    {
        $profile = (array) $state['profile'];
        $prefs = (array) $state['preferences'];
        $options = LinkStudioContract::managementOptions();
        if (($profile['publication_status'] ?? '') === 'published' && !empty($profile['public_slug'])) {
            $url = home_url('/' . $profile['public_slug'] . '/');
            echo '<p><a href="' . esc_url($url) . '">Voir mon Faluss public</a></p><label>Lien à partager<input readonly value="' . esc_attr($url) . '"></label>';
        }
        echo '<p><a href="' . esc_url(home_url('/')) . '">Accueil</a> · <a href="' . esc_url(home_url('/list/')) . '">Ma Liste</a></p>';
        self::start('save_profile', 'Bio et publication');
        self::input('bio', 'Bio', $profile['bio'] ?? '', 280, 'textarea');
        self::select('publication_status', 'Publication', ['draft' => 'Brouillon', 'published' => 'Publié'], $profile['publication_status'] ?? 'draft');
        self::end();
        self::start('save_header', 'En-tête et réseaux');
        foreach (['available' => 'Disponible', 'avatar_visible' => 'Afficher l’avatar'] as $key => $label) { self::select($key, $label, ['0' => 'Non', '1' => 'Oui'], $prefs[$key] ?? 0); }
        self::select('alignment', 'Alignement', ['left' => 'Gauche', 'center' => 'Centre'], $prefs['alignment'] ?? 'center');
        self::select('social_layout', 'Disposition des réseaux', ['inline' => 'Ligne', 'bubbles' => 'Bulles'], $prefs['social_layout'] ?? 'inline');
        $fonts = [];
        foreach ((array) $options['fonts'] as $key => $font) { $fonts[$key] = $font['label']; }
        self::select('name_font', 'Toutes les polices', $fonts, $prefs['name_font'] ?? 'outfit');
        self::select('name_treatment', 'Espacement du nom', (array) $options['treatments'], $prefs['name_treatment'] ?? 'strong');
        self::end();
        self::start('save_atomic_design', 'Présentation des liens');
        self::select('links_mode', 'Disposition', ['neutral' => 'Liste', 'image-grid' => 'Grille d’images'], $prefs['links_mode'] ?? 'neutral');
        self::select('link_width', 'Largeur', ['wide' => 'Large', 'compact' => 'Compacte'], $prefs['link_width'] ?? 'wide');
        self::end();
        self::start('save_atomic_design', 'Tous les styles des réseaux');
        self::select('social_style', 'Style', ['brand-light' => 'Marques claires', 'brand-dark' => 'Marques sombres', 'outline-dark' => 'Contours noirs', 'outline-light' => 'Contours blancs', 'mono-dark' => 'Monochrome sombre', 'mono-light' => 'Monochrome clair', 'tint-pink' => 'Teinte rose', 'tint-mint' => 'Teinte menthe', 'solid-custom' => 'Couleur unie'], $prefs['social_style'] ?? 'brand-light');
        self::end();
        self::start('save_appearance', 'Thème du catalogue');
        $themes = [];
        foreach ((array) $options['themes'] as $theme) {
            if (empty($theme['locked'])) { $themes[$theme['slug']] = $theme['name'] ?? $theme['label'] ?? $theme['slug']; }
        }
        self::select('selected_theme', 'Thème', $themes, $prefs['theme_reference'] ?? $prefs['selected_theme'] ?? 'faluss-default');
        echo '<p>Appliquer un thème réinitialise les couleurs et styles personnalisés du thème.</p>';
        self::end('Appliquer le thème');
        self::start('save_appearance', 'Transition de couverture');
        self::input('hero_transition_color', 'Couleur', $prefs['hero_transition_color'] ?? '#FFFDF5', 7, 'color');
        self::input('hero_transition_intensity', 'Intensité · 0 à 100', $prefs['hero_transition_intensity'] ?? 100, 3, 'number');
        self::input('hero_transition_position', 'Position · 35 à 100', $prefs['hero_transition_position'] ?? 70, 3, 'number');
        self::end();
        self::start('save_appearance', 'Retirer la couverture');
        self::input('cover_attachment_id', '', 0, 1, 'hidden');
        self::end('Retirer l’image de fond');
        self::start('save_profile', 'Retirer la photo de profil');
        self::input('avatar_attachment_id', '', 0, 1, 'hidden');
        self::end('Retirer la photo');
    }

    private static function start(string $mutation, string $label, string $id = ''): void
    {
        echo '<details class="faluss-studio-v3__editor" data-v3-editor data-mutation="' . esc_attr($mutation) . '" data-id="' . esc_attr($id) . '"><summary>' . esc_html($label) . '</summary>';
    }

    private static function end(string $label = 'Enregistrer', string $remove = '', string $removeLabel = ''): void
    {
        echo '<button type="button" class="faluss-onboarding-v3__secondary" data-v3-save-item>' . esc_html($label) . '</button>';
        if ($remove !== '') { echo '<button type="button" class="faluss-onboarding-v3__secondary" data-v3-delete-item="' . esc_attr($remove) . '">' . esc_html($removeLabel) . '</button>'; }
        echo '</details>';
    }

    private static function input(string $key, string $label, mixed $value, int $max, string $type = 'text'): void
    {
        $value = (string) $value;
        if ($type === 'hidden') { echo '<input type="hidden" data-field="' . esc_attr($key) . '" value="' . esc_attr($value) . '">'; return; }
        echo '<label>' . esc_html($label);
        if ($type === 'textarea') { echo '<textarea data-field="' . esc_attr($key) . '" maxlength="' . $max . '">' . esc_textarea($value) . '</textarea>'; }
        else { echo '<input type="' . esc_attr($type) . '" data-field="' . esc_attr($key) . '" maxlength="' . $max . '" value="' . esc_attr($value) . '">'; }
        echo '</label>';
    }

    /** @param array<mixed> $choices */
    private static function select(string $key, string $label, array $choices, mixed $value): void
    {
        echo '<label>' . esc_html($label) . '<select data-field="' . esc_attr($key) . '">';
        foreach ($choices as $choice => $text) { echo '<option value="' . esc_attr((string) $choice) . '" ' . selected((string) $value, (string) $choice, false) . '>' . esc_html((string) $text) . '</option>'; }
        echo '</select></label>';
    }

    /** @param array<string, mixed> $block */
    private static function media(array $block): void
    {
        $id = (int) ($block['attachment_id'] ?? 0);
        if ($id) { echo wp_get_attachment_image($id, 'thumbnail', false, ['alt' => 'Image enregistrée']); }
        self::input('attachment_id', '', $id, 12, 'hidden');
        echo '<label>Image<input type="file" accept="image/jpeg,image/png,image/gif,image/webp" data-v3-content-upload></label><button type="button" data-v3-remove-image>Retirer l’image</button>';
    }
}
