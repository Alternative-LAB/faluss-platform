<?php

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'Elementor\\Widget_Base' ) ) {
    exit;
}

abstract class Faluss_Identity_Public_Profile_Widget_Base extends \Elementor\Widget_Base {

    public function get_categories() {
        return array( 'general' );
    }

    public function get_style_depends() {
        return array( Faluss_Identity_Public_Profile::STYLE_HANDLE );
    }

    protected function add_card_style_controls( $selector ) {
        $this->add_control( 'card_background', array( 'label' => __( 'Fond de carte', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => '#FFFFFF', 'selectors' => array( $selector => 'background-color: {{VALUE}};' ) ) );
        $this->add_control( 'card_text', array( 'label' => __( 'Texte', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => '#080808', 'selectors' => array( $selector => 'color: {{VALUE}};' ) ) );
        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name' => 'card_typography', 'selector' => $selector ) );
        $this->add_group_control( \Elementor\Group_Control_Border::get_type(), array( 'name' => 'card_border', 'selector' => $selector ) );
        $this->add_responsive_control( 'card_radius', array( 'label' => __( 'Arrondi', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array( 'px', '%' ), 'default' => array( 'top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20, 'unit' => 'px', 'isLinked' => true ), 'selectors' => array( $selector => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
        $this->add_responsive_control( 'card_padding', array( 'label' => __( 'Espacement interne', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array( 'px', 'em', 'rem', '%' ), 'selectors' => array( $selector => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
        $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), array( 'name' => 'card_shadow', 'selector' => $selector ) );
    }

    protected function add_button_style_controls( $selector ) {
        $this->start_controls_tabs( 'button_tabs' );
        $this->start_controls_tab( 'button_normal', array( 'label' => __( 'Normal', 'faluss-identity' ) ) );
        $this->add_control( 'button_text', array( 'label' => __( 'Texte', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => '#FFFFFF', 'selectors' => array( $selector => 'color: {{VALUE}};' ) ) );
        $this->add_control( 'button_background', array( 'label' => __( 'Fond', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => '#FF3D16', 'selectors' => array( $selector => 'background-color: {{VALUE}};' ) ) );
        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name' => 'button_typography', 'selector' => $selector ) );
        $this->add_group_control( \Elementor\Group_Control_Border::get_type(), array( 'name' => 'button_border', 'selector' => $selector ) );
        $this->add_responsive_control( 'button_radius', array( 'label' => __( 'Arrondi pill', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array( 'px', '%' ), 'default' => array( 'top' => 999, 'right' => 999, 'bottom' => 999, 'left' => 999, 'unit' => 'px', 'isLinked' => true ), 'selectors' => array( $selector => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
        $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), array( 'name' => 'button_shadow', 'selector' => $selector ) );
        $this->end_controls_tab();
        $this->start_controls_tab( 'button_hover', array( 'label' => __( 'Survol', 'faluss-identity' ) ) );
        $this->add_control( 'button_hover_text', array( 'label' => __( 'Texte', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => '#FFFFFF', 'selectors' => array( $selector . ':hover, ' . $selector . ':focus' => 'color: {{VALUE}};' ) ) );
        $this->add_control( 'button_hover_background', array( 'label' => __( 'Fond', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => '#D93210', 'selectors' => array( $selector . ':hover, ' . $selector . ':focus' => 'background-color: {{VALUE}};' ) ) );
        $this->add_group_control( \Elementor\Group_Control_Border::get_type(), array( 'name' => 'button_hover_border', 'selector' => $selector . ':hover, ' . $selector . ':focus' ) );
        $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), array( 'name' => 'button_hover_shadow', 'selector' => $selector . ':hover, ' . $selector . ':focus' ) );
        $this->end_controls_tab();
        $this->end_controls_tabs();
    }
}

final class Faluss_Identity_Public_Profile_Editor_Widget extends Faluss_Identity_Public_Profile_Widget_Base {

    public function get_name() { return 'faluss_identity_profile_editor'; }
    public function get_title() { return __( 'Éditeur de profil Faluss', 'faluss-identity' ); }
    public function get_icon() { return 'eicon-user-circle-o'; }

    protected function register_controls() {
        $this->start_controls_section( 'content', array( 'label' => __( 'Contenu', 'faluss-identity' ) ) );
        $this->add_control( 'heading', array( 'label' => __( 'Titre', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => __( 'Mon profil Faluss', 'faluss-identity' ) ) );
        $this->add_control( 'intro', array( 'label' => __( 'Introduction', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::TEXTAREA, 'default' => __( 'Choisissez les informations visibles sur faluss.me.', 'faluss-identity' ) ) );
        $this->end_controls_section();
        $this->start_controls_section( 'card_style', array( 'label' => __( 'Carte', 'faluss-identity' ), 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
        $this->add_control( 'canvas_color', array( 'label' => __( 'Fond', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => '#FFFDF5', 'selectors' => array( '{{WRAPPER}} .faluss-identity-profile-editor' => 'background-color: {{VALUE}};' ) ) );
        $this->add_card_style_controls( '{{WRAPPER}} .faluss-identity-profile-editor__card' );
        $this->end_controls_section();
        $this->start_controls_section( 'button_style', array( 'label' => __( 'Bouton', 'faluss-identity' ), 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
        $this->add_button_style_controls( '{{WRAPPER}} .faluss-identity-profile-editor button[type="submit"]' );
        $this->end_controls_section();
    }

    protected function render() {
        echo Faluss_Identity_Public_Profile::render_editor( $this->get_settings_for_display() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safe server-rendered markup.
    }
}

final class Faluss_Identity_Public_Profile_Widget extends Faluss_Identity_Public_Profile_Widget_Base {

    public function get_name() { return 'faluss_identity_public_profile'; }
    public function get_title() { return __( 'Profil public Faluss', 'faluss-identity' ); }
    public function get_icon() { return 'eicon-person'; }

    protected function register_controls() {
        $this->start_controls_section( 'content', array( 'label' => __( 'Profil', 'faluss-identity' ) ) );
        $this->add_control( 'identifier', array( 'label' => __( 'Identifiant public', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::TEXT, 'description' => __( 'Laissez vide pour utiliser le profil de la route publique actuelle.', 'faluss-identity' ) ) );
        $this->end_controls_section();
        $this->start_controls_section( 'card_style', array( 'label' => __( 'Carte', 'faluss-identity' ), 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
        $this->add_control( 'canvas_color', array( 'label' => __( 'Fond', 'faluss-identity' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => '#FFFDF5', 'selectors' => array( '{{WRAPPER}} .faluss-identity-public-profile' => 'background-color: {{VALUE}};' ) ) );
        $this->add_card_style_controls( '{{WRAPPER}} .faluss-identity-public-profile__card' );
        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        echo Faluss_Identity_Public_Profile::render_public_profile( isset( $settings['identifier'] ) ? $settings['identifier'] : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safe server-rendered markup.
    }
}
