<?php

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'Elementor\\Widget_Base' ) ) {
    exit;
}

final class Faluss_Identity_Elementor_Widget extends \Elementor\Widget_Base {

    public function get_name() {
        return 'faluss_identity_passwordless';
    }

    public function get_title() {
        return __( 'Connexion Faluss', 'faluss-identity' );
    }

    public function get_icon() {
        return 'eicon-lock-user';
    }

    public function get_categories() {
        return array( 'general' );
    }

    public function get_style_depends() {
        return array( Faluss_Identity_Passwordless::STYLE_HANDLE );
    }

    public function get_script_depends() {
        return array( Faluss_Identity_Passwordless::SCRIPT_HANDLE );
    }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', array( 'label' => __( 'Contenu', 'faluss-identity' ) ) );
        $this->add_control( 'heading', array( 'label' => __( 'Titre', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => __( 'Bienvenue sur Faluss', 'faluss-identity' ) ) );
        $this->add_control( 'intro', array( 'label' => __( 'Introduction', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::TEXTAREA, 'default' => __( 'Entrez votre adresse e-mail pour recevoir un code de connexion.', 'faluss-identity' ) ) );
        $this->add_control( 'redirect_url', array( 'label' => __( 'URL de redirection', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::URL, 'default' => array( 'url' => home_url( '/mon-faluss/' ) ), 'description' => __( 'Seules les URL locales à faluss.me sont acceptées.', 'faluss-identity' ) ) );
        $this->end_controls_section();

        $this->start_controls_section( 'style_card_section', array( 'label' => __( 'Carte', 'faluss-identity' ), 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
        $this->add_control( 'page_color', array( 'label' => __( 'Fond de page', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => '#FFFDF5', 'selectors' => array( '{{WRAPPER}} .faluss-identity-login' => 'background-color: {{VALUE}};' ) ) );
        $this->add_control( 'card_color', array( 'label' => __( 'Fond de carte', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => '#FFFFFF', 'selectors' => array( '{{WRAPPER}} .faluss-identity-login__content' => 'background-color: {{VALUE}};' ) ) );
        $this->add_control( 'text_color', array( 'label' => __( 'Texte', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => '#000000', 'selectors' => array( '{{WRAPPER}} .faluss-identity-login__content' => 'color: {{VALUE}};' ) ) );
        $this->add_group_control( \Elementor\Group_Control_Border::get_type(), array( 'name' => 'card_border', 'selector' => '{{WRAPPER}} .faluss-identity-login__content' ) );
        $this->add_responsive_control( 'radius', array( 'label' => __( 'Arrondi', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array( 'px', '%' ), 'default' => array( 'top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20, 'unit' => 'px', 'isLinked' => true ), 'selectors' => array( '{{WRAPPER}} .faluss-identity-login__content' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
        $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), array( 'name' => 'card_shadow', 'selector' => '{{WRAPPER}} .faluss-identity-login__content' ) );
        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name' => 'card_typography', 'selector' => '{{WRAPPER}} .faluss-identity-login__content' ) );
        $this->end_controls_section();

        $this->start_controls_section( 'style_field_section', array( 'label' => __( 'Champs', 'faluss-identity' ), 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
        $this->add_control( 'field_background', array( 'label' => __( 'Fond', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => '#FFFDF5', 'selectors' => array( '{{WRAPPER}} .faluss-identity-login__form input' => 'background-color: {{VALUE}};' ) ) );
        $this->add_control( 'field_text', array( 'label' => __( 'Texte', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => '#000000', 'selectors' => array( '{{WRAPPER}} .faluss-identity-login__form input' => 'color: {{VALUE}};' ) ) );
        $this->add_group_control( \Elementor\Group_Control_Border::get_type(), array( 'name' => 'field_border', 'selector' => '{{WRAPPER}} .faluss-identity-login__form input' ) );
        $this->add_responsive_control( 'field_radius', array( 'label' => __( 'Arrondi', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array( 'px', '%' ), 'selectors' => array( '{{WRAPPER}} .faluss-identity-login__form input' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name' => 'field_typography', 'selector' => '{{WRAPPER}} .faluss-identity-login__form input' ) );
        $this->end_controls_section();

        $this->start_controls_section( 'style_button_section', array( 'label' => __( 'Boutons', 'faluss-identity' ), 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
        $this->start_controls_tabs( 'button_tabs' );
        $this->start_controls_tab( 'button_normal', array( 'label' => __( 'Normal', 'faluss-identity' ) ) );
        $this->add_control( 'button_text', array( 'label' => __( 'Texte', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => '#FFFFFF', 'selectors' => array( '{{WRAPPER}} .faluss-identity-login__form button, {{WRAPPER}} .faluss-identity-login__link' => 'color: {{VALUE}};' ) ) );
        $this->add_control( 'accent_color', array( 'label' => __( 'Fond', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => '#080808', 'selectors' => array( '{{WRAPPER}} .faluss-identity-login' => '--faluss-identity-action: {{VALUE}};' ) ) );
        $this->add_group_control( \Elementor\Group_Control_Border::get_type(), array( 'name' => 'button_border', 'selector' => '{{WRAPPER}} .faluss-identity-login__form button, {{WRAPPER}} .faluss-identity-login__link' ) );
        $this->add_responsive_control( 'button_radius', array( 'label' => __( 'Arrondi pill', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array( 'px', '%' ), 'default' => array( 'top' => 999, 'right' => 999, 'bottom' => 999, 'left' => 999, 'unit' => 'px', 'isLinked' => true ), 'selectors' => array( '{{WRAPPER}} .faluss-identity-login__form button, {{WRAPPER}} .faluss-identity-login__link' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
        $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), array( 'name' => 'button_shadow', 'selector' => '{{WRAPPER}} .faluss-identity-login__form button, {{WRAPPER}} .faluss-identity-login__link' ) );
        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name' => 'button_typography', 'selector' => '{{WRAPPER}} .faluss-identity-login__form button, {{WRAPPER}} .faluss-identity-login__link' ) );
        $this->end_controls_tab();
        $this->start_controls_tab( 'button_hover', array( 'label' => __( 'Survol', 'faluss-identity' ) ) );
        $this->add_control( 'button_hover_text', array( 'label' => __( 'Texte', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => '#FFFFFF', 'selectors' => array( '{{WRAPPER}} .faluss-identity-login__form button:hover, {{WRAPPER}} .faluss-identity-login__form button:focus, {{WRAPPER}} .faluss-identity-login__link:hover, {{WRAPPER}} .faluss-identity-login__link:focus' => 'color: {{VALUE}};' ) ) );
        $this->add_control( 'button_hover_background', array( 'label' => __( 'Fond', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => '#D93210', 'selectors' => array( '{{WRAPPER}} .faluss-identity-login__form button:hover, {{WRAPPER}} .faluss-identity-login__form button:focus, {{WRAPPER}} .faluss-identity-login__link:hover, {{WRAPPER}} .faluss-identity-login__link:focus' => 'background-color: {{VALUE}};' ) ) );
        $this->add_group_control( \Elementor\Group_Control_Border::get_type(), array( 'name' => 'button_hover_border', 'selector' => '{{WRAPPER}} .faluss-identity-login__form button:hover, {{WRAPPER}} .faluss-identity-login__form button:focus, {{WRAPPER}} .faluss-identity-login__link:hover, {{WRAPPER}} .faluss-identity-login__link:focus' ) );
        $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), array( 'name' => 'button_hover_shadow', 'selector' => '{{WRAPPER}} .faluss-identity-login__form button:hover, {{WRAPPER}} .faluss-identity-login__form button:focus, {{WRAPPER}} .faluss-identity-login__link:hover, {{WRAPPER}} .faluss-identity-login__link:focus' ) );
        $this->end_controls_tab();
        $this->end_controls_tabs();
        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        $settings['radius'] = isset( $settings['radius']['top'] ) ? $settings['radius']['top'] : 20;
        $settings['redirect_url'] = isset( $settings['redirect_url']['url'] ) ? $settings['redirect_url']['url'] : home_url( '/mon-faluss/' );
        echo Faluss_Identity_Passwordless::render_form( $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safe, server-rendered widget markup.
    }
}
