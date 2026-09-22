<?php

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'Elementor\\Widget_Base' ) ) {
    exit;
}

final class Faluss_Identity_Onboarding_Elementor_Widget extends \Elementor\Widget_Base {

    public function get_name() { return 'faluss_identity_onboarding'; }
    public function get_title() { return __( 'Onboarding Faluss', 'faluss-identity' ); }
    public function get_icon() { return 'eicon-person'; }
    public function get_categories() { return array( 'general' ); }
    public function get_style_depends() { return array( Faluss_Identity_Onboarding::STYLE_HANDLE ); }
    public function get_script_depends() { return array( Faluss_Identity_Onboarding::SCRIPT_HANDLE ); }

    protected function register_controls() {
        $this->start_controls_section( 'content', array( 'label' => __( 'Contenu', 'faluss-identity' ) ) );
        $this->add_control( 'heading', array( 'label' => __( 'Titre', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => __( 'Créez votre espace Faluss', 'faluss-identity' ) ) );
        $this->add_control( 'intro', array( 'label' => __( 'Introduction', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::TEXTAREA, 'default' => __( 'Choisissez ce que vous souhaitez faire après votre connexion.', 'faluss-identity' ) ) );
        $this->add_control( 'create_label', array( 'label' => __( 'Libellé création', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => __( 'Créer mon Faluss', 'faluss-identity' ) ) );
        $this->add_control( 'continue_label', array( 'label' => __( 'Libellé sans carte', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => __( 'Continuer sans carte', 'faluss-identity' ) ) );
        $this->end_controls_section();

        $this->start_controls_section( 'card_style', array( 'label' => __( 'Carte', 'faluss-identity' ), 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
        $this->add_control( 'canvas_color', array( 'label' => __( 'Fond', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => '#FFFDF5', 'selectors' => array( '{{WRAPPER}} .faluss-identity-onboarding' => '--faluss-onboarding-canvas: {{VALUE}};' ) ) );
        $this->add_control( 'surface_color', array( 'label' => __( 'Surface', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => '#FFFFFF', 'selectors' => array( '{{WRAPPER}} .faluss-identity-onboarding' => '--faluss-onboarding-surface: {{VALUE}};' ) ) );
        $this->add_control( 'text_color', array( 'label' => __( 'Texte', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => '#080808', 'selectors' => array( '{{WRAPPER}} .faluss-identity-onboarding' => '--faluss-onboarding-ink: {{VALUE}};' ) ) );
        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name' => 'typography', 'selector' => '{{WRAPPER}} .faluss-identity-onboarding' ) );
        $this->add_group_control( \Elementor\Group_Control_Border::get_type(), array( 'name' => 'border', 'selector' => '{{WRAPPER}} .faluss-identity-onboarding__card' ) );
        $this->add_responsive_control( 'radius', array( 'label' => __( 'Arrondi', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array( 'px', '%', 'em', 'rem' ), 'selectors' => array( '{{WRAPPER}} .faluss-identity-onboarding__card' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
        $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), array( 'name' => 'shadow', 'selector' => '{{WRAPPER}} .faluss-identity-onboarding__card' ) );
        $this->end_controls_section();

        $this->start_controls_section( 'button_style', array( 'label' => __( 'Boutons', 'faluss-identity' ), 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
        $this->start_controls_tabs( 'button_tabs' );
        $this->start_controls_tab( 'normal', array( 'label' => __( 'Normal', 'faluss-identity' ) ) );
        $this->add_control( 'button_background', array( 'label' => __( 'Fond', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .faluss-identity-onboarding' => '--faluss-onboarding-action: {{VALUE}};' ) ) );
        $this->add_control( 'button_text', array( 'label' => __( 'Texte', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .faluss-identity-onboarding__button:not(.faluss-identity-onboarding__button--secondary)' => 'color: {{VALUE}};' ) ) );
        $this->end_controls_tab();
        $this->start_controls_tab( 'hover', array( 'label' => __( 'Survol', 'faluss-identity' ) ) );
        $this->add_control( 'button_hover_background', array( 'label' => __( 'Fond', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .faluss-identity-onboarding' => '--faluss-onboarding-action-hover: {{VALUE}};' ) ) );
        $this->add_control( 'button_hover_text', array( 'label' => __( 'Texte', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .faluss-identity-onboarding__button:not(.faluss-identity-onboarding__button--secondary):hover, {{WRAPPER}} .faluss-identity-onboarding__button:not(.faluss-identity-onboarding__button--secondary):focus-visible' => 'color: {{VALUE}};' ) ) );
        $this->end_controls_tab();
        $this->end_controls_tabs();
        $this->end_controls_section();
    }

    protected function render() {
        echo Faluss_Identity_Onboarding::render( $this->get_settings_for_display() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safe component markup.
    }
}
