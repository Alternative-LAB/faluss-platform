<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Faluss_Link_Card_Widget extends \Elementor\Widget_Base {
    public function get_name() { return 'faluss_link_card'; }
    public function get_title() { return 'Carte Faluss'; }
    public function get_icon() { return 'eicon-person'; }
    public function get_categories() { return array( 'general' ); }
    public function get_style_depends() { return array( 'faluss-link-card', 'faluss-link-immersive' ); }
    public function get_script_depends() { return array( 'faluss-link-card', 'faluss-link-immersive' ); }
    protected function register_controls() {
        $this->start_controls_section( 'content', array( 'label' => 'Contenu' ) );
        $this->add_control( 'identifier', array( 'label' => 'Identifiant', 'type' => \Elementor\Controls_Manager::TEXT ) );
        $this->add_control( 'presentation', array( 'label' => 'Présentation', 'type' => \Elementor\Controls_Manager::SELECT, 'default' => 'immersive', 'options' => array( 'immersive' => 'Page immersive', 'compact' => 'Carte compacte' ) ) );
        $this->add_responsive_control( 'align', array( 'label' => 'Alignement', 'type' => \Elementor\Controls_Manager::CHOOSE, 'default' => '', 'options' => array( 'left' => array( 'title' => 'Gauche', 'icon' => 'eicon-text-align-left' ), 'center' => array( 'title' => 'Centre', 'icon' => 'eicon-text-align-center' ), 'right' => array( 'title' => 'Droite', 'icon' => 'eicon-text-align-right' ) ), 'selectors_dictionary' => array( 'left' => 'margin-left:0;margin-right:auto;', 'center' => 'margin-left:auto;margin-right:auto;', 'right' => 'margin-left:auto;margin-right:0;' ), 'selectors' => array( '{{WRAPPER}} .faluss-link-card' => '{{VALUE}}' ) ) );
        $this->end_controls_section();
        $this->start_controls_section( 'style', array( 'label' => 'Carte', 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
        $this->add_responsive_control( 'width', array( 'label' => 'Largeur', 'type' => \Elementor\Controls_Manager::SLIDER, 'range' => array( 'px' => array( 'min' => 240, 'max' => 900 ) ), 'selectors' => array( '{{WRAPPER}} .faluss-link-card' => 'max-width:{{SIZE}}{{UNIT}};' ) ) );
        $this->add_responsive_control( 'spacing', array( 'label' => 'Espacement', 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'selectors' => array( '{{WRAPPER}} .faluss-link-card__body' => 'padding:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
        $this->add_control( 'surface', array( 'label' => 'Surface', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .faluss-link-card' => '--fl-canvas:{{VALUE}};' ) ) );
        $this->add_control( 'page_background', array( 'label' => 'Fond de page', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .faluss-link-card--presentation-immersive' => '--fl-page-background:{{VALUE}} !important;' ) ) );
        $this->add_control( 'hero_transition_color', array( 'label' => 'Couleur de transition du hero', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .faluss-link-card--presentation-immersive' => '--fl-hero-transition-color:{{VALUE}} !important;' ) ) );
        $this->add_control( 'hero_transition_position', array( 'label' => 'Position de transition', 'type' => \Elementor\Controls_Manager::SLIDER, 'range' => array( '%' => array( 'min' => 35, 'max' => 100 ) ), 'selectors' => array( '{{WRAPPER}} .faluss-link-card--presentation-immersive' => '--fl-hero-transition-position:{{SIZE}}% !important;' ) ) );
        $this->add_control( 'hero_transition_intensity', array( 'label' => 'Intensité de transition', 'type' => \Elementor\Controls_Manager::SLIDER, 'range' => array( '%' => array( 'min' => 0, 'max' => 100 ) ), 'selectors' => array( '{{WRAPPER}} .faluss-link-card--presentation-immersive' => '--fl-hero-transition-intensity:{{SIZE}}% !important;' ) ) );
        $this->add_control( 'accent', array( 'label' => 'Accent', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .faluss-link-card' => '--fl-accent:{{VALUE}};' ) ) );
        $this->add_control( 'action', array( 'label' => 'Action', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .faluss-link-card' => '--fl-action:{{VALUE}};' ) ) );
        $this->add_control( 'action_text', array( 'label' => 'Texte action', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .faluss-link-card' => '--fl-action-text:{{VALUE}};' ) ) );
        $this->add_control( 'action_hover', array( 'label' => 'Action au survol', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .faluss-link-card__link:hover' => 'background:{{VALUE}};' ) ) );
        $this->add_control( 'hero_overlay_top', array( 'label' => 'Overlay haut du hero', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .faluss-link-card--presentation-immersive' => '--fl-hero-overlay-top:{{VALUE}};' ) ) );
        $this->add_control( 'hero_overlay_bottom', array( 'label' => 'Overlay bas du hero', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .faluss-link-card--presentation-immersive' => '--fl-hero-overlay-bottom:{{VALUE}};' ) ) );
        $this->add_control( 'primary_text', array( 'label' => 'Texte principal', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .faluss-link-card' => '--fl-ink:{{VALUE}};' ) ) );
        $this->add_control( 'name_color', array( 'label' => 'Couleur du nom', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .faluss-link-card__name' => '--fl-name-color:{{VALUE}} !important;' ) ) );
        $this->add_control( 'secondary_text', array( 'label' => 'Texte secondaire', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .faluss-link-card' => '--fl-muted:{{VALUE}};' ) ) );
        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name' => 'typography', 'selector' => '{{WRAPPER}} .faluss-link-card' ) );
        $this->add_group_control( \Elementor\Group_Control_Border::get_type(), array( 'name' => 'border', 'selector' => '{{WRAPPER}} .faluss-link-card' ) );
        $this->add_control( 'radius', array( 'label' => 'Arrondi', 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'selectors' => array( '{{WRAPPER}} .faluss-link-card' => 'border-radius:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
        $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), array( 'name' => 'shadow', 'selector' => '{{WRAPPER}} .faluss-link-card' ) );
        $this->end_controls_section();
    }
    protected function render() { $settings = $this->get_settings_for_display(); echo Faluss_Link::render_card( array( 'identifier' => $settings['identifier'], 'align' => $settings['align'], 'presentation' => $settings['presentation'] ) ); }
}
final class Faluss_Link_Appearance_Widget extends \Elementor\Widget_Base { public function get_name() { return 'faluss_link_appearance'; } public function get_title() { return 'Apparence de ma carte Faluss'; } public function get_icon() { return 'eicon-settings'; } public function get_categories() { return array( 'general' ); } public function get_style_depends() { return array( 'faluss-link-card', 'faluss-link-immersive', 'faluss-link-studio' ); } public function get_script_depends() { return array( 'faluss-link-card', 'faluss-link-editor' ); } protected function render() { echo Faluss_Link::studio_shortcode(); } }
final class Faluss_Link_Studio_Widget extends \Elementor\Widget_Base { public function get_name() { return 'faluss_link_studio'; } public function get_title() { return 'Studio Faluss'; } public function get_icon() { return 'eicon-dashboard'; } public function get_categories() { return array( 'general' ); } public function get_style_depends() { return array( 'faluss-link-card', 'faluss-link-immersive', 'faluss-link-studio' ); } public function get_script_depends() { return array( 'faluss-link-card', 'faluss-link-editor' ); } protected function render() { echo Faluss_Link::studio_shortcode(); } }

final class Faluss_Link_Discoveries_Widget extends \Elementor\Widget_Base {
    public function get_name() { return 'faluss_link_discoveries'; }
    public function get_title() { return 'Mes découvertes Faluss'; }
    public function get_icon() { return 'eicon-heart'; }
    public function get_categories() { return array( 'general' ); }
    public function get_style_depends() { return array( 'faluss-link-discoveries' ); }
    protected function register_controls() {
        $this->start_controls_section( 'content', array( 'label' => 'Bibliothèque' ) );
        $this->add_control( 'title', array( 'label' => 'Titre', 'type' => \Elementor\Controls_Manager::TEXT, 'default' => 'Mes découvertes' ) );
        $this->add_control( 'empty_label', array( 'label' => 'État vide', 'type' => \Elementor\Controls_Manager::TEXT, 'default' => 'Aucune découverte pour le moment.' ) );
        $this->add_control( 'per_page', array( 'label' => 'Nombre maximal affiché par page', 'type' => \Elementor\Controls_Manager::NUMBER, 'default' => 24, 'min' => 1, 'max' => 250 ) );
        $this->add_control( 'layout', array( 'label' => 'Présentation', 'type' => \Elementor\Controls_Manager::SELECT, 'default' => 'list', 'options' => array( 'list' => 'Liste', 'grid' => 'Grille' ) ) );
        $this->end_controls_section();
        $this->start_controls_section( 'style', array( 'label' => 'Bibliothèque', 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
        $this->add_responsive_control( 'spacing', array( 'label' => 'Espacement', 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'selectors' => array( '{{WRAPPER}} .faluss-link-discoveries' => 'padding:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
        $this->add_control( 'surface', array( 'label' => 'Surface', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .faluss-link-discoveries' => '--fld-surface:{{VALUE}};' ) ) );
        $this->add_control( 'text_color', array( 'label' => 'Texte', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .faluss-link-discoveries' => '--fld-ink:{{VALUE}};' ) ) );
        $this->add_control( 'muted_color', array( 'label' => 'Texte secondaire', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .faluss-link-discoveries' => '--fld-muted:{{VALUE}};' ) ) );
        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name' => 'typography', 'selector' => '{{WRAPPER}} .faluss-link-discoveries' ) );
        $this->add_group_control( \Elementor\Group_Control_Border::get_type(), array( 'name' => 'border', 'selector' => '{{WRAPPER}} .faluss-link-discoveries__item' ) );
        $this->add_control( 'radius', array( 'label' => 'Arrondi', 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'selectors' => array( '{{WRAPPER}} .faluss-link-discoveries__item' => 'border-radius:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
        $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), array( 'name' => 'shadow', 'selector' => '{{WRAPPER}} .faluss-link-discoveries__item' ) );
        $this->end_controls_section();
    }
    protected function render() {
        $settings = $this->get_settings_for_display();
        echo Faluss_Link::render_discoveries( array( 'title' => $settings['title'] ?? '', 'empty_label' => $settings['empty_label'] ?? '', 'per_page' => $settings['per_page'] ?? 24, 'layout' => $settings['layout'] ?? 'list' ) );
    }
}

final class Faluss_Link_Daily_Reward_Widget extends \Elementor\Widget_Base {
    public function get_name() { return 'faluss_link_daily_reward'; }
    public function get_title() { return 'Récompense quotidienne Faluss'; }
    public function get_icon() { return 'eicon-gift'; }
    public function get_categories() { return array( 'general' ); }
    public function get_style_depends() { return array( 'faluss-link-reward' ); }
    public function get_script_depends() { return array( 'faluss-link-reward' ); }
    protected function register_controls() {
        $this->start_controls_section( 'content', array( 'label' => 'Récompense' ) );
        $this->add_control( 'presentation', array( 'label' => 'Présentation', 'type' => \Elementor\Controls_Manager::SELECT, 'default' => 'immersive', 'options' => array( 'immersive' => 'Page immersive', 'compact' => 'Carte compacte' ) ) );
        $this->add_responsive_control( 'align', array( 'label' => 'Alignement', 'type' => \Elementor\Controls_Manager::CHOOSE, 'default' => 'left', 'options' => array( 'left' => array( 'title' => 'Gauche', 'icon' => 'eicon-text-align-left' ), 'center' => array( 'title' => 'Centre', 'icon' => 'eicon-text-align-center' ), 'right' => array( 'title' => 'Droite', 'icon' => 'eicon-text-align-right' ) ) ) );
        $this->add_control( 'show_balance', array( 'label' => 'Afficher le solde', 'type' => \Elementor\Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => '' ) );
        $this->add_control( 'hide_unavailable', array( 'label' => 'Masquer si indisponible', 'type' => \Elementor\Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => '' ) );
        $this->add_control( 'login_label', array( 'label' => 'Libellé non connecté', 'type' => \Elementor\Controls_Manager::TEXT, 'default' => 'Réclamer mes %1$s %2$s', 'description' => 'Utilisez %1$s pour le montant et %2$s pour le code de l’unité.' ) );
        $this->add_control( 'login_microcopy', array( 'label' => 'Microcopie non connecté', 'type' => \Elementor\Controls_Manager::TEXT, 'default' => 'et débloquer le teaser gratuitement', 'description' => 'Cette microcopie accompagne la connexion ; elle ne transforme pas les tokens en droit d’accès.' ) );
        $this->add_control( 'claim_label', array( 'label' => 'Libellé éligible', 'type' => \Elementor\Controls_Manager::TEXT, 'default' => 'Réclamer %1$s %2$s', 'description' => 'Utilisez %1$s pour le montant et %2$s pour le code de l’unité.' ) );
        $this->add_control( 'claimed_label', array( 'label' => 'Libellé déjà réclamé', 'type' => \Elementor\Controls_Manager::TEXT, 'default' => 'Récompense quotidienne déjà réclamée.' ) );
        $this->add_control( 'unavailable_label', array( 'label' => 'Libellé indisponible', 'type' => \Elementor\Controls_Manager::TEXT, 'default' => 'Récompense quotidienne indisponible.' ) );
        $this->end_controls_section();

        $this->start_controls_section( 'style', array( 'label' => 'Bloc', 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
        $this->add_control( 'background', array( 'label' => 'Fond', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .faluss-link-reward' => '--fl-reward-surface:{{VALUE}};' ) ) );
        $this->add_control( 'text_color', array( 'label' => 'Texte', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .faluss-link-reward' => '--fl-reward-ink:{{VALUE}};' ) ) );
        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name' => 'typography', 'selector' => '{{WRAPPER}} .faluss-link-reward' ) );
        $this->add_group_control( \Elementor\Group_Control_Border::get_type(), array( 'name' => 'border', 'selector' => '{{WRAPPER}} .faluss-link-reward' ) );
        $this->add_control( 'radius', array( 'label' => 'Arrondi', 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'selectors' => array( '{{WRAPPER}} .faluss-link-reward' => 'border-radius:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
        $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), array( 'name' => 'shadow', 'selector' => '{{WRAPPER}} .faluss-link-reward' ) );
        $this->add_control( 'button_heading', array( 'label' => 'Bouton', 'type' => \Elementor\Controls_Manager::HEADING, 'separator' => 'before' ) );
        $this->start_controls_tabs( 'button_states' );
        $this->start_controls_tab( 'button_normal', array( 'label' => 'Normal' ) );
        $this->add_control( 'button_background', array( 'label' => 'Fond', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .faluss-link-reward__button' => '--fl-reward-action:{{VALUE}};' ) ) );
        $this->add_control( 'button_text', array( 'label' => 'Texte', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .faluss-link-reward__button' => '--fl-reward-action-text:{{VALUE}};' ) ) );
        $this->end_controls_tab();
        $this->start_controls_tab( 'button_hover', array( 'label' => 'Survol' ) );
        $this->add_control( 'button_hover_background', array( 'label' => 'Fond', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .faluss-link-reward__button:hover, {{WRAPPER}} .faluss-link-reward__button:focus-visible' => '--fl-reward-action-hover:{{VALUE}};' ) ) );
        $this->add_control( 'button_active_background', array( 'label' => 'Actif', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .faluss-link-reward__button:active' => '--fl-reward-action-active:{{VALUE}};' ) ) );
        $this->end_controls_tab();
        $this->end_controls_tabs();
        $this->end_controls_section();
    }
    protected function render() {
        $settings = $this->get_settings_for_display();
        echo Faluss_Link::render_daily_reward( array(
            'presentation' => $settings['presentation'] ?? 'immersive',
            'align' => $settings['align'] ?? 'left',
            'show_balance' => $settings['show_balance'] ?? '',
            'hide_unavailable' => $settings['hide_unavailable'] ?? '',
            'login_label' => $settings['login_label'] ?? '',
            'login_microcopy' => $settings['login_microcopy'] ?? '',
            'claim_label' => $settings['claim_label'] ?? '',
            'claimed_label' => $settings['claimed_label'] ?? '',
            'unavailable_label' => $settings['unavailable_label'] ?? '',
        ) );
    }
}
