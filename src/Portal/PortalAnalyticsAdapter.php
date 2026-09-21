<?php

declare(strict_types=1);

namespace Faluss\Platform\Portal;

final class PortalAnalyticsAdapter
{
    public static function registerRuntime(): bool
    {
        if (!class_exists('Faluss_Analytics') || !\Faluss_Analytics::register_runtime()) {
            return false;
        }

        return \Faluss_Analytics::runtime_state() === [
            'local_hub' => true,
            'schema_ready' => true,
            'validators_registered' => true,
            'consumer_registered' => true,
            'conflict' => false,
        ];
    }

    /** @return array<string, mixed> */
    public static function runtimeState(): array
    {
        if (!class_exists('Faluss_Analytics')) {
            return [];
        }
        $state = \Faluss_Analytics::runtime_state();

        return $state;
    }
}
