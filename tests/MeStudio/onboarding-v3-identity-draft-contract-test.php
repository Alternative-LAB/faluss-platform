<?php

declare(strict_types=1);

// Isolated request-level characterization; the real WordPress/MariaDB recipe is separate.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

const TEST_FALUSS_ID = '123e4567-e89b-42d3-a456-426614174000';
$testStep = 'v3_identity_simple';
$testMeta = [];
$testUser = 7;

class WP_Post
{
    public int $post_author = 7;
    public string $post_mime_type = 'image/png';
}

class V3Response extends RuntimeException
{
    public function __construct(public bool $success, public array $data, public int $status = 200)
    {
        parent::__construct('JSON response');
    }
}

class Faluss_Identity_Schema
{
    public static function get_status(): array { return ['ready' => true]; }
}

class Faluss_Identity_Registry
{
    public static function get_active_for_wp_user(int $user): ?string { return $user === 7 ? TEST_FALUSS_ID : null; }
}

class Faluss_Identity_Onboarding
{
    public static function v3_context(): array
    {
        return ['available' => true, 'step' => $GLOBALS['testStep']];
    }
    public static function set_v3_cursor(string $step): bool
    {
        $GLOBALS['testStep'] = $step;
        return true;
    }
}

class Faluss_Link
{
    public static function studio_v2_state(): array
    {
        return ['version' => str_repeat('a', 64), 'profile' => ['public_slug' => '', 'publication_status' => 'draft'], 'preferences' => ['structure' => 'simple']];
    }
}

function is_user_logged_in(): bool { return $GLOBALS['testUser'] > 0; }
function get_current_user_id(): int { return $GLOBALS['testUser']; }
function check_ajax_referer(string $action, string $queryArg, bool $die): bool { return $action === 'faluss_onboarding_v3_transition' || $action === 'faluss_onboarding_v3_identity_draft'; }
function is_wp_error(mixed $value): bool { return false; }
function wp_unslash(string $value): string { return $value; }
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function get_post(int $id): ?WP_Post { return $id === 9 ? new WP_Post() : null; }
function get_user_meta(int $user, string $key, bool $single): mixed { return $GLOBALS['testMeta'][$user][$key] ?? ''; }
function update_user_meta(int $user, string $key, mixed $value): bool { $GLOBALS['testMeta'][$user][$key] = $value; return true; }
function delete_user_meta(int $user, string $key): bool { unset($GLOBALS['testMeta'][$user][$key]); return true; }
function wp_send_json_success(array $data): never { throw new V3Response(true, $data); }
function wp_send_json_error(array $data, int $status): never { throw new V3Response(false, $data, $status); }

function request(string $action, array $post): V3Response
{
    $_POST = $post;
    try {
        \Faluss\Platform\MeStudio\OnboardingV3::$action();
    } catch (V3Response $response) {
        return $response;
    }
    throw new RuntimeException('Missing JSON response');
}

function check(bool $value, string $message): void
{
    if (!$value) { throw new RuntimeException($message); }
}

define('FALUSS_PLATFORM_ONBOARDING_V3', true);
$identity = ['display_name' => 'Nom modifié', 'public_slug' => 'nouveau-slug', 'avatar_attachment_id' => '9'];
$autosave = request('identityDraft', ['version' => str_repeat('a', 64), 'fields' => json_encode($identity)]);
check($autosave->success, 'Identity autosave must persist');
$back = request('transition', ['step' => 'v3_identity', 'direction' => 'back', 'version' => str_repeat('a', 64), 'fields' => json_encode($identity)]);
check($back->success && $back->data['step'] === 'v3_mode', 'Back must stay in V3');
check($GLOBALS['testStep'] === 'v3_mode_simple', 'Identity cursor must use V3 mode');
check(\Faluss\Platform\Identity\IdentityContract::onboardingV3IdentityDraft() === [
    'display_name' => 'Nom modifié', 'public_slug' => 'nouveau-slug', 'avatar_attachment_id' => 9,
], 'Reload must read the saved Identity fields');
$GLOBALS['testUser'] = 8;
check(\Faluss\Platform\Identity\IdentityContract::onboardingV3IdentityDraft() === [], 'A different user must not read the draft');
$GLOBALS['testUser'] = 7;
$GLOBALS['testStep'] = 'v3_identity_simple';
$invalid = request('identityDraft', ['version' => str_repeat('b', 64), 'fields' => json_encode($identity)]);
check(!$invalid->success && $invalid->status === 409 && $invalid->data['code'] === 'stale_version', 'Stale autosave must fail closed');
$identity['avatar_attachment_id'] = '10';
$invalid = request('transition', ['step' => 'v3_identity', 'direction' => 'back', 'version' => str_repeat('a', 64), 'fields' => json_encode($identity)]);
check(!$invalid->success && $GLOBALS['testStep'] === 'v3_identity_simple', 'Foreign avatar must block Back without changing cursor');
echo "V3 Identity draft request contract passed.\n";
