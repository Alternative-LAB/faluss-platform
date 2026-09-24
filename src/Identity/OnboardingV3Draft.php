<?php

declare(strict_types=1);

namespace Faluss\Platform\Identity;

final class OnboardingV3Draft
{
    private const META_KEY = '_faluss_onboarding_v3_identity_draft';

    /** @return array{}|array{display_name: string, public_slug: string, avatar_attachment_id: int} */
    public static function read(): array
    {
        $falussId = IdentityContract::currentFalussId();
        if ($falussId === '') {
            return [];
        }
        $saved = get_user_meta(get_current_user_id(), self::META_KEY, true);
        if (!is_array($saved) || ($saved['faluss_id'] ?? '') !== $falussId) {
            return [];
        }
        return [
            'display_name' => (string) ($saved['display_name'] ?? ''),
            'public_slug' => (string) ($saved['public_slug'] ?? ''),
            'avatar_attachment_id' => (int) ($saved['avatar_attachment_id'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $fields */
    public static function save(array $fields): bool
    {
        $falussId = IdentityContract::currentFalussId();
        if ($falussId === '') {
            return false;
        }
        $name = $fields['display_name'] ?? '';
        $slug = $fields['public_slug'] ?? '';
        $avatar = $fields['avatar_attachment_id'] ?? '0';
        if (!is_string($name) || !is_string($slug) || !is_scalar($avatar)
            || strlen($name) > 320 || strlen($slug) > 160 || !preg_match('/^(0|[1-9][0-9]*)$/D', (string) $avatar)) {
            return false;
        }
        $avatarId = (int) $avatar;
        if ($avatarId > 0) {
            $post = get_post($avatarId);
            if (!$post instanceof \WP_Post || (int) $post->post_author !== get_current_user_id()
                || !str_starts_with((string) $post->post_mime_type, 'image/')) {
                return false;
            }
        }
        $draft = [
            'faluss_id' => $falussId,
            'display_name' => sanitize_text_field($name),
            'public_slug' => sanitize_text_field($slug),
            'avatar_attachment_id' => $avatarId,
        ];
        update_user_meta(get_current_user_id(), self::META_KEY, $draft);
        return get_user_meta(get_current_user_id(), self::META_KEY, true) === $draft;
    }

    public static function clear(): void
    {
        if (get_current_user_id() > 0) {
            delete_user_meta(get_current_user_id(), self::META_KEY);
        }
    }
}
