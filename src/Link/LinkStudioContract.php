<?php

declare(strict_types=1);

namespace Faluss\Platform\Link;

/** Narrow Studio boundary. The native module never reads Link or Identity tables. */
final class LinkStudioContract
{
    /** @return array<string, mixed> */
    public static function managementOptions(): array
    {
        return \Faluss_Link::studio_v3_management_options();
    }

    public static function available(): bool
    {
        return class_exists('Faluss_Link', false)
            && class_exists('Faluss_Link_Schema', false)
            && \Faluss_Link_Schema::composition_ready();
    }

    /** @return array<string, mixed>|\WP_Error */
    public static function state(): array|\WP_Error
    {
        return \Faluss_Link::studio_v2_state();
    }

    /**
     * @param array<string, mixed> $draft
     * @return array<string, mixed>|\WP_Error
     */
    public static function preview(array $draft, bool $onboarding = false): array|\WP_Error
    {
        return \Faluss_Link::studio_v2_preview($draft, $onboarding);
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function mutate(array $request): array
    {
        return \Faluss_Link::studio_v2_mutate($request);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function saveOnboardingStep(string $step, string $nextStep, array $fields, string $aggregateVersion): array
    {
        return \Faluss_Link::studio_v2_save_onboarding_step($step, $nextStep, $fields, $aggregateVersion);
    }

    /** @return array<string, array<string, mixed>> */
    public static function socialCatalog(): array
    {
        return \Faluss_Link::studio_v2_social_catalog();
    }

    public static function uploadImage(string $field, string $nonceAction): void
    {
        \Faluss_Link::studio_v2_upload_image($field, $nonceAction);
    }

    /**
     * @param array<string, mixed> $draft
     * @return array<string, mixed>|\WP_Error
     */
    public static function previewOnboardingV3(array $draft): array|\WP_Error
    {
        return \Faluss_Link::studio_v3_preview($draft);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function saveOnboardingV3Step(string $step, string $next, array $fields, string $version): array
    {
        return \Faluss_Link::studio_v2_save_onboarding_step($step, $next, $fields, $version);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function saveStudioV3Section(string $section, array $fields, string $version): array
    {
        return \Faluss_Link::studio_v2_save_onboarding_step($section, '', $fields, $version, true);
    }

    /** @return array<string, mixed> */
    public static function publishOnboardingV3(string $version): array
    {
        return \Faluss_Link::studio_v3_publish($version);
    }

    /** @return array<string, mixed> */
    public static function nameOptions(): array
    {
        return \Faluss_Link::studio_v3_name_options();
    }
}
