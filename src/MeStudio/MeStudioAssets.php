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
