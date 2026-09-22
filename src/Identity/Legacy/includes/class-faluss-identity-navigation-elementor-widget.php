<?php

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'Elementor\\Widget_Base' ) ) {
    exit;
}

/**
 * A manually placed Header widget. Its panel is portaled to a native dialog so
 * it cannot be trapped by an Elementor header, a theme wrapper, or a profile card.
 */
final class Faluss_Identity_Navigation_Elementor_Widget extends \Elementor\Widget_Base {

    public function get_name() {
        return 'faluss_identity_navigation';
    }

    public function get_title() {
        return __( 'Navigation Faluss', 'faluss-identity' );
    }

    public function get_icon() {
        return 'eicon-menu-bar';
    }

    public function get_categories() {
        return array( 'general' );
    }

    public function get_style_depends() {
        return array( Faluss_Identity_Navigation::STYLE_HANDLE );
    }

    public function get_script_depends() {
        return array( Faluss_Identity_Navigation::SCRIPT_HANDLE );
    }

    protected function register_controls() {
        $this->start_controls_section( 'content_navigation', array( 'label' => __( 'Contenu', 'faluss-identity' ) ) );
        $this->add_control( 'trigger_icon', array(
            'label'   => __( 'Icône du déclencheur', 'faluss-identity' ),
            'type'    => \Elementor\Controls_Manager::ICONS,
            'default' => array( 'value' => 'eicon-menu-bar', 'library' => 'eicons' ),
        ) );
        $this->add_control( 'trigger_label', array( 'label' => __( 'Libellé accessible du déclencheur', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => __( 'Ouvrir la navigation', 'faluss-identity' ) ) );
        $this->add_control( 'my_faluss_label', array( 'label' => __( 'Libellé Mon Faluss', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => __( 'Mon Faluss', 'faluss-identity' ) ) );
        $this->add_control( 'my_list_label', array( 'label' => __( 'Libellé Ma liste', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => __( 'Ma liste', 'faluss-identity' ) ) );
        $this->add_control( 'login_label', array( 'label' => __( 'Libellé Connexion', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => __( 'Connexion', 'faluss-identity' ) ) );
        $this->add_control( 'logout_label', array( 'label' => __( 'Libellé Déconnexion', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => __( 'Déconnexion', 'faluss-identity' ) ) );
        $this->add_responsive_control( 'sidebar_width', array(
            'label'      => __( 'Largeur du panneau', 'faluss-identity' ),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => array( 'vw', 'px', '%' ),
            'range'      => array( 'vw' => array( 'min' => 40, 'max' => 100 ), 'px' => array( 'min' => 260, 'max' => 720 ), '%' => array( 'min' => 40, 'max' => 100 ) ),
            'default'    => array( 'size' => 65, 'unit' => 'vw' ),
            'selectors'  => array( '{{WRAPPER}} .faluss-identity-navigation' => '--faluss-navigation-sidebar-width: {{SIZE}}{{UNIT}};' ),
        ) );
        $this->add_control( 'open_animation', array( 'label' => __( 'Animation d’ouverture', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::SELECT, 'default' => 'slide', 'options' => array( 'slide' => __( 'Glissement', 'faluss-identity' ), 'fade' => __( 'Fondu', 'faluss-identity' ), 'none' => __( 'Aucune', 'faluss-identity' ) ) ) );
        $this->add_control( 'animation_duration', array( 'label' => __( 'Durée de transition', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::SLIDER, 'size_units' => array( 'ms' ), 'default' => array( 'size' => 320, 'unit' => 'ms' ), 'range' => array( 'ms' => array( 'min' => 0, 'max' => 1200 ) ), 'selectors' => array( '{{WRAPPER}} .faluss-identity-navigation' => '--faluss-navigation-duration: {{SIZE}}ms;' ) ) );
        $this->end_controls_section();

        $this->register_trigger_style_controls();
        $this->register_sidebar_style_controls();
        $this->register_backdrop_style_controls();
        $this->register_link_style_controls( 'nav', __( 'Liens de navigation', 'faluss-identity' ), '.faluss-identity-navigation__style-source--nav' );
        $this->register_link_style_controls( 'smart', __( 'Action intelligente', 'faluss-identity' ), '.faluss-identity-navigation__style-source--smart' );
    }

    private function register_trigger_style_controls() {
        $selector = '{{WRAPPER}} .faluss-identity-navigation__trigger';
        $this->start_controls_section( 'style_trigger', array( 'label' => __( 'Bulle du déclencheur', 'faluss-identity' ), 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
        $this->add_responsive_control( 'trigger_alignment', array( 'label' => __( 'Alignement', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::CHOOSE, 'options' => array( 'flex-start' => array( 'title' => __( 'Gauche', 'faluss-identity' ), 'icon' => 'eicon-h-align-left' ), 'center' => array( 'title' => __( 'Centre', 'faluss-identity' ), 'icon' => 'eicon-h-align-center' ), 'flex-end' => array( 'title' => __( 'Droite', 'faluss-identity' ), 'icon' => 'eicon-h-align-right' ) ), 'default' => 'flex-end', 'selectors' => array( '{{WRAPPER}} .faluss-identity-navigation' => '--faluss-navigation-trigger-align: {{VALUE}};' ) ) );
        $this->add_responsive_control( 'trigger_width', array( 'label' => __( 'Largeur', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::SLIDER, 'size_units' => array( 'px', 'rem' ), 'default' => array( 'size' => 46, 'unit' => 'px' ), 'range' => array( 'px' => array( 'min' => 20, 'max' => 200 ), 'rem' => array( 'min' => 1, 'max' => 12 ) ), 'selectors' => array( $selector => 'inline-size: {{SIZE}}{{UNIT}};' ) ) );
        $this->add_responsive_control( 'trigger_height', array( 'label' => __( 'Hauteur', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::SLIDER, 'size_units' => array( 'px', 'rem' ), 'default' => array( 'size' => 46, 'unit' => 'px' ), 'range' => array( 'px' => array( 'min' => 20, 'max' => 200 ), 'rem' => array( 'min' => 1, 'max' => 12 ) ), 'selectors' => array( $selector => 'block-size: {{SIZE}}{{UNIT}};' ) ) );
        $this->add_responsive_control( 'trigger_icon_size', array( 'label' => __( 'Taille de l’icône', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::SLIDER, 'size_units' => array( 'px', 'em', 'rem' ), 'default' => array( 'size' => 18, 'unit' => 'px' ), 'range' => array( 'px' => array( 'min' => 8, 'max' => 96 ), 'em' => array( 'min' => .5, 'max' => 6 ), 'rem' => array( 'min' => .5, 'max' => 6 ) ), 'selectors' => array( '{{WRAPPER}} .faluss-identity-navigation' => '--faluss-navigation-trigger-icon-size: {{SIZE}}{{UNIT}};' ) ) );
        $this->add_responsive_control( 'trigger_padding', array( 'label' => __( 'Espacement interne', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array( 'px', 'em', 'rem', '%' ), 'selectors' => array( $selector => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
        $this->add_responsive_control( 'trigger_margin', array( 'label' => __( 'Marge', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array( 'px', 'em', 'rem', '%' ), 'selectors' => array( $selector => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
        $this->start_controls_tabs( 'trigger_state_tabs' );
        foreach ( array( 'normal' => __( 'Normal', 'faluss-identity' ), 'hover' => __( 'Survol', 'faluss-identity' ), 'active' => __( 'Actif', 'faluss-identity' ) ) as $state => $label ) {
            $this->start_controls_tab( 'trigger_' . $state, array( 'label' => $label ) );
            $suffix = 'normal' === $state ? '' : ':' . ( 'hover' === $state ? 'hover, ' . $selector . ':focus-visible' : 'active' );
            $state_selector = 'normal' === $state ? $selector : $selector . $suffix;
            $this->add_control( 'trigger_' . $state . '_icon', array( 'label' => __( 'Couleur de l’icône', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => 'normal' === $state ? '#FFFFFF' : '', 'selectors' => array( '{{WRAPPER}} .faluss-identity-navigation' => '--faluss-navigation-trigger-icon-' . $state . ': {{VALUE}};' ) ) );
            $this->add_control( 'trigger_' . $state . '_background', array( 'label' => __( 'Fond', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => 'normal' === $state ? '#080808' : '', 'selectors' => array( '{{WRAPPER}} .faluss-identity-navigation' => '--faluss-navigation-trigger-background-' . $state . ': {{VALUE}};' ) ) );
            $this->add_control( 'trigger_' . $state . '_background_opacity', array( 'label' => __( 'Opacité du fond', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::SLIDER, 'default' => array( 'size' => 100 ), 'range' => array( 'px' => array( 'min' => 0, 'max' => 100 ) ), 'selectors' => array( '{{WRAPPER}} .faluss-identity-navigation' => '--faluss-navigation-trigger-background-opacity-' . $state . ': {{SIZE}}%;' ) ) );
            $this->add_group_control( \Elementor\Group_Control_Border::get_type(), array( 'name' => 'trigger_' . $state . '_border', 'selector' => $state_selector ) );
            $this->add_responsive_control( 'trigger_' . $state . '_radius', array( 'label' => __( 'Arrondi', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array( 'px', '%' ), 'selectors' => array( $state_selector => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
            $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), array( 'name' => 'trigger_' . $state . '_shadow', 'selector' => $state_selector ) );
            $this->end_controls_tab();
        }
        $this->end_controls_tabs();
        $this->add_responsive_control( 'trigger_backdrop_blur', array( 'label' => __( 'Flou d’arrière-plan', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::SLIDER, 'size_units' => array( 'px' ), 'default' => array( 'size' => 0, 'unit' => 'px' ), 'range' => array( 'px' => array( 'min' => 0, 'max' => 30 ) ), 'selectors' => array( '{{WRAPPER}} .faluss-identity-navigation' => '--faluss-navigation-trigger-blur: {{SIZE}}px;' ) ) );
        $this->end_controls_section();
    }

    private function register_sidebar_style_controls() {
        $source = '{{WRAPPER}} .faluss-identity-navigation__style-source--sidebar';
        $this->start_controls_section( 'style_sidebar', array( 'label' => __( 'Panneau latéral', 'faluss-identity' ), 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
        $this->add_group_control( \Elementor\Group_Control_Background::get_type(), array( 'name' => 'sidebar_background', 'selector' => $source ) );
        $this->add_control( 'sidebar_background_opacity', array( 'label' => __( 'Opacité du fond', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::SLIDER, 'default' => array( 'size' => 100 ), 'range' => array( 'px' => array( 'min' => 0, 'max' => 100 ) ), 'selectors' => array( '{{WRAPPER}} .faluss-identity-navigation' => '--faluss-navigation-sidebar-background-opacity: {{SIZE}}%;' ) ) );
        $this->add_responsive_control( 'sidebar_padding', array( 'label' => __( 'Espacement interne', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array( 'px', 'em', 'rem', '%' ), 'selectors' => array( $source => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
        $this->add_responsive_control( 'sidebar_margin', array( 'label' => __( 'Marge', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array( 'px', 'em', 'rem', '%' ), 'selectors' => array( $source => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
        $this->add_group_control( \Elementor\Group_Control_Border::get_type(), array( 'name' => 'sidebar_border', 'selector' => $source ) );
        $this->add_responsive_control( 'sidebar_radius', array( 'label' => __( 'Arrondi', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array( 'px', '%' ), 'selectors' => array( $source => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
        $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), array( 'name' => 'sidebar_shadow', 'selector' => $source ) );
        $this->add_responsive_control( 'sidebar_alignment', array( 'label' => __( 'Alignement Flexbox', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::CHOOSE, 'options' => array( 'flex-start' => array( 'title' => __( 'Gauche', 'faluss-identity' ), 'icon' => 'eicon-h-align-left' ), 'center' => array( 'title' => __( 'Centre', 'faluss-identity' ), 'icon' => 'eicon-h-align-center' ), 'flex-end' => array( 'title' => __( 'Droite', 'faluss-identity' ), 'icon' => 'eicon-h-align-right' ) ), 'default' => 'flex-start', 'selectors' => array( '{{WRAPPER}} .faluss-identity-navigation' => '--faluss-navigation-sidebar-align: {{VALUE}};' ) ) );
        $this->add_responsive_control( 'sidebar_spacing', array( 'label' => __( 'Espacement entre les liens', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::SLIDER, 'size_units' => array( 'px', 'rem' ), 'default' => array( 'size' => 10, 'unit' => 'px' ), 'selectors' => array( '{{WRAPPER}} .faluss-identity-navigation' => '--faluss-navigation-sidebar-gap: {{SIZE}}{{UNIT}};' ) ) );
        $this->end_controls_section();
    }

    private function register_backdrop_style_controls() {
        $this->start_controls_section( 'style_backdrop', array( 'label' => __( 'Fond derrière le panneau', 'faluss-identity' ), 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
        $this->start_controls_tabs( 'backdrop_state_tabs' );
        foreach ( array( 'normal' => __( 'Normal', 'faluss-identity' ), 'hover' => __( 'Survol', 'faluss-identity' ) ) as $state => $label ) {
            $this->start_controls_tab( 'backdrop_' . $state, array( 'label' => $label ) );
            $this->add_control( 'backdrop_' . $state . '_color', array( 'label' => __( 'Couleur', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => 'normal' === $state ? '#080808' : '', 'selectors' => array( '{{WRAPPER}} .faluss-identity-navigation' => '--faluss-navigation-backdrop-' . $state . '-color: {{VALUE}};' ) ) );
            $this->add_control( 'backdrop_' . $state . '_opacity', array( 'label' => __( 'Opacité', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::SLIDER, 'default' => array( 'size' => 'normal' === $state ? 34 : 40 ), 'range' => array( 'px' => array( 'min' => 0, 'max' => 100 ) ), 'selectors' => array( '{{WRAPPER}} .faluss-identity-navigation' => '--faluss-navigation-backdrop-' . $state . '-opacity: {{SIZE}}%;' ) ) );
            $this->add_control( 'backdrop_' . $state . '_transition', array( 'label' => __( 'Transition', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::SLIDER, 'size_units' => array( 'ms' ), 'default' => array( 'size' => 160, 'unit' => 'ms' ), 'range' => array( 'ms' => array( 'min' => 0, 'max' => 1200 ) ), 'selectors' => array( '{{WRAPPER}} .faluss-identity-navigation' => '--faluss-navigation-backdrop-' . $state . '-transition: {{SIZE}}ms;' ) ) );
            $this->end_controls_tab();
        }
        $this->end_controls_tabs();
        $this->end_controls_section();
    }

    private function register_link_style_controls( $prefix, $label, $source ) {
        $this->start_controls_section( 'style_' . $prefix . '_links', array( 'label' => $label, 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
        $this->add_responsive_control( $prefix . '_link_alignment', array( 'label' => __( 'Alignement', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::CHOOSE, 'options' => array( 'flex-start' => array( 'title' => __( 'Gauche', 'faluss-identity' ), 'icon' => 'eicon-h-align-left' ), 'center' => array( 'title' => __( 'Centre', 'faluss-identity' ), 'icon' => 'eicon-h-align-center' ), 'flex-end' => array( 'title' => __( 'Droite', 'faluss-identity' ), 'icon' => 'eicon-h-align-right' ) ), 'selectors' => array( '{{WRAPPER}} .faluss-identity-navigation' => '--faluss-navigation-' . $prefix . '-link-align: {{VALUE}};' ) ) );
        $this->start_controls_tabs( $prefix . '_link_state_tabs' );
        foreach ( array( 'normal' => __( 'Normal', 'faluss-identity' ), 'hover' => __( 'Survol', 'faluss-identity' ), 'active' => __( 'Actif', 'faluss-identity' ) ) as $state => $state_label ) {
            $state_source = $source . '--' . $state;
            $this->start_controls_tab( $prefix . '_link_' . $state, array( 'label' => $state_label ) );
            $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name' => $prefix . '_link_' . $state . '_typography', 'selector' => $state_source ) );
            $this->add_control( $prefix . '_link_' . $state . '_text', array( 'label' => __( 'Texte', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => 'normal' === $state ? '#080808' : '', 'selectors' => array( $state_source => 'color: {{VALUE}};' ) ) );
            $this->add_group_control( \Elementor\Group_Control_Background::get_type(), array( 'name' => $prefix . '_link_' . $state . '_background', 'selector' => $state_source ) );
            $this->add_responsive_control( $prefix . '_link_' . $state . '_padding', array( 'label' => __( 'Espacement interne', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array( 'px', 'em', 'rem', '%' ), 'selectors' => array( $state_source => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
            $this->add_responsive_control( $prefix . '_link_' . $state . '_margin', array( 'label' => __( 'Marge', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array( 'px', 'em', 'rem', '%' ), 'selectors' => array( $state_source => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
            $this->add_group_control( \Elementor\Group_Control_Border::get_type(), array( 'name' => $prefix . '_link_' . $state . '_border', 'selector' => $state_source ) );
            $this->add_responsive_control( $prefix . '_link_' . $state . '_radius', array( 'label' => __( 'Arrondi', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array( 'px', '%' ), 'selectors' => array( $state_source => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
            $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), array( 'name' => $prefix . '_link_' . $state . '_shadow', 'selector' => $state_source ) );
            $this->end_controls_tab();
        }
        $this->end_controls_tabs();
        $this->end_controls_section();
    }

    protected function render() {
        $settings  = $this->get_settings_for_display();
        $unique_id = function_exists( 'wp_unique_id' ) ? wp_unique_id( 'faluss-identity-navigation-' ) : uniqid( 'faluss-identity-navigation-', false );
        $dialog_id = $unique_id . '-dialog';
        $title_id  = $unique_id . '-title';
        $labels    = array(
            'my_faluss' => isset( $settings['my_faluss_label'] ) ? $settings['my_faluss_label'] : '',
            'my_list'   => isset( $settings['my_list_label'] ) ? $settings['my_list_label'] : '',
            'login'     => isset( $settings['login_label'] ) ? $settings['login_label'] : '',
            'logout'    => isset( $settings['logout_label'] ) ? $settings['logout_label'] : '',
        );
        $actions = Faluss_Identity_Navigation::actions( $labels );
        $animation = isset( $settings['open_animation'] ) && in_array( $settings['open_animation'], array( 'slide', 'fade', 'none' ), true ) ? $settings['open_animation'] : 'slide';
        $label = isset( $settings['trigger_label'] ) && '' !== trim( (string) $settings['trigger_label'] ) ? $settings['trigger_label'] : __( 'Ouvrir la navigation', 'faluss-identity' );
        ?>
        <div class="faluss-identity-navigation" data-faluss-identity-navigation="<?php echo esc_attr( $unique_id ); ?>" data-faluss-navigation-animation="<?php echo esc_attr( $animation ); ?>">
            <button class="faluss-identity-navigation__trigger" type="button" aria-expanded="false" aria-controls="<?php echo esc_attr( $dialog_id ); ?>" aria-label="<?php echo esc_attr( $label ); ?>" data-faluss-navigation-trigger>
                <?php $this->render_trigger_icon( $settings ); ?>
            </button>
            <span class="faluss-identity-navigation__style-source faluss-identity-navigation__style-source--sidebar" aria-hidden="true"></span>
            <?php foreach ( array( 'nav', 'smart' ) as $kind ) : ?>
                <?php foreach ( array( 'normal', 'hover', 'active' ) as $state ) : ?>
                    <span class="faluss-identity-navigation__style-source faluss-identity-navigation__style-source--<?php echo esc_attr( $kind . '--' . $state ); ?>" aria-hidden="true"></span>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <template data-faluss-navigation-template>
                <dialog class="faluss-identity-navigation-portal" id="<?php echo esc_attr( $dialog_id ); ?>" aria-modal="true" aria-labelledby="<?php echo esc_attr( $title_id ); ?>" data-faluss-navigation-portal>
                    <div class="faluss-identity-navigation-portal__backdrop" role="button" tabindex="0" aria-label="<?php esc_attr_e( 'Fermer la navigation', 'faluss-identity' ); ?>" data-faluss-navigation-close></div>
                    <aside class="faluss-identity-navigation-portal__sidebar" aria-labelledby="<?php echo esc_attr( $title_id ); ?>" tabindex="-1" data-faluss-navigation-sidebar>
                        <h2 class="screen-reader-text" id="<?php echo esc_attr( $title_id ); ?>"><?php esc_html_e( 'Navigation Faluss', 'faluss-identity' ); ?></h2>
                        <nav class="faluss-identity-navigation-portal__links" aria-label="<?php esc_attr_e( 'Navigation Faluss', 'faluss-identity' ); ?>">
                            <?php foreach ( array_slice( $actions, 0, 2 ) as $action ) : ?>
                                <a class="faluss-identity-navigation-portal__link" href="<?php echo esc_url( $action['url'] ); ?>" data-faluss-navigation-link="<?php echo esc_attr( $action['key'] ); ?>"><?php echo esc_html( $action['label'] ); ?></a>
                            <?php endforeach; ?>
                        </nav>
                        <a class="faluss-identity-navigation-portal__link faluss-identity-navigation-portal__link--smart" href="<?php echo esc_url( $actions[2]['url'] ); ?>" data-faluss-navigation-link="smart"><?php echo esc_html( $actions[2]['label'] ); ?></a>
                    </aside>
                </dialog>
            </template>
        </div>
        <?php
    }

    /**
     * @param array $settings Widget settings.
     * @return void
     */
    private function render_trigger_icon( $settings ) {
        if ( class_exists( '\\Elementor\\Icons_Manager' ) && ! empty( $settings['trigger_icon']['value'] ) ) {
            \Elementor\Icons_Manager::render_icon( $settings['trigger_icon'], array( 'aria-hidden' => 'true', 'focusable' => 'false' ) );
            return;
        }

        echo '<span aria-hidden="true">&#9776;</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed fallback character.
    }
}
