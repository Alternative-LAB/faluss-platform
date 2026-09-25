<?php

declare(strict_types=1);

namespace Faluss\Platform\MeStudio;

final class MeStudioAssets
{
    private const CARD_STYLE = 'faluss-me-studio-v2-card';
    private const STUDIO_STYLE = 'faluss-me-studio-v2';
    private const STUDIO_SCRIPT = 'faluss-me-studio-v2';
    private const ONBOARDING_STYLE = 'faluss-me-studio-v2-onboarding';
    private const ONBOARDING_SCRIPT = 'faluss-me-studio-v2-onboarding';
    private const ONBOARDING_V3_STYLE = 'faluss-me-onboarding-v3';
    private const ONBOARDING_V3_SCRIPT = 'faluss-me-onboarding-v3';

    public static function enqueueCard(): void
    {
        self::register();
        wp_enqueue_style(self::CARD_STYLE);
    }

    public static function enqueueStudio(): void
    {
        self::register();
        wp_localize_script(self::STUDIO_SCRIPT, 'falussMeStudioV2', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'previewNonce' => wp_create_nonce('faluss_me_studio_preview'),
            'saveNonce' => wp_create_nonce('faluss_me_studio_save'),
            'uploadNonce' => wp_create_nonce('faluss_me_studio_upload_link_image'),
        ]);
        wp_enqueue_style(self::CARD_STYLE);
        wp_enqueue_style(self::STUDIO_STYLE);
        wp_enqueue_script(self::STUDIO_SCRIPT);
    }

    public static function enqueueOnboarding(string $step): void
    {
        self::register();
        wp_localize_script(self::ONBOARDING_SCRIPT, 'falussMeStudioOnboarding', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'step' => $step,
            'previewNonce' => wp_create_nonce('faluss_me_studio_preview'),
            'saveNonce' => wp_create_nonce('faluss_me_studio_onboarding'),
            'avatarNonce' => wp_create_nonce('faluss_me_studio_upload_avatar'),
            'coverNonce' => wp_create_nonce('faluss_me_studio_upload_cover'),
        ]);
        wp_enqueue_style(self::CARD_STYLE);
        wp_enqueue_style(self::ONBOARDING_STYLE);
        wp_enqueue_script(self::ONBOARDING_SCRIPT);
    }

    public static function enqueueOnboardingV3(): void
    {
        self::register();
        $file = dirname(__DIR__, 2) . '/faluss-platform.php';
        if (!wp_style_is(self::ONBOARDING_V3_STYLE, 'registered')) {
            wp_register_style(self::ONBOARDING_V3_STYLE, plugins_url('assets/me-studio/css/onboarding-v3.css', $file), [self::CARD_STYLE], MeStudioModule::VERSION);
            $scriptUrl = plugins_url('assets/me-studio/js/onboarding-v3.js', $file);
            if ($scriptUrl !== '') {
                wp_register_script(self::ONBOARDING_V3_SCRIPT, $scriptUrl, [], MeStudioModule::VERSION, true);
            }
        }
        wp_localize_script(self::ONBOARDING_V3_SCRIPT, 'falussOnboardingV3', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'managementNonce' => wp_create_nonce('faluss_studio_v3_manage'),
            'contentNonce' => wp_create_nonce('faluss_studio_v3_upload_content'),
            'studioNonce' => wp_create_nonce('faluss_studio_v3_save'),
            'transitionNonce' => wp_create_nonce('faluss_onboarding_v3_transition'),
            'identityDraftNonce' => wp_create_nonce('faluss_onboarding_v3_identity_draft'),
            'previewNonce' => wp_create_nonce('faluss_onboarding_v3_preview'),
            'publishNonce' => wp_create_nonce('faluss_onboarding_v3_publish'),
            'avatarNonce' => wp_create_nonce('faluss_onboarding_v3_upload_avatar'),
            'coverNonce' => wp_create_nonce('faluss_onboarding_v3_upload_cover'),
        ]);
        wp_enqueue_style(self::CARD_STYLE);
        wp_enqueue_style(self::ONBOARDING_V3_STYLE);
        wp_enqueue_script(self::ONBOARDING_V3_SCRIPT);
    }

    public static function enqueueStudioV3(): void
    {
        self::register();
        $file = dirname(__DIR__, 2) . '/faluss-platform.php';
        wp_enqueue_style('faluss-studio-v3', plugins_url('assets/me-studio/css/studio-v3.css', $file), [self::CARD_STYLE], MeStudioModule::VERSION);
        wp_enqueue_script('faluss-studio-v3', plugins_url('assets/me-studio/js/studio-v3.js', $file), [], MeStudioModule::VERSION, true);
        wp_localize_script('faluss-studio-v3', 'falussStudioV3', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'managementNonce' => wp_create_nonce('faluss_studio_v3_manage'),
            'contentNonce' => wp_create_nonce('faluss_studio_v3_upload_content'),
            'studioNonce' => wp_create_nonce('faluss_studio_v3_save'),
            'previewNonce' => wp_create_nonce('faluss_onboarding_v3_preview'),
            'avatarNonce' => wp_create_nonce('faluss_onboarding_v3_upload_avatar'),
            'coverNonce' => wp_create_nonce('faluss_onboarding_v3_upload_cover'),
        ]);
    }

    private static function register(): void
    {
        $file = dirname(__DIR__, 2) . '/faluss-platform.php';
        if (!wp_style_is(self::CARD_STYLE, 'registered')) {
            wp_register_style(self::CARD_STYLE, plugins_url('assets/me-studio/css/card-v2.css', $file), [], MeStudioModule::VERSION);
            wp_register_style(self::STUDIO_STYLE, plugins_url('assets/me-studio/css/studio-v2.css', $file), [self::CARD_STYLE], MeStudioModule::VERSION);
            wp_register_style(self::ONBOARDING_STYLE, plugins_url('assets/me-studio/css/onboarding-v2.css', $file), [self::CARD_STYLE], MeStudioModule::VERSION);
            $studioScriptUrl = plugins_url('assets/me-studio/js/studio-v2.js', $file);
            $onboardingScriptUrl = plugins_url('assets/me-studio/js/onboarding-v2.js', $file);
            if ($studioScriptUrl !== '') {
                wp_register_script(self::STUDIO_SCRIPT, $studioScriptUrl, [], MeStudioModule::VERSION, true);
            }
            if ($onboardingScriptUrl !== '') {
                wp_register_script(self::ONBOARDING_SCRIPT, $onboardingScriptUrl, [], MeStudioModule::VERSION, true);
            }
        }
    }
}
