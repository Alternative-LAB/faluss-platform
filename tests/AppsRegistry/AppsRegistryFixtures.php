<?php

declare(strict_types=1);

namespace Faluss\Platform\AppsRegistry;

final class AppsRegistryFixtures
{
    /** @return array<string, mixed> */
    public static function hubManifest(): array
    {
        return [
            'manifest_version' => '1.0.0',
            'app_key' => 'faluss-hub',
            'capability_namespace' => 'faluss-hub',
            'owner' => ['engine' => 'faluss-hub', 'authority' => 'faluss.com'],
            'product_state' => 'active',
            'canonical_origins' => ['https://faluss.com'],
            'public_presentation' => [
                'display_name' => 'Faluss Hub',
                'summary' => 'Le portail central permettant au membre de retrouver ses applications.',
            ],
            'official_asset' => null,
            'capabilities' => [[
                'capability_key' => 'faluss-hub.daily-reward',
                'interfaces' => ['delegated_action'],
                'requested_bindings' => [[
                    'interface' => 'delegated_action',
                    'slot' => 'portal.apps.card_action',
                ]],
                'read_model_contract' => [
                    'document_type' => 'daily-reward.status',
                    'contract_version' => '1.0.0',
                ],
                'symbolic_actions' => [[
                    'action_key' => 'faluss-hub.daily-reward.claim',
                    'kind' => 'delegated_action',
                ]],
                'compatibility' => self::manifestCompatibility(),
            ]],
            'compatibility' => self::manifestCompatibility(),
        ];
    }

    /** @return array<string, mixed> */
    public static function meManifest(): array
    {
        return [
            'manifest_version' => '1.0.0',
            'app_key' => 'faluss-me',
            'capability_namespace' => 'faluss-me',
            'owner' => ['engine' => 'faluss-me', 'authority' => 'faluss.me'],
            'product_state' => 'active',
            'canonical_origins' => ['https://faluss.me'],
            'public_presentation' => [
                'display_name' => 'Faluss Me',
                'summary' => 'La carte publique personnalisable du membre Faluss.',
            ],
            'official_asset' => null,
            'capabilities' => [],
            'compatibility' => self::manifestCompatibility(),
        ];
    }

    /** @return array<string, mixed> */
    public static function manifestContext(string $appKey): array
    {
        return [
            'operation' => 'manifest.read',
            'parameters' => ['app_key' => $appKey, 'requested_manifest_version' => '1.0.0'],
            'subject_context' => null,
            'sender' => ['node_id' => 'hub-node', 'app_key' => 'faluss-hub'],
            'recipient' => ['node_id' => 'owner-node', 'app_key' => $appKey],
        ];
    }

    /** @return array<string, string> */
    public static function manifestContract(): array
    {
        return [
            'document_type' => 'faluss.app-capability-manifest',
            'contract_version' => '1.0.0',
        ];
    }

    /** @return array<string, mixed> */
    public static function readModel(string $relationship = 'active'): array
    {
        $outputs = $relationship === 'active';

        return [
            'contract_version' => '1.0.0',
            'namespace' => 'apps.registry',
            'consumer' => ['surface' => 'portal', 'consumer_version' => '1.0.0'],
            'freshness' => self::freshness('omit'),
            'source' => [
                'type' => 'registry_read_model',
                'engine' => 'faluss-apps-registry',
                'read_model' => 'apps-registry',
                'source_version' => '1.0.0',
            ],
            'applications' => [[
                'app_key' => 'faluss-hub',
                'availability' => 'available',
                'member_relationship' => $relationship,
                'capabilities' => [[
                    'capability_key' => 'faluss-hub.daily-reward',
                    'owner' => 'faluss-hub',
                    'interfaces' => ['delegated_action'],
                    'state' => 'enabled',
                    'specialized_read_model' => [
                        'status' => 'available',
                        'source' => [
                            'engine' => 'faluss-hub',
                            'read_model' => 'hub-daily-reward',
                            'source_version' => '1.0.0',
                        ],
                        'freshness' => self::freshness('refresh_from_owner'),
                    ],
                    'surface_compatibility' => [
                        'status' => 'compatible',
                        'consumer_version' => '1.0.0',
                    ],
                    'active_bindings' => $outputs ? [[
                        'slot' => 'portal.apps.card_action',
                        'interface' => 'delegated_action',
                        'binding_state' => 'active',
                    ]] : [],
                    'allowed_actions' => $outputs ? [[
                        'action_key' => 'faluss-hub.daily-reward.claim',
                        'owner' => 'faluss-hub',
                        'delegation' => [
                            'type' => 'owner_delegated_action',
                            'target' => 'faluss-hub.daily-reward.claim',
                        ],
                    ]] : [],
                ]],
            ]],
            'compatibility' => [
                'minimum_consumer_version' => '1.0.0',
                'compatible_with' => ['1.0.0'],
                'deprecated' => false,
                'sunset_at' => null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function ownerCapability(): array
    {
        return [
            'capability_key' => 'faluss-hub.daily-reward',
            'state' => 'enabled',
            'specialized_read_model' => [
                'status' => 'available',
                'source' => [
                    'engine' => 'faluss-hub',
                    'read_model' => 'hub-daily-reward',
                    'source_version' => '1.0.0',
                ],
                'freshness' => self::freshness('refresh_from_owner'),
            ],
            'allowed_action_keys' => ['faluss-hub.daily-reward.claim'],
        ];
    }

    /** @return array<string, mixed> */
    private static function manifestCompatibility(): array
    {
        return [
            'minimum_consumer_version' => '1.0.0',
            'compatible_with' => ['1.0.0'],
            'deprecated' => false,
            'sunset_at' => null,
            'replacement_capability_key' => null,
        ];
    }

    /** @return array{generated_at:string,max_age_seconds:int,stale_behavior:string} */
    private static function freshness(string $behavior): array
    {
        return [
            'generated_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'max_age_seconds' => 60,
            'stale_behavior' => $behavior,
        ];
    }
}
