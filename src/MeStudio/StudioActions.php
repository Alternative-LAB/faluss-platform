<?php

declare(strict_types=1);

namespace Faluss\Platform\MeStudio;

use Faluss\Platform\Identity\IdentityContract;
use Faluss\Platform\Link\LinkStudioContract;

final class StudioActions
{
    public static function register(): void
    {
        add_action('wp_ajax_faluss_me_studio_preview', [self::class, 'preview']);
        add_action('wp_ajax_faluss_me_studio_save', [self::class, 'save']);
        add_action('wp_ajax_faluss_me_studio_onboarding', [self::class, 'onboarding']);
        add_action('wp_ajax_faluss_me_studio_upload_avatar', [self::class, 'uploadAvatar']);
        add_action('wp_ajax_faluss_me_studio_upload_cover', [self::class, 'uploadCover']);
        add_action('wp_ajax_faluss_me_studio_upload_link_image', [self::class, 'uploadLinkImage']);
    }

    public static function preview(): void
    {
        self::verify('faluss_me_studio_preview');
        $fields = self::fields([
            'cover_attachment_id', 'avatar_visible', 'name_font', 'page_background', 'button_color', 'link_style',
            'structure', 'button_texture', 'avatar_shape', 'avatar_effect', 'wallpaper_size', 'wallpaper_effect',
            'social_style', 'social_color', 'links_mode', 'link_width',
        ]);
        $context = self::text('context');
        if (!in_array($context, ['', 'studio', 'onboarding'], true)) {
            wp_send_json_error(['code' => 'invalid_context'], 422);
        }
        $result = LinkStudioContract::preview($fields, $context === 'onboarding');
        if (is_wp_error($result)) {
            wp_send_json_error(['code' => $result->get_error_code()], 422);
        }
        wp_send_json_success($result);
    }

    public static function save(): void
    {
        self::verify('faluss_me_studio_save');
        $fields = self::fields([
            'cover_attachment_id', 'avatar_visible', 'name_font', 'page_background', 'button_color', 'link_style',
            'structure', 'button_texture', 'avatar_shape', 'avatar_effect', 'wallpaper_size', 'wallpaper_effect',
            'social_style', 'social_color', 'links_mode', 'link_width',
        ]);
        $version = self::text('aggregate_version');
        $request = array_merge([
            'action' => 'faluss_me_studio_save',
            'mutation' => 'save_atomic_design',
            'aggregate_version' => $version,
            'faluss_studio_response' => 'json',
        ], $fields);
        $result = LinkStudioContract::mutate($request);
        $payload = ['code' => $result['code'] ?? 'invalid', 'message' => $result['message'] ?? __('Enregistrement impossible.', 'faluss-platform')];
        if (isset($result['state'])) {
            $payload['state'] = $result['state'];
        }
        if (!empty($result['ok'])) {
            wp_send_json_success($payload);
        }
        wp_send_json_error($payload, (int) ($result['status'] ?? 422));
    }

    public static function onboarding(): void
    {
        self::verify('faluss_me_studio_onboarding');
        $context = IdentityContract::onboardingContext();
        $step = is_string($context['step'] ?? null) ? $context['step'] : '';
        if (empty($context['required']) || $step !== self::text('step')) {
            wp_send_json_error(['code' => 'stale_step'], 409);
        }
        $direction = self::text('direction');
        if (!in_array($direction, ['next', 'back', 'skip'], true)) {
            wp_send_json_error(['code' => 'invalid_direction'], 422);
        }
        $state = LinkStudioContract::state();
        if (is_wp_error($state)) {
            wp_send_json_error(['code' => $state->get_error_code()], 503);
        }
        $nextStep = self::nextStep($step, $direction);
        if ($step === 'wizard_structure' && $direction === 'next') {
            $structure = self::text('structure');
            $nextStep = $structure === 'atomic' ? 'wizard_atomic_warning' : 'wizard_name';
        }
        $fields = self::onboardingFields($step, $direction, $state);
        $result = LinkStudioContract::saveOnboardingStep($step, $nextStep, $fields, self::text('aggregate_version'));
        if (empty($result['ok'])) {
            $payload = ['code' => $result['code'] ?? 'step_failed'];
            if (isset($result['state'])) {
                $payload['state'] = $result['state'];
            }
            wp_send_json_error($payload, (int) ($result['status'] ?? 422));
        }
        wp_send_json_success(['step' => $nextStep]);
    }

    public static function uploadAvatar(): void
    {
        LinkStudioContract::uploadImage('avatar', 'faluss_me_studio_upload_avatar');
    }

    public static function uploadCover(): void
    {
        LinkStudioContract::uploadImage('cover', 'faluss_me_studio_upload_cover');
    }

    public static function uploadLinkImage(): void
    {
        LinkStudioContract::uploadImage('link_image', 'faluss_me_studio_upload_link_image');
    }

    /**
     * @param list<string> $allowed
     * @return array<string, mixed>
     */
    private static function fields(array $allowed): array
    {
        $system = ['action', 'nonce', 'aggregate_version', 'step', 'direction', 'context'];
        $unexpected = array_diff(array_keys($_POST), array_merge($system, $allowed));
        if ($unexpected !== []) {
            wp_send_json_error(['code' => 'unexpected_field'], 422);
        }
        $fields = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $_POST)) {
                if (is_array($_POST[$field])) {
                    wp_send_json_error(['code' => 'invalid_field'], 422);
                }
                $fields[$field] = wp_unslash((string) $_POST[$field]);
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function onboardingFields(string $step, string $direction, array $state): array
    {
        $contracts = [
            'wizard_structure' => ['structure'],
            'wizard_atomic_warning' => [],
            'wizard_atomic_colors' => ['page_background', 'button_color'],
            'wizard_atomic_buttons' => ['link_style', 'button_texture'],
            'wizard_atomic_avatar_upload' => ['avatar_attachment_id'],
            'wizard_atomic_avatar' => ['avatar_shape', 'avatar_effect'],
            'wizard_atomic_wallpaper_upload' => ['cover_attachment_id'],
            'wizard_atomic_wallpaper' => ['wallpaper_size', 'wallpaper_effect'],
            'wizard_atomic_networks' => ['social_style', 'social_color'],
        ];
        if (!isset($contracts[$step])) {
            wp_send_json_error(['code' => 'invalid_step'], 422);
        }
        $posted = self::fields($contracts[$step]);
        $preferences = is_array($state['preferences'] ?? null) ? $state['preferences'] : [];
        $profile = is_array($state['profile'] ?? null) ? $state['profile'] : [];
        $fields = [];
        foreach ($contracts[$step] as $field) {
            if ($direction === 'next' && array_key_exists($field, $posted)) {
                $fields[$field] = $posted[$field];
            } elseif ($field === 'avatar_attachment_id') {
                $fields[$field] = (int) ($profile[$field] ?? 0);
            } else {
                $fields[$field] = $preferences[$field] ?? '';
            }
        }

        return $fields;
    }

    private static function nextStep(string $step, string $direction): string
    {
        $forward = [
            'wizard_structure' => 'wizard_name',
            'wizard_atomic_warning' => 'wizard_atomic_colors',
            'wizard_atomic_colors' => 'wizard_atomic_buttons',
            'wizard_atomic_buttons' => 'wizard_atomic_avatar_upload',
            'wizard_atomic_avatar_upload' => 'wizard_atomic_avatar',
            'wizard_atomic_avatar' => 'wizard_atomic_wallpaper_upload',
            'wizard_atomic_wallpaper_upload' => 'wizard_atomic_wallpaper',
            'wizard_atomic_wallpaper' => 'wizard_atomic_networks',
            'wizard_atomic_networks' => 'wizard_finish',
        ];
        $back = [
            'wizard_atomic_warning' => 'wizard_structure',
            'wizard_atomic_colors' => 'wizard_atomic_warning',
            'wizard_atomic_buttons' => 'wizard_atomic_colors',
            'wizard_atomic_avatar_upload' => 'wizard_atomic_buttons',
            'wizard_atomic_avatar' => 'wizard_atomic_avatar_upload',
            'wizard_atomic_wallpaper_upload' => 'wizard_atomic_avatar',
            'wizard_atomic_wallpaper' => 'wizard_atomic_wallpaper_upload',
            'wizard_atomic_networks' => 'wizard_atomic_wallpaper',
        ];

        return $direction === 'back' ? ($back[$step] ?? 'wizard_structure') : ($forward[$step] ?? 'wizard_finish');
    }

    private static function verify(string $action): void
    {
        if (!is_user_logged_in() || !check_ajax_referer($action, 'nonce', false)) {
            wp_send_json_error(['code' => 'forbidden'], 403);
        }
    }

    private static function text(string $key): string
    {
        return isset($_POST[$key]) && is_string($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
    }
}
