<?php

declare(strict_types=1);

final class Faluss_Identity_Client_Elementor_Widget extends \Elementor\Widget_Base
{
    public function get_name(): string
    {
        return 'faluss_identity_client_button';
    }

    public function get_title(): string
    {
        return 'Continuer avec Faluss';
    }

    public function get_icon(): string
    {
        return 'eicon-lock-user';
    }

    /** @return list<string> */
    public function get_categories(): array
    {
        return ['general'];
    }

    protected function register_controls(): void
    {
        $this->start_controls_section('content', ['label' => 'Contenu']);
        $this->add_control('label', [
            'label' => 'Libellé',
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => 'Continuer avec Faluss',
        ]);
        $this->add_control('redirect_url', [
            'label' => 'Retour local',
            'type' => \Elementor\Controls_Manager::URL,
        ]);
        $this->end_controls_section();

        $this->start_controls_section('style', [
            'label' => 'Style',
            'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        ]);
        $this->start_controls_tabs('states');
        $this->start_controls_tab('normal', ['label' => 'Normal']);
        $this->add_control('text_color', [
            'label' => 'Texte',
            'type' => \Elementor\Controls_Manager::COLOR,
            'default' => '#080808',
            'selectors' => ['{{WRAPPER}} .faluss-identity-client-button__action' => 'color: {{VALUE}};'],
        ]);
        $this->add_control('background_color', [
            'label' => 'Fond',
            'type' => \Elementor\Controls_Manager::COLOR,
            'default' => '#FF3D16',
            'selectors' => [
                '{{WRAPPER}} .faluss-identity-client-button__action' => 'background-color: {{VALUE}}; border-color: {{VALUE}};',
            ],
        ]);
        $this->end_controls_tab();
        $this->start_controls_tab('hover', ['label' => 'Survol']);
        $this->add_control('hover_text_color', [
            'label' => 'Texte',
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .faluss-identity-client-button__action:hover' => 'color: {{VALUE}};'],
        ]);
        $this->add_control('hover_background', [
            'label' => 'Fond',
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .faluss-identity-client-button__action:hover' => 'background-color: {{VALUE}}; border-color: {{VALUE}};',
            ],
        ]);
        $this->end_controls_tab();
        $this->end_controls_tabs();
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
            'name' => 'typography',
            'selector' => '{{WRAPPER}} .faluss-identity-client-button__action',
        ]);
        $this->add_group_control(\Elementor\Group_Control_Border::get_type(), [
            'name' => 'border',
            'selector' => '{{WRAPPER}} .faluss-identity-client-button__action',
        ]);
        $this->add_responsive_control('padding', [
            'label' => 'Padding',
            'type' => \Elementor\Controls_Manager::DIMENSIONS,
            'selectors' => [
                '{{WRAPPER}} .faluss-identity-client-button__action' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);
        $this->add_control('radius', [
            'label' => 'Arrondi',
            'type' => \Elementor\Controls_Manager::DIMENSIONS,
            'selectors' => [
                '{{WRAPPER}} .faluss-identity-client-button__action' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);
        $this->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), [
            'name' => 'shadow',
            'selector' => '{{WRAPPER}} .faluss-identity-client-button__action',
        ]);
        $this->end_controls_section();
    }

    protected function render(): void
    {
        $settings = $this->get_settings_for_display();
        $redirect = is_array($settings['redirect_url'] ?? null)
            ? ($settings['redirect_url']['url'] ?? '')
            : '';
        echo Faluss_Identity_Client::button([
            'label' => $settings['label'] ?? '',
            'redirect_url' => $redirect,
        ], false);
    }
}
